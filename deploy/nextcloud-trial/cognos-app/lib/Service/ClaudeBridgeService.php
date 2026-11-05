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
 * 叫它的有三种：交互对象在对话里叫（HermesAgentService::chatCreation），
 * 它写下的想法被反对到（PushBackService），和一件没完的接着走（ContinuationService）。
 *
 * 叫之前先记一次运行（RunService），run_id 带给桥；桥发回结果时带着它，Cognos 按 result_id 只收一次。
 */
final class ClaudeBridgeService {
    /** 桥正忙（同一片空间同时只做一件事）时抛出的异常码 */
    public const BUSY = 409;

    private RestoreGuardService $restoreGuard;

    public function __construct(
        private IClientService $http,
        private IConfig $config,
        private RunService $runs,
        private DecisionService $decisions,
        ?RestoreGuardService $restoreGuard = null,
    ) {
        $this->restoreGuard = $restoreGuard ?? new RestoreGuardService($config);
    }

    public function configured(): bool {
        return $this->config->getAppValue('cognos', 'claude_bridge_token', '') !== '';
    }

    /** 在 Cognos 自己的空间里被叫来的叫 Cognos，在那里改的是 Cognos 自己的代码；别处是 Claude Code。 */
    public function subjectFor(string $spaceType, string $spaceId): string {
        return $this->isSelf($spaceType, $spaceId) ? 'Cognos' : 'Claude Code';
    }

    public function isSelf(string $spaceType, string $spaceId): bool {
        return $spaceType === 'creation' && $spaceId !== ''
            && $this->config->getAppValue('cognos', 'self_creation_id', '') === $spaceId;
    }

    /** 被叫进来的 Claude Code 这次能用什么、够不着什么，放进入口。 */
    public function canDo(string $spaceType, string $spaceId): array {
        $lines = [
            'search_traces：在这片空间留下的发生里找。',
            'leave_trace：把你这次做的尝试，或你推出来的东西留下，记在你的名字下。',
            'add_reading：留下你对这里的读法。你之前的读法不会被删，只会被标为已替换。',
            'hold_premise：写下你现在依据的一个想法，可以连上它涉及的代码或做法（governs）。',
            'hit_premise：对别人写下的想法提出反对（ref_id 指过去），写清理由。写那个想法的人会被叫回来。',
            'answer_hit：回应别人对你的想法提出的反对，act 是还成立、放下或改成新的（改成新的要写出新的想法）。',
            'hang_open：把一件还没完的挂在这片空间里（比如你自己的一个尝试，它还有你够不着的后续）。挂着的会拉着下一次，Cognos 会从它接着叫。',
            'stop_here：记下这一次停在哪件没完的哪里：接着（Cognos 会从口子叫下一次）、卡住（等交互对象）、完了。',
            'note_basis：这次真正依据了哪条在生效的决定（照抄原话里的一段）或这一处当前内容的哪一版，想记就记，不记也可以。',
            'adopt：觉得这一处该正式采用某一版时用它；没有交给你采用的权限时，只记成你的提议，由用户定。',
            '这些工具都要填 space_id：这里是 ' . $spaceId . '，space_type 是 ' . $spaceType . '。',
        ];
        if ($this->isSelf($spaceType, $spaceId)) {
            $lines[] = '这里是 Cognos 自己。你的工作目录是 Cognos 仓库的一份副本，在一条新分支上；想法的 governs 写着它涉及哪段代码。改完不用提交，桥会提交到这条分支，由交互对象来合。';
        } else {
            $lines[] = '够不着的：笔记（这次没接给你）、交互对象电脑上工作目录以外的文件；Cognos 里别的空间这次也看不到。';
        }
        return $lines;
    }

