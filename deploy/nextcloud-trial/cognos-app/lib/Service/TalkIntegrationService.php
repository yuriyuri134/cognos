<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Talk API 集成服务
 * 深度集成 Nextcloud Talk 应用的 API
 */
class TalkIntegrationService {
    private const API_BASE_PATH = '/ocs/v2.php/apps/spreed/api/v4';

    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * 列出用户的所有对话
     */
    public function listConversations(): array {
        try {
            $response = $this->makeRequest('GET', '/room');
            return $response['ocs']['data'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to list Talk conversations', [
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to fetch conversations: ' . $e->getMessage());
        }
    }

    /**
     * 获取对话详情
     */
    public function getConversation(string $token): array {
        try {
            $response = $this->makeRequest('GET', "/room/$token");
            return $response['ocs']['data'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get Talk conversation', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to fetch conversation: ' . $e->getMessage());
        }
    }

    /**
     * 获取对话消息
     *
     * @param string $token 对话 token
     * @param int $limit 消息数量限制
     * @param int $lastKnownMessageId 最后已知消息 ID（用于分页）
     */
    public function getMessages(string $token, int $limit = 100, int $lastKnownMessageId = 0): array {
        try {
            $params = [
                'limit' => $limit,
                'lookIntoFuture' => 0,
            ];

            if ($lastKnownMessageId > 0) {
                $params['lastKnownMessageId'] = $lastKnownMessageId;
            }

            $response = $this->makeRequest('GET', "/chat/$token", $params);
            return $response['ocs']['data'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get Talk messages', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to fetch messages: ' . $e->getMessage());
        }
    }

    /**
     * 发送消息到对话
     *
     * @param string $token 对话 token
     * @param string $message 消息内容
     * @param string $replyTo 回复的消息 ID（可选）
     */
    public function sendMessage(string $token, string $message, ?string $replyTo = null): array {
        try {
            $params = [
                'message' => $message,
            ];

            if ($replyTo !== null) {
                $params['replyTo'] = $replyTo;
            }

            $response = $this->makeRequest('POST', "/chat/$token", $params);
            return $response['ocs']['data'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to send Talk message', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to send message: ' . $e->getMessage());
        }
    }

    /**
     * 创建新对话
     *
     * @param int $roomType 对话类型（1=one-to-one, 2=group, 3=public）
     * @param string $roomName 对话名称
     */
    public function createConversation(int $roomType, string $roomName): array {
        try {
            $params = [
                'roomType' => $roomType,
                'roomName' => $roomName,
            ];

            $response = $this->makeRequest('POST', '/room', $params);
            return $response['ocs']['data'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to create Talk conversation', [
                'roomName' => $roomName,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to create conversation: ' . $e->getMessage());
        }
    }

    /**
     * 搜索消息
     *
     * @param string $token 对话 token
     * @param string $query 搜索关键词
     */
    public function searchMessages(string $token, string $query): array {
        try {
            // Talk API 可能不直接支持搜索，这里先返回空
            // TODO: 实现客户端搜索或等待 Talk API 支持
            $this->logger->warning('Talk message search not yet implemented');
            return [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to search Talk messages', [
                'token' => $token,
                'query' => $query,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * 获取对话参与者列表
     */
    public function getParticipants(string $token): array {
        try {
            $response = $this->makeRequest('GET', "/room/$token/participants");
            return $response['ocs']['data'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get Talk participants', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to fetch participants: ' . $e->getMessage());
        }
    }

    /**
     * 执行 Talk API 请求
     *
     * @param string $method HTTP 方法
     * @param string $endpoint API 端点
     * @param array $params 请求参数
     */
    private function makeRequest(string $method, string $endpoint, array $params = []): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \RuntimeException('User not authenticated');
        }

        $baseUrl = $this->urlGenerator->getAbsoluteURL('');
        $url = $baseUrl . self::API_BASE_PATH . $endpoint;

        $client = $this->clientService->newClient();

        $options = [
            'headers' => [
                'OCS-APIRequest' => 'true',
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ];

        // 使用当前用户的会话（cookie）进行认证
        // 在 Nextcloud 内部调用，会自动携带会话

        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        } elseif ($method === 'POST' && !empty($params)) {
            $options['form_params'] = $params;
        }

        try {
            $response = $client->request($method, $url, $options);
            $body = $response->getBody();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON response from Talk API');
            }

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Talk API request failed', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 获取对话的摘要（用于 Agent 上下文）
     *
     * @param string $token 对话 token
     * @param int $messageCount 要包含的消息数量
     */
    public function getConversationSummary(string $token, int $messageCount = 20): array {
        $conversation = $this->getConversation($token);
        $messages = $this->getMessages($token, $messageCount);
        $participants = $this->getParticipants($token);

        return [
            'conversation' => [
                'name' => $conversation['displayName'] ?? 'Unknown',
                'type' => $conversation['type'] ?? 0,
                'token' => $token,
            ],
            'participants' => array_map(function($p) {
                return [
                    'userId' => $p['actorId'] ?? '',
                    'displayName' => $p['displayName'] ?? '',
                    'participantType' => $p['participantType'] ?? 0,
                ];
            }, $participants),
            'recentMessages' => array_map(function($m) {
                return [
                    'id' => $m['id'] ?? 0,
                    'actor' => $m['actorDisplayName'] ?? '',
                    'message' => $m['message'] ?? '',
                    'timestamp' => $m['timestamp'] ?? 0,
                ];
            }, $messages),
        ];
    }
}
