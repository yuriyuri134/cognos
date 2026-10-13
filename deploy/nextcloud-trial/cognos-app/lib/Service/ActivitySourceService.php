<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\IDBConnection;
use OCP\IUserSession;

final class ActivitySourceService {
    public function __construct(
        private IDBConnection $db,
        private IUserSession $users,
    ) {
    }

    public function createFromNote(array $note, string $participantId): array {
        $user = $this->user();
        $participant = $this->requireParticipant($participantId);
        $query = $this->db->getQueryBuilder();
        $query->select('*')
            ->from('cognos_activities')
            ->where($query->expr()->eq('actor_uid', $query->createNamedParameter($user->getUID())))
            ->andWhere($query->expr()->eq('source_type', $query->createNamedParameter('note')))
            ->andWhere($query->expr()->eq('source_id', $query->createNamedParameter($note['id'])))
            ->orderBy('created_at', 'DESC');

        foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
            $snapshot = json_decode((string)$row['source_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            if (($snapshot['content_hash'] ?? null) === ($note['content_hash'] ?? null)
                && (int)($snapshot['revision'] ?? 0) === (int)($note['revision'] ?? 0)) {
                $activity = $this->decode($row);
                if (($activity['participant_id'] ?? '') !== $participant['id']) {
                    $activity = $this->assignParticipant((string)$activity['id'], $participant['id']);
                }
                return ['activity' => $activity, 'reused' => true];
            }
        }

        $id = bin2hex(random_bytes(16));
        $now = time();
        $snapshot = [
            'kind' => 'nextcloud_cognos_note',
            'note_id' => $note['id'],
            'space_id' => $note['space_id'],
            'title' => $note['title'],
            'content' => $note['content'],
            'revision' => (int)$note['revision'],
            'content_hash' => $note['content_hash'],
            'captured_at' => gmdate(DATE_ATOM),
        ];
        $this->insertActivity(
            id: $id,
            actorUid: $user->getUID(),
            actorName: $user->getDisplayName(),
            boardId: 0,
            cardId: 0,
            title: '围绕笔记继续：' . $note['title'],
            snapshot: $snapshot,
            sourceType: 'note',
            sourceId: (string)$note['id'],
            now: $now,
            participant: $participant,
        );

        return [
            'activity' => [
                'id' => $id,
                'actor_uid' => $user->getUID(),
                'actor_name' => $user->getDisplayName(),
                'board_id' => 0,
                'card_id' => 0,
                'title' => '围绕笔记继续：' . $note['title'],
                'source_snapshot' => $snapshot,
                'source_type' => 'note',
                'source_id' => $note['id'],
                'participant_id' => $participant['id'],
                'participant_name' => $participant['display_name'],
                'status' => 'in_progress',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'reused' => false,
        ];
    }

    public function createManual(string $title, string $context, string $participantId = ''): array {
        $user = $this->user();
        $title = trim($title);
        $context = trim($context);
        if ($title === '') {
            throw new \InvalidArgumentException('Activity title is required');
        }
        if ($context === '') {
            throw new \InvalidArgumentException('Activity context is required');
        }
        $participant = $this->requireParticipant($participantId);

        $id = bin2hex(random_bytes(16));
        $now = time();
        $snapshot = [
            'kind' => 'manual_activity',
            'title' => $title,
            'content' => $context,
            'captured_at' => gmdate(DATE_ATOM),
        ];
        $this->insertActivity(
            id: $id,
            actorUid: $user->getUID(),
            actorName: $user->getDisplayName(),
            boardId: 0,
            cardId: 0,
            title: $title,
            snapshot: $snapshot,
            sourceType: 'manual_activity',
            sourceId: $id,
            now: $now,
            participant: $participant,
        );

        return [
            'id' => $id,
            'actor_uid' => $user->getUID(),
            'actor_name' => $user->getDisplayName(),
            'board_id' => 0,
            'card_id' => 0,
            'title' => $title,
            'source_type' => 'manual_activity',
            'source_id' => $id,
            'source_snapshot' => $snapshot,
            'participant_id' => $participant['id'],
            'participant_name' => $participant['display_name'],
            'status' => 'in_progress',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    public function referencesForNote(string $noteId): array {
        $user = $this->user();
        $query = $this->db->getQueryBuilder();
        $query->select('*')
            ->from('cognos_activities')
            ->where($query->expr()->eq('actor_uid', $query->createNamedParameter($user->getUID())))
            ->andWhere($query->expr()->eq('source_type', $query->createNamedParameter('note')))
            ->andWhere($query->expr()->eq('source_id', $query->createNamedParameter($noteId)))
            ->orderBy('created_at', 'DESC');
        return array_map(fn(array $row): array => $this->decode($row), $query->executeQuery()->fetchAllAssociative());
    }

    public function create(int $boardId, int $cardId, string $title, array $snapshot, string $participantId): array {
        $user = $this->user();
        $participant = $this->requireParticipant($participantId);
        $id = bin2hex(random_bytes(16));
        $now = time();
        $sourceType = (string)($snapshot['kind'] ?? 'unknown');
        $this->insertActivity(
            id: $id,
            actorUid: $user->getUID(),
            actorName: $user->getDisplayName(),
            boardId: $boardId,
            cardId: $cardId,
            title: $title,
            snapshot: $snapshot,
            sourceType: $sourceType,
            sourceId: (string)$cardId,
            now: $now,
            participant: $participant,
        );
        return [
            'id' => $id,
            'actor_uid' => $user->getUID(),
            'actor_name' => $user->getDisplayName(),
            'board_id' => $boardId,
            'card_id' => $cardId,
            'title' => $title,
            'source_snapshot' => $snapshot,
            'source_type' => $sourceType,
            'source_id' => (string)$cardId,
            'participant_id' => $participant['id'] ?? null,
            'participant_name' => $participant['display_name'] ?? null,
            'status' => 'in_progress',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return list<array<string, string>> */
    public function participants(): array {
        $this->ensureParticipantRows();
        $query = $this->db->getQueryBuilder();
        $query->select('id', 'display_name', 'kind', 'runtime_key', 'description')
            ->from('cognos_participants')
            ->orderBy('display_name', 'ASC');
        $defaultRoute = trim((string)(getenv('HERMES_API_MODEL') ?: 'hermes-cognos'));
        $defaultAvailable = $this->hermesConfigured();
        return array_map(function (array $participant) use ($defaultRoute, $defaultAvailable): array {
            $specificRoute = trim((string)(getenv('HERMES_PARTICIPANT_ROUTE_' . strtoupper(str_replace('-', '_', (string)$participant['id']))) ?: ''));
            $participant['runtime_available'] = $defaultAvailable && ($specificRoute !== '' || $defaultRoute !== '');
            $participant['runtime_model'] = $specificRoute !== '' ? $specificRoute : $defaultRoute;
            $participant['runtime_source'] = $specificRoute !== '' ? 'participant-route' : 'hermes-default';
            return $participant;
        }, $query->executeQuery()->fetchAllAssociative());
    }

    public function participant(string $id): ?array {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        foreach ($this->participants() as $participant) {
            if ((string)$participant['id'] === $id) {
                return $participant;
            }
        }
        return null;
    }

    private function participantRouteModel(string $participantId): string {
        $specific = trim((string)(getenv('HERMES_PARTICIPANT_ROUTE_' . strtoupper(str_replace('-', '_', $participantId))) ?: ''));
        return $specific !== '' ? $specific : trim((string)(getenv('HERMES_API_MODEL') ?: 'hermes-cognos'));
    }

    private function hermesConfigured(): bool {
        $url = trim((string)(getenv('HERMES_API_URL') ?: 'http://host.docker.internal:8642/v1/chat/completions'));
        $keyFile = getenv('HERMES_API_KEY_FILE') ?: '/run/secrets/hermes_api_key';
        $key = is_readable($keyFile)
            ? trim((string)file_get_contents($keyFile))
            : trim((string)(getenv('HERMES_API_KEY') ?: ''));
        return $url !== '' && $key !== '';
    }

    private function requireParticipant(string $id): array {
        $participant = $this->participant($id);
        if ($participant === null) {
            throw new \InvalidArgumentException('请选择一个 Cognos 参与者');
        }
        $participant['route_model'] = (string)($participant['runtime_model'] ?? '');
        return $participant;
    }

    public function assignParticipant(string $activityId, string $participantId): array {
        $user = $this->user();
        $participant = $this->requireParticipant($participantId);
        if (!$this->getForUser($activityId)) {
            throw new \RuntimeException('Activity not found');
        }
        $query = $this->db->getQueryBuilder();
        $query->update('cognos_activities')
            ->set('participant_id', $query->createNamedParameter($participant['id']))
            ->set('participant_name', $query->createNamedParameter($participant['display_name']))
            ->set('updated_at', $query->createNamedParameter(time()))
            ->where($query->expr()->eq('id', $query->createNamedParameter($activityId)))
            ->andWhere($query->expr()->eq('actor_uid', $query->createNamedParameter($user->getUID())))
            ->executeStatement();
        return $this->getForUser($activityId) ?? throw new \RuntimeException('Activity not found');
    }

    public function listForUser(): array {
        $user = $this->user();
        $query = $this->db->getQueryBuilder();
        $query->select('*')
            ->from('cognos_activities')
            ->where($query->expr()->eq('actor_uid', $query->createNamedParameter($user->getUID())))
            ->orderBy('created_at', 'DESC');
        return array_map(fn(array $row): array => $this->decode($row), $query->executeQuery()->fetchAllAssociative());
    }

    public function updateStatus(string $id, string $status, ?string $expectedStatus = null): array {
        $allowed = ['in_progress', 'paused', 'needs_revision', 'completed'];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid Activity status');
        }
        $user = $this->user();
        $activity = $this->getForUser($id);
        if ($activity === null) {
            throw new \RuntimeException('Activity not found');
        }
        if ($expectedStatus !== null && ($activity['status'] ?? 'in_progress') !== $expectedStatus) {
            throw new \DomainException('Activity status changed; reload before updating');
        }

        $query = $this->db->getQueryBuilder();
        $query->update('cognos_activities')
            ->set('status', $query->createNamedParameter($status))
            ->set('updated_at', $query->createNamedParameter(time()))
            ->where($query->expr()->eq('id', $query->createNamedParameter($id)))
            ->andWhere($query->expr()->eq('actor_uid', $query->createNamedParameter($user->getUID())));
        if ($expectedStatus !== null) {
            $query->andWhere($query->expr()->eq('status', $query->createNamedParameter($expectedStatus)));
        }
        if ($query->executeStatement() !== 1) {
            throw new \DomainException('Activity status changed; reload before updating');
        }
        return $this->getForUser($id) ?? throw new \RuntimeException('Activity not found');
    }

    public function getForUser(string $id): ?array {
        $user = $this->user();
        $query = $this->db->getQueryBuilder();
        $query->select('*')
            ->from('cognos_activities')
            ->where($query->expr()->eq('id', $query->createNamedParameter($id)))
            ->andWhere($query->expr()->eq('actor_uid', $query->createNamedParameter($user->getUID())));
        $row = $query->executeQuery()->fetchAssociative();
        return $row ? $this->decode($row) : null;
    }

    public function runsForUser(string $activityId): array {
        $this->user();
        if (!$this->getForUser($activityId)) {
            throw new \RuntimeException('Activity not found');
        }
        $query = $this->db->getQueryBuilder();
        $query->select('*')
            ->from('cognos_activity_runs')
            ->where($query->expr()->eq('activity_id', $query->createNamedParameter($activityId)))
            ->orderBy('created_at', 'ASC');
        return $query->executeQuery()->fetchAllAssociative();
    }

    private function insertActivity(
        string $id,
        string $actorUid,
        string $actorName,
        int $boardId,
        int $cardId,
        string $title,
        array $snapshot,
        string $sourceType,
        string $sourceId,
        int $now,
        ?array $participant = null,
    ): void {
        $query = $this->db->getQueryBuilder();
        $query->insert('cognos_activities')->values([
            'id' => $query->createNamedParameter($id),
            'actor_uid' => $query->createNamedParameter($actorUid),
            'actor_name' => $query->createNamedParameter($actorName),
            'board_id' => $query->createNamedParameter($boardId),
            'card_id' => $query->createNamedParameter($cardId),
            'title' => $query->createNamedParameter($title),
            'source_snapshot' => $query->createNamedParameter(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'source_type' => $query->createNamedParameter($sourceType),
            'source_id' => $query->createNamedParameter($sourceId),
            'participant_id' => $query->createNamedParameter($participant['id'] ?? null),
            'participant_name' => $query->createNamedParameter($participant['display_name'] ?? null),
            'created_at' => $query->createNamedParameter($now),
            'status' => $query->createNamedParameter('in_progress'),
            'updated_at' => $query->createNamedParameter($now),
        ])->executeStatement();
    }

    private function ensureParticipantRows(): void {
        $defaults = [
            ['id' => 'codex', 'display_name' => 'Codex', 'kind' => 'ai', 'runtime_key' => 'codex', 'description' => '工程与实现参与者'],
            ['id' => 'gemini', 'display_name' => 'Gemini', 'kind' => 'ai', 'runtime_key' => 'gemini', 'description' => '探索与发散参与者'],
            ['id' => 'gemini-b', 'display_name' => 'Gemini-B', 'kind' => 'ai', 'runtime_key' => 'gemini-b', 'description' => '独立连续性参与者'],
            ['id' => 'deepseek', 'display_name' => 'DeepSeek', 'kind' => 'ai', 'runtime_key' => 'deepseek', 'description' => '分析与反思参与者'],
            ['id' => 'pi', 'display_name' => 'Pi', 'kind' => 'ai', 'runtime_key' => 'pi', 'description' => '执行与现场参与者'],
        ];
        foreach ($defaults as $participant) {
            $this->db->executeStatement(
                'INSERT INTO *PREFIX*cognos_participants '
                . '(id, display_name, kind, runtime_key, description, created_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (id) DO NOTHING',
                [
                    $participant['id'],
                    $participant['display_name'],
                    $participant['kind'],
                    $participant['runtime_key'],
                    $participant['description'],
                    time(),
                ],
            );
        }
    }

    private function user(): object {
        $user = $this->users->getUser();
        if (!$user) {
            throw new \RuntimeException('Authentication required');
        }
        return $user;
    }

    private function decode(array $row): array {
        $row['source_snapshot'] = json_decode((string)$row['source_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        return $row;
    }
}
