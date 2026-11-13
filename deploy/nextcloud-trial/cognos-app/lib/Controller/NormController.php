<?php

declare(strict_types=1);

namespace OCA\Cognos\Controller;

use OCA\Cognos\Service\CreationSpaceService;
use OCA\Cognos\Service\NormService;
use OCA\Cognos\Service\ReaderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

/**
 * Norm（示范空间）：Norm 自己的对话，和一个个空间。
 * 空间页：中间是这个空间的对话，上面是你不在时发生了什么、只有你能定的一件，右边是它用的几块；
 * 读者回头读过、觉得它变了样，最上面给几种接着活的办法。
 */
final class NormController extends Controller {
    public function __construct(
        IRequest $request,
        private IUserSession $users,
        private NormService $norm,
        private ReaderService $readers,
        private CreationSpaceService $creations,
        private IConfig $config,
        private \OCA\Cognos\Service\ClaudeBridgeService $bridge,
    ) {
        parent::__construct('cognos', $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function page(): TemplateResponse {
        Util::addScript('cognos', 'agent-pick');
        Util::addScript('cognos', 'chat-box');
        Util::addScript('cognos', 'norm-nav');
        Util::addScript('cognos', 'norm');
        Util::addStyle('cognos', 'norm');
        return new TemplateResponse('cognos', 'norm');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function projectPage(string $projectId): TemplateResponse {
        Util::addScript('cognos', 'agent-pick');
        Util::addScript('cognos', 'chat-box');
        Util::addScript('cognos', 'norm-nav');
        Util::addScript('cognos', 'norm-project');
        Util::addStyle('cognos', 'norm');
        return new TemplateResponse('cognos', 'norm-project', ['projectId' => $projectId]);
    }

    #[NoAdminRequired]
    public function data(): DataResponse {
        $uid = $this->uid();
        return new DataResponse([
            'conversation_id' => $this->norm->conversationId($uid),
            'projects' => $this->norm->projects($uid),
            'boards' => array_map(static fn(array $b): string => $b['about'], NormService::BOARDS),
            'parts' => NormService::PARTS,
        ]);
    }

    /** $history=1 时连历史（各段和全部条目）一起给：页面打开「历史」那一栏时才要。 */
    #[NoAdminRequired]
    public function project(string $projectId, int $arrive = 0, int $history = 0): DataResponse {
        $uid = $this->uid();
        $space = $this->norm->project($uid, $projectId);
        if ($space === null) {
            return new DataResponse(['message' => '没有这个空间'], 404);
        }
        $conversation = $space['state'] === 'active' ? $this->norm->spaceConversation($uid, $projectId) : $space['conversation_id'];
        $drift = $this->readers->latest($uid, 'drift', null, $projectId);
        return new DataResponse([
            'project' => $space,
            'conversation_id' => $conversation,
            // 第一次打开时才算「你回来了」：之后刷新不再把发生的记成看过
            'arrive' => $arrive === 1 ? $this->norm->arrive($uid, $projectId) : null,
            'events' => $this->norm->events($uid, $projectId, null, 30),
            'links' => $this->norm->links($uid, $projectId),
            'messages' => $conversation ? count($this->creations->getMessages((int)$conversation, $uid)) : 0,
            'drift' => $drift,
            'drift_done' => $drift !== null && $drift['state'] === 'done' ? $drift : $this->readers->lastDone($uid, 'drift', null, $projectId),
            // 还有多少原话没读：正在读的时候不算（按钮那时是灰的，省得每 8 秒把全部原话翻一遍）
            'unread' => $drift !== null && $drift['state'] === 'reading' ? null : $this->readers->unread($uid, $space),
            'history' => $history === 1 ? $this->norm->history($uid, $projectId) : null,
            'spaces' => $this->norm->projects($uid),
            'versions' => $this->norm->versions($uid, $projectId),
            'parts' => NormService::PARTS,
            'boards' => array_map(static fn(array $b): string => $b['about'], NormService::BOARDS),
        ]);
    }

    /** 改「agent 在这里怎么工作」那段话。 */
    #[NoAdminRequired]
    public function setWay(string $projectId, array $way = []): DataResponse {
        try {
            $this->norm->setWay($this->uid(), $projectId, $way);
            $this->norm->addEvent($this->uid(), $projectId, 'happened', '你改了 agent 在这里的做法。', [], '你');
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], 422);
        }
        return new DataResponse(['project' => $this->norm->project($this->uid(), $projectId)]);
    }

    /** 撤回这个空间：它不再在 Norm 里，连着的 Initium 对话还在。 */
    #[NoAdminRequired]
    public function withdraw(string $projectId): DataResponse {
        try {
            $this->norm->setState($this->uid(), $projectId, 'withdrawn');
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], 404);
        }
        return new DataResponse(['ok' => true]);
    }

    /** 把一块换回改之前的某一版。 */
    #[NoAdminRequired]
    public function restorePart(string $projectId, int $version = 0): DataResponse {
        try {
            $part = $this->norm->restorePart($this->uid(), $projectId, $version);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], 422);
        }
        return new DataResponse(['part' => $part, 'project' => $this->norm->project($this->uid(), $projectId)]);
    }

    /** 撤回了、退回 Initium 的空间，放回 Norm。 */
    #[NoAdminRequired]
    public function restore(string $projectId): DataResponse {
        $uid = $this->uid();
        $space = $this->norm->project($uid, $projectId);
        if ($space === null) {
            return new DataResponse(['message' => '没有这个空间'], 404);
        }
        if ($space['state'] !== 'active') {
            $this->norm->setState($uid, $projectId, 'active');
            $this->norm->addEvent($uid, $projectId, 'happened', '你把它放回了 Norm。', [], '你');
        }
        return new DataResponse(['project' => $this->norm->project($uid, $projectId)]);
    }

    #[NoAdminRequired]
    public function answer(int $eventId, string $answer = ''): DataResponse {
        try {
            $done = $this->norm->answer($this->uid(), $eventId, $answer);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], 422);
        }
        return new DataResponse(['answered' => $done]);
    }

    /** 让这个空间读自己还没读过的原话，接着写历史（读到头时再写总观和自述）；mode=merge 时只把还没归段的条目合起来。 */
    #[NoAdminRequired]
    public function read(string $projectId, string $mode = ''): DataResponse {
        try {
            $r = $this->readers->readSpace($this->uid(), $projectId, true, $mode === 'merge' ? 'merge' : '');
        } catch (\Throwable $e) {
            return new DataResponse(['message' => $e->getMessage()], 422);
        }
        return new DataResponse($r, $r['started'] ? 202 : 200);
    }

    #[NoAdminRequired]
    public function choose(int $readingId, int $option = -1, string $reason = ''): DataResponse {
        try {
            return new DataResponse($this->readers->choose($this->uid(), $readingId, $option, trim($reason)));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], 422);
        }
    }

    // ---- Initium 一段对话的读者 ----

    /** 这段对话里回你的是谁：claude（本机 Claude Code）或 gpt（本机 Codex，用你的 ChatGPT 账号）。两边看到的是同一段全文。 */
    #[NoAdminRequired]
    public function agent(int $id, bool $chatgpt_status = false): DataResponse {
        $uid = $this->uid();
        if ($this->creations->get($id, $uid) === null) {
            return new DataResponse(['message' => '没有这段对话'], 404);
        }
        $agent = $this->config->getUserValue($uid, 'cognos', 'agent_c' . $id, 'claude');
        $data = ['agent' => $agent, 'reasoning_effort' => $this->config->getUserValue($uid, 'cognos', ($agent === 'chatgpt' ? 'chatgpt_effort_c' : 'agent_effort_c') . $id, 'default')];
        if ($agent === 'chatgpt' && !in_array($data['reasoning_effort'], ['default','low','medium','high'], true)) $data['reasoning_effort'] = 'default';
        if ($chatgpt_status) $data['chatgpt'] = $this->bridge->chatgptBrowser($uid);
        return new DataResponse($data);
    }

    #[NoAdminRequired]
    public function setAgent(int $id, string $agent = '', ?string $reasoning_effort = null, bool $connect_chatgpt = false): DataResponse {
        $uid = $this->uid();
        if ($this->creations->get($id, $uid) === null) {
            return new DataResponse(['message' => '没有这段对话'], 404);
        }
        if (!in_array($agent, ['claude', 'gpt', 'chatgpt'], true)) {
            return new DataResponse(['message' => '只能选 Claude、GPT 聊天端或 Codex'], 422);
        }
        $allowed = $agent === 'chatgpt' ? ['default', 'low', 'medium', 'high'] : ['default', 'low', 'medium', 'high', 'xhigh', 'max'];
        if ($reasoning_effort !== null && !in_array($reasoning_effort, $allowed, true)) {
            return new DataResponse(['message' => '无效的思考强度'], 422);
        }
        $this->config->setUserValue($uid, 'cognos', 'agent_c' . $id, $agent);
        if ($reasoning_effort !== null) {
            $this->config->setUserValue($uid, 'cognos', ($agent === 'chatgpt' ? 'chatgpt_effort_c' : 'agent_effort_c') . $id, $reasoning_effort);
        }
        $data = [
            'agent' => $agent,
            'reasoning_effort' => $this->config->getUserValue($uid, 'cognos', ($agent === 'chatgpt' ? 'chatgpt_effort_c' : 'agent_effort_c') . $id, 'default'),
        ];
        if ($agent === 'chatgpt' && !in_array($data['reasoning_effort'], ['default','low','medium','high'], true)) $data['reasoning_effort'] = 'default';
        if ($connect_chatgpt && $agent === 'chatgpt') $data['chatgpt'] = $this->bridge->chatgptBrowser($uid, true);
        return new DataResponse($data);
    }

    #[NoAdminRequired]
    public function creationReaders(int $id): DataResponse {
        $uid = $this->uid();
        if ($this->creations->get($id, $uid) === null) {
            return new DataResponse(['message' => '没有这段对话'], 404);
        }
        $latest = $this->readers->latest($uid, 'initium', $id, null);
        $linked = $this->norm->linkedSpace($uid, $id);
        return new DataResponse([
            'latest' => $latest,
            'done' => $latest !== null && $latest['state'] === 'done' ? $latest : $this->readers->lastDone($uid, 'initium', $id, null),
            'linked' => $linked === null ? null : ['id' => $linked['space']['id'], 'title' => $linked['space']['title'], 'board' => $linked['space']['board']],
            'in_norm' => in_array($id, $this->norm->hiddenConversationIds($uid), true),
        ]);
    }

    #[NoAdminRequired]
    public function readCreation(int $id): DataResponse {
        try {
            $r = $this->readers->readInitium($this->uid(), $id, true);
        } catch (\Throwable $e) {
            return new DataResponse(['message' => $e->getMessage()], 422);
        }
        return new DataResponse($r, $r['started'] ? 202 : 200);
    }

    #[NoAdminRequired]
    public function undo(int $readingId): DataResponse {
        try {
            return new DataResponse(['reading' => $this->readers->undo($this->uid(), $readingId)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], 422);
        }
    }

    private function uid(): string {
        $user = $this->users->getUser();
        if ($user === null) {
            throw new \RuntimeException('Authentication required');
        }
        return $user->getUID();
    }
}
