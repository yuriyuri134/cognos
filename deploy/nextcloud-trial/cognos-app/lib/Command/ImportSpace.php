<?php
declare(strict_types=1);

namespace OCA\Cognos\Command;

use OCA\Cognos\Service\CreationSpaceService;
use OCA\Cognos\Service\SpaceService;
use OCP\IConfig;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 把一份种子（JSON）导进一片创立空间。种子里常有个人信息，所以数据不进仓库，只放命令。
 *
 * 格式：{"title": "...", "traces": [{"key": "t1", "kind": "intent", "source": "原", "author": "...",
 *   "content": "...", "said_at": "2026-10-24 07:01", "act": "...", "confidence": 50, "about": "...",
 *   "stopped_at": "...", "touched_reality": true, "ref": "t0", "status": "withdrawn", "governs": "..."}],
 *   "readings": [{"reader": "...", "content": "...", "confidence": 60, "refs": ["t1"]}]}
 * ref / refs 用种子里的 key 指向前面的条目；更正就是 kind=correction 加 ref 加 status。
 * 押着的前提是 kind=premise（governs 写它管着什么），顶是 kind=hit 加 ref，面对是 kind=answer 加 ref 加 act。
 *
 * --as-self：这片空间就是 Cognos 自己（种子见 seeds/cognos-self.json）。在这里被叫回来的那位，
 * 工作目录是 Cognos 仓库的副本，重新切前提就是改代码（见 tools/cognos-space-mcp/claude_bridge.py）。
 */
final class ImportSpace extends Command {
    public function __construct(
        private CreationSpaceService $creations,
        private SpaceService $spaces,
        private IUserManager $users,
        private IConfig $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('cognos:space:import')
            ->setDescription('Import a seed JSON (traces and readings) into a Cognos creation space')
            ->addArgument('file', InputArgument::REQUIRED, 'Seed JSON file')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Nextcloud owner UID', 'cognos-admin')
            ->addOption('creation', null, InputOption::VALUE_REQUIRED, 'Existing creation id; omitted = create one from the seed title')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and report without writing')
            ->addOption('as-self', null, InputOption::VALUE_NONE, 'This space is Cognos itself: agents called back here work on a branch of the Cognos repo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $file = (string)$input->getArgument('file');
        $uid = (string)$input->getOption('user');
        if (!is_readable($file)) {
            $output->writeln('<error>Seed file is not readable.</error>');
            return Command::FAILURE;
        }
        if (!$this->users->userExists($uid)) {
            $output->writeln('<error>Nextcloud user does not exist.</error>');
            return Command::FAILURE;
        }
        try {
            $seed = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $output->writeln('<error>Seed is not valid JSON: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $traces = is_array($seed['traces'] ?? null) ? $seed['traces'] : [];
        $readings = is_array($seed['readings'] ?? null) ? $seed['readings'] : [];
        $keys = [];
        foreach ($traces as $i => $t) {
            if (isset($t['ref']) && !isset($keys[$t['ref']])) {
                $output->writeln(sprintf('<error>trace %d refers to unknown or later key "%s".</error>', $i, $t['ref']));
                return Command::FAILURE;
            }
            if (isset($t['key'])) {
                $keys[$t['key']] = true;
            }
        }
        $output->writeln(sprintf('Seed has %d traces and %d readings.', count($traces), count($readings)));
        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        $creationId = $input->getOption('creation');
        if ($creationId === null) {
            $title = trim((string)($seed['title'] ?? ''));
            if ($title === '') {
                $output->writeln('<error>Seed has no title; pass --creation.</error>');
                return Command::FAILURE;
            }
            $creationId = (string)$this->creations->create($uid, $title)['id'];
            $output->writeln(sprintf('Created creation %s「%s」.', $creationId, $title));
        } elseif ($this->creations->get((int)$creationId, $uid) === null) {
            $output->writeln('<error>Creation not found for this user.</error>');
            return Command::FAILURE;
        }
        $spaceId = (string)$creationId;

        $ids = [];
        foreach ($traces as $i => $t) {
            try {
                $row = $this->spaces->addTrace('creation', $spaceId, [
                    'kind' => (string)($t['kind'] ?? ''),
                    'source' => (string)($t['source'] ?? ''),
                    'author' => (string)($t['author'] ?? $uid),
                    'content' => (string)($t['content'] ?? ''),
                    'said_at' => $this->timestamp($t['said_at'] ?? null),
                    'act' => $t['act'] ?? null,
                    'confidence' => isset($t['confidence']) ? (int)$t['confidence'] : null,
                    'status' => (string)($t['status'] ?? 'said'),
                    'about' => $t['about'] ?? null,
                    'stopped_at' => $t['stopped_at'] ?? null,
                    'touched_reality' => array_key_exists('touched_reality', $t) ? $t['touched_reality'] : null,
                    'ref_id' => isset($t['ref']) ? $ids[$t['ref']] : null,
                    'governs' => $t['governs'] ?? null,
                ]);
            } catch (\InvalidArgumentException $e) {
                $output->writeln(sprintf('<error>trace %d: %s</error>', $i, $e->getMessage()));
                return Command::FAILURE;
            }
            if (isset($t['key'])) {
                $ids[$t['key']] = $row['id'];
            }
        }
        foreach ($readings as $r) {
            $refs = array_values(array_filter(array_map(static fn($k) => $ids[$k] ?? null, (array)($r['refs'] ?? []))));
            $this->spaces->addReading('creation', $spaceId, (string)($r['reader'] ?? 'AI'), (string)($r['content'] ?? ''), isset($r['confidence']) ? (int)$r['confidence'] : null, $refs);
        }
        if ($input->getOption('as-self')) {
            $this->config->setAppValue('cognos', 'self_creation_id', $spaceId);
            $output->writeln(sprintf('Creation %s is now Cognos itself (self_creation_id).', $spaceId));
        }
        $output->writeln(sprintf('Imported into creation %s. Open /apps/cognos/creations/%s', $spaceId, $spaceId));
        return Command::SUCCESS;
    }

    private function timestamp(mixed $value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        // 种子里的时间按北京时间写
        $date = date_create_immutable((string)$value, new \DateTimeZone('Asia/Shanghai'));
        return $date === false ? null : $date->getTimestamp();
    }
}
