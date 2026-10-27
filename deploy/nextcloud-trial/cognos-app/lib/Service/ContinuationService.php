<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * 让思考自己接着走：叫醒它的不再只有交互对象的下一句话。
 *
 * 这片空间里挂着没完的（open）。一次运行走完，它说停在哪（stop）：
 * - 接着：下一个它自己够得着的口子。Cognos 马上从这个口子叫下一次，不从整段对话重新开始。
 * - 卡住：只有交互对象、钱或现实能给的那一件。这一件停下，换另一件还拉着的。
 * - 完了：它凭什么算完。也停下；收不收由交互对象定。
 * 检查（reality）没过，不管它自己说停在哪，都接着叫，像 CI 红了一样。
 * 口子和以前走过的几乎一样，Cognos 记一条〔世〕「原地」，这一件停下：接着走不等于往前走。
 *
 * 没有心跳、没有定时：只在一次运行结束、有东西还拉着时才叫；每片空间每天有上限（continue_daily）。
 * 交互对象说的话和押着的前提被顶，排在它前面（PushBackService::drain 先走）。
 * 来路见项目文件 框架审视/为什么会停-2026-10-27.md。
 */
final class ContinuationService {
    private const DAILY_DEFAULT = 12;
    /** 两个口子的字二元组重合到这个比例，就算在原地 */
    private const CIRCLING = 0.6;

