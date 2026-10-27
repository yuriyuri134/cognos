<?php

declare(strict_types=1);

namespace OCA\Cognos\Controller;

use OCA\Cognos\Service\ClaudeBridgeService;
use OCA\Cognos\Service\ContinuationService;
use OCA\Cognos\Service\CreationSpaceService;
use OCA\Cognos\Service\HermesAgentService;
use OCA\Cognos\Service\PushBackService;
use OCA\Cognos\Service\SpaceEntryService;
use OCA\Cognos\Service\SpaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * 外面的 agent（本机的 Claude Code、Codex……）走进一片空间用的接口，和 Hermes 在里面用的是同一套动作：
 * 进来（拿到入口）、留下尝试、留下读法、在痕迹里找，押上前提、顶别人押着的、面对顶到自己的。
 *
 * agent 用交互对象的账号（应用密码）来调，但它写下的东西一律记为〔AI〕和它报的名字（subject），
 * 不能代替交互对象写原话。
 */
final class SpaceApiController extends OCSController {
    public function __construct(
        IRequest $request,
        private IUserSession $users,
        private CreationSpaceService $creations,
        private SpaceService $spaces,
        private SpaceEntryService $entries,
        private PushBackService $pushBack,
        private ContinuationService $continuation,
        private HermesAgentService $hermes,
        private IConfig $config,
    ) {
        parent::__construct('cognos', $request);
    }

