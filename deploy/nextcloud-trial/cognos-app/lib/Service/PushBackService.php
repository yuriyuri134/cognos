<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * 反对要找回写下想法的那位。
 *
 * 一条前提被反对到（kind=hit），就把写下它的那位叫回这片空间，第一件事是回应这一下：还成立、放下或改成新的。
 * 被碰醒，不是到点醒：没有心跳、没有定时，只有被反对到、和上一位做完时排着的下一下
 * （排着的可以是一条反对，也可以是交互对象在对话里说的一句：桥正忙时那句也排着）。
 * 见项目文件 框架审视/会不会全错-2026-10-27.md 第十、十一节。
 *
 * 现在叫得回来的只有经本机桥进来的那几位（Claude Code，和在 Cognos 自己那片空间里替 Cognos 站着的那位）。
 * Hermes 和交互对象写下的想法，被反对了不另叫，排在它下次进来时入口的最前面。
 */
final class PushBackService {
    /** 写下想法、收到反对时能经本机桥叫回来的主体 */
    public const BRIDGE_HOLDERS = ['Claude Code', 'Cognos'];
    /** agent 之间互相反对，一片空间一天最多叫回来几次（交互对象和现实反对的不限） */
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
     * 一条反对已经记下了：去找写下想法的那位。
     *
     * @return array{called: bool, holder: ?string, message: string}
     */
    public function afterHit(string $spaceType, string $spaceId, string $uid, array $hit): array {
        $premise = $this->spaces->premiseState($spaceType, $spaceId, (int)$hit['ref_id']);
        if ($premise === null) {
            return $this->result(false, null, '反对指向的不是一个写下的想法。');
        }
        $holder = $premise['holder'];
        if ($hit['author'] === $holder) {
            return $this->result(false, $holder, '反对的是自己写的想法，记下了，下次进来时排在入口前面。');
        }
        if (!in_array($holder, self::BRIDGE_HOLDERS, true)) {
            return $this->result(false, $holder, $holder === $uid
                ? '这个想法是你写的，这条反对排在你侧栏的前面。'
                : sprintf('%s 现在叫不回来，这条反对排在它下次进来时入口的前面。', $holder));
        }
        if ($hit['source'] === 'AI' && !$this->takeAiBudget($spaceType, $spaceId)) {
            return $this->result(false, $holder, sprintf('今天 agent 之间在这里互相反对、叫回来的次数到了（%d 次），这条记下了，%s 下次进来时排在前面。', $this->aiDaily(), $holder));
        }
        return $this->call($spaceType, $spaceId, $uid, $premise, $hit);
    }

    /**
     * 上一位做完、回答发回来时：还排着的下一件，接着叫。
     * 排着的是一条反对，就在这里叫；是对话里的一句，就把那句的 id 交回去，由调用的地方去叫（它要走对话那条路）。
     * 返回 ['msg', id]（要调用的地方去叫）、['hit', id]（这里已经叫了），或者 null（什么都没排着，没人被叫）。
     *
     * @return ?array{0: string, 1: int}
     */
    public function drain(string $spaceType, string $spaceId, string $uid): ?array {
        $queue = $this->queue($spaceType, $spaceId);
        while ($queue !== []) {
            $next = array_shift($queue);
            $this->saveQueue($spaceType, $spaceId, $queue);
            [$what, $id] = explode(':', $next, 2) + [1 => '0'];
            if ($what === 'msg') {
                return ['msg', (int)$id];
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
            if ($this->call($spaceType, $spaceId, $uid, $premise, $hit)['called']) {
                return ['hit', $hitId];
            }
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
            return $this->result(false, $holder, sprintf('本机的桥没配置，叫不回 %s；这条反对排在它下次进来时入口的前面。', $holder));
        }
        $hitter = $hit['source'] === '世' ? '现实（' . $hit['author'] . ' 记下的）' : $hit['author'];
        $message = sprintf(
            "%3$s 对你写下的 #%1$d「%2$s」提出了反对（#%4$d）：\n\n%5$s\n\n要回应的话用 answer_hit（hit_id=%6$d，act 是还成立、放下或改成新的）%7$s。",
            $premise['id'],
            $premise['content'],
            $hitter,
            $hit['id'],
            $hit['content'],
            $hit['id'],
            $premise['governs'] !== null ? '。这个想法写着它涉及：' . $premise['governs'] : '',
        );
        $entry = $this->entries->entry($space, $holder, ['caller' => $hitter, 'message' => $message], $this->bridge->canDo($spaceType, $spaceId));
        try {
            // reply_to=0：发回来的回答不回对话里的任何一句
            $this->bridge->send($spaceType, $spaceId, $holder, $this->entries->renderText($entry), [], $message, $hitter, ['hit_id' => $hit['id'], 'reply_to' => 0, 'decision_ids' => SpaceEntryService::decisionIds($entry)]);
        } catch (\RuntimeException $error) {
            if ($error->getCode() === ClaudeBridgeService::BUSY) {
                $this->enqueue($spaceType, $spaceId, 'hit:' . $hit['id']);
                return $this->result(false, $holder, sprintf('%s 正在这片空间里做上一件事，做完会接着被叫回来回应这一下。', $holder));
            }
            $this->logger->warning('push-back could not reach the holder', ['holder' => $holder, 'error' => $error->getMessage()]);
            return $this->result(false, $holder, sprintf('没叫上 %s：%s。这一下记下了，排在它下次进来时入口的最前面。', $holder, $error->getMessage()));
        }
        $this->spaces->markSeen($spaceType, $spaceId, $holder);
        return $this->result(true, $holder, sprintf('已经把 %s 叫回来回应这一下，它做完的回答会出现在对话里。', $holder));
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
        // 早先排的只有反对，存的是数字
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
