<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\App\IAppManager;

/**
 * 入口：主体走进一片空间时，空间从它站的位置把自己显出来。
 *
 * 同一份内容，Hermes 被叫进来时放进 system prompt，页面侧栏「这里」也显同样的东西。
 * 不是角色，不说「你是谁、该怎么表现」，只说这里在哪、有什么、以前谁试过什么、停在哪、哪里还空着。
 * 设计见项目文件 空间入口/第一片空间-实现说明.md 第三节。
 */
final class SpaceEntryService {
    private const ATTEMPT_LIMIT = 15;
    private const STALE_AFTER = 30 * 86400;

    public function __construct(
        private SpaceService $spaces,
        private CreationSpaceService $creations,
        private IAppManager $apps,
    ) {
    }

    /**
     * 找到这片空间；不属于这个用户就返回 null。
     */
    public function resolve(string $spaceType, string $spaceId, string $uid): ?array {
        if ($spaceType !== 'creation' || !ctype_digit($spaceId)) {
            return null;
        }
        $creation = $this->creations->get((int)$spaceId, $uid);
        if ($creation === null) {
            return null;
        }
        return ['type' => 'creation', 'id' => $spaceId, 'title' => (string)$creation['title'], 'kind_label' => '创立空间'];
    }

    /**
     * @param string $subject 走进来的主体（用户 id，或 Hermes、Claude Code 这样的参与者名）
     * @param ?array{caller: string, message: string} $call 这次是谁叫的、原话是什么
     * @param list<string> $canDo 这次能用的工具和够不着的东西
     */
    public function entry(array $space, string $subject, ?array $call = null, array $canDo = []): array {
        $type = $space['type'];
        $id = $space['id'];
        $traces = $this->spaces->listTraces($type, $id);
        $now = time();

        $intents = [];
        $intentsClosed = [];
        $attempts = [];
        $facts = [];
        $others = [];
        $bySubject = [];
        foreach ($traces as $trace) {
            $bySubject[$trace['author']] = $trace;
            if ($trace['kind'] === 'correction') {
                continue;
            }
            $closed = in_array($trace['effective_status'], ['rejected', 'withdrawn'], true);
            switch ($trace['kind']) {
                case 'intent':
                    if ($closed) {
                        $intentsClosed[] = $trace;
                    } else {
                        $intents[] = $trace;
                    }
                    break;
                case 'attempt':
                    $attempts[] = $trace;
                    break;
                case 'fact':
                case 'feedback':
                    $trace['stale'] = $trace['said_at'] !== null && $now - $trace['said_at'] > self::STALE_AFTER;
                    $facts[] = $trace;
                    break;
                default:
                    $others[] = $trace;
            }
        }

        $since = null;
        $lastSeen = $this->spaces->lastSeen($type, $id, $subject);
        if ($lastSeen !== null) {
            $since = [
                'last_seen' => $lastSeen,
                'traces' => array_values(array_filter($traces, static fn(array $t): bool => $t['created_at'] > $lastSeen)),
                'readings' => $this->spaces->readingsSince($type, $id, $lastSeen),
            ];
        }

        return [
            'space' => $space,
            'purpose' => $this->purpose(),
            'call' => $call,
            'subjects' => array_values(array_map(static fn(array $t): array => ['author' => $t['author'], 'last' => $t], $bySubject)),
            'intents' => $intents,
            'intents_closed' => $intentsClosed,
            'attempts' => array_slice($attempts, -self::ATTEMPT_LIMIT),
            'attempts_total' => count($attempts),
            'facts' => $facts,
            'others' => $others,
            'readings' => $this->spaces->currentReadings($type, $id),
            'gaps' => $this->gaps($intents, $attempts, $traces),
            'since' => $since,
            'can_do' => $canDo,
            'before_leaving' => [
                '开头两三行写清楚：你站在哪；你读到的意图是什么，经过了上面哪几条；这一步是不是因为好做才选的。',
                '你做的尝试留下来，写明停在哪、碰没碰过现实。',
                '你对这里的理解变了，把新的读法留下来。旧的不会被删。',
            ],
        ];
    }

