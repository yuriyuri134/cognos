<?php

declare(strict_types=1);

namespace OCA\Cognos\Service\RAG;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Vector store service using pgvector
 * Provides vector storage and similarity search functionality
 */
class VectorStoreService {
    private IDBConnection $db;
    private LoggerInterface $logger;
    private string $tableName = 'hermes_document_embeddings';

    public function __construct(
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Insert document chunk with embedding
     *
     * @param int $documentId Document ID
     * @param int $chunkIndex Chunk index in document
     * @param string $content Text content
     * @param array $embedding Embedding vector
     * @param array $metadata Optional metadata (JSON)
     * @return int Inserted row ID
     */
    public function insert(
        int $documentId,
        int $chunkIndex,
        string $content,
        array $embedding,
        array $metadata = []
    ): int {
        $sql = "
            INSERT INTO {$this->tableName}
            (document_id, chunk_index, content, embedding, metadata, created_at)
            VALUES (?, ?, ?, ?::vector, ?::jsonb, NOW())
            RETURNING id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $documentId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $chunkIndex, \PDO::PARAM_INT);
        $stmt->bindValue(3, $content, \PDO::PARAM_STR);
        $stmt->bindValue(4, $this->formatVector($embedding), \PDO::PARAM_STR);
        $stmt->bindValue(5, json_encode($metadata), \PDO::PARAM_STR);

        $result = $stmt->executeQuery();
        $row = $result->fetch();

        $this->logger->debug('Inserted document chunk', [
            'document_id' => $documentId,
            'chunk_index' => $chunkIndex,
            'id' => $row['id'],
        ]);

        return (int) $row['id'];
    }

    /**
     * Search for similar vectors using cosine similarity
     *
     * @param array $queryEmbedding Query embedding vector
     * @param int $limit Maximum results to return
     * @param float $minSimilarity Minimum similarity threshold (0.0 - 1.0)
     * @param array $filters Optional filters (e.g., document_id, metadata)
     * @return array Array of results with similarity scores
     */
    public function search(
        array $queryEmbedding,
        int $limit = 10,
        float $minSimilarity = 0.0,
        array $filters = []
    ): array {
        $queryVector = $this->formatVector($queryEmbedding);

        // Build WHERE clause from filters
        $whereClauses = [];
        $params = [];
        $paramIndex = 1;

        if (isset($filters['document_id'])) {
            $whereClauses[] = "document_id = ?";
            $params[] = [$filters['document_id'], \PDO::PARAM_INT];
        }

        if (isset($filters['metadata_filter'])) {
            foreach ($filters['metadata_filter'] as $key => $value) {
                $whereClauses[] = "metadata->? = ?";
                $params[] = [$key, \PDO::PARAM_STR];
                $params[] = [json_encode($value), \PDO::PARAM_STR];
            }
        }

        // Add similarity threshold
        $whereClauses[] = "(1 - (embedding <=> ?::vector)) >= ?";

        $whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        $sql = "
            SELECT
                id,
                document_id,
                chunk_index,
                content,
                metadata,
                created_at,
                1 - (embedding <=> ?::vector) AS similarity
            FROM {$this->tableName}
            $whereSQL
            ORDER BY embedding <=> ?::vector
            LIMIT ?
        ";

        $stmt = $this->db->prepare($sql);

        // Bind parameters
        $bindIndex = 1;
        $stmt->bindValue($bindIndex++, $queryVector, \PDO::PARAM_STR);

        foreach ($params as $param) {
            $stmt->bindValue($bindIndex++, $param[0], $param[1]);
        }

        $stmt->bindValue($bindIndex++, $queryVector, \PDO::PARAM_STR);
        $stmt->bindValue($bindIndex++, $minSimilarity, \PDO::PARAM_STR);
        $stmt->bindValue($bindIndex++, $queryVector, \PDO::PARAM_STR);
        $stmt->bindValue($bindIndex++, $limit, \PDO::PARAM_INT);

        $result = $stmt->executeQuery();
        $rows = $result->fetchAll();

        // Parse metadata JSON
        foreach ($rows as &$row) {
            $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
        }

        $this->logger->debug('Vector search completed', [
            'results_count' => count($rows),
            'limit' => $limit,
        ]);

        return $rows;
    }

