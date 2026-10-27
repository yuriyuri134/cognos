<?php
declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\IDBConnection;
use OCP\IUserSession;

class NotesService {
    public function __construct(private IDBConnection $db, private IUserSession $users) {}

    private function userUid(): string {
        $user = $this->users->getUser();
        if ($user === null) {
            throw new \RuntimeException('Authentication required');
        }
        return $user->getUID();
    }

    private function id(): string {
        return bin2hex(random_bytes(16));
    }

    private function now(): int {
        return time();
    }

    private function decode(array $row): array {
        $row['source'] = json_decode((string)$row['source_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['source_json']);
        return $row;
    }

    public function listSpaces(): array {
        $uid = $this->userUid();
        $q = $this->db->getQueryBuilder();
        $q->select('s.*')->addSelect($q->createFunction('COUNT(n.id) AS note_count'))
            ->from('cognos_note_spaces', 's')
            ->leftJoin('s', 'cognos_notes', 'n', $q->expr()->eq('n.space_id', 's.id'))
            ->where($q->expr()->eq('s.owner_uid', $q->createNamedParameter($uid)))
            ->groupBy('s.id')->addGroupBy('s.owner_uid')->addGroupBy('s.title')
            ->addGroupBy('s.description')->addGroupBy('s.created_at')->addGroupBy('s.updated_at')
            ->orderBy('s.updated_at', 'DESC');
        return $q->executeQuery()->fetchAllAssociative();
    }

    public function listNotes(string $spaceId): array {
        $uid = $this->userUid();
        $this->assertSpace($spaceId, $uid);
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'title', 'content', 'revision', 'created_at', 'updated_at')
            ->from('cognos_notes')
            ->where($q->expr()->eq('space_id', $q->createNamedParameter($spaceId)))
            ->andWhere($q->expr()->eq('owner_uid', $q->createNamedParameter($uid)))
            ->orderBy('updated_at', 'DESC');
        $rows = $q->executeQuery()->fetchAllAssociative();
        foreach ($rows as &$row) {
            $row['preview'] = mb_substr((string)$row['content'], 0, 200);
            unset($row['content']);
        }
        return $rows;
    }

    public function getNote(string $noteId): array {
        $uid = $this->userUid();
        $q = $this->db->getQueryBuilder();
        $q->select('n.*', 's.title AS space_title')
            ->from('cognos_notes', 'n')
            ->innerJoin('n', 'cognos_note_spaces', 's', $q->expr()->eq('s.id', 'n.space_id'))
            ->where($q->expr()->eq('n.id', $q->createNamedParameter($noteId)))
            ->andWhere($q->expr()->eq('n.owner_uid', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('s.owner_uid', $q->createNamedParameter($uid)));
        $row = $q->executeQuery()->fetchAssociative();
        if (!$row) throw new \RuntimeException('Note not found');
        $note = $this->decode($row);
        $note['read_only'] = $this->isImported($note);
        $note['outlinks'] = $this->links($noteId, true, $uid);
        $note['backlinks'] = $this->links($noteId, false, $uid);
        return $note;
    }

    public function search(string $query, ?string $spaceId = null): array {
        $uid = $this->userUid();
        $q = $this->db->getQueryBuilder();
        $term = '%' . $query . '%';
        $q->select('n.id', 'n.space_id', 'n.title', 'n.updated_at')
            ->addSelect($q->createFunction('SUBSTRING(n.content, 1, 200) AS preview'))
            ->from('cognos_notes', 'n')
            ->where($q->expr()->eq('n.owner_uid', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->orX(
                $q->expr()->like('n.title', $q->createNamedParameter($term)),
                $q->expr()->like('n.content', $q->createNamedParameter($term))
            ))->orderBy('n.updated_at', 'DESC')->setMaxResults(50);
        if ($spaceId !== null) {
            $this->assertSpace($spaceId, $uid);
            $q->andWhere($q->expr()->eq('n.space_id', $q->createNamedParameter($spaceId)));
        }
        return $q->executeQuery()->fetchAllAssociative();
    }

    public function createSpace(string $title, string $description): array {
        $uid = $this->userUid();
        $now = $this->now();
        $space = ['id' => $this->id(), 'owner_uid' => $uid, 'title' => trim($title),
            'description' => $description, 'created_at' => $now, 'updated_at' => $now, 'note_count' => 0];
        $q = $this->db->getQueryBuilder();
        $q->insert('cognos_note_spaces')->values([
            'id' => $q->createNamedParameter($space['id']), 'owner_uid' => $q->createNamedParameter($uid),
            'title' => $q->createNamedParameter($space['title']), 'description' => $q->createNamedParameter($description),
            'created_at' => $q->createNamedParameter($now), 'updated_at' => $q->createNamedParameter($now),
        ])->executeStatement();
        return $space;
    }

    public function createNote(string $spaceId, string $title, string $content, array $source = []): array {
        $uid = $this->userUid();
        $this->assertSpace($spaceId, $uid);
        $now = $this->now();
        $note = ['id' => $this->id(), 'space_id' => $spaceId, 'owner_uid' => $uid,
            'title' => trim($title), 'content' => $content, 'source' => $source, 'revision' => 1,
            'content_hash' => hash('sha256', $content), 'created_at' => $now, 'updated_at' => $now];
        $q = $this->db->getQueryBuilder();
        $q->insert('cognos_notes')->values([
            'id' => $q->createNamedParameter($note['id']), 'space_id' => $q->createNamedParameter($spaceId),
            'owner_uid' => $q->createNamedParameter($uid), 'title' => $q->createNamedParameter($note['title']),
            'content' => $q->createNamedParameter($content), 'source_json' => $q->createNamedParameter(json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'revision' => $q->createNamedParameter(1), 'content_hash' => $q->createNamedParameter($note['content_hash']),
            'created_at' => $q->createNamedParameter($now), 'updated_at' => $q->createNamedParameter($now),
        ])->executeStatement();
        return $note;
    }

    /** 导入的原文是「发生」：可以读、可以围绕它展开，但不回写 */
    public function isImported(array $note): bool {
        return ($note['source']['source'] ?? null) === 'obsidian';
    }

    public function updateNote(string $noteId, string $title, string $content): array {
        $uid = $this->userUid();
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('cognos_notes')
            ->where($q->expr()->eq('id', $q->createNamedParameter($noteId)))
            ->andWhere($q->expr()->eq('owner_uid', $q->createNamedParameter($uid)));
        $row = $q->executeQuery()->fetchAssociative();
        if (!$row) throw new \RuntimeException('Note not found');
        if ($this->isImported($this->decode($row))) throw new NoteReadOnlyException('Imported material is read-only');
        $now = $this->now();
        $nextRevision = (int)$row['revision'] + 1;
        // 先把上一版原样留下，再改
        $q = $this->db->getQueryBuilder();
        $q->insert('cognos_note_revisions')->values([
            'note_id' => $q->createNamedParameter($noteId), 'revision' => $q->createNamedParameter((int)$row['revision']),
            'title' => $q->createNamedParameter((string)$row['title']), 'content' => $q->createNamedParameter((string)$row['content']),
            'content_hash' => $q->createNamedParameter((string)$row['content_hash']), 'saved_at' => $q->createNamedParameter((int)$row['updated_at']),
        ])->executeStatement();
        $q = $this->db->getQueryBuilder();
        $q->update('cognos_notes')->set('title', $q->createNamedParameter(trim($title)))
            ->set('content', $q->createNamedParameter($content))
            ->set('revision', $q->createNamedParameter($nextRevision))
            ->set('content_hash', $q->createNamedParameter(hash('sha256', $content)))
            ->set('updated_at', $q->createNamedParameter($now))
            ->where($q->expr()->eq('id', $q->createNamedParameter($noteId)))
            ->andWhere($q->expr()->eq('owner_uid', $q->createNamedParameter($uid)))
            ->executeStatement();
        $q = $this->db->getQueryBuilder();
        $q->update('cognos_note_spaces')->set('updated_at', $q->createNamedParameter($now))
            ->where($q->expr()->eq('id', $q->createNamedParameter($row['space_id'])))
            ->andWhere($q->expr()->eq('owner_uid', $q->createNamedParameter($uid)))
            ->executeStatement();
        return $this->getNote($noteId);
    }

    public function assertSpaceReadable(string $spaceId): void {
        $this->assertSpace($spaceId, $this->userUid());
    }

    public function createActivity(string $noteId, ActivitySourceService $activities, string $participantId): array {
        $note = $this->getNote($noteId);
        return $activities->createFromNote($note, $participantId);
    }

    private function assertSpace(string $spaceId, string $uid): void {
        $q = $this->db->getQueryBuilder();
        $q->select('id')->from('cognos_note_spaces')
            ->where($q->expr()->eq('id', $q->createNamedParameter($spaceId)))
            ->andWhere($q->expr()->eq('owner_uid', $q->createNamedParameter($uid)));
        if (!$q->executeQuery()->fetchOne()) throw new \RuntimeException('Space not found');
    }

    private function links(string $noteId, bool $outgoing, string $uid): array {
        $q = $this->db->getQueryBuilder();
        $from = $outgoing ? 'l.source_note_id' : 'l.target_note_id';
        $join = $outgoing ? 'l.target_note_id' : 'l.source_note_id';
        $q->select('l.id', $outgoing ? 'l.target_note_id' : 'l.source_note_id', 'n.title AS ' . ($outgoing ? 'target_title' : 'source_title'))
            ->from('cognos_note_links', 'l')
            ->innerJoin('l', 'cognos_notes', 'n', $q->expr()->eq('n.id', $join))
            ->where($q->expr()->eq($from, $q->createNamedParameter($noteId)))
            ->andWhere($q->expr()->eq('n.owner_uid', $q->createNamedParameter($uid)));
        return $q->executeQuery()->fetchAllAssociative();
    }
}
