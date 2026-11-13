<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class CreationSpaceService {
    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function create(string $userId, string $title, string $initialMessage = ''): array {
        $qb = $this->db->getQueryBuilder();
        $createdAt = time();

        $qb->insert('cognos_creations')
            ->values([
                'user_id' => $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR),
                'title' => $qb->createNamedParameter($title, IQueryBuilder::PARAM_STR),
                'created_at' => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
                'updated_at' => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
            ])
            ->executeStatement();

        $creationId = (int)$qb->getLastInsertId();

        // Add initial message if provided
        if (!empty($initialMessage)) {
            $this->addMessage($creationId, $userId, 'user', $initialMessage);
        }

        return [
            'id' => $creationId,
            'user_id' => $userId,
            'title' => $title,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /**
     * A newly opened conversation gets its title only after the first user message.
     * Existing user-named conversations and imported conversations are untouched.
     */
    public function titleFromFirstMessage(int $id, string $userId, string $message): void {
        $creation = $this->get($id, $userId);
        if ($creation === null || $creation['title'] !== '新对话') return;
        $firstLine = trim(preg_split('/\\R/u', trim($message), 2)[0] ?? '');
        if ($firstLine === '') return;
        $title = mb_substr($firstLine, 0, 40) . (mb_strlen($firstLine) > 40 ? '…' : '');
        $qb = $this->db->getQueryBuilder();
        $qb->update('cognos_creations')
            ->set('title', $qb->createNamedParameter($title))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('title', $qb->createNamedParameter('新对话')))
            ->executeStatement();
    }

    public function list(string $userId): array {
        $countQb = $this->db->getQueryBuilder();
        $countQb->select('m.creation_id')
            ->addSelect($countQb->func()->count('m.id', 'cnt'))
            ->from('cognos_creation_messages', 'm')
            ->innerJoin('m', 'cognos_creations', 'c', $countQb->expr()->eq('c.id', 'm.creation_id'))
            ->where($countQb->expr()->eq('c.user_id', $countQb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->groupBy('m.creation_id');
        $countRes = $countQb->executeQuery();
        $counts = [];
        while ($crow = $countRes->fetch()) {
            $counts[(int)$crow['creation_id']] = (int)$crow['cnt'];
        }
        $countRes->closeCursor();

        $qb = $this->db->getQueryBuilder();
        $qb->select('c.*')
            ->from('cognos_creations', 'c')
            ->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->orderBy('c.updated_at', 'DESC');

        $result = $qb->executeQuery();
        $creations = [];
        while ($row = $result->fetch()) {
            $creationId = (int)$row['id'];
            $creations[] = [
                'id' => $creationId,
                'user_id' => $row['user_id'],
                'title' => $row['title'],
                'created_at' => (int)$row['created_at'],
                'updated_at' => (int)$row['updated_at'],
                'message_count' => $counts[$creationId] ?? 0,
            ];
        }
        $result->closeCursor();
        return $creations;
    }

    public function rename(int $id, string $userId, string $title): array {
        $clean = mb_substr(trim($title), 0, 200);
        if ($clean === '') {
            throw new \InvalidArgumentException('标题不能为空');
        }
        $existing = $this->get($id, $userId);
        if (!$existing) {
            throw new \InvalidArgumentException('Creation not found or access denied');
        }
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->update('cognos_creations')
            ->set('title', $qb->createNamedParameter($clean, IQueryBuilder::PARAM_STR))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->executeStatement();
        return [
            'id' => $id,
            'user_id' => $userId,
            'title' => $clean,
            'created_at' => $existing['created_at'],
            'updated_at' => $now,
        ];
    }

    /** @param list<array{role: string, content: string, author?: string}> $turns */
    public function importMessages(int $id, string $userId, array $turns): array {
        $existing = $this->getMessages($id, $userId);
        $clean = [];
        foreach ($turns as $t) {
            if (!is_array($t)) continue;
            $role = ($t['role'] ?? '') === 'user' ? 'user' : (($t['role'] ?? '') === 'assistant' ? 'assistant' : '');
            $content = trim((string)($t['content'] ?? ''));
            if ($role === '' || $content === '') continue;
            $author = $role === 'assistant' ? mb_substr(trim((string)($t['author'] ?? 'ChatGPT')) ?: 'ChatGPT', 0, 64) : null;
            $clean[] = ['role' => $role, 'content' => $content, 'author' => $author];
        }
        $head = static function (string $s): string {
            $stripped = (string)preg_replace('/\n*\[在 ChatGPT 里打开这条聊天\]\(https:\/\/chatgpt\.com\/[^\s)]+\)\s*$/u', '', trim($s));
            $compact = (string)preg_replace('/\s+/u', '', $stripped);
            return mb_substr($compact, 0, 400);
        };
        $skip = 0;
        $maxMatch = min(count($existing), count($clean));
        for ($k = $maxMatch; $k >= 1; $k--) {
            $ok = true;
            $offset = count($existing) - $k;
            for ($i = 0; $i < $k; $i++) {
                if ($existing[$offset + $i]['role'] !== $clean[$i]['role']
                    || $head((string)$existing[$offset + $i]['content']) !== $head($clean[$i]['content'])) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $skip = $k;
                break;
            }
        }
        $toInsert = array_slice($clean, $skip);
        $lastTs = $existing !== [] ? (int)$existing[count($existing) - 1]['created_at'] : 0;
        $ts = max(time(), $lastTs);
        $lastUserId = null;
        foreach (array_reverse($existing) as $m) {
            if ($m['role'] === 'user') {
                $lastUserId = (int)$m['id'];
                break;
            }
        }
        $inserted = [];
        if ($toInsert !== []) {
            $this->db->beginTransaction();
            try {
                foreach ($toInsert as $turn) {
                    $replyTo = $turn['role'] === 'assistant' ? $lastUserId : null;
                    $qb = $this->db->getQueryBuilder();
                    $qb->insert('cognos_creation_messages')
                        ->values([
                            'creation_id' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT),
                            'role' => $qb->createNamedParameter($turn['role'], IQueryBuilder::PARAM_STR),
                            'content' => $qb->createNamedParameter($turn['content'], IQueryBuilder::PARAM_STR),
                            'created_at' => $qb->createNamedParameter($ts, IQueryBuilder::PARAM_INT),
                            'reply_to' => $qb->createNamedParameter($replyTo, $replyTo === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
                            'author' => $qb->createNamedParameter($turn['author'], $turn['author'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
                        ])
                        ->executeStatement();
                    $msgId = (int)$qb->getLastInsertId();
                    if ($turn['role'] === 'user') {
                        $lastUserId = $msgId;
                    }
                    $inserted[] = [
                        'id' => $msgId,
                        'creation_id' => $id,
                        'role' => $turn['role'],
                        'content' => $turn['content'],
                        'created_at' => $ts,
                        'reply_to' => $replyTo,
                        'author' => $turn['author'],
                    ];
                }
                $uqb = $this->db->getQueryBuilder();
                $uqb->update('cognos_creations')
                    ->set('updated_at', $uqb->createNamedParameter($ts, IQueryBuilder::PARAM_INT))
                    ->where($uqb->expr()->eq('id', $uqb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
                    ->executeStatement();
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        }
        return [
            'imported' => count($inserted),
            'skipped' => $skip,
            'messages' => $inserted,
            'all_messages' => $this->getMessages($id, $userId),
        ];
    }

    public function get(int $id, string $userId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('cognos_creations')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'user_id' => $row['user_id'],
            'title' => $row['title'],
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
        ];
    }

    /**
     * @param ?int $replyTo 回答回的是哪一句；被反对叫回来的回答不回任何一句，传 0
     */
    public function addMessage(int $creationId, string $userId, string $role, string $content, ?int $replyTo = null, ?string $author = null): array {
        return $this->mutate($creationId, $userId, function () use ($creationId, $role, $content, $replyTo, $author): array {
            $archive = null;
            if ($replyTo !== null && $replyTo > 0) {
                $q = $this->db->getQueryBuilder();
                $result = $q->select('first_archive')->from('cognos_creation_messages')
                    ->where($q->expr()->eq('creation_id', $q->createNamedParameter($creationId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($q->expr()->eq('id', $q->createNamedParameter($replyTo, IQueryBuilder::PARAM_INT)))
                    ->andWhere($q->expr()->eq('is_archived', $q->createNamedParameter(1, IQueryBuilder::PARAM_INT)))->executeQuery();
                $row = $result->fetch();
                $result->closeCursor();
                $archive = $row ? (int)$row['first_archive'] : null;
            }
            return $this->insertMessage($creationId, $role, $content, $replyTo, $author, $archive);
        });
    }

    private function insertMessage(int $creationId, string $role, string $content, ?int $replyTo, ?string $author, ?int $archive = null): array {
        $qb = $this->db->getQueryBuilder();
        $createdAt = time();
        $author = $author === null || $author === '' ? null : mb_substr($author, 0, 64);

        $qb->insert('cognos_creation_messages')
            ->values([
                'creation_id' => $qb->createNamedParameter($creationId, IQueryBuilder::PARAM_INT),
                'role' => $qb->createNamedParameter($role, IQueryBuilder::PARAM_STR),
                'content' => $qb->createNamedParameter($content, IQueryBuilder::PARAM_STR),
                'created_at' => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
                'reply_to' => $qb->createNamedParameter($replyTo, $replyTo === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
                'author' => $qb->createNamedParameter($author, $author === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
                'is_archived' => $qb->createNamedParameter($archive === null ? 0 : 1, IQueryBuilder::PARAM_INT),
                'first_archive' => $qb->createNamedParameter($archive, $archive === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
            ])
            ->executeStatement();

        $messageId = (int)$qb->getLastInsertId();

        return [
            'id' => $messageId,
            'creation_id' => $creationId,
            'role' => $role,
            'content' => $content,
            'created_at' => $createdAt,
            'reply_to' => $replyTo,
            'author' => $author,
            'archived' => $archive !== null,
        ];
    }

    public function getMessages(int $creationId, string $userId, bool $includeArchived = false): array {
        $this->requireOwner($creationId, $userId);
        return $this->messages($creationId, $includeArchived);
    }

    private function messages(int $creationId, bool $includeArchived = false, ?array $ids = null): array {
        if ($ids === []) return [];
        if ($ids !== null && count($ids) > 500) {
            $messages = [];
            foreach (array_chunk($ids, 500) as $group) {
                array_push($messages, ...$this->messages($creationId, $includeArchived, $group));
            }
            usort($messages, static fn(array $a, array $b): int => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);
            return $messages;
        }
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('cognos_creation_messages')
            ->where($q->expr()->eq('creation_id', $q->createNamedParameter($creationId, IQueryBuilder::PARAM_INT)));
        if (!$includeArchived) {
            $q->andWhere($q->expr()->eq('is_archived', $q->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        }
        if ($ids !== null) {
            $q->andWhere($q->expr()->in('id', $q->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        }
        $result = $q->orderBy('created_at', 'ASC')->addOrderBy('id', 'ASC')->executeQuery();
        $messages = array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'creation_id' => (int)$row['creation_id'],
            'role' => $row['role'],
            'content' => $row['content'],
            'created_at' => (int)$row['created_at'],
            'reply_to' => isset($row['reply_to']) ? (int)$row['reply_to'] : null,
            'author' => isset($row['author']) && $row['author'] !== '' ? (string)$row['author'] : null,
            'archived' => (bool)$row['is_archived'],
            'first_archive' => isset($row['first_archive']) ? (int)$row['first_archive'] : null,
        ], $result->fetchAll());
        $result->closeCursor();
        return $messages;
    }

    /** A source link opens the current conversation or the saved context of a retired message. */
    public function history(int $creationId, string $userId, int $revision, int $source): array {
        $this->requireOwner($creationId, $userId);
        $messages = $revision === 0 ? $this->messages($creationId) : [];
        $original = null;
        if ($revision === 0 && $source > 0 && !in_array($source, array_column($messages, 'id'), true)) {
            $original = $this->messages($creationId, true, [$source])[0] ?? null;
            if ($original === null) {
                throw new \InvalidArgumentException('Message not found');
            }
            $revision = $original['first_archive'] ?? 0;
            if ($revision === 0) {
                // A restore may have committed between the two reads.
                $messages = $this->messages($creationId);
            }
        }
        if ($revision !== 0) {
            $ids = $this->revisionIds($creationId, $revision);
            if ($source > 0 && !in_array($source, $ids, true)) {
                // A reply in flight can arrive after its prompt has left the current conversation.
                if (($original['first_archive'] ?? null) !== $revision) {
                    throw new \InvalidArgumentException('Message not found in revision');
                }
                $ids[] = $source;
            }
            $messages = $this->messages($creationId, true, $ids);
        }
        $q = $this->db->getQueryBuilder();
        $result = $q->select('id', 'kind', 'anchor_id', 'created_at')->from('cognos_creation_revisions')
            ->where($q->expr()->eq('creation_id', $q->createNamedParameter($creationId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'DESC')->executeQuery();
        $revisions = $result->fetchAll();
        $result->closeCursor();
        return ['messages' => $messages, 'revision' => $revision, 'revisions' => $revisions];
    }

    public function editMessage(int $creationId, string $userId, int $messageId, string $content): array {
        return $this->mutate($creationId, $userId, function () use ($creationId, $messageId, $content): array {
            $messages = $this->messages($creationId);
            $ids = array_column($messages, 'id');
            $index = array_search($messageId, $ids, true);
            if ($index === false || $messages[$index]['role'] !== 'user') {
                throw new \InvalidArgumentException('Message not found');
            }
            $revision = $this->saveRevision($creationId, 'edit', $messageId, $ids);
            $removed = array_slice($ids, $index);
            $this->setArchived($creationId, $removed, $revision);
            $message = $this->insertMessage($creationId, 'user', $content, null, null);
            return ['id' => $message['id'], 'message' => $message, 'removed' => $removed, 'revision' => $revision];
        });
    }

    public function deleteMessage(int $creationId, string $userId, int $messageId): array {
        return $this->mutate($creationId, $userId, function () use ($creationId, $messageId): array {
            $messages = $this->messages($creationId);
            $all = array_column($messages, 'id');
            $index = array_search($messageId, $all, true);
            if ($index === false) {
                throw new \InvalidArgumentException('Message not found');
            }
            $removed = [$messageId];
            if ($messages[$index]['role'] === 'user') {
                foreach ($messages as $m) {
                    if ($m['role'] !== 'user' && $m['reply_to'] === $messageId) $removed[] = $m['id'];
                }
                // Older replies without reply_to used adjacency.
                for ($i = $index + 1; $i < count($messages); $i++) {
                    $m = $messages[$i];
                    if ($m['role'] === 'user' || $m['reply_to'] !== null) break;
                    $removed[] = $m['id'];
                }
            }
            $revision = $this->saveRevision($creationId, 'delete', $messageId, $all);
            $this->setArchived($creationId, $removed, $revision);
            return ['removed' => $removed, 'revision' => $revision];
        });
    }

    public function restoreRevision(int $creationId, string $userId, int $revision): array {
        return $this->mutate($creationId, $userId, function () use ($creationId, $revision): array {
            $current = array_column($this->messages($creationId), 'id');
            $previous = $this->revisionIds($creationId, $revision);
            if ($current === $previous) {
                return ['revision' => $revision];
            }
            $saved = $this->saveRevision($creationId, 'restore', null, $current);
            $this->setArchived($creationId, array_values(array_diff($current, $previous)), $saved);
            $this->setArchived($creationId, array_values(array_diff($previous, $current)), null);
            return ['revision' => $saved];
        });
    }

    private function requireOwner(int $creationId, string $userId): void {
        if ($this->get($creationId, $userId) === null) {
            throw new \InvalidArgumentException('Creation not found or access denied');
        }
    }

    /** Serialize message writes and revisions on the same creation row. */
    private function mutate(int $creationId, string $userId, callable $operation): array {
        $this->db->beginTransaction();
        try {
            $q = $this->db->getQueryBuilder();
            $q->update('cognos_creations')->set('updated_at', $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('id', $q->createNamedParameter($creationId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('user_id', $q->createNamedParameter($userId)))
                ->executeStatement();
            $this->requireOwner($creationId, $userId);
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    private function saveRevision(int $creationId, string $kind, ?int $anchor, array $ids): int {
        $q = $this->db->getQueryBuilder();
        $q->insert('cognos_creation_revisions')->values([
            'creation_id' => $q->createNamedParameter($creationId, IQueryBuilder::PARAM_INT),
            'kind' => $q->createNamedParameter($kind),
            'anchor_id' => $q->createNamedParameter($anchor, $anchor === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
            'created_at' => $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            'message_ids' => $q->createNamedParameter(json_encode($ids, JSON_THROW_ON_ERROR)),
        ])->executeStatement();
        return (int)$q->getLastInsertId();
    }

    private function revisionIds(int $creationId, int $revision): array {
        $q = $this->db->getQueryBuilder();
        $result = $q->select('message_ids')->from('cognos_creation_revisions')
            ->where($q->expr()->eq('creation_id', $q->createNamedParameter($creationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('id', $q->createNamedParameter($revision, IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        if (!$row) {
            throw new \InvalidArgumentException('Revision not found');
        }
        return json_decode($row['message_ids'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function setArchived(int $creationId, array $ids, ?int $revision): void {
        foreach (array_chunk($ids, 500) as $group) {
            $q = $this->db->getQueryBuilder();
            $q->update('cognos_creation_messages')
                ->set('is_archived', $q->createNamedParameter($revision === null ? 0 : 1, IQueryBuilder::PARAM_INT))
                ->where($q->expr()->eq('creation_id', $q->createNamedParameter($creationId, IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->in('id', $q->createNamedParameter($group, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeStatement();
            if ($revision !== null) {
                $q = $this->db->getQueryBuilder();
                $q->update('cognos_creation_messages')
                    ->set('first_archive', $q->createNamedParameter($revision, IQueryBuilder::PARAM_INT))
                    ->where($q->expr()->eq('creation_id', $q->createNamedParameter($creationId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($q->expr()->in('id', $q->createNamedParameter($group, IQueryBuilder::PARAM_INT_ARRAY)))
                    ->andWhere($q->expr()->isNull('first_archive'))
                    ->executeStatement();
            }
        }
    }

    public function getContext(int $creationId, string $userId): array {
        $creation = $this->get($creationId, $userId);
        if (!$creation) {
            throw new \InvalidArgumentException('Creation not found or access denied');
        }

        $messages = $this->getMessages($creationId, $userId);

        return [
            'creation' => $creation,
            'messages' => $messages,
        ];
    }
}
