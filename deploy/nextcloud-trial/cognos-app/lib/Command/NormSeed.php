<?php
declare(strict_types=1);

namespace OCA\Cognos\Command;

use OCA\Cognos\Service\NormService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 在 Norm 里建主项目「cognos」，三部分先填一点，看效果用：
 *   occ cognos:norm:seed <账号>
 * 已经有叫 cognos 的项目就不再建。
 */
final class NormSeed extends Command {
    public function __construct(private NormService $norm, private IUserManager $users) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('cognos:norm:seed')
            ->setDescription('在 Norm 里建主项目 cognos，先填一点内容')
            ->addArgument('user', InputArgument::REQUIRED, '账号');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $uid = (string)$input->getArgument('user');
        if (!$this->users->userExists($uid)) {
            $output->writeln('没有这个账号：' . $uid);
            return 1;
        }
        if (($id = $this->norm->findByTitle($uid, 'cognos')) !== null) {
            $output->writeln('已经有了：' . $id);
            return 0;
        }
        $id = $this->norm->create($uid, 'cognos', true, self::stages(), self::branches(), self::relations());
        $output->writeln('建好了：' . $id);
        return 0;
    }

    private static function stages(): array {
        return [
            ['when' => '2026-05 到 08', 'title' => '在对话里想', 'text' => '和不同的 AI 聊认识、展开、尝试，记在 Obsidian 里。还没有要做成什么东西。'],
            ['when' => '2026-09', 'title' => '总设计 v0.1', 'text' => '把聊出来的想法收成一份总设计，第一次说 Cognos 要做什么。'],
            ['when' => '2026-10 上旬', 'title' => '落到 Nextcloud', 'text' => '定下创立空间和示范空间（笔记、组织、项目、学习）。Hermes 能进空间里对话。'],
            ['when' => '2026-10-19 到 10-29', 'title' => '展开和尝试', 'text' => '尝试被认成核心，主体、对象、世界三个空间。本机 Claude Code 经桥进到空间里。'],
            ['when' => '2026-11-01 到 11-05', 'title' => '项目部分', 'text' => '照着文档做了决定、采用、交出去、排队……越做越像记账。没有拿一件真事用过，agent 也没有自己读、自己展开。'],
            ['when' => '2026-11-05', 'title' => '回到 Initium 和 Norm', 'text' => '进来只有两块：Initium 对应创立空间，Norm 对应示范空间，两边都能和 agent 对话。项目放在 Norm 里。', 'current' => true],
        ];
    }

    private static function branches(): array {
        return [
            ['title' => 'Initium（创立空间）', 'parts' => [
                ['title' => '写下来', 'text' => '一句话、一个链接都行，写下就进对话。'],
                ['title' => 'agent 自己读、自己展开', 'text' => '不用点按钮，它读你写的，接着往下走。'],
                ['title' => '走到 Norm', 'text' => '展开到一定程度，进到示范空间里成为项目或别的。还没做。'],
            ]],
            ['title' => 'Norm（示范空间）', 'parts' => [
                ['title' => '对话', 'text' => '在 Norm 里也能直接和 agent 说话。'],
                ['title' => '项目', 'text' => '主项目置顶，其他项目在下面。每个项目三部分：历史阶段、分支和部分拆解、关系图。'],
                ['title' => '笔记、组织、学习', 'text' => '以前设想过的示范空间，这次先不放。'],
            ]],
            ['title' => 'Agent', 'parts' => [
                ['title' => 'Hermes', 'text' => '在 WSL 里，经中转进空间。'],
                ['title' => '本机 Claude Code', 'text' => '经桥（8643）进空间，现在主要用它。'],
                ['title' => 'Codex', 'text' => '能被叫来只读地跑一次。'],
            ]],
            ['title' => '底座', 'parts' => [
                ['title' => 'Nextcloud 应用', 'text' => '本机 8780。'],
                ['title' => '仓库', 'text' => 'GitHub 上的 cognos。'],
            ]],
            ['title' => '想法', 'parts' => [
                ['title' => '尝试', 'text' => '展开、透支都只是尝试的样子，尝试比它们大。'],
                ['title' => '展开', 'text' => '可以往细里填，也可以往别的维度跳。'],
                ['title' => '三个空间', 'text' => '主体、对象、世界，要被填充、建模、模拟。'],
            ]],
        ];
    }

    private static function relations(): array {
        $nodes = [
            ['id' => 'cognos', 'label' => 'cognos', 'kind' => 'project'],
            ['id' => 'initium', 'label' => 'Initium', 'kind' => 'branch'],
            ['id' => 'norm', 'label' => 'Norm', 'kind' => 'branch'],
            ['id' => 'agent', 'label' => 'Agent', 'kind' => 'branch'],
            ['id' => 'base', 'label' => '底座', 'kind' => 'branch'],
            ['id' => 'ideas', 'label' => '想法', 'kind' => 'branch'],
            ['id' => 'a-hermes', 'label' => 'Hermes 进空间', 'kind' => 'attempt', 'state' => '还在'],
            ['id' => 'a-bridge', 'label' => 'Claude Code 经桥', 'kind' => 'attempt', 'state' => '还在'],
            ['id' => 'a-candidates', 'label' => 'agent 提展开候选让人挑', 'kind' => 'attempt', 'state' => '放下了'],
            ['id' => 'a-readings', 'label' => '发生和读法分开', 'kind' => 'attempt', 'state' => '做过'],
            ['id' => 'a-pm', 'label' => '项目管理第 4 版', 'kind' => 'attempt', 'state' => '做成了记账'],
            ['id' => 'a-two', 'label' => '只留 Initium 和 Norm', 'kind' => 'attempt', 'state' => '现在'],
        ];
        $edges = [
            ['from' => 'cognos', 'to' => 'initium'], ['from' => 'cognos', 'to' => 'norm'], ['from' => 'cognos', 'to' => 'agent'],
            ['from' => 'cognos', 'to' => 'base'], ['from' => 'cognos', 'to' => 'ideas'],
            ['from' => 'agent', 'to' => 'a-hermes'], ['from' => 'agent', 'to' => 'a-bridge'],
            ['from' => 'initium', 'to' => 'a-candidates'], ['from' => 'ideas', 'to' => 'a-readings'],
            ['from' => 'norm', 'to' => 'a-pm'], ['from' => 'norm', 'to' => 'a-two'],
            ['from' => 'a-pm', 'to' => 'a-two', 'label' => '回头'],
            ['from' => 'initium', 'to' => 'norm', 'label' => '展开后进入'],
            ['from' => 'agent', 'to' => 'initium', 'label' => '读和展开'],
        ];
        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
