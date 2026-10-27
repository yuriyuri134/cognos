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

    /** 在创立空间里被叫进来的参与者名，留下的痕迹记在这个名字下 */
    private const CREATION_PARTICIPANT = 'Hermes';
    private const CLAUDE_CODE_PARTICIPANT = 'Claude Code';

    /** 只在被叫进一片空间时提供的工具 @var array<string, array> */
    private array $spaceToolSchemas = [];

    /** @var ?array{type: string, id: string, author: string} 当前被叫进的空间 */
    private ?array $activeSpace = null;

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
        private SpaceService $spaces,
        private SpaceEntryService $entries,
        private CreationSpaceService $creations,
        private ClaudeBridgeService $bridge,
        private PushBackService $pushBack,
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

        // 空间里的工具：只在被叫进一片空间时提供（见 chatCreation），不进通用工具表
        $this->registerSpaceTool(
            'leave_trace',
            '在这片空间里留下一条发生。你做了一次尝试（在模型上推，或者碰了现实），就用 kind=attempt 留下，写明停在哪、碰没碰过现实。来源固定记为 AI 和你的名字，不能代替交互对象写原话。',
            [
                'kind' => ['type' => 'string', 'description' => 'attempt（尝试，默认）、fact（推出来的事实）、material（材料）、correction（更正某一条，要带 ref_id 和 status）等，自由文本'],
                'content' => ['type' => 'string', 'description' => '正文：你做了什么、看到了什么'],
                'about' => ['type' => 'string', 'description' => '它和哪个意图有关，用入口里意图「标为」的那个词；不知道就不填'],
                'stopped_at' => ['type' => 'string', 'description' => '尝试停在哪里、为什么停'],
                'touched_reality' => ['type' => 'boolean', 'description' => '碰过现实为 true，只在模型上推为 false'],
                'act' => ['type' => 'string', 'description' => '这条在做什么：陈述、推测、提问、举例……'],
                'confidence' => ['type' => 'integer', 'description' => '你的把握，0-100'],
                'ref_id' => ['type' => 'integer', 'description' => 'kind=correction 时，指向被更正的那一条的 #id'],
                'status' => ['type' => 'string', 'description' => 'kind=correction 时，那一条的新状态：confirmed / rejected / withdrawn'],
            ],
            ['content', 'confidence'],
            [$this, 'toolLeaveTrace']
        );
        $this->registerSpaceTool(
            'add_reading',
            '留下你对这片空间的读法：你怎么理解这里的意图、尝试和它们的关系。它和原话分开放，标是你的；你之前的读法不会被删，只会被标为已替换。',
            [
                'content' => ['type' => 'string', 'description' => '读法正文'],
                'confidence' => ['type' => 'integer', 'description' => '你的把握，0-100'],
                'refs' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => '它读的是哪几条（入口里的 #id）'],
            ],
            ['content', 'confidence'],
            [$this, 'toolAddReading']
        );
        $this->registerSpaceTool(
            'search_traces',
            '在这片空间留下的全部发生里找（入口只列了最近的尝试）。',
            [
                'query' => ['type' => 'string', 'description' => '关键词；空字符串返回最近的'],
                'limit' => ['type' => 'integer', 'description' => '最多返回几条，默认 20'],
            ],
            ['query'],
            [$this, 'toolSearchTraces']
        );
        $this->registerSpaceTool(
            'hold_premise',
            '押上一条你自己的前提：你凭它在想、在做的那一条，连上它管着的代码或做法。押着的东西能被顶回来，被顶到时你要面对它。',
            [
                'content' => ['type' => 'string', 'description' => '前提本身，一句话说清'],
                'governs' => ['type' => 'string', 'description' => '它管着什么：哪段代码、哪个做法、哪个判断'],
                'confidence' => ['type' => 'integer', 'description' => '你的把握，0-100'],
            ],
            ['content', 'confidence'],
            [$this, 'toolHoldPremise']
        );
        $this->registerSpaceTool(
            'hit_premise',
            '顶这片空间里别人押着的一条前提（入口「这里别人押着的」里的 #id）：写清是什么把它顶回来的。押的那位会被叫回来面对。',
            [
                'ref_id' => ['type' => 'integer', 'description' => '被顶的那条前提的 #id'],
                'content' => ['type' => 'string', 'description' => '顶回来的是什么：事实、矛盾、反例、现实的结果'],
                'confidence' => ['type' => 'integer', 'description' => '你的把握，0-100'],
            ],
            ['ref_id', 'content', 'confidence'],
            [$this, 'toolHitPremise']
        );
        $this->registerSpaceTool(
            'answer_hit',
            '面对顶到你押着的前提的一下：站住（写清凭什么）、碎了（写清碎在哪）、重新切（写出新的前提）。',
            [
                'hit_id' => ['type' => 'integer', 'description' => '那一下的 #id'],
                'act' => ['type' => 'string', 'enum' => SpaceService::ANSWER_ACTS, 'description' => '站住 / 碎了 / 重新切'],
                'content' => ['type' => 'string', 'description' => '你怎么面对的，这一下之后什么变了'],
                'new_premise' => ['type' => 'string', 'description' => 'act=重新切 时，新的前提'],
                'governs' => ['type' => 'string', 'description' => 'act=重新切 时新前提管着什么；不填就沿用旧的'],
                'confidence' => ['type' => 'integer', 'description' => '你的把握，0-100'],
            ],
            ['hit_id', 'act', 'content', 'confidence'],
            [$this, 'toolAnswerHit']
        );
    }

    private function registerSpaceTool(string $name, string $description, array $properties, array $required, callable $handler): void {
        $this->tools[$name] = $handler;
        $this->spaceToolSchemas[$name] = [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => ['type' => 'object', 'properties' => $properties, 'required' => $required],
            ],
        ];
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
     * Hermes 被叫进一片创立空间。它拿到的是入口：它在哪、这里有什么、以前谁试过什么、停在哪、哪里还空着，
     * 不是角色。它能搜材料，也能在这片空间里留下尝试和读法。
     */
    /**
     * @return string|null Hermes 的回答；叫的是 Claude Code 时返回 null，它在本机接着做，做完自己把回答发回这段对话
     */
    /**
     * @param ?int $replyTo 这次回的是对话里哪一句（交互对象那句的 id），带给桥，回答发回来时记上
     */
    public function chatCreation(int $creationId, string $message, array $context = [], ?int $replyTo = null): ?string {
        $user = $this->users->getUser();
        if ($user === null) {
            throw new \RuntimeException('Authentication required');
        }

        $message = trim($message);
        if ($message === '') {
            throw new \InvalidArgumentException('Message is required');
        }

        $space = $this->entries->resolve('creation', (string)$creationId, $user->getUID());
        if ($space === null) {
            throw new \InvalidArgumentException('Creation not found or access denied');
        }

        $caller = $user->getDisplayName() ?: $user->getUID();
        $history = [];
        foreach (($context['messages'] ?? []) as $msg) {
            $history[] = [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }
        // controller 先存了这条用户消息再取上下文，它多半已经是最后一条
        $last = end($history);
        if ($last !== false && ($last['role'] ?? '') === 'user' && trim((string)($last['content'] ?? '')) === $message) {
            array_pop($history);
        }

        // 被叫进来的是谁：creation_agent 设成 claude-code 就换成本机的 Claude Code（经 tools/cognos-space-mcp/claude_bridge.py）
        if ($this->config->getAppValue('cognos', 'creation_agent', 'hermes') === 'claude-code') {
            // 在 Cognos 自己的空间里，被叫来的就是替 Cognos 站着的那位，它押着的是 Cognos 的架构
            $subject = $this->bridge->subjectFor('creation', (string)$creationId);
            $entry = $this->entries->entry(
                $space,
                $subject,
                ['caller' => $caller, 'message' => $message],
                $this->bridge->canDo('creation', (string)$creationId),
            );
            $this->bridge->send('creation', (string)$creationId, $subject, $this->entries->renderText($entry), $history, $message, $caller, ['reply_to' => $replyTo]);
            $this->spaces->markSeen('creation', (string)$creationId, $subject);
            return null;
        }

        $subject = self::CREATION_PARTICIPANT;
        $entry = $this->entries->entry(
            $space,
            $subject,
            ['caller' => $caller, 'message' => $message],
            [
                'search_notes：在材料（笔记）里找。',
                'search_traces：在这片空间留下的发生里找。',
                'leave_trace：把你这次做的尝试，或你推出来的东西留下。来源固定记为〔AI〕和你的名字。',
                'add_reading：留下你对这里的读法。你之前的读法不会被删，只会被标为已替换。',
                'hold_premise / hit_premise / answer_hit：押上一条前提、顶别人押着的、面对顶到你的。',
                '够不着的：交互对象电脑上的文件、外部网页；Cognos 里别的空间这次也看不到。',
            ],
        );

        $messages = array_merge(
            [['role' => 'system', 'content' => $this->entries->renderText($entry)]],
            $history,
            [['role' => 'user', 'content' => $message]],
        );

        $tools = array_merge([$this->toolSchemas['search_notes']], array_values($this->spaceToolSchemas));
        $this->activeSpace = ['type' => 'creation', 'id' => (string)$creationId, 'author' => $subject];
        try {
            $response = $this->chatWithTools('creation_' . $creationId, $messages, null, 'hermes-agent', $tools);
        } finally {
            $this->activeSpace = null;
        }
        $this->spaces->markSeen('creation', (string)$creationId, $subject);

        return $response['content'];
    }

    /**
     * 用对话里已经存下的一句去叫（排着的那句轮到了）：上下文只到那一句为止。
     *
     * @return ?string 同 chatCreation
     */
    public function chatCreationFromMessage(int $creationId, int $messageId): ?string {
        $user = $this->users->getUser();
        if ($user === null) {
            throw new \RuntimeException('Authentication required');
        }
        $context = $this->creations->getContext($creationId, $user->getUID());
        $upTo = [];
        $said = null;
        foreach ($context['messages'] as $item) {
            $upTo[] = $item;
            if ($item['id'] === $messageId) {
                $said = $item;
                break;
            }
        }
        if ($said === null || $said['role'] !== 'user') {
            throw new \InvalidArgumentException('Message not found');
        }
        $context['messages'] = $upTo;
        return $this->chatCreation($creationId, (string)$said['content'], $context, $messageId);
    }

    /**
     * Tool implementation: leave_trace（只在被叫进一片空间时可用）
     */
    private function toolLeaveTrace(array $args): array {
        if ($this->activeSpace === null) {
            return ['success' => false, 'error' => 'leave_trace 只能在一片空间里用', 'data' => null];
        }
        try {
            $trace = $this->spaces->addTrace($this->activeSpace['type'], $this->activeSpace['id'], [
                'kind' => (string)($args['kind'] ?? 'attempt'),
                'source' => 'AI',
                'author' => $this->activeSpace['author'],
                'content' => (string)($args['content'] ?? ''),
                'about' => $args['about'] ?? null,
                'stopped_at' => $args['stopped_at'] ?? null,
                'touched_reality' => array_key_exists('touched_reality', $args) ? (bool)$args['touched_reality'] : null,
                'act' => $args['act'] ?? null,
                'confidence' => isset($args['confidence']) ? (int)$args['confidence'] : null,
                'ref_id' => isset($args['ref_id']) ? (int)$args['ref_id'] : null,
                'status' => (string)($args['status'] ?? 'said'),
            ]);
            return ['success' => true, 'data' => $trace, 'error' => null];
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Tool implementation: add_reading（只在被叫进一片空间时可用）
     */
    private function toolAddReading(array $args): array {
        if ($this->activeSpace === null) {
            return ['success' => false, 'error' => 'add_reading 只能在一片空间里用', 'data' => null];
        }
        try {
            $reading = $this->spaces->addReading(
                $this->activeSpace['type'],
                $this->activeSpace['id'],
                $this->activeSpace['author'],
                (string)($args['content'] ?? ''),
                isset($args['confidence']) ? (int)$args['confidence'] : null,
                is_array($args['refs'] ?? null) ? $args['refs'] : [],
            );
            return ['success' => true, 'data' => $reading, 'error' => null];
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Tool implementation: search_traces（只在被叫进一片空间时可用）
     */
    private function toolSearchTraces(array $args): array {
        if ($this->activeSpace === null) {
            return ['success' => false, 'error' => 'search_traces 只能在一片空间里用', 'data' => null];
        }
        $hits = $this->spaces->searchTraces(
            $this->activeSpace['type'],
            $this->activeSpace['id'],
            (string)($args['query'] ?? ''),
            max(1, min(50, (int)($args['limit'] ?? 20))),
        );
        return ['success' => true, 'data' => $hits, 'error' => null, 'metadata' => ['count' => count($hits)]];
    }

    /**
     * Tool implementation: hold_premise（只在被叫进一片空间时可用）
     */
    private function toolHoldPremise(array $args): array {
        if ($this->activeSpace === null) {
            return ['success' => false, 'error' => 'hold_premise 只能在一片空间里用', 'data' => null];
        }
        try {
            $trace = $this->spaces->addTrace($this->activeSpace['type'], $this->activeSpace['id'], [
                'kind' => 'premise',
                'source' => 'AI',
                'author' => $this->activeSpace['author'],
                'content' => (string)($args['content'] ?? ''),
                'governs' => $args['governs'] ?? null,
                'confidence' => isset($args['confidence']) ? (int)$args['confidence'] : null,
            ]);
            return ['success' => true, 'data' => $trace, 'error' => null];
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Tool implementation: hit_premise（只在被叫进一片空间时可用）。记下以后去找押的那位。
     */
    private function toolHitPremise(array $args): array {
        if ($this->activeSpace === null) {
            return ['success' => false, 'error' => 'hit_premise 只能在一片空间里用', 'data' => null];
        }
        $user = $this->users->getUser();
        try {
            $trace = $this->spaces->addTrace($this->activeSpace['type'], $this->activeSpace['id'], [
                'kind' => 'hit',
                'source' => 'AI',
                'author' => $this->activeSpace['author'],
                'content' => (string)($args['content'] ?? ''),
                'ref_id' => isset($args['ref_id']) ? (int)$args['ref_id'] : null,
                'confidence' => isset($args['confidence']) ? (int)$args['confidence'] : null,
            ]);
            $pushback = $user === null ? null : $this->pushBack->afterHit($this->activeSpace['type'], $this->activeSpace['id'], $user->getUID(), $trace);
            return ['success' => true, 'data' => ['hit' => $trace, 'pushback' => $pushback], 'error' => null];
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Tool implementation: answer_hit（只在被叫进一片空间时可用）
     */
    private function toolAnswerHit(array $args): array {
        if ($this->activeSpace === null) {
            return ['success' => false, 'error' => 'answer_hit 只能在一片空间里用', 'data' => null];
        }
        try {
            $result = $this->spaces->answerHit(
                $this->activeSpace['type'],
                $this->activeSpace['id'],
                (int)($args['hit_id'] ?? 0),
                'AI',
                $this->activeSpace['author'],
                (string)($args['act'] ?? ''),
                (string)($args['content'] ?? ''),
                isset($args['confidence']) ? (int)$args['confidence'] : null,
                isset($args['new_premise']) ? (string)$args['new_premise'] : null,
                isset($args['governs']) ? (string)$args['governs'] : null,
            );
            return ['success' => true, 'data' => $result, 'error' => null];
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'data' => null];
        }
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
    private function chatWithTools(string $sessionId, array $messages, ?string $model, ?string $participantId, ?array $tools = null): array {
        $tools ??= array_values($this->toolSchemas);
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

        $system = '这里是 Cognos 的团队对话。' . "\n"
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

        $prompt = '这里是 Cognos 的一个 Activity，用户在这里叫了：' . $participant . "\n"
            . '不要自动创建任务、决定或项目事实。' . "\n"
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

        return $prompt . '原始来源是材料，不是已经确认的事实。';
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
            // Hermes 跑在本机（host.docker.internal），Nextcloud 默认不让连内网地址
            'nextcloud' => ['allow_local_address' => true],
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
