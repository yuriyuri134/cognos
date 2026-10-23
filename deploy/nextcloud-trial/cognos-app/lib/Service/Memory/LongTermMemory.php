<?php

declare(strict_types=1);

namespace OCA\Cognos\Service\Memory;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Long-term Memory: persists conversation history and knowledge
 */
class LongTermMemory {
    private IDBConnection $db;
    private LoggerInterface $logger;
    private string $messagesTable = 'cognos_memory_messages';

    public function __construct(
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Save a message to long-term storage
     */
    public function saveMessage(
        string $sessionId,
        string $role,
        string $content,
        ?array $metadata = null
    ): int {
        $query = $this->db->getQueryBuilder();
        $query->insert($this->messagesTable)
            ->values([
                'session_id' => $query->createNamedParameter($sessionId),
                'role' => $query->createNamedParameter($role),
                'content' => $query->createNamedParameter($content),
                'metadata' => $query->createNamedParameter(json_encode($metadata ?? [])),
                'created_at' => $query->createNamedParameter(time()),
            ])
            ->executeStatement();

        return (int)$this->db->lastInsertId();
    }

    /**
     * Retrieve messages for a session
     */
    public function getMessages(string $sessionId, int $limit = 50, int $offset = 0): array {
        $query = $this->db->getQueryBuilder();
        $query->select('*')
            ->from($this->messagesTable)
            ->where($query->expr()->eq('session_id', $query->createNamedParameter($sessionId)))
            ->orderBy('created_at', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $results = $query->executeQuery()->fetchAllAssociative();

        return array_map(function($row) {
            $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
            return $row;
        }, $results);
    }

    /**
     * Search messages by keyword
     */
    public function searchMessages(string $keyword, int $limit = 20): array {
        $query = $this->db->getQueryBuilder();
        $query->select('*')
            ->from($this->messagesTable)
            ->where(
                $query->expr()->like('content', $query->createNamedParameter('%' . $this->db->escapeLikeParameter($keyword) . '%'))
            )
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);

        $results = $query->executeQuery()->fetchAllAssociative();

        return array_map(function($row) {
            $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
            return $row;
        }, $results);
    }

    /**
     * Delete old messages for a session
     */
    public function pruneMessages(string $sessionId, int $keepLast = 100): int {
        // Get the timestamp of the Nth newest message
        $query = $this->db->getQueryBuilder();
        $query->select('created_at')
            ->from($this->messagesTable)
            ->where($query->expr()->eq('session_id', $query->createNamedParameter($sessionId)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults(1)
            ->setFirstResult($keepLast);

        $result = $query->executeQuery()->fetch();
        if (!$result) {
            return 0; // No messages to prune
        }

        $cutoffTime = (int)$result['created_at'];

        // Delete messages older than cutoff
        $deleteQuery = $this->db->getQueryBuilder();
        $deleteQuery->delete($this->messagesTable)
            ->where($deleteQuery->expr()->eq('session_id', $deleteQuery->createNamedParameter($sessionId)))
            ->andWhere($deleteQuery->expr()->lt('created_at', $deleteQuery->createNamedParameter($cutoffTime)));

        return $deleteQuery->executeStatement();
    }

    /**
     * Get session statistics
     */
    public function getSessionStats(string $sessionId): array {
        $query = $this->db->getQueryBuilder();
        $query->select(
            $query->func()->count('id', 'total_messages'),
            $query->func()->min('created_at', 'first_message'),
            $query->func()->max('created_at', 'last_message')
        )
            ->from($this->messagesTable)
            ->where($query->expr()->eq('session_id', $query->createNamedParameter($sessionId)));

        return $query->executeQuery()->fetchAssociative() ?: [];
    }

    /**
     * Initialize database schema (call during app setup)
     */
    public function initializeSchema(): void {
        // Schema creation should be handled by Nextcloud migrations
        // This is a placeholder for manual initialization if needed
        $this->logger->info('Long-term memory schema check');
    }
}
