<?php

declare(strict_types=1);

namespace OCA\Cognos\Command;

use OCA\Cognos\Service\CreationSpaceService;
use OCA\Cognos\Service\ReaderService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 让读者现在读，试读者用：
 *   occ cognos:read <账号> <对话 id ……>          读 Initium 里的这几段对话
 *   occ cognos:read <账号> --space <空间 id>       回头读 Norm 里的一个空间
 *   occ cognos:read <账号> --list                  列出这个账号最长的 Initium 对话，挑着读
 * 读者在本机的桥上读（要桥开着），读完自己发回来；结果在那段对话页面右边。
 */
final class ReadNow extends Command {
    public function __construct(private ReaderService $readers, private CreationSpaceService $creations, private IUserManager $users) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('cognos:read')
            ->setDescription('让读者现在读几段 Initium 对话，或回头读 Norm 里的一个空间')
            ->addArgument('user', InputArgument::REQUIRED, '账号')
            ->addArgument('creations', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Initium 对话 id')
            ->addOption('space', null, InputOption::VALUE_REQUIRED, 'Norm 空间 id')
            ->addOption('list', null, InputOption::VALUE_NONE, '列出最长的对话');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $uid = (string)$input->getArgument('user');
        if (!$this->users->userExists($uid)) {
            $output->writeln('没有这个账号：' . $uid);
            return 1;
        }
        if ($input->getOption('list')) {
            $rows = [];
            foreach ($this->creations->list($uid) as $c) {
                $rows[] = [(int)$c['id'], (string)$c['title'], count($this->creations->getMessages((int)$c['id'], $uid))];
            }
            usort($rows, static fn(array $a, array $b): int => $b[2] <=> $a[2]);
            foreach (array_slice($rows, 0, 30) as [$id, $title, $n]) {
                $output->writeln(sprintf('%6d  %4d 条  %s', $id, $n, $title));
            }
            return 0;
        }
        $space = $input->getOption('space');
        if (is_string($space) && $space !== '') {
            $r = $this->readers->readSpace($uid, $space, true);
            $output->writeln($r['started'] ? '读者开始回头读，记录 #' . $r['reading']['id'] . ($r['reading']['state'] === 'failed' ? '，但没交出去：' . $r['reading']['error'] : '') : '没读：' . ($r['why'] ?? ''));
            return 0;
        }
        foreach ((array)$input->getArgument('creations') as $id) {
            try {
                $r = $this->readers->readInitium($uid, (int)$id, true);
                $output->writeln($id . '：' . ($r['started'] ? '开始读，记录 #' . $r['reading']['id'] . ($r['reading']['state'] === 'failed' ? '，但没交出去：' . $r['reading']['error'] : '') : '没读：' . ($r['why'] ?? '')));
            } catch (\Throwable $e) {
                $output->writeln($id . '：' . $e->getMessage());
            }
        }
        return 0;
    }
}
