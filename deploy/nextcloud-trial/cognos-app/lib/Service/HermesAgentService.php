<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCA\Cognos\Service\RAG\EmbeddingService;
use OCA\Cognos\Service\RAG\RetrievalService;
use OCA\Cognos\Service\RAG\VectorStoreService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

final class HermesAgentService {
    private const MAX_TOOL_ROUNDS = 4;

    /** @var array<string, array{state: string, understanding: ?array, pendingMessage: ?string}> */
    private array $sessionStates = [];

    /** @var array<string, callable> */
    private array $tools = [];

    /** @var array<string, array> */
    private array $toolSchemas = [];

    private ?RetrievalService $retrievalService = null;
    private ?EmbeddingService $embeddingService = null;
    private ?VectorStoreService $vectorStore = null;
    private bool $ragEnabled = false;

    public function __construct(
        private IDBConnection $db,
        private IUserSession $users,
        private IClientService $http,
        private NotesService $notes,
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
                            'space_id' => [
                                'type' => 'string',
                                'description' => 'Optional note space id; defaults to the most recently updated space',
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
            $results = array_slice($this->notes->search((string)$query), 0, max(1, (int)$limit));

            $notes = array_map(function($row) {
                return [
                    'id' => (string)$row['id'],
                    'space_id' => (string)$row['space_id'],
                    'title' => (string)$row['title'],
                    'preview' => (string)($row['preview'] ?? ''),
                    'modified_at' => (int)$row['updated_at'],
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

        $title = trim((string)($args['title'] ?? ''));
        $content = (string)($args['content'] ?? '');
        $spaceId = trim((string)($args['space_id'] ?? ''));

        if ($title === '' || $content === '') {
            return ['success' => false, 'error' => 'Title and content are required', 'data' => null];
        }

        try {
            if ($spaceId === '') {
                $spaces = $this->notes->listSpaces();
                if ($spaces === []) {
                    return ['success' => false, 'error' => 'No note space exists yet', 'data' => null];
                }
                $spaceId = (string)$spaces[0]['id'];
            }
            $note = $this->notes->createNote($spaceId, $title, $content, ['type' => 'agent_tool']);

            return [
                'success' => true,
                'data' => [
                    'id' => (string)$note['id'],
                    'space_id' => $spaceId,
                    'title' => $title,
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
                ->where($qb->expr()->eq('owner', $qb->createNamedParameter($user->getUID())))
                ->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0)));

            if (!$includeArchived) {
                $qb->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
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

        $sessionId = $this->sessionId($activityId, $participantId);

        // Retrieve relevant context using RAG
        $ragContext = $this->retrieveContext($newUserMessage, 5);

        // Build request messages with enhanced system prompt
        $requestMessages = [
            [
                'role' => 'system',
                'content' => $this->buildEnhancedSystemPrompt($activity, $ragContext),
            ],
        ];

        // Conversation history lives in cognos_activity_messages; only replay completed turns
        foreach ($this->buildConversationHistory($activityId) as $history) {
            if (($history['state'] ?? 'succeeded') !== 'succeeded') {
                continue;
            }
            $requestMessages[] = ['role' => (string)$history['role'], 'content' => (string)$history['content']];
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
        } catch (\Throwable $error) {
            $this->markMessageFailed($userMessageId, $error->getMessage());
            throw new ActivityChatException('Hermes 暂时不可用；你的输入已保存，可以重试。', $userMessageId);
        }
        return $response;
    }

    /**
     * Call Hermes with the registered tools and run the tool calls it requests, for a bounded number of rounds
     */
    private function chatWithTools(string $sessionId, array $messages, ?string $model, ?string $participantId): array {
        $tools = array_values($this->toolSchemas);
        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $response = $this->callHermes($sessionId, $messages, $model, $participantId, $tools);
            $toolCalls = $response['tool_calls'] ?? [];
            if ($toolCalls === []) {
                return $response;
            }
            $messages[] = ['role' => 'assistant', 'content' => $response['content'], 'tool_calls' => $toolCalls];
            foreach ($toolCalls as $call) {
                $arguments = json_decode((string)($call['function']['arguments'] ?? '{}'), true);
                $result = $this->callTool((string)($call['function']['name'] ?? ''), is_array($arguments) ? $arguments : []);
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string)($call['id'] ?? ''),
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ];
            }
        }
        // Out of rounds: ask for a final answer without offering tools again
        return $this->callHermes($sessionId, $messages, $model, $participantId);
    }

    public function chatRoom(
        string $roomToken,
        string $message,
        string $author,
        string $authorId,
        ?string $projectContext = null,
    ): array {
        $message = trim($message);
        if ($message === '') {
            throw new \InvalidArgumentException('Message is required');
        }

        $system = '你是 Cognos 的 Hermes 团队协作助手。' . "\n"
            . '房间 token：' . $roomToken . "\n";
        if ($projectContext !== null && trim($projectContext) !== '') {
            $system .= '项目上下文：' . "\n" . $projectContext . "\n";
        }
        $system .= '只根据当前团队消息和提供的项目上下文回答；不要臆造成员权限或项目事实。' . "\n";

        return $this->callHermes(
            'talk_' . hash('sha256', $roomToken),
            [
                ['role' => 'system', 'content' => $system],
                [
                    'role' => 'user',
                    'content' => '发言人：' . $author . '（' . $authorId . '）' . "\n" . $message,
                ],
            ],
            null,
        );
    }

    public function getMessages(string $activityId): array {
        $user = $this->users->getUser();
        if ($user === null || $this->getActivityForUser($activityId, $user->getUID()) === null) {
            throw new \RuntimeException('Activity not found');
        }
        $query = $this->db->getQueryBuilder();
        $query->select('*')->from('cognos_activity_messages')
            ->where($query->expr()->eq('activity_id', $query->createNamedParameter($activityId)))
            ->orderBy('created_at', 'ASC');
        return $query->executeQuery()->fetchAllAssociative();
    }

    private function lastUserMessage(array $messages): string {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if (($messages[$index]['role'] ?? '') === 'user') {
                return trim((string)($messages[$index]['content'] ?? ''));
            }
        }
        return '';
    }

    private function normalizeRequestId(?string $requestId): string {
        $requestId = trim((string)$requestId);
        if ($requestId === '') {
            return bin2hex(random_bytes(16));
        }
        if (strlen($requestId) > 64 || !preg_match('/^[A-Za-z0-9._:-]+$/', $requestId)) {
            throw new \InvalidArgumentException('Invalid request_id');
        }
        return $requestId;
    }

    private function findUserMessageByRequest(string $activityId, string $userId, string $requestId): ?array {
        $query = $this->db->getQueryBuilder();
        $query->select('*')
            ->from('cognos_activity_messages')
            ->where($query->expr()->eq('activity_id', $query->createNamedParameter($activityId)))
            ->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
            ->andWhere($query->expr()->eq('role', $query->createNamedParameter('user')))
            ->andWhere($query->expr()->eq('request_id', $query->createNamedParameter($requestId)));
        $row = $query->executeQuery()->fetchAssociative();
        return $row ?: null;
    }

    private function replayExistingRequest(string $activityId, string $participantId, array $message): array {
        $messageId = (string)$message['id'];
        $state = (string)($message['state'] ?? 'pending');
        if ($state === 'failed') {
            throw new ActivityChatException('Hermes 暂时不可用；你的输入已保存，可以重试。', $messageId);
        }
        if ($state !== 'succeeded') {
            throw new ActivityRequestInProgressException('这条消息正在处理中，请稍候。', $messageId);
        }

        $query = $this->db->getQueryBuilder();
        $query->select('*')
            ->from('cognos_activity_messages')
            ->where($query->expr()->eq('activity_id', $query->createNamedParameter($activityId)))
            ->andWhere($query->expr()->eq('reply_to', $query->createNamedParameter($messageId)))
            ->andWhere($query->expr()->eq('role', $query->createNamedParameter('assistant')))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults(1);
        $reply = $query->executeQuery()->fetchAssociative();
        if (!$reply) {
            throw new \RuntimeException('已完成的消息缺少参与者回复');
        }
        return [
            'content' => (string)$reply['content'],
            'model' => $this->participantRouteModel($participantId),
            'session_id' => $this->sessionId($activityId, $participantId),
            'replayed' => true,
        ];
    }

    public function retryFailedMessage(string $activityId, string $messageId): array {
        $user = $this->users->getUser();
        if ($user === null || $this->getActivityForUser($activityId, $user->getUID()) === null) throw new \RuntimeException('Activity not found');
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('cognos_activity_messages')
            ->where($q->expr()->eq('id', $q->createNamedParameter($messageId)))
            ->andWhere($q->expr()->eq('activity_id', $q->createNamedParameter($activityId)))
            ->andWhere($q->expr()->eq('user_id', $q->createNamedParameter($user->getUID())))
            ->andWhere($q->expr()->eq('role', $q->createNamedParameter('user')));
        $message = $q->executeQuery()->fetchAssociative();
        if (!$message || ($message['state'] ?? '') !== 'failed') throw new \InvalidArgumentException('Only a saved failed message can be retried');
        $activity = $this->getActivityForUser($activityId, $user->getUID());
        if ($activity === null) {
            throw new \RuntimeException('Activity not found');
        }
        $messageParticipantId = trim((string)($message['participant_id'] ?? ''));
        $retryRouteModel = trim((string)($this->participantRouteModel($messageParticipantId) ?: ''));
        if ($retryRouteModel === '') {
            throw new ParticipantRuntimeUnavailableException('Hermes runtime 未配置。');
        }
        $claimed = $this->db->getQueryBuilder();
        $claimed->update('cognos_activity_messages')->set('state', $claimed->createNamedParameter('pending'))
            ->set('error_text', $claimed->createNamedParameter(null))
            ->where($claimed->expr()->eq('id', $claimed->createNamedParameter($messageId)))
            ->andWhere($claimed->expr()->eq('state', $claimed->createNamedParameter('failed')));
        if ($claimed->executeStatement() !== 1) {
            throw new ActivityRequestInProgressException('重试已在进行中。');
        }
        $requestMessages = [['role'=>'system','content'=>$this->buildSystemPrompt($activity)]];
        foreach ($this->buildConversationHistory($activityId) as $history) {
            if (($history['id'] ?? '') === $messageId) continue;
            if (($history['state'] ?? 'succeeded') !== 'succeeded') continue;
            $requestMessages[] = ['role'=>(string)$history['role'],'content'=>(string)$history['content']];
        }
        $requestMessages[] = ['role'=>'user','content'=>(string)$message['content']];
        $participant = [
            'id' => $messageParticipantId,
            'display_name' => (string)($message['participant_name'] ?? $messageParticipantId),
        ];
        try {
            $response = $this->callHermes($this->sessionId($activityId, $messageParticipantId), $requestMessages, $retryRouteModel, $messageParticipantId);
            $this->persistSuccessfulReply($activityId, $user->getUID(), $messageId, $response['content'], $participant);
        } catch (\Throwable $error) {
            $this->markMessageFailed($messageId, $error->getMessage());
            throw new ActivityChatException('Hermes 暂时不可用；你的输入已保存，可以重试。', $messageId);
        }
        return ['response'=>$response,'messages'=>$this->getMessages($activityId)];
    }

    private function participantRouteModel(string $participantId): string {
        $specific = trim((string)(getenv('HERMES_PARTICIPANT_ROUTE_' . strtoupper(str_replace('-', '_', $participantId))) ?: ''));
        return $specific !== '' ? $specific : trim((string)(getenv('HERMES_API_MODEL') ?: 'hermes-cognos'));
    }

    private function sessionId(string $activityId, string $participantId): string {
        return 'cognos_' . hash('sha256', $activityId . ':' . $participantId);
    }

    private function getActivityForUser(string $activityId, string $uid): ?array {
        $query = $this->db->getQueryBuilder();
        $query->select('*')
            ->from('cognos_activities')
            ->where($query->expr()->eq('id', $query->createNamedParameter($activityId)))
            ->andWhere($query->expr()->eq('actor_uid', $query->createNamedParameter($uid)));
        $row = $query->executeQuery()->fetchAssociative();
        if (!$row) {
            return null;
        }

        $row['source_snapshot'] = json_decode(
            (string)$row['source_snapshot'],
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        return $row;
    }

    private function buildSystemPrompt(array $activity): string {
        $snapshot = is_array($activity['source_snapshot'] ?? null)
            ? $activity['source_snapshot']
            : [];
        $sourceType = (string)($activity['source_type'] ?? 'unknown');
        $participant = (string)($activity['participant_name'] ?? $activity['participant_id'] ?? '未命名参与者');

        $prompt = '你是 Cognos 中被用户明确召唤的参与者：' . $participant . "\n"
            . '你不是匿名助手，也不是已确认事实的发布者。' . "\n"
            . '只把回答作为候选理解、提议或待验证问题；不要自动创建任务、决定或项目事实。' . "\n"
            . 'Activity 标题：' . (string)($activity['title'] ?? '') . "\n"
            . '来源类型：' . $sourceType . "\n";

        if ($sourceType === 'note') {
            $prompt .= '材料标题：' . (string)($snapshot['title'] ?? '') . "\n"
                . '材料正文：' . "\n"
                . (string)($snapshot['content'] ?? '') . "\n";
        } elseif ($sourceType === 'nextcloud_deck_card') {
            $prompt .= '项目/任务卡片标题：' . (string)($snapshot['title'] ?? '') . "\n"
                . '卡片描述：' . (string)($snapshot['description'] ?? '') . "\n"
                . '看板：' . (string)($snapshot['board_id'] ?? '')
                . '，列表：' . (string)($snapshot['stack_title'] ?? '') . "\n";
        }

        return $prompt . '原始来源是上下文，不是已经确认的事实；请区分候选建议和用户已确认内容。';
    }

    private function buildConversationHistory(string $activityId): array {
        $query = $this->db->getQueryBuilder();
        $query->select('id', 'role', 'content', 'state', 'reply_to')
            ->from('cognos_activity_messages')
            ->where($query->expr()->eq('activity_id', $query->createNamedParameter($activityId)))
            ->orderBy('created_at', 'ASC')
            ->setMaxResults(50);

        $rows = $query->executeQuery()->fetchAllAssociative();
        return array_map(
            static fn(array $row): array => [
                'id' => (string)$row['id'],
                'role' => (string)$row['role'],
                'content' => (string)$row['content'],
                'state' => (string)($row['state'] ?? 'succeeded'),
                'reply_to' => $row['reply_to'] ?? null,
            ],
            $rows,
        );
    }

    private function callHermes(string $sessionId, array $messages, ?string $model, ?string $participantId = null, array $tools = []): array {
        $url = getenv('HERMES_API_URL') ?: 'http://host.docker.internal:8642/v1/chat/completions';
        $keyFile = getenv('HERMES_API_KEY_FILE') ?: '/run/secrets/hermes_api_key';
        $key = is_readable($keyFile)
            ? trim((string)file_get_contents($keyFile))
            : trim((string)(getenv('HERMES_API_KEY') ?: ''));
        if ($key === '') {
            throw new \RuntimeException('Hermes API is not configured');
        }

        $payload = [
            'model' => $model ?: (getenv('HERMES_API_MODEL') ?: 'hermes-cognos'),
            'messages' => $messages,
            'stream' => false,
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        $response = $this->http->newClient()->post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Hermes-Session-Id' => $sessionId,
                'X-Cognos-Participant-Id' => $participantId ?: 'unknown',
            ],
            'body' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'timeout' => 180,
        ]);

        $status = $response->getStatusCode();
        $body = json_decode($response->getBody(), true);
        if ($status < 200 || $status >= 300 || !is_array($body)) {
            throw new \RuntimeException('Hermes API request failed');
        }

        $content = $body['choices'][0]['message']['content'] ?? null;
        $toolCalls = $tools !== [] ? ($body['choices'][0]['message']['tool_calls'] ?? []) : [];
        if (!is_array($toolCalls)) {
            $toolCalls = [];
        }
        if ($toolCalls === [] && (!is_string($content) || trim($content) === '')) {
            throw new \RuntimeException('Hermes returned no response');
        }

        return [
            'content' => trim((string)$content),
            'model' => (string)($body['model'] ?? $payload['model']),
            'session_id' => (string)($response->getHeader('X-Hermes-Session-Id') ?: $sessionId),
            'tool_calls' => $toolCalls,
        ];
    }

