<?php

declare(strict_types=1);

namespace OCA\Cognos\Service\Memory;

use Psr\Log\LoggerInterface;

/**
 * Memory Manager: orchestrates working memory and long-term memory
 */
class MemoryManager {
    private WorkingMemory $workingMemory;
    private LongTermMemory $longTermMemory;
    private LoggerInterface $logger;
    private string $sessionId;

    public function __construct(
        string $sessionId,
        WorkingMemory $workingMemory,
        LongTermMemory $longTermMemory,
        LoggerInterface $logger
    ) {
        $this->sessionId = $sessionId;
        $this->workingMemory = $workingMemory;
        $this->longTermMemory = $longTermMemory;
        $this->logger = $logger;
    }

    /**
     * Add a message to both working and long-term memory
     */
    public function addMessage(string $role, string $content, ?array $metadata = null): void {
        $this->workingMemory->addMessage($role, $content, $metadata);
        $this->longTermMemory->saveMessage($this->sessionId, $role, $content, $metadata);

        $this->logger->debug('Message added to memory', [
            'session' => $this->sessionId,
            'role' => $role,
            'content_length' => strlen($content),
        ]);
    }

    /**
     * Get recent messages from working memory
     */
    public function getRecentMessages(): array {
        return $this->workingMemory->getFormattedMessages();
    }

    /**
     * Get historical messages from long-term memory
     */
    public function getHistory(int $limit = 50, int $offset = 0): array {
        return $this->longTermMemory->getMessages($this->sessionId, $limit, $offset);
    }

    /**
     * Build context for LLM (working memory + relevant history)
     */
    public function buildContext(int $historyLimit = 10): array {
        $workingMessages = $this->workingMemory->getFormattedMessages();

        // If working memory is not empty, just use it
        if (!empty($workingMessages)) {
            return $workingMessages;
        }

        // Otherwise, load from long-term memory
        $history = $this->longTermMemory->getMessages($this->sessionId, $historyLimit);
        return array_map(function($msg) {
            return [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }, $history);
    }

    /**
     * Load messages from long-term storage into working memory
     */
    public function loadWorkingMemory(int $limit = 20): void {
        $messages = $this->longTermMemory->getMessages($this->sessionId, $limit);

        $this->workingMemory->clear();

        foreach ($messages as $msg) {
            $this->workingMemory->addMessage(
                $msg['role'],
                $msg['content'],
                $msg['metadata'] ?? null
            );
        }

        $this->logger->debug('Working memory loaded', [
            'session' => $this->sessionId,
            'message_count' => count($messages),
        ]);
    }

    /**
     * Search memories by keyword
     */
    public function search(string $keyword, int $limit = 20): array {
        return $this->longTermMemory->searchMessages($keyword, $limit);
    }

    /**
     * Clear working memory
     */
    public function clearWorking(): void {
        $this->workingMemory->clear();
    }

    /**
     * Prune old memories
     */
    public function prune(int $keepLast = 100): int {
        return $this->longTermMemory->pruneMessages($this->sessionId, $keepLast);
    }

    /**
     * Get memory statistics
     */
    public function getStats(): array {
        $longTermStats = $this->longTermMemory->getSessionStats($this->sessionId);
        $workingCount = $this->workingMemory->count();

        return [
            'session_id' => $this->sessionId,
            'working_memory_count' => $workingCount,
            'long_term_total' => (int)($longTermStats['total_messages'] ?? 0),
            'first_message_at' => $longTermStats['first_message'] ?? null,
            'last_message_at' => $longTermStats['last_message'] ?? null,
        ];
    }
}
