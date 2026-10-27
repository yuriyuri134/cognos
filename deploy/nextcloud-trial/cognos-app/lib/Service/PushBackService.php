<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * 顶回来的要找回押的那位。
 *
 * 一条前提被顶到（kind=hit），就把押着它的那位叫回这片空间，第一件事是面对这一下：站住、碎了、或重新切。
 * 被碰醒，不是到点醒：没有心跳、没有定时，只有被顶到、和上一位做完时排着的下一下
 * （排着的可以是一下顶，也可以是交互对象在对话里说的一句：桥正忙时那句也排着）。
 * 见项目文件 框架审视/会不会全错-2026-10-27.md 第十、十一节。
 *
 * 现在叫得回来的只有经本机桥进来的那几位（Claude Code，和在 Cognos 自己那片空间里替 Cognos 站着的那位）。
 * Hermes 和交互对象押着的，被顶了不另叫，排在它下次进来时入口的最前面。
 */
final class PushBackService {
    /** 押着前提、被顶到时能经本机桥叫回来的主体 */
    public const BRIDGE_HOLDERS = ['Claude Code', 'Cognos'];
    /** agent 之间互相顶，一片空间一天最多叫回来几次（交互对象和现实顶的不限） */
    private const AI_DAILY_DEFAULT = 3;

