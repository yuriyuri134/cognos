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
 */
final class SpaceService {
    public const SOURCES = ['原', '世', 'AI'];
    public const STATUSES = ['said', 'confirmed', 'rejected', 'withdrawn'];

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /**
     * @param array{kind: string, source: string, author: string, content: string, said_at?: ?int, act?: ?string, confidence?: ?int, status?: ?string, about?: ?string, stopped_at?: ?string, touched_reality?: ?bool, ref_id?: ?int, created_at?: ?int} $trace
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