    private function saveMessage(string $activityId, string $userId, string $role, string $content, string $state = 'succeeded', ?string $requestId = null, ?string $replyTo = null, ?array $participant = null): string {
        $id = bin2hex(random_bytes(16));
        $query = $this->db->getQueryBuilder();
        $query->insert('cognos_activity_messages')->values([
            'id' => $query->createNamedParameter($id),
            'activity_id' => $query->createNamedParameter($activityId),
            'user_id' => $query->createNamedParameter($userId),
            'role' => $query->createNamedParameter($role),
            'content' => $query->createNamedParameter($content),
            'created_at' => $query->createNamedParameter(time()),
            'state' => $query->createNamedParameter($state),
            'request_id' => $requestId === null ? $query->createNamedParameter(null) : $query->createNamedParameter($requestId),
            'error_text' => $query->createNamedParameter(null),
            'reply_to' => $replyTo === null ? $query->createNamedParameter(null) : $query->createNamedParameter($replyTo),
            'participant_id' => $query->createNamedParameter($participant['id'] ?? null),
            'participant_name' => $query->createNamedParameter($participant['display_name'] ?? null),
        ])->executeStatement();
        return $id;
    }

    private function persistSuccessfulReply(string $activityId, string $userId, string $userMessageId, string $content, array $participant): void {
        $this->db->beginTransaction();
        try {
            $query = $this->db->getQueryBuilder();
            $updated = $query->update('cognos_activity_messages')
                ->set('state', $query->createNamedParameter('succeeded'))
                ->set('error_text', $query->createNamedParameter(null))
                ->where($query->expr()->eq('id', $query->createNamedParameter($userMessageId)))
                ->andWhere($query->expr()->eq('activity_id', $query->createNamedParameter($activityId)))
                ->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
                ->andWhere($query->expr()->eq('state', $query->createNamedParameter('pending')))
                ->executeStatement();
            if ($updated !== 1) {
                throw new \RuntimeException('User message is no longer pending');
            }
            $this->saveMessage(
                $activityId,
                $userId,
                'assistant',
                $content,
                'succeeded',
                null,
                $userMessageId,
                $participant,
            );
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function markMessageFailed(string $messageId, string $error): void {
        $query = $this->db->getQueryBuilder();
        $query->update('cognos_activity_messages')
            ->set('state', $query->createNamedParameter('failed'))
            ->set('error_text', $query->createNamedParameter(mb_substr($error, 0, 500)))
            ->where($query->expr()->eq('id', $query->createNamedParameter($messageId)))
            ->andWhere($query->expr()->eq('state', $query->createNamedParameter('pending')))
            ->executeStatement();
    }

    /**
     * Public wrapper for callHermes: 用于 ActivityRunService 等其他服务调用 Hermes API
     */
    public function callAgent(string $sessionId, array $messages, ?string $model = null, ?string $participantId = null): array {
        return $this->callHermes($sessionId, $messages, $model, $participantId);
    }
}
