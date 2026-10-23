<?php

declare(strict_types=1);

namespace OCA\HermesAgent\Service\RAG;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Embedding service for generating text embeddings
 * Supports multiple providers: Zhipu AI (GLM-4), Tongyi Qianwen, OpenAI
 */
class EmbeddingService {
    private const PROVIDER_ZHIPU = 'zhipu';
    private const PROVIDER_TONGYI = 'tongyi';
    private const PROVIDER_OPENAI = 'openai';

    private const API_ENDPOINTS = [
        self::PROVIDER_ZHIPU => 'https://open.bigmodel.cn/api/paas/v4/embeddings',
        self::PROVIDER_TONGYI => 'https://dashscope.aliyuncs.com/api/v1/services/embeddings/text-embedding/text-embedding',
        self::PROVIDER_OPENAI => 'https://api.openai.com/v1/embeddings',
    ];

    private const MODELS = [
        self::PROVIDER_ZHIPU => 'embedding-3',
        self::PROVIDER_TONGYI => 'text-embedding-v2',
        self::PROVIDER_OPENAI => 'text-embedding-3-small',
    ];

    private IClientService $httpClient;
    private IConfig $config;
    private LoggerInterface $logger;
    private string $provider;
    private string $apiKey;

    public function __construct(
        IClientService $httpClient,
        IConfig $config,
        LoggerInterface $logger
    ) {
        $this->httpClient = $httpClient;
        $this->config = $config;
        $this->logger = $logger;

        // Load config from Nextcloud app settings
        $this->provider = $this->config->getAppValue(
            'hermes_agent',
            'embedding_provider',
            self::PROVIDER_ZHIPU
        );

        $this->apiKey = $this->config->getAppValue(
            'hermes_agent',
            'embedding_api_key',
            ''
        );

        if (empty($this->apiKey)) {
            throw new \RuntimeException('Embedding API key not configured');
        }
    }

    /**
     * Generate embedding for a single text
     *
     * @param string $text Text to embed
     * @return array Embedding vector (float array)
     * @throws \RuntimeException On API errors
     */
    public function embed(string $text): array {
        if (empty(trim($text))) {
            throw new \InvalidArgumentException('Text cannot be empty');
        }

        $results = $this->batchEmbed([$text]);
        return $results[0];
    }

    /**
     * Generate embeddings for multiple texts (batch processing)
     * More efficient than calling embed() multiple times
     *
     * @param array $texts Array of texts to embed
     * @param int $batchSize Maximum batch size (default 25 for Zhipu)
     * @return array Array of embedding vectors
     * @throws \RuntimeException On API errors
     */
    public function batchEmbed(array $texts, int $batchSize = 25): array {
        if (empty($texts)) {
            return [];
        }

        $allEmbeddings = [];

        // Process in batches
        foreach (array_chunk($texts, $batchSize) as $batch) {
            $embeddings = $this->callEmbeddingAPI($batch);
            $allEmbeddings = array_merge($allEmbeddings, $embeddings);
        }

        return $allEmbeddings;
    }

    /**
     * Call the embedding API based on provider
     *
     * @param array $texts Texts to embed
     * @return array Array of embeddings
     * @throws \RuntimeException
     */
    private function callEmbeddingAPI(array $texts): array {
        $client = $this->httpClient->newClient();
        $endpoint = self::API_ENDPOINTS[$this->provider] ?? null;
        $model = self::MODELS[$this->provider] ?? null;

        if (!$endpoint || !$model) {
            throw new \RuntimeException("Unsupported embedding provider: {$this->provider}");
        }

        try {
            $headers = $this->buildHeaders();
            $body = $this->buildRequestBody($texts, $model);

            $this->logger->debug('Calling embedding API', [
                'provider' => $this->provider,
                'model' => $model,
                'text_count' => count($texts),
            ]);

            $response = $client->post($endpoint, [
                'headers' => $headers,
                'json' => $body,
                'timeout' => 30,
            ]);

            $data = json_decode($response->getBody(), true);
            return $this->parseResponse($data);

        } catch (\Exception $e) {
            $this->logger->error('Embedding API call failed', [
                'provider' => $this->provider,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Embedding API failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Build HTTP headers for API request
     */
    private function buildHeaders(): array {
        switch ($this->provider) {
            case self::PROVIDER_ZHIPU:
                return [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ];

            case self::PROVIDER_TONGYI:
                return [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ];

            case self::PROVIDER_OPENAI:
                return [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ];

            default:
                throw new \RuntimeException("Unknown provider: {$this->provider}");
        }
    }

    /**
     * Build request body for API
     */
    private function buildRequestBody(array $texts, string $model): array {
        $input = count($texts) === 1 ? $texts[0] : $texts;

        return [
            'model' => $model,
            'input' => $input,
        ];
    }

    /**
     * Parse API response and extract embeddings
     */
    private function parseResponse(array $data): array {
        // Check for API errors
        if (isset($data['error'])) {
            $errorMsg = $data['error']['message'] ?? 'Unknown API error';
            throw new \RuntimeException("API error: {$errorMsg}");
        }

        // Extract embeddings from response
        if (!isset($data['data']) || !is_array($data['data'])) {
            throw new \RuntimeException('Invalid API response: missing data field');
        }

        $embeddings = [];
        foreach ($data['data'] as $item) {
            if (!isset($item['embedding'])) {
                throw new \RuntimeException('Invalid API response: missing embedding field');
            }
            $embeddings[] = $item['embedding'];
        }

        return $embeddings;
    }

    /**
     * Get embedding dimension for current provider
     */
    public function getEmbeddingDimension(): int {
        return match($this->provider) {
            self::PROVIDER_ZHIPU => 1024,
            self::PROVIDER_TONGYI => 1536,
            self::PROVIDER_OPENAI => 1536,
            default => throw new \RuntimeException("Unknown provider: {$this->provider}"),
        };
    }

    /**
     * Get current provider name
     */
    public function getProvider(): string {
        return $this->provider;
    }
}
