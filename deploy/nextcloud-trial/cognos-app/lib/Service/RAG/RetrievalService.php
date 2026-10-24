<?php

declare(strict_types=1);

namespace OCA\Cognos\Service\RAG;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Retrieval service implementing hybrid search
 * Combines semantic search (vector) and keyword search (BM25)
 */
class RetrievalService {
    private const SEMANTIC_WEIGHT = 0.7;
    private const KEYWORD_WEIGHT = 0.3;

    private EmbeddingService $embeddingService;
    private VectorStoreService $vectorStore;
    private IDBConnection $db;
    private LoggerInterface $logger;
    private string $tableName = 'hermes_document_embeddings';

    public function __construct(
        EmbeddingService $embeddingService,
        VectorStoreService $vectorStore,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->embeddingService = $embeddingService;
        $this->vectorStore = $vectorStore;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Hybrid search: combines semantic and keyword search
     *
     * @param string $query Search query
     * @param int $limit Number of results to return
     * @param array $options Search options (filters, weights, etc.)
     * @return array Ranked search results
     */
    public function search(string $query, int $limit = 10, array $options = []): array {
        $semanticWeight = $options['semantic_weight'] ?? self::SEMANTIC_WEIGHT;
        $keywordWeight = $options['keyword_weight'] ?? self::KEYWORD_WEIGHT;
        $filters = $options['filters'] ?? [];

        $this->logger->debug('Starting hybrid search', [
            'query' => $query,
            'limit' => $limit,
            'semantic_weight' => $semanticWeight,
            'keyword_weight' => $keywordWeight,
        ]);

        // 1. Generate query embedding
        $queryEmbedding = $this->embeddingService->embed($query);

        // 2. Perform semantic search
        $vectorResults = $this->vectorStore->search(
            $queryEmbedding,
            $limit * 2,  // Get more candidates for merging
            0.0,
            $filters
        );

        // 3. Perform keyword search
        $keywordResults = $this->keywordSearch($query, $limit * 2, $filters);

        // 4. Merge and rank results
        $mergedResults = $this->mergeResults(
            $vectorResults,
            $keywordResults,
            $semanticWeight,
            $keywordWeight,
            $limit
        );

        $this->logger->debug('Hybrid search completed', [
            'vector_results' => count($vectorResults),
            'keyword_results' => count($keywordResults),
            'final_results' => count($mergedResults),
        ]);

        return $mergedResults;
    }

    /**
     * Perform keyword search using PostgreSQL full-text search
     *
     * @param string $query Search query
     * @param int $limit Result limit
     * @param array $filters Additional filters
     * @return array Search results with BM25 scores
     */
    public function keywordSearch(string $query, int $limit = 10, array $filters = []): array {
        // Build WHERE clause from filters
        $whereClauses = ["content_tsv @@ plainto_tsquery('simple', ?)"];
        $params = [[$query, \PDO::PARAM_STR]];

        if (isset($filters['document_id'])) {
            $whereClauses[] = "document_id = ?";
            $params[] = [$filters['document_id'], \PDO::PARAM_INT];
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $whereClauses);

        $sql = "
            SELECT
                id,
                document_id,
                chunk_index,
                content,
                metadata,
                created_at,
                ts_rank(content_tsv, plainto_tsquery('simple', ?)) AS rank
            FROM {$this->tableName}
            $whereSQL
            ORDER BY rank DESC
            LIMIT ?
        ";

        $stmt = $this->db->prepare($sql);

        // Bind parameters
        $bindIndex = 1;
        $stmt->bindValue($bindIndex++, $query, \PDO::PARAM_STR);

        foreach ($params as $param) {
            $stmt->bindValue($bindIndex++, $param[0], $param[1]);
        }

        $stmt->bindValue($bindIndex++, $limit, \PDO::PARAM_INT);

        $result = $stmt->executeQuery();
        $rows = $result->fetchAll();

        // Parse metadata
        foreach ($rows as &$row) {
            $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
        }

        return $rows;
    }

    /**
     * Merge semantic and keyword search results with weighted scoring
     *
     * @param array $vectorResults Semantic search results
     * @param array $keywordResults Keyword search results
     * @param float $semanticWeight Weight for semantic scores
     * @param float $keywordWeight Weight for keyword scores
     * @param int $limit Final result limit
     * @return array Merged and ranked results
     */
    private function mergeResults(
        array $vectorResults,
        array $keywordResults,
        float $semanticWeight,
        float $keywordWeight,
        int $limit
    ): array {
        $merged = [];

        // Add semantic search results
        foreach ($vectorResults as $result) {
            $id = $result['id'];
            $merged[$id] = [
                'data' => $result,
                'semantic_score' => $result['similarity'] ?? 0.0,
                'keyword_score' => 0.0,
            ];
        }

        // Add keyword search results
        foreach ($keywordResults as $result) {
            $id = $result['id'];
            $keywordScore = $result['rank'] ?? 0.0;

            if (isset($merged[$id])) {
                // Document found in both searches
                $merged[$id]['keyword_score'] = $keywordScore;
            } else {
                // Document only in keyword search
                $merged[$id] = [
                    'data' => $result,
                    'semantic_score' => 0.0,
                    'keyword_score' => $keywordScore,
                ];
            }
        }

        // Calculate final scores
        foreach ($merged as $id => &$item) {
            $item['final_score'] =
                $semanticWeight * $item['semantic_score'] +
                $keywordWeight * $item['keyword_score'];
        }

        // Sort by final score (descending)
        uasort($merged, function($a, $b) {
            return $b['final_score'] <=> $a['final_score'];
        });

        // Take top N results and extract data
        $results = [];
        $count = 0;
        foreach ($merged as $item) {
            if ($count >= $limit) {
                break;
            }

            $result = $item['data'];
            $result['retrieval_score'] = $item['final_score'];
            $result['semantic_score'] = $item['semantic_score'];
            $result['keyword_score'] = $item['keyword_score'];

            $results[] = $result;
            $count++;
        }

        return $results;
    }

    /**
     * Search with query expansion (synonyms, related terms)
     * Useful for improving recall
     *
     * @param string $query Original query
     * @param int $limit Result limit
     * @param array $options Search options
     * @return array Search results
     */
    public function expandedSearch(string $query, int $limit = 10, array $options = []): array {
        // TODO: Implement query expansion logic
        // Could use LLM to generate related queries or use a thesaurus
        $expandedQueries = [$query];  // Placeholder

        $allResults = [];
        foreach ($expandedQueries as $expandedQuery) {
            $results = $this->search($expandedQuery, $limit, $options);
            $allResults = array_merge($allResults, $results);
        }

        // Deduplicate and re-rank
        $uniqueResults = [];
        foreach ($allResults as $result) {
            $id = $result['id'];
            if (!isset($uniqueResults[$id]) ||
                $result['retrieval_score'] > $uniqueResults[$id]['retrieval_score']) {
                $uniqueResults[$id] = $result;
            }
        }

        // Sort and limit
        usort($uniqueResults, function($a, $b) {
            return $b['retrieval_score'] <=> $a['retrieval_score'];
        });

        return array_slice($uniqueResults, 0, $limit);
    }

    /**
     * Re-rank results with additional factors
     * - Document freshness (time decay)
     * - Document type priority
     * - User permissions
     *
     * @param array $results Initial search results
     * @param array $options Re-ranking options
     * @return array Re-ranked results
     */
    public function rerank(array $results, array $options = []): array {
        $useTimedecay = $options['time_decay'] ?? false;
        $typeWeights = $options['type_weights'] ?? [];

        foreach ($results as &$result) {
            $score = $result['retrieval_score'];

            // Apply time decay
            if ($useTimedecay && isset($result['created_at'])) {
                $timestamp = strtotime($result['created_at']);
                $ageInDays = (time() - $timestamp) / 86400;
                $decayFactor = exp(-0.01 * $ageInDays);  // Exponential decay
                $score *= $decayFactor;
            }

            // Apply document type weights
            if (!empty($typeWeights) && isset($result['metadata']['type'])) {
                $type = $result['metadata']['type'];
                $typeWeight = $typeWeights[$type] ?? 1.0;
                $score *= $typeWeight;
            }

            $result['final_score'] = $score;
        }

        // Re-sort by final score
        usort($results, function($a, $b) {
            return $b['final_score'] <=> $a['final_score'];
        });

        return $results;
    }

    /**
     * Get semantic neighbors of a document
     * Find similar documents based on vector similarity
     *
     * @param int $documentId Source document ID
     * @param int $limit Number of neighbors
     * @return array Similar documents
     */
    public function getNeighbors(int $documentId, int $limit = 5): array {
        // Get all chunks for the document
        $chunks = $this->vectorStore->getByDocumentId($documentId);

        if (empty($chunks)) {
            return [];
        }

        // Use the first chunk's embedding as representative
        // TODO: Could use average embedding or most important chunk
        $firstChunkId = $chunks[0]['id'];

        $sql = "
            SELECT embedding
            FROM {$this->tableName}
            WHERE id = ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $firstChunkId, \PDO::PARAM_INT);
        $result = $stmt->executeQuery();
        $row = $result->fetch();

        if (!$row || !isset($row['embedding'])) {
            return [];
        }

        // Parse the embedding vector
        $embeddingStr = $row['embedding'];
        $embedding = $this->parseVector($embeddingStr);

        // Search for similar documents (excluding self)
        return $this->vectorStore->search(
            $embedding,
            $limit + 1,
            0.5,  // Minimum similarity threshold
            []
        );
    }

    /**
     * Parse PostgreSQL vector string to PHP array
     *
     * @param string $vectorStr Vector string like "[1.0,2.0,3.0]"
     * @return array Float array
     */
    private function parseVector(string $vectorStr): array {
        $vectorStr = trim($vectorStr, '[]');
        return array_map('floatval', explode(',', $vectorStr));
    }
}