    /**
     * Delete embeddings for a document
     *
     * @param int $documentId Document ID to delete
     * @return int Number of rows deleted
     */
    public function deleteByDocumentId(int $documentId): int {
        $sql = "DELETE FROM {$this->tableName} WHERE document_id = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $documentId, \PDO::PARAM_INT);
        $stmt->execute();

        $deletedCount = $stmt->rowCount();

        $this->logger->info('Deleted document embeddings', [
            'document_id' => $documentId,
            'count' => $deletedCount,
        ]);

        return $deletedCount;
    }

    /**
     * Get total number of embeddings
     *
     * @return int Total count
     */
    public function count(): int {
        $sql = "SELECT COUNT(*) as count FROM {$this->tableName}";
        $result = $this->db->executeQuery($sql);
        $row = $result->fetch();
        return (int) $row['count'];
    }

    /**
     * Get embeddings for a specific document
     *
     * @param int $documentId Document ID
     * @return array Array of chunks with embeddings
     */
    public function getByDocumentId(int $documentId): array {
        $sql = "
            SELECT
                id,
                document_id,
                chunk_index,
                content,
                metadata,
                created_at
            FROM {$this->tableName}
            WHERE document_id = ?
            ORDER BY chunk_index ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $documentId, \PDO::PARAM_INT);
        $result = $stmt->executeQuery();
        $rows = $result->fetchAll();

        foreach ($rows as &$row) {
            $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
        }

        return $rows;
    }

    /**
     * Format embedding vector for PostgreSQL vector type
     * Convert PHP array to PostgreSQL vector string format: [1.0, 2.0, 3.0]
     *
     * @param array $vector Embedding vector
     * @return string Formatted vector string
     */
    private function formatVector(array $vector): string {
        return '[' . implode(',', $vector) . ']';
    }

    /**
     * Initialize database table and indexes
     * Should be called during app installation/setup
     *
     * @throws \Exception If pgvector extension is not available
     */
    public function initializeSchema(): void {
        // Check if pgvector extension exists
        $checkExtension = "SELECT 1 FROM pg_extension WHERE extname = 'vector'";
        $result = $this->db->executeQuery($checkExtension);

        if (!$result->fetch()) {
            throw new \RuntimeException(
                'pgvector extension not installed. Run: CREATE EXTENSION vector;'
            );
        }

        // Create table if not exists
        $createTable = "
            CREATE TABLE IF NOT EXISTS {$this->tableName} (
                id SERIAL PRIMARY KEY,
                document_id INTEGER NOT NULL,
                chunk_index INTEGER NOT NULL,
                content TEXT NOT NULL,
                embedding vector(1024),
                metadata JSONB,
                created_at TIMESTAMP DEFAULT NOW(),
                content_tsv tsvector GENERATED ALWAYS AS (
                    to_tsvector('simple', content)
                ) STORED
            )
        ";
        $this->db->executeStatement($createTable);

        // Create HNSW index for vector similarity search
        $createHNSWIndex = "
            CREATE INDEX IF NOT EXISTS idx_hermes_embeddings_hnsw
            ON {$this->tableName}
            USING hnsw (embedding vector_cosine_ops)
        ";
        $this->db->executeStatement($createHNSWIndex);

        // Create GIN index for full-text search
        $createGINIndex = "
            CREATE INDEX IF NOT EXISTS idx_hermes_embeddings_fts
            ON {$this->tableName}
            USING gin(content_tsv)
        ";
        $this->db->executeStatement($createGINIndex);

        // Create index on document_id for efficient filtering
        $createDocIdIndex = "
            CREATE INDEX IF NOT EXISTS idx_hermes_embeddings_doc_id
            ON {$this->tableName} (document_id)
        ";
        $this->db->executeStatement($createDocIdIndex);

        $this->logger->info('VectorStore schema initialized');
    }
}
