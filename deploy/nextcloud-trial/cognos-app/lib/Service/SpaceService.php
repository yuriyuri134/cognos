<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * 一片空间里的发生（traces）和读法（readings）。
 *
 * 发生只增不改：要改一条的状态，就新写一条 kind=correction，用 ref_id 指过去，
 * 它的 status 就是那一条的新状态。读的时候按最新的更正来算。
 *
 * 押着的、顶、面对也是发生：
 * - premise：谁押着的一条前提（押的那位就是 author），governs 写它管着的代码或做法；
 *   ref_id 指向旧前提时，它是那一条被重新切出来的。
 * - hit：顶到某条前提（ref_id 指过去）。谁都能顶：交互对象的原话、现实碰回来的、别的 agent。
 * - answer：押的那位面对某一下（ref_id 指向 hit），act 是 站住 / 碎了 / 重新切。
 *   「站住」不是到头了：hit 的 ref_id 也可以指向一条站住的 answer，那就是顶这个站住，
 *   算作又顶了一下它站住的那条前提，押的那位还会被叫回来。
 * - unanswered：押的那位被叫回来面对某一下（ref_id 指向 hit），做完走了却没面对。
 *   这不是谁的读法，是现实碰回来的（source=世），由 PushBackService 记下；躲开的留着。
 * 顶完要找回押的那位，见 PushBackService。
 */
final class SpaceService {
    public const SOURCES = ['原', '世', 'AI'];
    public const STATUSES = ['said', 'confirmed', 'rejected', 'withdrawn'];
    public const ANSWER_ACTS = ['站住', '碎了', '重新切'];

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /**
     * @param array{kind: string, source: string, author: string, content: string, said_at?: ?int, act?: ?string, confidence?: ?int, status?: ?string, about?: ?string, stopped_at?: ?string, touched_reality?: ?bool, ref_id?: ?int, governs?: ?string, created_at?: ?int} $trace
     */
    public function addTrace(string $spaceType, string $spaceId, array $trace): array {
        $kind = trim((string)($trace['kind'] ?? ''));
        $source = (string)($trace['source'] ?? '');
        $content = trim((string)($trace['content'] ?? ''));
        $status = (string)($trace['status'] ?? 'said');
        if ($kind === '' || mb_strlen($kind) > 32) {
            throw new \InvalidArgumentException('kind is required (≤32 chars)');
        }
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('source must be 原, 世 or AI');
        }
        if ($content === '') {
            throw new \InvalidArgumentException('content is required');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('status must be one of ' . implode(', ', self::STATUSES));
        }
        $refId = isset($trace['ref_id']) ? (int)$trace['ref_id'] : null;
        if ($kind === 'correction') {
            if ($refId === null || $this->getTrace($spaceType, $spaceId, $refId) === null) {
                throw new \InvalidArgumentException('a correction needs ref_id pointing to a trace in this space');
            }
        }
        if ($kind === 'premise' && $refId !== null && ($this->getTrace($spaceType, $spaceId, $refId)['kind'] ?? null) !== 'premise') {
            throw new \InvalidArgumentException('a re-cut premise needs ref_id pointing to the premise it replaces');
        }
        if ($kind === 'hit') {
            $target = $refId === null ? null : $this->getTrace($spaceType, $spaceId, $refId);
            $ok = $target !== null && ($target['kind'] === 'premise' || ($target['kind'] === 'answer' && $target['act'] === '站住'));
            if (!$ok) {
                throw new \InvalidArgumentException('a hit needs ref_id pointing to a premise, or to an answer that stood (站住), in this space');
            }
        }
        if ($kind === 'unanswered' && ($refId === null || ($this->getTrace($spaceType, $spaceId, $refId)['kind'] ?? null) !== 'hit')) {
            throw new \InvalidArgumentException('unanswered needs ref_id pointing to a hit in this space');
        }
        if ($kind === 'answer') {
            if ($refId === null || ($this->getTrace($spaceType, $spaceId, $refId)['kind'] ?? null) !== 'hit') {
                throw new \InvalidArgumentException('an answer needs ref_id pointing to a hit in this space');
            }
            if (!in_array($trace['act'] ?? null, self::ANSWER_ACTS, true)) {
                throw new \InvalidArgumentException('an answer needs act: ' . implode(' / ', self::ANSWER_ACTS));
            }
        }
        $confidence = isset($trace['confidence']) ? max(0, min(100, (int)$trace['confidence'])) : null;
        if ($source !== 'AI') {
            $confidence = null;
        }
        $touched = array_key_exists('touched_reality', $trace) && $trace['touched_reality'] !== null
            ? ((bool)$trace['touched_reality'] ? 1 : 0)
            : null;

        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('cognos_space_traces')->values([
            'space_type' => $qb->createNamedParameter($spaceType),
            'space_id' => $qb->createNamedParameter($spaceId),
            'kind' => $qb->createNamedParameter($kind),
            'source' => $qb->createNamedParameter($source),
            'author' => $qb->createNamedParameter(mb_substr(trim((string)($trace['author'] ?? '')) ?: '未署名', 0, 128)),
            'content' => $qb->createNamedParameter($content),
            'said_at' => $qb->createNamedParameter($trace['said_at'] ?? null, IQueryBuilder::PARAM_INT),
            'act' => $qb->createNamedParameter($this->nullableText($trace['act'] ?? null, 32)),
            'confidence' => $qb->createNamedParameter($confidence, IQueryBuilder::PARAM_INT),
            'status' => $qb->createNamedParameter($status),
            'about' => $qb->createNamedParameter($this->nullableText($trace['about'] ?? null, 255)),
            'stopped_at' => $qb->createNamedParameter($this->nullableText($trace['stopped_at'] ?? null, null)),
            'touched_reality' => $qb->createNamedParameter($touched, IQueryBuilder::PARAM_INT),
            'ref_id' => $qb->createNamedParameter($refId, IQueryBuilder::PARAM_INT),
            'governs' => $qb->createNamedParameter($this->nullableText($trace['governs'] ?? null, null)),
            'created_at' => $qb->createNamedParameter((int)($trace['created_at'] ?? $now), IQueryBuilder::PARAM_INT),
        ])->executeStatement();