    /** 把入口写成一段文字，放进 agent 的上下文。 */
    public function renderText(array $entry): string {
        $space = $entry['space'];
        $lines = [];

        $lines[] = '## 你在哪';
        $lines[] = sprintf('这里是 Cognos 的%s「%s」。', $space['kind_label'], $space['title']);
        if ($entry['purpose'] !== []) {
            $lines[] = '它在 Cognos 里面。Cognos 为了什么（原话）：';
            foreach ($entry['purpose'] as $line) {
                $lines[] = '- ' . $line;
            }
        }
        if ($entry['call'] !== null) {
            $lines[] = sprintf('这次是 %s 叫你进来的，原话在对话最后一条。', $entry['call']['caller']);
        }
        if ($entry['since'] !== null) {
            $lines[] = sprintf('你上次来是 %s。之后这里多了 %d 条发生、%d 条读法，下面带〔新〕的就是。',
                $this->date($entry['since']['last_seen']), count($entry['since']['traces']), count($entry['since']['readings']));
        }
        $newIds = $entry['since'] === null ? [] : array_column($entry['since']['traces'], 'id');

        $lines[] = '';
        $lines[] = '## 还有谁在';
        if ($entry['subjects'] === []) {
            $lines[] = '还没有谁在这里留下过东西。';
        }
        foreach ($entry['subjects'] as $s) {
            $lines[] = sprintf('- %s：最近一条是 %s', $s['author'], $this->oneLine($s['last'], 60));
        }

        $lines[] = '';
        $lines[] = '## 意图（原话照放，不合并）';
        if ($entry['intents'] === []) {
            $lines[] = '这里还没有写下意图。';
        }
        foreach ($entry['intents'] as $t) {
            $lines[] = '- ' . $this->traceLine($t, $newIds);
        }
        foreach ($entry['intents_closed'] as $t) {
            $lines[] = '- （' . $this->statusLabel($t['effective_status']) . '）' . $this->traceLine($t, $newIds);
        }

        $lines[] = '';
        $lines[] = '## 以前的尝试';
        if ($entry['attempts_total'] === 0) {
            $lines[] = '还没有尝试。';
        } elseif ($entry['attempts_total'] > count($entry['attempts'])) {
            $lines[] = sprintf('（只列最近 %d 条，还有 %d 条，可以用 search_traces 找）', count($entry['attempts']), $entry['attempts_total'] - count($entry['attempts']));
        }
        foreach ($entry['attempts'] as $t) {
            $line = '- ' . $this->traceLine($t, $newIds);
            $line .= $t['touched_reality'] === true ? '；碰过现实' : ($t['touched_reality'] === false ? '；只在模型上推过' : '');
            $line .= $t['stopped_at'] !== null ? '；停在：' . $t['stopped_at'] : '；没写停在哪';
            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = '## 事实和现实碰回来的';
        if ($entry['facts'] === []) {
            $lines[] = '还没有。';
        }
        foreach ($entry['facts'] as $t) {
            $lines[] = '- ' . ($t['stale'] ? '（可能已经变了）' : '') . $this->traceLine($t, $newIds);
        }
        foreach ($entry['others'] as $t) {
            $lines[] = '- [' . $t['kind'] . '] ' . $this->traceLine($t, $newIds);
        }

        $lines[] = '';
        $lines[] = '## 读法（各是各的，可以互相矛盾）';
        if ($entry['readings'] === []) {
            $lines[] = '还没有谁留下读法。';
        }
        foreach ($entry['readings'] as $r) {
            $lines[] = sprintf('- %s 的读法%s：%s', $r['reader'], $r['confidence'] !== null ? '（' . $r['confidence'] . '%）' : '', $r['content']);
        }

        $lines[] = '';
        $lines[] = '## 空白（照实写，不是建议）';
        if ($entry['gaps'] === []) {
            $lines[] = '没有算出空白。这不等于没有空白。';
        }
        foreach ($entry['gaps'] as $gap) {
            $lines[] = '- ' . $gap;
        }

        if ($entry['can_do'] !== []) {
            $lines[] = '';
            $lines[] = '## 你能做什么，够不着什么';
            foreach ($entry['can_do'] as $line) {
                $lines[] = '- ' . $line;
            }
        }

        $lines[] = '';
        $lines[] = '## 走之前';
        $lines[] = '这片空间请每个进来的主体（人和 agent 一样）：';
        foreach ($entry['before_leaving'] as $line) {
            $lines[] = '- ' . $line;
        }
        $lines[] = '';
        $lines[] = '标记：〔原〕是交互对象的原话或他给的事实，〔世〕是现实碰回来的，〔AI〕是某个 AI 推的，后面跟把握。';

        return implode("\n", $lines);
    }

    /**
     * 照实算出来的空白。意图和尝试靠 about 里的文字对上（v0：互相包含就算）。
     *
     * @return list<string>
     */
    private function gaps(array $intents, array $attempts, array $traces): array {
        $gaps = [];
        foreach ($intents as $intent) {
            $label = $intent['about'];
            if ($label === null) {
                $gaps[] = sprintf('意图「%s」没有标 about，看不出哪些尝试是朝着它的。', $this->snippet($intent['content'], 30));
                continue;
            }
            $linked = array_filter($attempts, static fn(array $a): bool => $a['about'] !== null
                && (mb_stripos($a['about'], $label) !== false || mb_stripos($label, $a['about']) !== false));
            if ($linked === []) {
                $gaps[] = sprintf('意图「%s」下面还没有尝试。', $label);
                continue;
            }
            $touched = array_filter($linked, static fn(array $a): bool => $a['touched_reality'] === true);
            if ($touched === []) {
                $gaps[] = sprintf('意图「%s」下面的 %d 条尝试都只在模型上推过，没碰过现实。', $label, count($linked));
                continue;
            }
            $stops = array_values(array_unique(array_filter(array_map(static fn(array $a): ?string => $a['stopped_at'], $touched))));
            if ($stops !== []) {
                $gaps[] = sprintf('意图「%s」下面碰过现实的 %d 条尝试，停在：%s。', $label, count($touched), implode('；', array_map(fn(string $s): string => $this->snippet($s, 40), $stops)));
            }
        }

        $answered = [];
        foreach ($traces as $t) {
            if ($t['ref_id'] !== null) {
                $answered[$t['ref_id']] = true;
            }
        }
        foreach ($traces as $t) {
            if ($t['act'] === '提问' && $t['source'] === '原' && !isset($answered[$t['id']])) {
                $gaps[] = sprintf('还没答的问题：「%s」', $this->snippet($t['content'], 60));
            }
        }

        $noStop = count(array_filter($attempts, static fn(array $a): bool => $a['stopped_at'] === null));
        if ($noStop > 0) {
            $gaps[] = sprintf('有 %d 条尝试没写停在哪。', $noStop);
        }
        return $gaps;
    }

    /** @return list<string> */
    private function purpose(): array {
        try {
            $path = $this->apps->getAppPath('cognos') . '/appinfo/purpose.md';
        } catch (\Throwable) {
            return [];
        }
        if (!is_readable($path)) {
            return [];
        }
        $lines = [];
        foreach (preg_split('/\R/u', (string)file_get_contents($path)) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '- ')) {
                $lines[] = substr($line, 2);
            }
        }
        return $lines;
    }

    private function traceLine(array $t, array $newIds): string {
        $mark = match ($t['source']) {
            '原' => '〔原',
            '世' => '〔世',
            default => '〔AI',
        };
        $when = $t['said_at'] !== null ? ' ' . $this->date($t['said_at']) : '';
        $conf = $t['source'] === 'AI' && $t['confidence'] !== null ? ' ' . $t['confidence'] . '%' : '';
        $who = $t['source'] === 'AI' ? ' ' . $t['author'] : '';
        $tags = [];
        if ($t['act'] !== null) {
            $tags[] = $t['act'];
        }
        if ($t['about'] !== null && $t['kind'] !== 'intent') {
            $tags[] = '关于 ' . $t['about'];
        }
        if ($t['kind'] === 'intent' && $t['about'] !== null) {
            $tags[] = '标为 ' . $t['about'];
        }
        if ($t['effective_status'] === 'confirmed') {
            $tags[] = '已确认';
        }
        $line = sprintf('#%d %s%s%s%s〕%s', $t['id'], $mark, $who, $when, $conf, $t['content']);
        if ($tags !== []) {
            $line .= '（' . implode('，', $tags) . '）';
        }
        foreach ($t['corrections'] as $c) {
            $line .= sprintf(' →%s：%s', $this->statusLabel($c['status']), $this->snippet($c['content'], 80));
        }
        if (in_array($t['id'], $newIds, true)) {
            $line = '〔新〕' . $line;
        }
        return $line;
    }

    private function oneLine(array $t, int $max): string {
        return sprintf('[%s] %s', $t['kind'], $this->snippet($t['content'], $max));
    }

    private function statusLabel(string $status): string {
        return match ($status) {
            'confirmed' => '确认',
            'rejected' => '被否',
            'withdrawn' => '撤回',
            default => '说过',
        };
    }

    private function snippet(string $text, int $max): string {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? $text;
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }

    private function date(int $ts): string {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i');
    }
}