    public function __construct(
        private SpaceService $spaces,
        private SpaceEntryService $entries,
        private ClaudeBridgeService $bridge,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * 挂上一件没完的，马上从它开始走（桥忙就等手上那件做完）。
     *
     * @return array{open: array, started: array}
     */
    public function hang(string $spaceType, string $spaceId, string $uid, string $content, string $source, string $author, ?int $saidAt = null, ?string $about = null): array {
        $open = $this->spaces->addTrace($spaceType, $spaceId, [
            'kind' => 'open',
            'about' => $about,
            'source' => $source,
            'author' => $author,
            'content' => $content,
            'said_at' => $source === 'AI' ? null : ($saidAt ?? time()),
        ]);
        return ['open' => $open, 'started' => $this->start($spaceType, $spaceId, $uid, (int)$open['id'])];
    }

    /**
     * 从一件没完的接着叫一次。$force：交互对象按了「接着走」，原地、卡住、完了的也叫。
     *
     * @return array{called: bool, message: string}
     */
    public function start(string $spaceType, string $spaceId, string $uid, int $openId, bool $force = false): array {
        $open = $this->spaces->openState($spaceType, $spaceId, $openId);
        if ($open === null || $open['closed']) {
            return $this->result(false, '这一件已经收了，或者不在这片空间里。');
        }
        if (!$force && !$open['pulls']) {
            return $this->result(false, sprintf('#%d 停在「%s」，不自己往下叫。', $openId, $open['state']));
        }
        if (!$this->bridge->configured()) {
            return $this->result(false, '本机的桥没配置，叫不了；它挂着，桥起来以后按「接着走」。');
        }
        $space = $this->entries->resolve($spaceType, $spaceId, $uid);
        if ($space === null) {
            return $this->result(false, '找不到这片空间。');
        }
        if (!$this->takeBudget($spaceType, $spaceId)) {
            return $this->result(false, sprintf('今天在这里接着走的次数到了（%d 次），它挂着，明天或你按「接着走」再叫。', $this->daily()));
        }

        $subject = $this->bridge->subjectFor($spaceType, $spaceId);
        $message = $this->message($open, $force);
        $entry = $this->entries->entry($space, $subject, ['caller' => 'Cognos（#' . $openId . ' 还没完）', 'message' => $message], array_merge(
            $this->bridge->canDo($spaceType, $spaceId),
            ['stop_here：走之前说清这一次停在哪（open_id=' . $openId . '）。不说，Cognos 就不知道从哪接着。'],
        ));
        try {
            // history 是空的：从口子接着走，不从整段对话重新开始
            $this->bridge->send($spaceType, $spaceId, $subject, $this->entries->renderText($entry), [], $message, 'Cognos', [
                'reply_to' => 0,
                'open_id' => $openId,
                'run_started' => time(),
                'base_branch' => $this->config->getAppValue('cognos', $this->branchKey($spaceType, $spaceId, $openId), '') ?: null,
            ]);
        } catch (\RuntimeException $error) {
            $this->giveBackBudget($spaceType, $spaceId);
            if ($error->getCode() === ClaudeBridgeService::BUSY) {
                // 手上那件做完发回来时（SpaceApiController::addMessage）会接着叫还拉着的
                return $this->result(false, sprintf('%s 正在做手上那件，做完会从 #%d 接着走。', $subject, $openId));
            }
            $this->logger->warning('continuation could not reach the bridge', ['open' => $openId, 'error' => $error->getMessage()]);
            return $this->result(false, '没叫上：' . $error->getMessage());
        }
        $this->spaces->markSeen($spaceType, $spaceId, $subject);
        return $this->result(true, sprintf('%s 从 #%d 接着走了，做完的回答会出现在对话里。', $subject, $openId));
    }

    /**
     * 一次从没完的叫起来的运行走完了：记下检查，看它停在哪，判断是不是原地。只记，不叫（叫在 resume）。
     *
     * @param ?array{ok: bool, text: string} $checks 桥在仓库副本上跑的检查
     */
    public function afterRun(string $spaceType, string $spaceId, int $openId, int $runStarted, ?string $branch, ?array $checks, bool $timedOut): void {
        $open = $this->spaces->openState($spaceType, $spaceId, $openId);
        if ($open === null || $open['closed']) {
            return;
        }
        if ($branch !== null && $branch !== '') {
            // 下一次从这条分支接着改，而不是又从 master 重新切
            $this->config->setAppValue('cognos', $this->branchKey($spaceType, $spaceId, $openId), $branch);
        }
        if ($checks !== null) {
            $this->spaces->addTrace($spaceType, $spaceId, [
                'kind' => 'reality',
                'source' => '世',
                'author' => '检查',
                'act' => $checks['ok'] ? '过了' : '没过',
                'content' => $checks['text'] !== '' ? $checks['text'] : ($checks['ok'] ? '检查过了。' : '检查没过。'),
                'touched_reality' => true,
                'ref_id' => $openId,
                'said_at' => time(),
            ]);
        }

        $stopsThisRun = array_values(array_filter($open['steps'], static fn(array $s): bool => $s['kind'] === 'stop' && $s['source'] === 'AI' && $s['created_at'] >= $runStarted));
        $stop = $stopsThisRun === [] ? null : $stopsThisRun[count($stopsThisRun) - 1];
        if ($stop === null) {
            if ($timedOut) {
                // 做到一半被时限停下：还拉着，下一次从工作目录和这一次留下的发生里接着
                $this->observe($spaceType, $spaceId, $openId, '时限', '这一次做到时限被停下了，没来得及说停在哪。下一次从工作目录和这一次留下的发生里接着。');
                return;
            }
            $this->observe($spaceType, $spaceId, $openId, '没说', '这一次走完，没说停在哪。Cognos 不知道从哪接着，这一件先停下。');
            return;
        }
        if ($stop['act'] !== '接着' || $stop['stopped_at'] === null) {
            return;
        }
        foreach ($open['edges'] as $before) {
            if ($before['id'] === $stop['id']) {
                continue;
            }
            if ($this->similar((string)$stop['stopped_at'], $before['edge']) >= self::CIRCLING) {
                $this->observe($spaceType, $spaceId, $openId, '原地', sprintf(
                    '这一次的口子（#%d）和 #%d 走过的几乎一样：在原地转。这一件先停下，等别的东西撞进来，或者你按「接着走」。',
                    $stop['id'], $before['id'],
                ));
                return;
            }
        }
    }

    /**
     * 手上的做完了、交互对象和顶也没排着：从还拉着的那一件接着叫。先接着刚才那件，再按挂上的先后。
     *
     * @return ?array{called: bool, message: string} 没有还拉着的就是 null
     */
    public function resume(string $spaceType, string $spaceId, string $uid, ?int $prefer = null): ?array {
        $pulling = array_values(array_filter($this->spaces->opens($spaceType, $spaceId), static fn(array $o): bool => $o['pulls']));
        if ($pulling === []) {
            return null;
        }
        usort($pulling, static fn(array $a, array $b): int => ($b['id'] === $prefer) <=> ($a['id'] === $prefer) ?: $a['id'] <=> $b['id']);
        return $this->start($spaceType, $spaceId, $uid, (int)$pulling[0]['id']);
    }

    /** 今天在这片空间里接着走了几次、上限多少（侧栏显）。 */
    public function budget(string $spaceType, string $spaceId): array {
        [$day, $count] = $this->budgetState($spaceType, $spaceId);
        return ['used' => $day === $this->today() ? $count : 0, 'daily' => $this->daily()];
    }

    /** 叫起这一次时给它的话：从哪件没完的、哪个口子接着，走过哪些口子。 */
    private function message(array $open, bool $force): string {
        $lines = [];
        $lines[] = sprintf('这一次不是有人在对话里叫你。是 #%d 还没完，Cognos 让你从它接着走%s。', $open['id'], $force ? '（交互对象按了「接着走」）' : '');
        $lines[] = '';
        $lines[] = '## 还没完的';
        $who = $open['source'] === '原' ? '〔原 ' . $open['author'] . '〕' : '〔' . $open['source'] . ' ' . $open['author'] . '〕';
        $lines[] = sprintf('#%d %s%s', $open['id'], $who, $open['content']);

        $stop = $open['last_stop'];
        $lines[] = '';
        if ($stop === null) {
            $lines[] = '## 还没有谁从它走过';
            $lines[] = '这是第一次。先答：它在问什么、在什么情况里、你押着什么去走；然后走到够不着为止。';
        } else {
            $lines[] = '## 上一次停在';
            $lines[] = sprintf('- 停：%s（#%d，%s）', $stop['act'], $stop['id'], $stop['author']);
            $lines[] = '- 往前走了：' . $stop['content'];
            if ($stop['stopped_at'] !== null) {
                $lines[] = '- 口子：' . $stop['stopped_at'];
            }
        }
        $last = $open['last'];
        if ($last !== null && $last['kind'] === 'reality') {
            $lines[] = '';
            $lines[] = sprintf('## 检查（〔世〕，%s）', $last['act']);
            $lines[] = $last['content'];
            if ($last['act'] === '没过') {
                $lines[] = '没过的先面对：是改错了，还是检查本身不对，写清楚。';
            }
        }
        if (count($open['edges']) > 1) {
            $lines[] = '';
            $lines[] = '## 走过的口子（再回到其中一个，就是在原地转，Cognos 会停下这一件）';
            foreach ($open['edges'] as $e) {
                $lines[] = sprintf('- #%d %s', $e['id'], $e['edge']);
            }
        }
        $lines[] = '';
        $lines[] = '## 这一次';
        $lines[] = '从口子接着走，不从头复述。做到你自己够不着为止。';
        $lines[] = sprintf('走之前用 stop_here（open_id=%d）说清停在哪：', $open['id']);
        $lines[] = '- 接着：下一个你自己够得着的口子，要具体到下一次一进来就能动手；Cognos 会马上从它叫下一次。';
        $lines[] = '- 卡住：只有交互对象、钱或现实能给的是哪一件。';
        $lines[] = '- 完了：凭什么算完。收不收由交互对象定。';
        $lines[] = 'content 写这一次往前走了什么（和上一次比，多了什么、哪里变了），不是做了哪些动作。';
        return implode("\n", $lines);
    }

    private function observe(string $spaceType, string $spaceId, int $openId, string $act, string $content): void {
        $this->spaces->addTrace($spaceType, $spaceId, [
            'kind' => 'stop',
            'source' => '世',
            'author' => 'Cognos',
            'act' => $act,
            'content' => $content,
            'ref_id' => $openId,
            'said_at' => time(),
        ]);
    }

    /** 两段话的字二元组重合度（Dice），0 到 1。 */
    private function similar(string $a, string $b): float {
        $grams = static function (string $text): array {
            $chars = preg_split('//u', preg_replace('/[\s\p{P}]+/u', '', mb_strtolower($text)) ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $out = [];
            for ($i = 0; $i < count($chars) - 1; $i++) {
                $out[$chars[$i] . $chars[$i + 1]] = true;
            }
            return $out;
        };
        $x = $grams($a);
        $y = $grams($b);
        if ($x === [] || $y === []) {
            return 0.0;
        }
        return 2 * count(array_intersect_key($x, $y)) / (count($x) + count($y));
    }

    private function takeBudget(string $spaceType, string $spaceId): bool {
        [$day, $count] = $this->budgetState($spaceType, $spaceId);
        $count = $day === $this->today() ? $count : 0;
        if ($count >= $this->daily()) {
            return false;
        }
        $this->config->setAppValue('cognos', $this->budgetKey($spaceType, $spaceId), $this->today() . ':' . ($count + 1));
        return true;
    }

    private function giveBackBudget(string $spaceType, string $spaceId): void {
        [$day, $count] = $this->budgetState($spaceType, $spaceId);
        if ($day === $this->today() && $count > 0) {
            $this->config->setAppValue('cognos', $this->budgetKey($spaceType, $spaceId), $day . ':' . ($count - 1));
        }
    }

    /** @return array{0: string, 1: int} */
    private function budgetState(string $spaceType, string $spaceId): array {
        [$day, $count] = array_pad(explode(':', $this->config->getAppValue('cognos', $this->budgetKey($spaceType, $spaceId), '')), 2, '0');
        return [$day, (int)$count];
    }

    private function budgetKey(string $spaceType, string $spaceId): string {
        return 'continue_' . $spaceType . '_' . $spaceId;
    }

    private function branchKey(string $spaceType, string $spaceId, int $openId): string {
        return 'continue_branch_' . $spaceType . '_' . $spaceId . '_' . $openId;
    }

    private function today(): string {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
    }

    private function daily(): int {
        return max(0, (int)$this->config->getAppValue('cognos', 'continue_daily', (string)self::DAILY_DEFAULT));
    }

    private function result(bool $called, string $message): array {
        return ['called' => $called, 'message' => $message];
    }
}
