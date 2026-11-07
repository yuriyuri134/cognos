<?php
declare(strict_types=1);

namespace OCA\Cognos\Command;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 把别处的对话（比如 claude.ai 项目里的线程）导成 Initium 里的对话，保留原来的时间和说话的人：
 *   occ cognos:conversation:import <file.json> [--user cognos-admin] [--dry-run]
 *
 * 格式：{"conversations": [{"title": "...", "messages": [
 *   {"role": "user"|"assistant", "author": "Claude", "at": "2026-10-24T04:25:32Z", "content": "..."}]}]}
 * 同一账号下已有同名对话的就跳过，所以可以重复跑。数据里有个人内容，不进仓库，只放命令。
 * 直接写库，不叫任何 agent；对话里已经有回答，打开页面也不会自动去读。
 */
final class ImportConversations extends Command {
    public function __construct(private IDBConnection $db, private IUserManager $users) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('cognos:conversation:import')
            ->setDescription('把一份 JSON 里的对话导成 Initium 对话（保留时间和说话的人）')
            ->addArgument('file', InputArgument::REQUIRED, 'JSON 文件')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, '账号', 'cognos-admin')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, '只检查，不写');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $file = (string)$input->getArgument('file');
        $uid = (string)$input->getOption('user');
        $dry = (bool)$input->getOption('dry-run');
        if (!$this->users->userExists($uid)) {
            $output->writeln('没有这个账号：' . $uid);
            return Command::FAILURE;
        }
        $raw = is_readable($file) ? file_get_contents($file) : false;
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || !is_array($data['conversations'] ?? null)) {
            $output->writeln('读不了这个文件，或者里面没有 conversations。');
            return Command::FAILURE;
        }

        $made = 0; $skipped = 0; $messages = 0;
        foreach ($data['conversations'] as $i => $conv) {
            $title = trim((string)($conv['title'] ?? ''));
            $list = is_array($conv['messages'] ?? null) ? $conv['messages'] : [];
            if ($title === '' || $list === []) {
                $output->writeln("第 {$i} 段没有标题或没有话，跳过");
                continue;
            }
            if ($this->exists($uid, $title)) {
                $output->writeln('已经有了，跳过：' . $title);
                $skipped++;
                continue;
            }
            // 按原来的先后排；同一秒的往后挪一秒，页面按时间排时次序不乱
            $rows = [];
            $last = 0;
            foreach ($list as $m) {
                $at = strtotime((string)($m['at'] ?? '')) ?: $last + 1;
                $at = max($at, $last + 1);
                $last = $at;
                $role = ($m['role'] ?? '') === 'user' ? 'user' : 'assistant';
                $rows[] = ['role' => $role, 'author' => $role === 'user' ? null : mb_substr((string)($m['author'] ?? 'Claude'), 0, 64),
                    'content' => (string)($m['content'] ?? ''), 'at' => $at];
            }
            $output->writeln(sprintf('%s：%d 句', $title, count($rows)));
            if ($dry) continue;

            $this->db->beginTransaction();
            try {
                $q = $this->db->getQueryBuilder();
                $q->insert('cognos_creations')->values([
                    'user_id' => $q->createNamedParameter($uid),
                    'title' => $q->createNamedParameter($title),
                    'created_at' => $q->createNamedParameter($rows[0]['at'], IQueryBuilder::PARAM_INT),
                    'updated_at' => $q->createNamedParameter($last, IQueryBuilder::PARAM_INT),
                ])->executeStatement();
                $cid = (int)$q->getLastInsertId();
                $prevUser = null;
                foreach ($rows as $r) {
                    $q = $this->db->getQueryBuilder();
                    $q->insert('cognos_creation_messages')->values([
                        'creation_id' => $q->createNamedParameter($cid, IQueryBuilder::PARAM_INT),
                        'role' => $q->createNamedParameter($r['role']),
                        'content' => $q->createNamedParameter($r['content']),
                        'created_at' => $q->createNamedParameter($r['at'], IQueryBuilder::PARAM_INT),
                        'reply_to' => $q->createNamedParameter($r['role'] === 'user' ? null : $prevUser,
                            $r['role'] === 'user' || $prevUser === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
                        'author' => $q->createNamedParameter($r['author'], $r['author'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
                    ])->executeStatement();
                    if ($r['role'] === 'user') $prevUser = (int)$q->getLastInsertId();
                    $messages++;
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                $output->writeln('导「' . $title . '」时出错，这一段没写进去：' . $e->getMessage());
                return Command::FAILURE;
            }
            $made++;
        }
        $output->writeln(sprintf('新建 %d 段对话、%d 句；已有跳过 %d 段%s', $made, $messages, $skipped, $dry ? '（只检查，没写）' : ''));
        return Command::SUCCESS;
    }

    private function exists(string $uid, string $title): bool {
        $q = $this->db->getQueryBuilder();
        $q->select('id')->from('cognos_creations')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('title', $q->createNamedParameter($title)))
            ->setMaxResults(1);
        $r = $q->executeQuery();
        $found = $r->fetch() !== false;
        $r->closeCursor();
        return $found;
    }
}
