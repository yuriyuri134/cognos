<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCA\Cognos\Service\RAG\EmbeddingService;
use OCA\Cognos\Service\RAG\RetrievalService;
use OCA\Cognos\Service\RAG\VectorStoreService;
use OCA\Cognos\Service\Memory\WorkingMemory;
use OCA\Cognos\Service\Memory\LongTermMemory;
use OCA\Cognos\Service\Memory\MemoryManager;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

final class HermesAgentService {
    /** @var array<string, array{state: string, understanding: ?array, pendingMessage: ?string}> */
    private array $sessionStates = [];

    /** @var array<string, callable> */
    private array $tools = [];

    /** @var array<string, array> */
    private array $toolSchemas = [];

    /** @var array<string, MemoryManager> */
    private array $memoryManagers = [];

    private ?RetrievalService $retrievalService = null;
    private ?EmbeddingService $embeddingService = null;
    private ?VectorStoreService $vectorStore = null;
    private bool $ragEnabled = false;

    public function __construct(
        private IDBConnection $db,
        private IUserSession $users,
        private IClientService $http,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
        $this->initializeTools();
        $this->initializeRAG();
    }

    /**
     * Initialize built-in tools
     */
    private function initializeTools(): void {
        // Register search_notes tool
        $this->registerTool(
            'search_notes',
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_notes',
                    'description' => 'Search through notes by keyword. Returns matching notes with title, content preview, and metadata.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Search query string',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'Maximum number of results to return',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [$this, 'toolSearchNotes']
        );

        // Register create_note tool
        $this->registerTool(
            'create_note',
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_note',
                    'description' => 'Create a new note in Nextcloud Notes. Use this when the user wants to save information, create a document, or store text.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => [
                                'type' => 'string',
                                'description' => 'Note title',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'Note content in markdown format',
                            ],
                            'category' => [
                                'type' => 'string',
                                'description' => 'Optional category/folder for the note',
                            ],
                        ],
                        'required' => ['title', 'content'],
                    ],
                ],
            ],
            [$this, 'toolCreateNote']
        );

        // Register list_projects tool
        $this->registerTool(
            'list_projects',
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_projects',
                    'description' => 'List all projects/boards in Nextcloud Deck. Returns project ID, title, and status.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'archived' => [
                                'type' => 'boolean',
                                'description' => 'Include archived projects',
                            ],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [$this, 'toolListProjects']
        );
    }

    /**
     * Initialize RAG system
     */
    private function initializeRAG(): void {
        try {
            $ragProvider = $this->config->getAppValue('cognos', 'rag_enabled', 'false');
            if ($ragProvider !== 'true') {
                $this->logger->info('RAG is disabled in config');
                return;
            }

            $this->embeddingService = new EmbeddingService(
                $this->http,
                $this->config,
                $this->logger
            );

            $this->vectorStore = new VectorStoreService(
                $this->db,
                $this->logger
            );

            $this->retrievalService = new RetrievalService(
                $this->embeddingService,
                $this->vectorStore,
                $this->db,
                $this->logger
            );

            $this->ragEnabled = true;
            $this->logger->info('RAG system initialized');
        } catch (\Throwable $e) {
            $this->logger->warning('RAG initialization failed', [
                'error' => $e->getMessage(),
            ]);
            $this->ragEnabled = false;
        }
    }

    /**
     * Get or create memory manager for a session
     */
    private function getMemoryManager(string $sessionId): MemoryManager {
        if (!isset($this->memoryManagers[$sessionId])) {
            $workingMemory = new WorkingMemory();
            $longTermMemory = new LongTermMemory($this->db, $this->logger);

            $this->memoryManagers[$sessionId] = new MemoryManager(
                $sessionId,
                $workingMemory,
                $longTermMemory,
                $this->logger
            );

            // Load recent messages into working memory
            $this->memoryManagers[$sessionId]->loadWorkingMemory(20);
        }

        return $this->memoryManagers[$sessionId];
    }

    /**
     * Register a tool for LLM usage
     *
     * @param string $name Tool name
     * @param array $schema Tool schema in OpenAI/Anthropic format
     * @param callable $handler Tool execution handler
     */
    public function registerTool(string $name, array $schema, callable $handler): void {
        $this->tools[$name] = $handler;
        $this->toolSchemas[$name] = $schema;
        $this->logger->info('Tool registered', ['tool' => $name]);
    }

    /**
     * Call a tool by name
     *
     * @param string $name Tool name
     * @param array $arguments Tool arguments
     * @return array Tool result with success, data, error
     */
    public function callTool(string $name, array $arguments): array {
        $this->logger->info('Tool call requested', [
            'tool' => $name,
            'arguments' => $arguments,
        ]);

        if (!isset($this->tools[$name])) {
            $this->logger->error('Tool not found', ['tool' => $name]);
            return [
                'success' => false,
                'error' => "Tool not found: {$name}",
                'data' => null,
            ];
        }

        try {
            $startTime = microtime(true);
            $handler = $this->tools[$name];
            $result = $handler($arguments);
            $duration = microtime(true) - $startTime;

            $this->logger->info('Tool execution completed', [
                'tool' => $name,
                'success' => $result['success'] ?? false,
                'duration_ms' => round($duration * 1000, 2),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Tool execution failed', [
                'tool' => $name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Retrieve relevant context using RAG
     */
    private function retrieveContext(string $query, int $limit = 5): array {
        if (!$this->ragEnabled || $this->retrievalService === null) {
            return [];
        }

        try {
            $results = $this->retrievalService->search($query, $limit);

            return array_map(function($result) {
                return [
                    'content' => $result['content'] ?? '',
                    'score' => $result['retrieval_score'] ?? 0.0,
                    'metadata' => $result['metadata'] ?? [],
                ];
            }, $results);
        } catch (\Throwable $e) {
            $this->logger->error('RAG retrieval failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Build system prompt with available tools and context
     */
    private function buildEnhancedSystemPrompt(array $activity, array $ragContext = []): string {
        $basePrompt = $this->buildSystemPrompt($activity);

        // Add tool information
        $toolDescriptions = [];
        foreach ($this->toolSchemas as $name => $schema) {
            $func = $schema['function'] ?? [];
            $description = $func['description'] ?? $name;
            $toolDescriptions[] = "- {$name}: {$description}";
        }

        if (!empty($toolDescriptions)) {
            $basePrompt .= "\n\n可用工具：\n" . implode("\n", $toolDescriptions);
        }

        // Add RAG context
        if (!empty($ragContext)) {
            $basePrompt .= "\n\n相关知识：\n";
            foreach ($ragContext as $idx => $context) {
                $content = $context['content'];
                $score = round($context['score'], 2);
                $basePrompt .= "[" . ($idx + 1) . "] (相关度: {$score})\n{$content}\n\n";
            }
        }

        return $basePrompt;
    }

    /**
     * Tool implementation: search_notes
     */
    private function toolSearchNotes(array $args): array {
        $user = $this->users->getUser();
        if ($user === null) {
            return ['success' => false, 'error' => 'Authentication required', 'data' => null];
        }

        $query = $args['query'] ?? '';
        $limit = $args['limit'] ?? 10;

        if ($query === '') {
            return ['success' => false, 'error' => 'Query is required', 'data' => null];
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'title', 'content', 'category', 'modified')
                ->from('notes_notes')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($user->getUID())))
                ->andWhere(
                    $qb->expr()->orX(
                        $qb->expr()->like('title', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($query) . '%')),
                        $qb->expr()->like('content', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($query) . '%'))
                    )
                )
                ->orderBy('modified', 'DESC')
                ->setMaxResults((int)$limit);

            $results = $qb->executeQuery()->fetchAllAssociative();

            $notes = array_map(function($row) {
                return [
                    'id' => (int)$row['id'],
                    'title' => (string)$row['title'],
                    'preview' => mb_substr((string)$row['content'], 0, 200),
                    'category' => (string)($row['category'] ?? ''),
                    'modified_at' => (int)$row['modified'],
                ];
            }, $results);

            return [
                'success' => true,
                'data' => $notes,
                'error' => null,
                'metadata' => [
                    'query' => $query,
                    'count' => count($notes),
                    'limit' => $limit,
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('search_notes failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Tool implementation: create_note
     */
    private function toolCreateNote(array $args): array {
        $user = $this->users->getUser();
        if ($user === null) {
            return ['success' => false, 'error' => 'Authentication required', 'data' => null];
        }

        $title = $args['title'] ?? '';
        $content = $args['content'] ?? '';
        $category = $args['category'] ?? '';

        if ($title === '' || $content === '') {
            return ['success' => false, 'error' => 'Title and content are required', 'data' => null];
        }

        try {
            $noteId = (int)time();
            $now = time();

            $qb = $this->db->getQueryBuilder();
            $qb->insert('notes_notes')
                ->values([
                    'id' => $qb->createNamedParameter($noteId),
                    'user_id' => $qb->createNamedParameter($user->getUID()),
                    'title' => $qb->createNamedParameter($title),
                    'content' => $qb->createNamedParameter($content),
                    'category' => $qb->createNamedParameter($category),
                    'modified' => $qb->createNamedParameter($now),
                    'favorite' => $qb->createNamedParameter(0),
                ])
                ->executeStatement();

            return [
                'success' => true,
                'data' => [
                    'id' => $noteId,
                    'title' => $title,
                    'category' => $category,
                    'created_at' => $now,
                ],
                'error' => null,
                'metadata' => [
                    'operation' => 'create',
                    'resource_type' => 'note',
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('create_note failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Tool implementation: list_projects
     */
    private function toolListProjects(array $args): array {
        $user = $this->users->getUser();
        if ($user === null) {
            return ['success' => false, 'error' => 'Authentication required', 'data' => null];
        }

        $includeArchived = $args['archived'] ?? false;

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'title', 'archived', 'last_modified')
                ->from('deck_boards')
                ->where($qb->expr()->eq('owner', $qb->createNamedParameter($user->getUID())));

            if (!$includeArchived) {
                $qb->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(0)));
            }

            $qb->orderBy('last_modified', 'DESC');

            $results = $qb->executeQuery()->fetchAllAssociative();

            $projects = array_map(function($row) {
                return [
                    'id' => (int)$row['id'],
                    'title' => (string)$row['title'],
                    'archived' => (bool)$row['archived'],
                    'last_modified' => (int)$row['last_modified'],
                ];
            }, $results);

            return [
                'success' => true,
                'data' => $projects,
                'error' => null,
                'metadata' => [
                    'count' => count($projects),
                    'include_archived' => $includeArchived,
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('list_projects failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Chat in Creation Space context with understanding mode
     */
    public function chatCreation(int $creationId, string $message, array $context = []): string {
        $user = $this->users->getUser();
        if ($user === null) {
            throw new \RuntimeException('Authentication required');
        }

        $message = trim($message);
        if ($message === '') {
            throw new \InvalidArgumentException('Message is required');
        }

        $sessionId = 'creation_' . $creationId;
        $state = $this->getSessionState($sessionId);

        // Check if we're in waiting_confirmation state
        if ($state['state'] === 'waiting_confirmation') {
            if ($this->isConfirmation($message)) {
                // User confirmed, proceed with execution
                $this->setSessionState($sessionId, 'executing', null, null);
                return $this->executeWithUnderstanding($creationId, $state['pendingMessage'], $state['understanding'], $context);
            } else {
                // User corrected or provided more context, restart understanding
                $this->setSessionState($sessionId, 'understanding', null, null);
            }
        }

        // Perform understanding analysis
        $understanding = $this->understand($message, $context);

        // Check confidence threshold
        if ($understanding['confidence'] < 70) {
            // Low confidence, ask for confirmation
            $this->setSessionState($sessionId, 'waiting_confirmation', $understanding, $message);
            return $this->formatUnderstanding($understanding);
        }

        // High confidence, execute directly
        $this->setSessionState($sessionId, 'executing', null, null);
        return $this->executeWithUnderstanding($creationId, $message, $understanding, $context);
    }

    /**
     * Extract intent and uncertainty from user message
     */
    private function understand(string $message, array $context): array {
        $creation = $context['creation'] ?? [];
        $systemPrompt = '你是意图理解模块。分析用户消息，提取：
1. 意图类型（question/suggestion/reflection/critique/expansion）
2. 置信度（0-100）
3. 不确定性（需要澄清的地方）
4. 关键实体

创立标题：' . ($creation['title'] ?? '未命名创立') . '

返回JSON格式：
{
  "intent": "question|suggestion|reflection|critique|expansion",
  "confidence": 85,
  "uncertainties": ["需要澄清的点1", "需要澄清的点2"],
  "entities": ["关键词1", "关键词2"]
}';

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $message]
        ];

        try {
            $response = $this->callHermes('understanding_temp', $messages, null, 'hermes-agent');
            $content = trim($response['content']);

            // Extract JSON from response (handle markdown code blocks)
            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
                $content = $matches[1];
            }

            $parsed = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return [
                'intent' => $parsed['intent'] ?? 'question',
                'confidence' => (int)($parsed['confidence'] ?? 50),
                'uncertainties' => $parsed['uncertainties'] ?? [],
                'entities' => $parsed['entities'] ?? []
            ];
        } catch (\Throwable $e) {
            // Fallback on parsing error
            return [
                'intent' => 'question',
                'confidence' => 50,
                'uncertainties' => ['无法准确理解意图'],
                'entities' => []
            ];
        }
    }

    /**
     * Check if message is a confirmation
     */
    private function isConfirmation(string $message): bool {
        $message = mb_strtolower(trim($message));
        $confirmationPatterns = [
            '/^(是|对|好|可以|确认|没错|正确|yes|ok|yeah|correct|yep|确定)$/u',
            '/^(嗯|嗯嗯|嗯对|对的|是的|好的|可以的|就是这样)$/u',
        ];

        foreach ($confirmationPatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format understanding for user confirmation
     */
    private function formatUnderstanding(array $understanding): string {
        $intentMap = [
            'question' => '提问',
            'suggestion' => '建议',
            'reflection' => '反思',
            'critique' => '批判',
            'expansion' => '扩展'
        ];

        $intentLabel = $intentMap[$understanding['intent']] ?? '未知';

        $output = "我理解你的意图是：**{$intentLabel}**\n";
        $output .= "置信度：{$understanding['confidence']}%\n\n";

        if (!empty($understanding['uncertainties'])) {
            $output .= "不过我有些不确定的地方：\n";
            foreach ($understanding['uncertainties'] as $uncertainty) {
                $output .= "- {$uncertainty}\n";
            }
            $output .= "\n能帮我确认一下吗？或者提供更多信息？";
        }

        return $output;
    }

    /**
     * Execute with confirmed understanding
     */
    private function executeWithUnderstanding(int $creationId, string $message, array $understanding, array $context): string {
        $creation = $context['creation'] ?? [];
        $systemPrompt = '你是 Cognos 的 Hermes Agent，专注于帮助用户探索和深化认识。' . "\n"
            . '创立标题：' . ($creation['title'] ?? '未命名创立') . "\n"
            . '这是一个创立空间（Creation Space），用户在这里与你深度对话，探索认识的生长。' . "\n"
            . '你的角色是：' . "\n"
            . '- 提出深入的问题，帮助用户理清思路' . "\n"
            . '- 提供多角度的观点和候选理解' . "\n"
            . '- 不要简单给答案，而是引导用户自己发现' . "\n"
            . '- 区分候选建议和已确认的事实' . "\n\n"
            . '意图分析：' . ($understanding['intent'] ?? 'unknown') . "\n"
            . '关键实体：' . implode(', ', $understanding['entities'] ?? []);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        foreach (($context['messages'] ?? []) as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        $sessionId = 'creation_' . $creationId;
        $response = $this->callHermes($sessionId, $messages, null, 'hermes-agent');

        return $response['content'];
    }

    /**
     * Get session state
     */
    private function getSessionState(string $sessionId): array {
        return $this->sessionStates[$sessionId] ?? [
            'state' => 'understanding',
            'understanding' => null,
            'pendingMessage' => null
        ];
    }

    /**
     * Set session state
     */
    private function setSessionState(string $sessionId, string $state, ?array $understanding, ?string $pendingMessage): void {
        $this->sessionStates[$sessionId] = [
            'state' => $state,
            'understanding' => $understanding,
            'pendingMessage' => $pendingMessage
        ];
    }

    /**
     * Chat with tool use support
     */
    public function chat(string $activityId, array $messages, ?string $model = null, ?string $requestId = null): array {
        $user = $this->users->getUser();
        if ($user === null) {
            throw new \RuntimeException('Authentication required');
        }

        $activity = $this->getActivityForUser($activityId, $user->getUID());
        if ($activity === null) {
            throw new \RuntimeException('Activity not found');
        }
        $participantId = trim((string)($activity['participant_id'] ?? ''));
        if ($participantId === '') {
            throw new \RuntimeException('Activity participant is not selected');
        }
        $routeModel = trim($this->participantRouteModel($participantId));
        if ($routeModel === '') {
            throw new ParticipantRuntimeUnavailableException('Hermes runtime 未配置。');
        }
        $participant = [
            'id' => $participantId,
            'display_name' => (string)($activity['participant_name'] ?? $participantId),
        ];

        $newUserMessage = $this->lastUserMessage($messages);
        if ($newUserMessage === '') {
            throw new \InvalidArgumentException('Message is required');
        }

        // Get memory manager and add user message
        $sessionId = $this->sessionId($activityId, $participantId);
        $memoryManager = $this->getMemoryManager($sessionId);

        // Retrieve relevant context using RAG
        $ragContext = $this->retrieveContext($newUserMessage, 5);

        // Build request messages with enhanced system prompt
        $requestMessages = [
            [
                'role' => 'system',
                'content' => $this->buildEnhancedSystemPrompt($activity, $ragContext),
            ],
        ];

        // Add conversation history from memory
        foreach ($memoryManager->getRecentMessages() as $message) {
            $requestMessages[] = $message;
        }

        // Add the new user message
        $requestMessages[] = ['role' => 'user', 'content' => $newUserMessage];

        $requestId = $this->normalizeRequestId($requestId);
        $existing = $this->findUserMessageByRequest($activityId, $user->getUID(), $requestId);
        if ($existing !== null) {
            if ((string)$existing['content'] !== $newUserMessage) {
                throw new \InvalidArgumentException('request_id 已用于另一条消息，请生成新的请求标识');
            }
            return $this->replayExistingRequest($activityId, $participantId, $existing);
        }

        try {
            $userMessageId = $this->saveMessage(
                $activityId,
                $user->getUID(),
                'user',
                $newUserMessage,
                'pending',
                $requestId,
                null,
                $participant,
            );
        } catch (\Throwable $error) {
            // A concurrent request may have won the unique request_id reservation.
            $existing = $this->findUserMessageByRequest($activityId, $user->getUID(), $requestId);
            if ($existing !== null) {
                if ((string)$existing['content'] !== $newUserMessage) {
                    throw new \InvalidArgumentException('request_id 已用于另一条消息，请生成新的请求标识');
                }
                return $this->replayExistingRequest($activityId, $participantId, $existing);
            }
            throw $error;
        }

        try {
            // Call with tool support
            $response = $this->chatWithTools($sessionId, $requestMessages, $routeModel, $participantId);

            $this->persistSuccessfulReply($activityId, $user->getUID(), $userMessageId, $response['content'], $participant);

            // Save to memory
            $memoryManager->addMessage('user', $newUserMessage);
            $memoryManager->addMessage('assistant', $response['content']);
        } catch (\Throwable $error) {
            $this->markMessageFailed($userMessageId, $error->getMessage());
            throw new ActivityChatException('Hermes 暂时不可用；你的输入已保存，可以重试。', $userMessageId);
        }
        return $response;
    }

<!-- MARKER_CONTINUE_1 -->
