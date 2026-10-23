<?php

declare(strict_types=1);

namespace OCA\Cognos\Service\Memory;

/**
 * Working Memory: manages short-term conversation context
 * Keeps the most recent N messages in memory for fast access
 */
class WorkingMemory {
    private const DEFAULT_MAX_MESSAGES = 20;
    private const DEFAULT_MAX_TOKENS = 4000;

    private array $messages = [];
    private int $maxMessages;
    private int $maxTokens;

    public function __construct(
        int $maxMessages = self::DEFAULT_MAX_MESSAGES,
        int $maxTokens = self::DEFAULT_MAX_TOKENS
    ) {
        $this->maxMessages = $maxMessages;
        $this->maxTokens = $maxTokens;
    }

    /**
     * Add a message to working memory
     */
    public function addMessage(string $role, string $content, ?array $metadata = null): void {
        $this->messages[] = [
            'role' => $role,
            'content' => $content,
            'timestamp' => time(),
            'metadata' => $metadata ?? [],
        ];

        $this->trim();
    }

    /**
     * Get all messages in working memory
     */
    public function getMessages(): array {
        return $this->messages;
    }

    /**
     * Get messages formatted for LLM
     */
    public function getFormattedMessages(): array {
        return array_map(function($msg) {
            return [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }, $this->messages);
    }

    /**
     * Clear all messages
     */
    public function clear(): void {
        $this->messages = [];
    }

    /**
     * Trim messages to stay within limits
     */
    private function trim(): void {
        // Trim by count
        if (count($this->messages) > $this->maxMessages) {
            $this->messages = array_slice(
                $this->messages,
                -$this->maxMessages
            );
        }

        // Trim by token count
        $totalTokens = $this->estimateTokens();
        while ($totalTokens > $this->maxTokens && count($this->messages) > 1) {
            array_shift($this->messages);
            $totalTokens = $this->estimateTokens();
        }
    }

    /**
     * Estimate total token count
     */
    private function estimateTokens(): int {
        $total = 0;
        foreach ($this->messages as $msg) {
            // Rough estimate: 4 chars ≈ 1 token
            $total += (int)(strlen($msg['content']) / 4);
        }
        return $total;
    }

    /**
     * Get the last N messages
     */
    public function getLastN(int $n): array {
        return array_slice($this->messages, -$n);
    }

    /**
     * Count messages in working memory
     */
    public function count(): int {
        return count($this->messages);
    }
}
