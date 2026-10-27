<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;

/**
 * 请本机的 Claude Code 进来（经 tools/cognos-space-mcp/claude_bridge.py）。
 * 桥收下就回 202，它在本机接着做，做完经 SpaceApiController::addMessage 把回答发回这段对话。
 * 它在空间里留东西走 MCP（同一套 OCS 接口）。
 *
 * 叫它的有两种：交互对象在对话里叫（HermesAgentService::chatCreation），
 * 和它押着的前提被顶到（PushBackService）。
 */
final class ClaudeBridgeService {
    /** 桥正忙（同一片空间同时只做一件事）时抛出的异常码 */
    public const BUSY = 409;

    public function __construct(
        private IClientService $http,
        private IConfig $config,
    ) {
    }

    public function configured(): bool {
        return $this->config->getAppValue('cognos', 'claude_bridge_token', '') !== '';
    }

    /** Cognos 自己的那片空间：在这里押着的是 Cognos 的架构，重新切就是改 Cognos 自己的代码。 */
    public function isSelf(string $spaceType, string $spaceId): bool {
        return $spaceType === 'creation' && $spaceId !== ''
            && $this->config->getAppValue('cognos', 'self_creation_id', '') === $spaceId;
    }

    /** 被叫进来的 Claude Code 这次能用什么、够不着什么，放进入口。 */
    public function canDo(string $spaceType, string $spaceId): array {
        $lines = [
            'search_traces：在这片空间留下的发生里找。',
            'leave_trace：把你这次做的尝试，或你推出来的东西留下。来源固定记为〔AI〕和你的名字。',
            'add_reading：留下你对这里的读法。你之前的读法不会被删，只会被标为已替换。',
            'hold_premise：把你押着的一条前提留下，连上它管着的代码或做法（governs）。',
            'hit_premise：顶别人押着的一条（ref_id 指过去），写清是什么顶回来的。押的那位会被叫回来面对。',
            'answer_hit：面对顶到你押着的一下：站住、碎了、或重新切（重新切要写出新的前提）。',
            '这些工具都要填 space_id：这里是 ' . $spaceId . '，space_type 是 ' . $spaceType . '。',
        ];
        if ($this->isSelf($spaceType, $spaceId)) {
            $lines[] = '这里是 Cognos 自己。你的工作目录是 Cognos 仓库的一份副本，在一条新分支上；重新切一条前提，就去改它 governs 里写的那段代码。改完不用提交，桥会提交到这条分支，由交互对象来合。';
        } else {
            $lines[] = '够不着的：笔记（这次没接给你）、交互对象电脑上工作目录以外的文件；Cognos 里别的空间这次也看不到。';
        }
        return $lines;
    }

    /**
     * @param array<string, mixed> $extra 额外带给桥的字段（hit_id……）
     * @throws \RuntimeException 没接下；桥正忙时异常码是 self::BUSY
     */
    public function send(string $spaceType, string $spaceId, string $subject, string $system, array $history, string $message, string $caller, array $extra = []): void {
        $url = $this->config->getAppValue('cognos', 'claude_bridge_url', 'http://host.docker.internal:8643/chat');
        $token = $this->config->getAppValue('cognos', 'claude_bridge_token', '');
        if ($token === '') {
            throw new \RuntimeException('Claude Code 桥没有配置口令（occ config:app:set cognos claude_bridge_token）');
        }

        try {
            $response = $this->http->newClient()->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Cognos-Bridge-Token' => $token,
                ],
                'body' => json_encode(array_merge($extra, [
                    'space_type' => $spaceType,
                    'space_id' => $spaceId,
                    'subject' => $subject,
                    'system' => $system,
                    'history' => $history,
                    'message' => $message,
                    'caller' => $caller,
                    // Cognos 自己的空间：桥在仓库副本的新分支上做
                    'repo' => $this->isSelf($spaceType, $spaceId),
                ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'timeout' => 30,
                'http_errors' => false,
                // 桥跑在本机（host.docker.internal），Nextcloud 默认不让连内网地址
                'nextcloud' => ['allow_local_address' => true],
            ]);
        } catch (\Throwable $error) {
            throw new \RuntimeException('连不上本机的 Claude Code 桥（' . $url . '）：桥可能没起来，在仓库目录跑 python tools\\cognos-space-mcp\\claude_bridge.py');
        }

        $status = $response->getStatusCode();
        if ($status === 202) {
            if ($spaceType === 'creation') {
                // 页面刷新后靠它知道还有人在做；回答发回来时（SpaceApiController::addMessage）清掉
                $this->config->setAppValue('cognos', 'agent_pending_creation_' . $spaceId, (string)time());
            }
            return;
        }
        $body = json_decode((string)$response->getBody(), true);
        $reason = is_array($body) ? (string)($body['error'] ?? '') : '';
        throw new \RuntimeException('Claude Code 没接下这次' . ($reason !== '' ? '：' . $reason : '（HTTP ' . $status . '）'), $status === self::BUSY ? self::BUSY : 0);
    }
}
