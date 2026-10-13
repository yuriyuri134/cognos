<?php
declare(strict_types=1);

namespace OCA\Cognos\Command;

use OCP\IDBConnection;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImportObsidian extends Command {
    public function __construct(private IDBConnection $db, private IUserManager $users) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('cognos:import-obsidian')
            ->setDescription('Import an Obsidian Markdown batch into Cognos materials')
            ->addArgument('path', InputArgument::REQUIRED, 'Mounted Obsidian directory')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Nextcloud owner UID', 'cognos-admin')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Import batch identity', 'manual')
            ->addOption('space', null, InputOption::VALUE_REQUIRED, 'Existing Cognos space ID')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Space title when creating one', 'Obsidian 材料导入')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Scan and report without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $root = realpath((string)$input->getArgument('path'));
        $uid = (string)$input->getOption('user');
        $batch = (string)$input->getOption('batch');
        $dry = (bool)$input->getOption('dry-run');
        if ($root === false || !is_dir($root)) {
            $output->writeln('<error>Obsidian path does not exist.</error>'); return Command::FAILURE;
        }
        if (!$this->users->userExists($uid)) {
            $output->writeln('<error>Nextcloud user does not exist.</error>'); return Command::FAILURE;
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') continue;
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            if (str_starts_with($relative, '.obsidian/')) continue;
            $content = file_get_contents($file->getPathname());
            if ($content === false) continue;
            $files[] = ['path' => $relative, 'title' => pathinfo($relative, PATHINFO_FILENAME), 'content' => $content, 'hash' => hash('sha256', $content)];
        }
        usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        $output->writeln(sprintf('Found %d Markdown materials for batch %s.', count($files), $batch));
        if ($dry) return Command::SUCCESS;

        $spaceId = (string)($input->getOption('space') ?? '');
        if ($spaceId === '') {
            $spaceId = bin2hex(random_bytes(16)); $now = time();
            $q = $this->db->getQueryBuilder();
            $q->insert('cognos_note_spaces')->values([
                'id'=>$q->createNamedParameter($spaceId), 'owner_uid'=>$q->createNamedParameter($uid),
                'title'=>$q->createNamedParameter((string)$input->getOption('title')),
                'description'=>$q->createNamedParameter('Imported from Obsidian; batch: '.$batch),
                'created_at'=>$q->createNamedParameter($now), 'updated_at'=>$q->createNamedParameter($now),
            ])->executeStatement();
        }
        $existing = [];
        $q = $this->db->getQueryBuilder();
        $q->select('id','source_json')->from('cognos_notes')->where($q->expr()->eq('space_id',$q->createNamedParameter($spaceId)))->andWhere($q->expr()->eq('owner_uid',$q->createNamedParameter($uid)));
        foreach ($q->executeQuery()->fetchAllAssociative() as $row) {
            $source = json_decode((string)$row['source_json'], true) ?: [];
            if (($source['source'] ?? '') === 'obsidian') $existing[(string)($source['path'].'|'.$source['content_hash'])] = true;
        }
        $imported = 0; $skipped = 0; $now = time();
        foreach ($files as $file) {
            $key = $file['path'].'|'.$file['hash'];
            if (isset($existing[$key])) { $skipped++; continue; }
            $id = bin2hex(random_bytes(16));
            $source = ['source'=>'obsidian','path'=>$file['path'],'content_hash'=>$file['hash'],'revision'=>1,'import_batch'=>$batch];
            $q = $this->db->getQueryBuilder();
            $q->insert('cognos_notes')->values([
                'id'=>$q->createNamedParameter($id), 'space_id'=>$q->createNamedParameter($spaceId), 'owner_uid'=>$q->createNamedParameter($uid),
                'title'=>$q->createNamedParameter($file['title']), 'content'=>$q->createNamedParameter($file['content']),
                'source_json'=>$q->createNamedParameter(json_encode($source, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),
                'revision'=>$q->createNamedParameter(1), 'content_hash'=>$q->createNamedParameter($file['hash']),
                'created_at'=>$q->createNamedParameter($now), 'updated_at'=>$q->createNamedParameter($now),
            ])->executeStatement();
            $imported++;
        }
        $q = $this->db->getQueryBuilder();
        $q->update('cognos_note_spaces')->set('updated_at',$q->createNamedParameter(time()))->where($q->expr()->eq('id',$q->createNamedParameter($spaceId)))->andWhere($q->expr()->eq('owner_uid',$q->createNamedParameter($uid)))->executeStatement();
        $output->writeln(sprintf('Imported %d; skipped existing %d; space %s.', $imported, $skipped, $spaceId));
        return Command::SUCCESS;
    }
}
