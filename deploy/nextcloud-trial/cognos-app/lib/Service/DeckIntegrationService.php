<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Deck API 集成服务
 * 深度集成 Nextcloud Deck 应用的 API
 */
class DeckIntegrationService {
    private const API_BASE_PATH = '/index.php/apps/deck/api/v1.0';

    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * 列出用户的所有看板
     */
    public function listBoards(): array {
        try {
            $response = $this->makeRequest('GET', '/boards');
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Failed to list Deck boards', [
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to fetch boards: ' . $e->getMessage());
        }
    }

    /**
     * 获取看板详情（包含 stacks 和 cards）
     */
    public function getBoard(int $boardId): array {
        try {
            $response = $this->makeRequest('GET', "/boards/$boardId");
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get Deck board', [
                'boardId' => $boardId,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to fetch board: ' . $e->getMessage());
        }
    }

    /**
     * 获取看板的所有 stacks（列）
     */
    public function getStacks(int $boardId): array {
        try {
            $response = $this->makeRequest('GET', "/boards/$boardId/stacks");
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get Deck stacks', [
                'boardId' => $boardId,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to fetch stacks: ' . $e->getMessage());
        }
    }

    /**
     * 获取 stack 中的所有卡片
     */
    public function getCards(int $boardId, int $stackId): array {
        try {
            $response = $this->makeRequest('GET', "/boards/$boardId/stacks/$stackId");
            return $response['cards'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get Deck cards', [
                'boardId' => $boardId,
                'stackId' => $stackId,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to fetch cards: ' . $e->getMessage());
        }
    }

    /**
     * 创建新卡片
     */
    public function createCard(int $boardId, int $stackId, string $title, ?string $description = null): array {
        try {
            $data = [
                'title' => $title,
                'type' => 'plain',
                'order' => 999,
            ];

            if ($description !== null) {
                $data['description'] = $description;
            }

            $response = $this->makeRequest('POST', "/boards/$boardId/stacks/$stackId/cards", $data);
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Failed to create Deck card', [
                'boardId' => $boardId,
                'stackId' => $stackId,
                'title' => $title,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to create card: ' . $e->getMessage());
        }
    }

    /**
     * 更新卡片
     */
    public function updateCard(int $boardId, int $stackId, int $cardId, array $updates): array {
        try {
            $response = $this->makeRequest('PUT', "/boards/$boardId/stacks/$stackId/cards/$cardId", $updates);
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Failed to update Deck card', [
                'boardId' => $boardId,
                'cardId' => $cardId,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to update card: ' . $e->getMessage());
        }
    }

    /**
     * 移动卡片到另一个 stack
     */
    public function moveCard(int $boardId, int $sourceStackId, int $cardId, int $targetStackId, int $order = 0): array {
        try {
            $data = [
                'stackId' => $targetStackId,
                'order' => $order,
            ];

            $response = $this->makeRequest('PUT', "/boards/$boardId/stacks/$sourceStackId/cards/$cardId/reorder", $data);
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Failed to move Deck card', [
                'boardId' => $boardId,
                'cardId' => $cardId,
                'targetStackId' => $targetStackId,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to move card: ' . $e->getMessage());
        }
    }

    /**
     * 删除卡片
     */
    public function deleteCard(int $boardId, int $stackId, int $cardId): void {
        try {
            $this->makeRequest('DELETE', "/boards/$boardId/stacks/$stackId/cards/$cardId");
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete Deck card', [
                'boardId' => $boardId,
                'cardId' => $cardId,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to delete card: ' . $e->getMessage());
        }
    }

    /**
     * 为卡片添加标签
     */
    public function assignLabel(int $boardId, int $stackId, int $cardId, int $labelId): array {
        try {
            $response = $this->makeRequest('PUT', "/boards/$boardId/stacks/$stackId/cards/$cardId/assignLabel", [
                'labelId' => $labelId
            ]);
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Failed to assign label to Deck card', [
                'cardId' => $cardId,
                'labelId' => $labelId,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to assign label: ' . $e->getMessage());
        }
    }

    /**
     * 执行 Deck API 请求
     */
    private function makeRequest(string $method, string $endpoint, array $data = []): mixed {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \RuntimeException('User not authenticated');
        }

        $baseUrl = $this->urlGenerator->getAbsoluteURL('');
        $url = $baseUrl . self::API_BASE_PATH . $endpoint;

        $client = $this->clientService->newClient();

        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ];

        if (!empty($data)) {
            if ($method === 'GET') {
                $url .= '?' . http_build_query($data);
            } else {
                $options['json'] = $data;
            }
        }

        try {
            $response = $client->request($method, $url, $options);
            $body = $response->getBody();

            if ($method === 'DELETE') {
                return null;
            }

            $result = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON response from Deck API');
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Deck API request failed', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 获取看板摘要（用于 Agent 上下文）
     */
    public function getBoardSummary(int $boardId): array {
        $board = $this->getBoard($boardId);
        $stacks = $this->getStacks($boardId);

        $summary = [
            'board' => [
                'id' => $board['id'] ?? 0,
                'title' => $board['title'] ?? 'Unknown',
                'color' => $board['color'] ?? '',
            ],
            'stacks' => [],
            'stats' => [
                'totalStacks' => count($stacks),
                'totalCards' => 0,
            ],
        ];

        foreach ($stacks as $stack) {
            $cards = $this->getCards($boardId, $stack['id']);
            $summary['stacks'][] = [
                'id' => $stack['id'],
                'title' => $stack['title'],
                'order' => $stack['order'] ?? 0,
                'cardCount' => count($cards),
                'cards' => array_map(function($card) {
                    return [
                        'id' => $card['id'],
                        'title' => $card['title'],
                        'description' => $card['description'] ?? '',
                        'order' => $card['order'] ?? 0,
                        'duedate' => $card['duedate'] ?? null,
                    ];
                }, $cards),
            ];
            $summary['stats']['totalCards'] += count($cards);
        }

        return $summary;
    }
}