    #[NoAdminRequired]
    public function list(): DataResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        $spaces = array_map(static fn(array $c): array => [
            'type' => 'creation',
            'id' => (string)$c['id'],
            'title' => (string)$c['title'],
            'updated_at' => (int)$c['updated_at'],
        ], $this->creations->list($uid));
        return new DataResponse(['spaces' => $spaces]);
    }

    #[NoAdminRequired]
    public function enter(string $spaceType, string $spaceId, string $subject = '', string $message = ''): DataResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        $space = $this->entries->resolve($spaceType, $spaceId, $uid);
        if ($space === null) {
            return new DataResponse(['message' => 'Space not found'], 404);
        }
        $subject = $this->subject($subject);
        $call = trim($message) !== '' ? ['caller' => $uid, 'message' => trim($message)] : null;
        $entry = $this->entries->entry($space, $subject, $call, [
            'search_traces：在这片空间留下的发生里找。',
            'leave_trace：把你这次做的尝试，或你推出来的东西留下。来源固定记为〔AI〕和你报的名字。',
            'add_reading：留下你对这里的读法。你之前的读法不会被删，只会被标为已替换。',
            '你的其他能力（读文件、跑命令、上网）照你自己那边的权限，这片空间不知道，请在开头两三行里写明。',
        ]);
        $text = $this->entries->renderText($entry);
        if ($call !== null) {
            // 外面进来的没有对话记录，把这次的原话直接放进入口
            $text .= "\n\n## 这次叫你来的原话\n" . $call['message'];
        }
        $this->spaces->markSeen($spaceType, $spaceId, $subject);
        return new DataResponse(['subject' => $subject, 'text' => $text, 'entry' => $entry]);
    }

    #[NoAdminRequired]
    public function searchTraces(string $spaceType, string $spaceId, string $q = '', int $limit = 20): DataResponse {
        $uid = $this->uid();
        if ($uid === null || $this->entries->resolve($spaceType, $spaceId, $uid) === null) {
            return new DataResponse(['message' => 'Space not found'], 404);
        }
        return new DataResponse(['traces' => $this->spaces->searchTraces($spaceType, $spaceId, $q, max(1, min(50, $limit)))]);
    }

    #[NoAdminRequired]
    public function addTrace(string $spaceType, string $spaceId): DataResponse {
        $uid = $this->uid();
        if ($uid === null || $this->entries->resolve($spaceType, $spaceId, $uid) === null) {
            return new DataResponse(['message' => 'Space not found'], 404);
        }
        $body = $this->body();
        if (($body['kind'] ?? '') === 'answer') {
            return new DataResponse(['message' => '面对一下顶，用 answer_hit（/hits/{hitId}/answer）'], 422);
        }
        if (($body['kind'] ?? '') === 'reality') {
            return new DataResponse(['message' => '检查结果只能由桥在跑完检查后带回来，agent 不能自己写'], 422);
        }
        try {
            $trace = $this->spaces->addTrace($spaceType, $spaceId, [
                'kind' => (string)($body['kind'] ?? 'attempt'),
                'source' => 'AI',
                'author' => $this->subject((string)($body['subject'] ?? '')),
                'content' => (string)($body['content'] ?? ''),
                'about' => $body['about'] ?? null,
                'stopped_at' => $body['stopped_at'] ?? null,
                'touched_reality' => array_key_exists('touched_reality', $body) ? (bool)$body['touched_reality'] : null,
                'act' => $body['act'] ?? null,
                'confidence' => isset($body['confidence']) ? (int)$body['confidence'] : null,
                'ref_id' => isset($body['ref_id']) ? (int)$body['ref_id'] : null,
                'status' => (string)($body['status'] ?? 'said'),
                'governs' => $body['governs'] ?? null,
            ]);
            // 顶到一条押着的前提：去找押的那位
            $pushback = $trace['kind'] === 'hit' ? $this->pushBack->afterHit($spaceType, $spaceId, $uid, $trace) : null;
            return new DataResponse(['trace' => $trace, 'pushback' => $pushback], 201);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        }
    }

    #[NoAdminRequired]
    public function addReading(string $spaceType, string $spaceId): DataResponse {
        $uid = $this->uid();
        if ($uid === null || $this->entries->resolve($spaceType, $spaceId, $uid) === null) {
            return new DataResponse(['message' => 'Space not found'], 404);
        }
        $body = $this->body();
        try {
            $reading = $this->spaces->addReading(
                $spaceType,
                $spaceId,
                $this->subject((string)($body['subject'] ?? '')),
                (string)($body['content'] ?? ''),
                isset($body['confidence']) ? (int)$body['confidence'] : null,
                is_array($body['refs'] ?? null) ? $body['refs'] : [],
            );
            return new DataResponse(['reading' => $reading], 201);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        }
    }

    /**
     * 押着前提的那位面对顶到它的一下：站住、碎了、或重新切。只有押的那位（subject）能面对。
     */
    #[NoAdminRequired]
    public function answerHit(string $spaceType, string $spaceId, int $hitId): DataResponse {
        $uid = $this->uid();
        if ($uid === null || $this->entries->resolve($spaceType, $spaceId, $uid) === null) {
            return new DataResponse(['message' => 'Space not found'], 404);
        }
        $body = $this->body();
        try {
            $result = $this->spaces->answerHit(
                $spaceType,
                $spaceId,
                $hitId,
                'AI',
                $this->subject((string)($body['subject'] ?? '')),
                (string)($body['act'] ?? ''),
                (string)($body['content'] ?? ''),
                isset($body['confidence']) ? (int)$body['confidence'] : null,
                isset($body['new_premise']) ? (string)$body['new_premise'] : null,
                isset($body['governs']) ? (string)$body['governs'] : null,
            );
            return new DataResponse($result, 201);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        }
    }

    /**
     * 被叫进来、在本机接着做的 agent（claude_bridge.py）做完以后，把回答发回这段对话。
     * 它是从一件没完的叫起来的（open_id），先记下检查和它停在哪（ContinuationService::afterRun）。
     * 然后接着叫下一位：排着的顶和交互对象的话先走，都没有，就从还拉着的那件没完的接着走。
     */
    #[NoAdminRequired]
    public function addMessage(string $spaceType, string $spaceId): DataResponse {
        $uid = $this->uid();
        if ($uid === null || $spaceType !== 'creation' || $this->creations->get((int)$spaceId, $uid) === null) {
            return new DataResponse(['message' => 'Space not found'], 404);
        }
        $body = $this->body();
        $content = trim((string)($body['content'] ?? ''));
        if ($content === '') {
            return new DataResponse(['message' => 'content is required'], 422);
        }
        // 回的是对话里哪一句；被顶叫回来的回答是 0（不回任何一句）
        $replyTo = isset($body['reply_to']) && $body['reply_to'] !== null ? (int)$body['reply_to'] : null;
        $message = $this->creations->addMessage((int)$spaceId, $uid, 'assistant', $content, $replyTo);
        $this->config->deleteAppValue('cognos', 'agent_pending_creation_' . (int)$spaceId);
        $this->config->deleteAppValue('cognos', 'agent_pending_who_creation_' . (int)$spaceId);
        $openId = isset($body['open_id']) && $body['open_id'] !== null ? (int)$body['open_id'] : null;
        if ($openId !== null) {
            $checks = is_array($body['checks'] ?? null)
                ? ['ok' => (bool)($body['checks']['ok'] ?? false), 'text' => trim((string)($body['checks']['text'] ?? ''))]
                : null;
            try {
                $this->continuation->afterRun($spaceType, $spaceId, $openId, (int)($body['run_started'] ?? 0),
                    isset($body['branch']) ? (string)$body['branch'] : null, $checks, (bool)($body['timed_out'] ?? false));
            } catch (\InvalidArgumentException $error) {
                // 那件没完的不在了：回答照样留下，只是不接着走
            }
        }
        // 是被叫回来面对一下顶的：做完了，面对没面对，记下来
        if (isset($body['hit_id']) && (int)$body['hit_id'] > 0) {
            // changed_files：在仓库副本里做的，桥带回这次分支上改了哪些文件；不在仓库里做的没有这一项
            $changed = isset($body['changed_files']) && is_array($body['changed_files'])
                ? array_values(array_map('strval', array_filter($body['changed_files'], 'is_scalar')))
                : null;
            $this->pushBack->afterReturn($spaceType, $spaceId, $this->subject((string)($body['subject'] ?? '')), (int)$body['hit_id'], $changed);
        }
        $this->callNext($spaceType, $spaceId, $uid, $openId);
        return new DataResponse(['message' => $message], 201);
    }

    /** 这一位做完了：排着的下一件接着叫（一下顶，或者交互对象在它忙时说的一句）；都没有，就从还拉着的没完的接着走。 */
    private function callNext(string $spaceType, string $spaceId, string $uid, ?int $justRan): void {
        $next = $this->pushBack->drain($spaceType, $spaceId, $uid);
        if ($next === null) {
            $this->continuation->resume($spaceType, $spaceId, $uid, $justRan);
            return;
        }
        [$what, $messageId] = $next;
        if ($what !== 'msg') {
            return;
        }
        try {
            $answer = $this->hermes->chatCreationFromMessage((int)$spaceId, $messageId);
            if ($answer !== null) {
                // 叫来的是 Hermes：回答当场就有，没人占着桥，没完的接着走
                $this->creations->addMessage((int)$spaceId, $uid, 'assistant', $answer, $messageId);
                $this->continuation->resume($spaceType, $spaceId, $uid, $justRan);
            }
        } catch (\Throwable $error) {
            if ($error instanceof \RuntimeException && $error->getCode() === ClaudeBridgeService::BUSY) {
                $this->pushBack->enqueueMessage($spaceType, $spaceId, $messageId, true);
            }
            // 别的原因叫不上：那句还在对话里、没人回，页面上能「再叫一次」
        }
    }

    private function uid(): ?string {
        return $this->users->getUser()?->getUID();
    }

    private function subject(string $subject): string {
        $subject = trim($subject);
        return $subject === '' ? '未署名的 agent' : mb_substr($subject, 0, 128);
    }

    private function body(): array {
        // Nextcloud 已把 JSON 请求体解析进参数里（Content-Type: application/json）
        return $this->request->getParams();
    }
}
