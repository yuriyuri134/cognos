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

    public function list(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.*')
            ->from('cognos_creations', 'c')
            ->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->orderBy('c.updated_at', 'DESC');

        $result = $qb->executeQuery();
        $creations = [];
        while ($row = $result->fetch()) {
            $creationId = (int)$row['id'];

            // Get message count
            $countQb = $this->db->getQueryBuilder();
            $countQb->select($countQb->func()->count('*', 'count'))
                ->from('cognos_creation_messages')
                ->where($countQb->expr()->eq('creation_id', $countQb->createNamedParameter($creationId, IQueryBuilder::PARAM_INT)));
            $countResult = $countQb->executeQuery();
            $countRow = $countResult->fetch();
            $messageCount = (int)($countRow['count'] ?? 0);
            $countResult->closeCursor();

            $creations[] = [
                'id' => $creationId,
                'user_id' => $row['user_id'],
                'title' => $row['title'],
                'created_at' => (int)$row['created_at'],
                'updated_at' => (int)$row['updated_at'],
                'message_count' => $messageCount,
            ];
        }
        $result->closeCursor();
        return $creations;
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
     * @param ?int $replyTo 回答回的是哪一句；被顶叫回来的回答不回任何一句，传 0
     */
    public function addMessage(int $creationId, string $userId, string $role, string $content, ?int $replyTo = null): array {
        $qb = $this->db->getQueryBuilder();
        $createdAt = time();

        $qb->insert('cognos_creation_messages')
            ->values([
                'creation_id' => $qb->createNamedParameter($creationId, IQueryBuilder::PARAM_INT),
                'role' => $qb->createNamedParameter($role, IQueryBuilder::PARAM_STR),
                'content' => $qb->createNamedParameter($content, IQueryBuilder::PARAM_STR),
                'created_at' => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT),
                'reply_to' => $qb->createNamedParameter($replyTo, IQueryBuilder::PARAM_INT),
            ])
            ->executeStatement();

        $messageId = (int)$qb->getLastInsertId();

        // Update creation's updated_at
        $qb = $this->db->getQueryBuilder();
        $qb->update('cognos_creations')
            ->set('updated_at', $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($creationId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        return [
            'id' => $messageId,
            'creation_id' => $creationId,
            'role' => $role,
            'content' => $content,
            'created_at' => $createdAt,
            'reply_to' => $replyTo,
        ];
    }

    public function getMessages(int $creationId, string $userId): array {
        // Verify ownership
        $creation = $this->get($creationId, $userId);
        if (!$creation) {
            throw new \InvalidArgumentException('Creation not found or access denied');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('cognos_creation_messages')
            ->where($qb->expr()->eq('creation_id', $qb->createNamedParameter($creationId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC');

        $result = $qb->executeQuery();
        $messages = [];
        while ($row = $result->fetch()) {
            $messages[] = [
                'id' => (int)$row['id'],
                'creation_id' => (int)$row['creation_id'],
                'role' => $row['role'],
                'content' => $row['content'],
                'created_at' => (int)$row['created_at'],
                'reply_to' => isset($row['reply_to']) ? (int)$row['reply_to'] : null,
            ];
        }
        $result->closeCursor();
        return $messages;
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