    /**
     * @param array<string, mixed> $extra 额外带给桥的字段（hit_id……）
     * @return string 这次运行的 id
     * @throws \RuntimeException 没接下；桥正忙时异常码是 self::BUSY
     */
    public function send(string $spaceType, string $spaceId, string $subject, string $system, array $history, string $message, string $caller, array $extra = []): string {
        $this->restoreGuard->assertExecutionAllowed();
        $url = $this->config->getAppValue('cognos', 'claude_bridge_url', 'http://host.docker.internal:8643/chat');
        $codex = ($extra['executor'] ?? null) === 'codex';
        if ($codex) {
            $default = str_ends_with($url, '/chat') ? substr($url, 0, -5) . '/codex' : '';
            $url = $this->config->getAppValue('cognos', 'codex_bridge_url', $default);
            if (!str_ends_with($url, '/codex') || $subject !== 'Codex' || empty($extra['delegation_id']) || empty($extra['caller_uid'])) {
                throw new \RuntimeException('Codex 委托需要有效的本机入口和账号', 422);
            }
        }
        $token = $this->config->getAppValue('cognos', 'claude_bridge_token', '');
        if ($token === '') {
            throw new \RuntimeException('Claude Code 桥没有配置口令（occ config:app:set cognos claude_bridge_token）');
        }

        // 入口里实际给了哪几条在生效的决定：记在运行上，不带给桥
        $decisionIds = array_values(array_filter(array_map('strval', (array)($extra['decision_ids'] ?? []))));
        $queueClaim = isset($extra['queue_claim']) && is_array($extra['queue_claim']) ? $extra['queue_claim'] : null;
        unset($extra['queue_claim']);
        unset($extra['decision_ids']);
        [$targetType, $targetId, $purpose] = $this->target($spaceType, $spaceId, $extra);
        $run = $this->runs->create($spaceType, $spaceId, $targetType, $targetId, $purpose, $subject, (string)($extra['caller_uid'] ?? $caller), [
            'system' => $system,
            'history' => $history,
            'message' => $message,
            'extra' => $extra,
            'decision_ids' => $decisionIds,
            'queue_claim' => $queueClaim,
        ], $queueClaim);
        $this->decisions->recordIncluded($run['id'], $decisionIds);

        try {
            $response = $this->http->newClient()->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Cognos-Bridge-Token' => $token,
                ],
                'body' => json_encode(array_merge($extra, [
                    'run_id' => $run['id'],
                    'space_type' => $spaceType,
                    'space_id' => $spaceId,
                    'subject' => $subject,
                    'system' => $system,
                    'history' => $history,
                    'message' => $message,
                    'caller' => $caller,
                    // Cognos 自己的空间：桥在仓库副本的新分支上做
                    'repo' => !$codex && $this->isSelf($spaceType, $spaceId),
                ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'timeout' => 30,
                'http_errors' => false,
                // 桥跑在本机（host.docker.internal），Nextcloud 默认不让连内网地址
                'nextcloud' => ['allow_local_address' => true],
            ]);
        } catch (\Throwable $error) {
            if (self::neverConnected($error)) {
                // 连都没连上（桥没起来、端口没人听）：请求没发出去，不会开工。记成没能开始，交出去的事可以再交一次。
                $this->runs->failed($run['id'], '连不上桥，没有发出去：' . $error->getMessage());
                throw new \RuntimeException(($codex ? 'Codex' : 'Claude Code') . ' 没接下这次：连不上桥，可能桥没起来。没有发出去，可以再交一次。');
            }
            $this->runs->dispatchUnknown($run['id'], '桥响应丢失，不能确认是否已经开工：' . $error->getMessage());
            throw new \RuntimeException('没有收到桥的确认，工作可能已经开始；已记为状态未知，请先核对，勿重复派发。');
        }

        $status = $response->getStatusCode();
        if ($status === 202) {
            $this->runs->accepted($run['id']);
            if ($spaceType === 'creation') {
                // 页面刷新后靠它知道还有谁在做；回答发回来时（SpaceApiController::addMessage）清掉
                $this->config->setAppValue('cognos', 'agent_pending_creation_' . $spaceId, (string)time());
                $this->config->setAppValue('cognos', 'agent_pending_who_creation_' . $spaceId, $subject);
            }
            return $run['id'];
        }
        $body = json_decode((string)$response->getBody(), true);
        $reason = is_array($body) ? (string)($body['error'] ?? '') : '';
        if (in_array($status, [400, 401, 403, self::BUSY, 422], true) || ($codex && $status === 404)) {
            $this->runs->failed($run['id'], '桥明确拒绝（HTTP ' . $status . '）' . ($reason !== '' ? '：' . $reason : ''), $status === self::BUSY);
            throw new \RuntimeException(($codex ? 'Codex' : 'Claude Code') . ' 没接下这次' . ($reason !== '' ? '：' . $reason : '（HTTP ' . $status . '）'), $status === self::BUSY ? self::BUSY : 0);
        }
        $this->runs->dispatchUnknown($run['id'], '桥返回 HTTP ' . $status . '，不能确认是否已经开工');
        throw new \RuntimeException('桥返回异常回复，工作是否开始尚不确定；请先核对，勿重复派发。');
    }

    /**
     * 这次运行挂在哪、为了什么。从位置上交出去的挂在那条委托上，一件没完的接着走挂在那件 open 上，
     * 回应反对挂在那条反对上，其余挂在这段对话上；都不要求先做成任务。
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function target(string $spaceType, string $spaceId, array $extra): array {
        if (!empty($extra['delegation_id'])) {
            return ['delegation', (string)$extra['delegation_id'], '交出去：' . mb_substr(strtok((string)$extra['delegation_ask'], "
") ?: '', 0, 200)];
        }
        if (!empty($extra['open_id'])) {
            return ['open', (string)(int)$extra['open_id'], sprintf('从 #%d 这件没完的接着走', (int)$extra['open_id'])];
        }
        if (!empty($extra['hit_id'])) {
            return ['hit', (string)(int)$extra['hit_id'], sprintf('回应反对 #%d', (int)$extra['hit_id'])];
        }
        $replyTo = (int)($extra['reply_to'] ?? 0);
        return ['conversation', $spaceType . ':' . $spaceId, $replyTo > 0 ? sprintf('回对话里 #%d 那一句', $replyTo) : '在对话里被叫进来'];
    }

    /**
     * 只有 TCP 都没连上时才算「没发出去」：cURL 6（找不到主机）、7（连不上）。
     * 连上以后超时（28）不算，那时请求可能已经到了桥，仍记成不知道。
     */
    private static function neverConnected(\Throwable $error): bool {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof \GuzzleHttp\Exception\ConnectException) {
                $errno = $e->getHandlerContext()['errno'] ?? null;
                if (in_array($errno, [6, 7], true)) { return true; }
                if ($errno === null && preg_match('/cURL error (6|7):/', $e->getMessage())) { return true; }
                return false;
            }
        }
        return (bool)preg_match('/cURL error (6|7):/', $error->getMessage());
    }
}