    public function __construct(
        private SpaceService $spaces,
        private SpaceEntryService $entries,
        private ClaudeBridgeService $bridge,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * 一下顶已经记下了：去找押的那位。
     *
     * @return array{called: bool, holder: ?string, message: string}
     */
    public function afterHit(string $spaceType, string $spaceId, string $uid, array $hit): array {
        $premise = $this->spaces->premiseState($spaceType, $spaceId, (int)$hit['ref_id']);
        if ($premise === null) {
            return $this->result(false, null, '顶的那条不是押着的前提。');
        }
        $holder = $premise['holder'];
        if ($hit['author'] === $holder) {
            return $this->result(false, $holder, '自己顶自己押着的，记下了，下次进来时排在入口最前面。');
        }
        if (!in_array($holder, self::BRIDGE_HOLDERS, true)) {
            return $this->result(false, $holder, $holder === $uid
                ? '押着它的是你，这一下排在你侧栏的最前面。'
                : sprintf('%s 现在叫不回来，这一下排在它下次进来时入口的最前面。', $holder));
        }
        if ($hit['source'] === 'AI' && !$this->takeAiBudget($spaceType, $spaceId)) {
            return $this->result(false, $holder, sprintf('今天 agent 之间在这里互相顶、叫回来的次数到了（%d 次），这一下记下了，%s 下次进来时排在最前面。', $this->aiDaily(), $holder));
        }
        return $this->call($spaceType, $spaceId, $uid, $premise, $hit);
    }

    /**
     * 上一位做完、回答发回来时：还排着的下一件，接着叫。
     * 排着的是一下顶，就在这里叫；是对话里的一句，就把那句的 id 交回去，由调用的地方去叫（它要走对话那条路）。
     */
    public function drain(string $spaceType, string $spaceId, string $uid): ?int {
        $queue = $this->queue($spaceType, $spaceId);
        while ($queue !== []) {
            $next = array_shift($queue);
            $this->saveQueue($spaceType, $spaceId, $queue);
            [$what, $id] = explode(':', $next, 2) + [1 => '0'];
            if ($what === 'msg') {
                return (int)$id;
            }
            $hitId = (int)$id;
            $hit = $this->spaces->getTrace($spaceType, $spaceId, $hitId);
            $premise = $hit === null ? null : $this->spaces->premiseState($spaceType, $spaceId, (int)$hit['ref_id']);
            if ($premise === null || !$premise['standing']) {
                continue;
            }
            $open = array_filter($premise['hits'], static fn(array $h): bool => $h['id'] === $hitId && $h['answer'] === null);
            if ($open === []) {
                continue;
            }
            $this->call($spaceType, $spaceId, $uid, $premise, $hit);
            return null;
        }
        return null;
    }

    /** 桥正忙时交互对象在对话里说的一句：排着，上一位做完接着叫。 */
    public function enqueueMessage(string $spaceType, string $spaceId, int $messageId, bool $front = false): void {
        $this->enqueue($spaceType, $spaceId, 'msg:' . $messageId, $front);
    }

    private function call(string $spaceType, string $spaceId, string $uid, array $premise, array $hit): array {
        $holder = $premise['holder'];
        $space = $this->entries->resolve($spaceType, $spaceId, $uid);
        if ($space === null) {
            return $this->result(false, $holder, '找不到这片空间。');
        }
        if (!$this->bridge->configured()) {
            return $this->result(false, $holder, sprintf('本机的桥没配置，叫不回 %s；这一下排在它下次进来时入口的最前面。', $holder));
        }
        $hitter = $hit['source'] === '世' ? '现实（' . $hit['author'] . ' 记下的）' : $hit['author'];
        $message = sprintf(
            "你押着的 #%d「%s」被 %s 顶了（#%d）：\n\n%s\n\n先面对这一下，再做别的：用 answer_hit（hit_id=%d）留下你怎么面对。站住，就写清凭什么还站得住；碎了，就写清碎在哪；重新切，就写出新的前提%s。不要只改说法：要说清这一下之后，什么变了。",
            $premise['id'],
            $premise['content'],
            $hitter,
            $hit['id'],
            $hit['content'],
            $hit['id'],
            $premise['governs'] !== null ? '，并去改它管着的东西（' . $premise['governs'] . '）' : '',
        );
        $entry = $this->entries->entry($space, $holder, ['caller' => $hitter, 'message' => $message], $this->bridge->canDo($spaceType, $spaceId));
        try {
            // reply_to=0：发回来的回答不回对话里的任何一句
            $this->bridge->send($spaceType, $spaceId, $holder, $this->entries->renderText($entry), [], $message, $hitter, ['hit_id' => $hit['id'], 'reply_to' => 0]);
        } catch (\RuntimeException $error) {
            if ($error->getCode() === ClaudeBridgeService::BUSY) {
                $this->enqueue($spaceType, $spaceId, 'hit:' . $hit['id']);
                return $this->result(false, $holder, sprintf('%s 正在这片空间里做上一件事，做完会接着被叫回来面对这一下。', $holder));
            }
            $this->logger->warning('push-back could not reach the holder', ['holder' => $holder, 'error' => $error->getMessage()]);
            return $this->result(false, $holder, sprintf('没叫上 %s：%s。这一下记下了，排在它下次进来时入口的最前面。', $holder, $error->getMessage()));
        }
        $this->spaces->markSeen($spaceType, $spaceId, $holder);
        return $this->result(true, $holder, sprintf('已经把 %s 叫回来面对这一下，它做完的回答会出现在对话里。', $holder));
    }

    private function takeAiBudget(string $spaceType, string $spaceId): bool {
        $key = 'pushback_ai_' . $spaceType . '_' . $spaceId;
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        [$day, $count] = array_pad(explode(':', $this->config->getAppValue('cognos', $key, '')), 2, '0');
        $count = $day === $today ? (int)$count : 0;
        if ($count >= $this->aiDaily()) {
            return false;
        }
        $this->config->setAppValue('cognos', $key, $today . ':' . ($count + 1));
        return true;
    }

    private function aiDaily(): int {
        return max(0, (int)$this->config->getAppValue('cognos', 'pushback_ai_daily', (string)self::AI_DAILY_DEFAULT));
    }

    /** @return list<string> 一项是 hit:<id> 或 msg:<id> */
    private function queue(string $spaceType, string $spaceId): array {
        $raw = json_decode($this->config->getAppValue('cognos', 'pushback_queue_' . $spaceType . '_' . $spaceId, '[]'), true);
        if (!is_array($raw)) {
            return [];
        }
        // 早先排的只有顶，存的是数字
        return array_values(array_map(static fn($item): string => is_int($item) ? 'hit:' . $item : (string)$item, $raw));
    }

    private function saveQueue(string $spaceType, string $spaceId, array $queue): void {
        $key = 'pushback_queue_' . $spaceType . '_' . $spaceId;
        if ($queue === []) {
            $this->config->deleteAppValue('cognos', $key);
            return;
        }
        $this->config->setAppValue('cognos', $key, json_encode(array_values($queue)));
    }

    private function enqueue(string $spaceType, string $spaceId, string $item, bool $front = false): void {
        $queue = $this->queue($spaceType, $spaceId);
        if (!in_array($item, $queue, true)) {
            if ($front) {
                array_unshift($queue, $item);
            } else {
                $queue[] = $item;
            }
        }
        $this->saveQueue($spaceType, $spaceId, $queue);
    }

    private function result(bool $called, ?string $holder, string $message): array {
        return ['called' => $called, 'holder' => $holder, 'message' => $message];
    }
}