        return $this->getTrace($spaceType, $spaceId, (int)$qb->getLastInsertId()) ?? [];
    }

    public function getTrace(string $spaceType, string $spaceId, int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('cognos_space_traces')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('space_type', $qb->createNamedParameter($spaceType)))
            ->andWhere($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId)));
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->normalizeTrace($row) : null;
    }

    /**
     * 这片空间的全部发生，按时间排；每条带上 effective_status（按最新的更正算）和 corrections。
     *
     * @return list<array>
     */
    public function listTraces(string $spaceType, string $spaceId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('cognos_space_traces')
            ->where($qb->expr()->eq('space_type', $qb->createNamedParameter($spaceType)))
            ->andWhere($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId)))
            ->orderBy('id', 'ASC');
        $rows = array_map(fn(array $row): array => $this->normalizeTrace($row), $qb->executeQuery()->fetchAllAssociative());

        $byId = [];
        foreach ($rows as $row) {
            $row['effective_status'] = $row['status'];
            $row['corrections'] = [];
            $byId[$row['id']] = $row;
        }
        foreach ($rows as $row) {
            if ($row['kind'] === 'correction' && $row['ref_id'] !== null && isset($byId[$row['ref_id']])) {
                // 行按 id 升序，后来的更正覆盖先前的
                $byId[$row['ref_id']]['effective_status'] = $row['status'];
                $byId[$row['ref_id']]['corrections'][] = ['id' => $row['id'], 'status' => $row['status'], 'content' => $row['content'], 'author' => $row['author'], 'source' => $row['source'], 'created_at' => $row['created_at']];
            }
        }
        return array_values($byId);
    }

    public function searchTraces(string $spaceType, string $spaceId, string $query, int $limit = 20): array {
        $query = trim($query);
        $all = $this->listTraces($spaceType, $spaceId);
        if ($query === '') {
            return array_slice(array_reverse($all), 0, $limit);
        }
        $hits = array_filter($all, static function (array $t) use ($query): bool {
            foreach (['content', 'about', 'stopped_at', 'kind', 'author'] as $field) {
                if ($t[$field] !== null && mb_stripos((string)$t[$field], $query) !== false) {
                    return true;
                }
            }
            return false;
        });
        return array_slice(array_values($hits), 0, $limit);
    }

    /**
     * 押着的那位面对顶到它的一下。只有押的那位能面对。
     * 碎了：前提被撤回。重新切：写一条新前提（ref_id 指向旧的），旧的被撤回。都是新写的发生，旧的不改。
     *
     * @return array{answer: array, premise: ?array}
     */
    public function answerHit(string $spaceType, string $spaceId, int $hitId, string $source, string $author, string $act, string $content, ?int $confidence, ?string $newPremise = null, ?string $governs = null): array {
        $hit = $this->getTrace($spaceType, $spaceId, $hitId);
        if ($hit === null || $hit['kind'] !== 'hit') {
            throw new \InvalidArgumentException('hit not found in this space');
        }
        $premiseId = $this->premiseIdOfHit($spaceType, $spaceId, $hit);
        $premise = $premiseId === null ? null : $this->premiseState($spaceType, $spaceId, $premiseId);
        if ($premise === null) {
            throw new \InvalidArgumentException('the premise this hit points to is gone');
        }
        if ($premise['author'] !== $author) {
            throw new \InvalidArgumentException(sprintf('只有押着 #%d 的 %s 能面对这一下', $premise['id'], $premise['author']));
        }
        if (!$premise['standing']) {
            throw new \InvalidArgumentException(sprintf('#%d 已经不押了（%s）', $premise['id'], $premise['effective_status']));
        }
        if (!in_array($act, self::ANSWER_ACTS, true)) {
            throw new \InvalidArgumentException('act must be one of ' . implode(' / ', self::ANSWER_ACTS));
        }
        $newPremise = trim((string)$newPremise);
        if ($act === '重新切' && $newPremise === '') {
            throw new \InvalidArgumentException('重新切 needs the new premise');
        }

        $this->db->beginTransaction();
        try {
            $answer = $this->addTrace($spaceType, $spaceId, [
                'kind' => 'answer',
                'source' => $source,
                'author' => $author,
                'content' => $content,
                'act' => $act,
                'confidence' => $confidence,
                'ref_id' => $hitId,
                'said_at' => $source === 'AI' ? null : time(),
            ]);
            $recut = null;
            if ($act === '碎了') {
                $this->addTrace($spaceType, $spaceId, [
                    'kind' => 'correction',
                    'source' => $source,
                    'author' => $author,
                    'content' => sprintf('被 #%d 顶碎了，见 #%d', $hitId, $answer['id']),
                    'ref_id' => $premise['id'],
                    'status' => 'withdrawn',
                ]);
            } elseif ($act === '重新切') {
                $recut = $this->addTrace($spaceType, $spaceId, [
                    'kind' => 'premise',
                    'source' => $source,
                    'author' => $author,
                    'content' => $newPremise,
                    'confidence' => $confidence,
                    'governs' => trim((string)$governs) !== '' ? $governs : $premise['governs'],
                    'ref_id' => $premise['id'],
                    'said_at' => $source === 'AI' ? null : time(),
                ]);
                $this->addTrace($spaceType, $spaceId, [
                    'kind' => 'correction',
                    'source' => $source,
                    'author' => $author,
                    'content' => sprintf('被 #%d 顶了以后重新切成 #%d', $hitId, $recut['id']),
                    'ref_id' => $premise['id'],
                    'status' => 'withdrawn',
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        return ['answer' => $answer, 'premise' => $recut];
    }

    /**
     * 这片空间里押着的每一条，带上顶到它的每一下和押的那位怎么面对的。
     * standing：还押着（没被撤回、没被否）；open_hits：顶了还没面对的几下。
     *
     * @param ?list<array> $traces 已经取过的 listTraces 结果，省一次查询
     * @return list<array>
     */
    public function premises(string $spaceType, string $spaceId, ?array $traces = null): array {
        $traces ??= $this->listTraces($spaceType, $spaceId);
        $premises = [];
        $hits = [];
        $answers = [];
        $answerById = [];
        $hitById = [];
        $evaded = [];
        foreach ($traces as $t) {
            if ($t['kind'] === 'answer') {
                $answerById[$t['id']] = $t;
            } elseif ($t['kind'] === 'hit') {
                $hitById[$t['id']] = $t;
            } elseif ($t['kind'] === 'unanswered' && $t['ref_id'] !== null) {
                $evaded[$t['ref_id']][] = $t;
            }
        }
        foreach ($traces as $t) {
            if ($t['kind'] === 'premise') {
                $t['holder'] = $t['author'];
                $t['standing'] = !in_array($t['effective_status'], ['rejected', 'withdrawn'], true);
                $t['recut_from'] = $t['ref_id'];
                $t['recut_to'] = null;
                $t['hits'] = [];
                $t['open_hits'] = 0;
                $premises[$t['id']] = $t;
            } elseif ($t['kind'] === 'hit') {
                $hits[] = $t;
            } elseif ($t['kind'] === 'answer' && $t['ref_id'] !== null) {
                // 按 id 升序，同一下后来的回答算数
                $answers[$t['ref_id']] = $t;
            }
        }
        foreach ($premises as $p) {
            if ($p['recut_from'] !== null && isset($premises[$p['recut_from']])) {
                $premises[$p['recut_from']]['recut_to'] = $p['id'];
            }
        }
        foreach ($hits as $h) {
            $pid = $this->resolveHitTarget($h, $answerById, $hitById);
            if ($pid === null || !isset($premises[$pid])) {
                continue;
            }
            $h['premise_id'] = $pid;
            // 顶的是一次「站住」，不是前提本身
            $h['against_answer'] = $pid !== $h['ref_id'] ? $h['ref_id'] : null;
            $h['answer'] = $answers[$h['id']] ?? null;
            // 叫回来过、做完走了却没面对的次数
            $h['evaded'] = count($evaded[$h['id']] ?? []);
            $premises[$pid]['hits'][] = $h;
            $premises[$pid]['evaded'] = ($premises[$pid]['evaded'] ?? 0) + $h['evaded'];
            if ($h['answer'] === null && $premises[$pid]['standing']) {
                $premises[$pid]['open_hits']++;
            }
        }
        foreach ($premises as &$p) {
            $p['evaded'] ??= 0;
        }
        unset($p);
        return array_values($premises);
    }

    /** 一下顶最终顶到的是哪条前提：直接指前提，或指一次站住（顺着它面对的那一下找回去）。 */
    public function premiseIdOfHit(string $spaceType, string $spaceId, array $hit): ?int {
        $answerById = [];
        $hitById = [];
        foreach ($this->listTraces($spaceType, $spaceId) as $t) {
            if ($t['kind'] === 'answer') {
                $answerById[$t['id']] = $t;
            } elseif ($t['kind'] === 'hit') {
                $hitById[$t['id']] = $t;
            }
        }
        return $this->resolveHitTarget($hit, $answerById, $hitById);
    }

    private function resolveHitTarget(array $hit, array $answerById, array $hitById): ?int {
        $ref = $hit['ref_id'] === null ? null : (int)$hit['ref_id'];
        // 顶站住 → 站住面对的那一下 → 那一下顶的；最多顺 16 层，防环
        for ($depth = 0; $ref !== null && $depth < 16; $depth++) {
            if (!isset($answerById[$ref])) {
                return $ref;
            }
            $answeredHit = $hitById[$answerById[$ref]['ref_id']] ?? null;
            $ref = $answeredHit === null || $answeredHit['ref_id'] === null ? null : (int)$answeredHit['ref_id'];
        }
        return null;
    }

    /** 一条前提现在的样子（带 standing、hits）；不是前提就返回 null。 */
    public function premiseState(string $spaceType, string $spaceId, int $id): ?array {
        foreach ($this->premises($spaceType, $spaceId) as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }

    /**
     * @param list<int> $refs
     */
    public function addReading(string $spaceType, string $spaceId, string $reader, string $content, ?int $confidence, array $refs = []): array {
        $reader = mb_substr(trim($reader) ?: '未署名', 0, 128);
        $content = trim($content);
        if ($content === '') {
            throw new \InvalidArgumentException('content is required');
        }
        $refs = array_values(array_filter(array_map('intval', $refs), fn(int $id): bool => $this->getTrace($spaceType, $spaceId, $id) !== null));
        $previous = $this->latestReadingOf($spaceType, $spaceId, $reader);

        $this->db->beginTransaction();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('cognos_space_readings')->values([
                'space_type' => $qb->createNamedParameter($spaceType),
                'space_id' => $qb->createNamedParameter($spaceId),
                'reader' => $qb->createNamedParameter($reader),
                'content' => $qb->createNamedParameter($content),
                'confidence' => $qb->createNamedParameter($confidence === null ? null : max(0, min(100, $confidence)), IQueryBuilder::PARAM_INT),
                'refs' => $qb->createNamedParameter(json_encode($refs)),
                'superseded_by' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT),
                'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            ])->executeStatement();
            $id = (int)$qb->getLastInsertId();
            if ($previous !== null) {
                // 旧读法不删，只标上被哪一条替换
                $up = $this->db->getQueryBuilder();
                $up->update('cognos_space_readings')
                    ->set('superseded_by', $up->createNamedParameter($id, IQueryBuilder::PARAM_INT))
                    ->where($up->expr()->eq('id', $up->createNamedParameter($previous['id'], IQueryBuilder::PARAM_INT)))
                    ->executeStatement();
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        return $this->latestReadingOf($spaceType, $spaceId, $reader) ?? [];
    }

    /** 每个读者最新的一条读法。 */
    public function currentReadings(string $spaceType, string $spaceId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('cognos_space_readings')
            ->where($qb->expr()->eq('space_type', $qb->createNamedParameter($spaceType)))
            ->andWhere($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId)))
            ->andWhere($qb->expr()->isNull('superseded_by'))
            ->orderBy('created_at', 'ASC');
        return array_map(fn(array $row): array => $this->normalizeReading($row), $qb->executeQuery()->fetchAllAssociative());
    }

    public function readingsSince(string $spaceType, string $spaceId, int $since): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('cognos_space_readings')
            ->where($qb->expr()->eq('space_type', $qb->createNamedParameter($spaceType)))
            ->andWhere($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId)))
            ->andWhere($qb->expr()->gt('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC');
        return array_map(fn(array $row): array => $this->normalizeReading($row), $qb->executeQuery()->fetchAllAssociative());
    }

    /** 上次来的时间；没来过返回 null。 */
    public function lastSeen(string $spaceType, string $spaceId, string $subject): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('seen_at')->from('cognos_space_seen')
            ->where($qb->expr()->eq('space_type', $qb->createNamedParameter($spaceType)))
            ->andWhere($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId)))
            ->andWhere($qb->expr()->eq('subject', $qb->createNamedParameter($subject)));
        $value = $qb->executeQuery()->fetchOne();
        return $value === false ? null : (int)$value;
    }

    public function markSeen(string $spaceType, string $spaceId, string $subject): void {
        $now = time();
        $up = $this->db->getQueryBuilder();
        $changed = $up->update('cognos_space_seen')
            ->set('seen_at', $up->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($up->expr()->eq('space_type', $up->createNamedParameter($spaceType)))
            ->andWhere($up->expr()->eq('space_id', $up->createNamedParameter($spaceId)))
            ->andWhere($up->expr()->eq('subject', $up->createNamedParameter($subject)))
            ->executeStatement();
        if ($changed === 0) {
            $in = $this->db->getQueryBuilder();
            $in->insert('cognos_space_seen')->values([
                'space_type' => $in->createNamedParameter($spaceType),
                'space_id' => $in->createNamedParameter($spaceId),
                'subject' => $in->createNamedParameter(mb_substr($subject, 0, 128)),
                'seen_at' => $in->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ])->executeStatement();
        }
    }

    private function latestReadingOf(string $spaceType, string $spaceId, string $reader): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('cognos_space_readings')
            ->where($qb->expr()->eq('space_type', $qb->createNamedParameter($spaceType)))
            ->andWhere($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId)))
            ->andWhere($qb->expr()->eq('reader', $qb->createNamedParameter($reader)))
            ->andWhere($qb->expr()->isNull('superseded_by'))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->normalizeReading($row) : null;
    }

    private function normalizeTrace(array $row): array {
        return [
            'id' => (int)$row['id'],
            'kind' => (string)$row['kind'],
            'source' => (string)$row['source'],
            'author' => (string)$row['author'],
            'content' => (string)$row['content'],
            'said_at' => $row['said_at'] === null ? null : (int)$row['said_at'],
            'act' => $row['act'] === null ? null : (string)$row['act'],
            'confidence' => $row['confidence'] === null ? null : (int)$row['confidence'],
            'status' => (string)$row['status'],
            'about' => $row['about'] === null ? null : (string)$row['about'],
            'stopped_at' => $row['stopped_at'] === null ? null : (string)$row['stopped_at'],
            'touched_reality' => $row['touched_reality'] === null ? null : ((int)$row['touched_reality'] === 1),
            'ref_id' => $row['ref_id'] === null ? null : (int)$row['ref_id'],
            'governs' => ($row['governs'] ?? null) === null ? null : (string)$row['governs'],
            'created_at' => (int)$row['created_at'],
        ];
    }

    private function normalizeReading(array $row): array {
        $refs = json_decode((string)($row['refs'] ?? '[]'), true);
        return [
            'id' => (int)$row['id'],
            'reader' => (string)$row['reader'],
            'content' => (string)$row['content'],
            'confidence' => $row['confidence'] === null ? null : (int)$row['confidence'],
            'refs' => is_array($refs) ? array_map('intval', $refs) : [],
            'superseded_by' => $row['superseded_by'] === null ? null : (int)$row['superseded_by'],
            'created_at' => (int)$row['created_at'],
        ];
    }

    private function nullableText(mixed $value, ?int $max): ?string {
        if ($value === null) {
            return null;
        }
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        return $max === null ? $text : mb_substr($text, 0, $max);
    }
}
