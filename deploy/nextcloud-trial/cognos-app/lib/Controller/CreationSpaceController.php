<?php

declare(strict_types=1);

namespace OCA\Cognos\Controller;

use OCA\Cognos\Service\ClaudeBridgeService;
use OCA\Cognos\Service\CreationSpaceService;
use OCA\Cognos\Service\HermesAgentService;
use OCA\Cognos\Service\PushBackService;
use OCA\Cognos\Service\SpaceEntryService;
use OCA\Cognos\Service\SpaceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

final class CreationSpaceController extends Controller {
    public function __construct(
        IRequest $request,
        private IUserSession $users,
        private CreationSpaceService $creations,
        private HermesAgentService $hermes,
        private SpaceService $spaces,
        private SpaceEntryService $entries,
        private PushBackService $pushBack,
        private ClaudeBridgeService $bridge,
        private IConfig $config,
    ) {
        parent::__construct('cognos', $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        Util::addScript('cognos', 'creation-space');
        Util::addStyle('cognos', 'creation-space');
        return new TemplateResponse('cognos', 'creation-space', []);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function detail(int $id): TemplateResponse {
        // space-panel 要先于 creation-detail 载入，后者 init 时会调 window.CognosSpacePanel
        Util::addScript('cognos', 'space-panel');
        Util::addScript('cognos', 'creation-detail');
        Util::addStyle('cognos', 'creation-detail');
        return new TemplateResponse('cognos', 'creation-detail', ['creationId' => $id]);
    }

    #[NoAdminRequired]
    public function list(): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            return new DataResponse(['creations' => $this->creations->list($user->getUID())]);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Failed to fetch creations'], 500);
        }
    }

    #[NoAdminRequired]
    public function create(): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            $body = $this->request->getParams();
            $title = (string)($body['title'] ?? '');
            $initialMessage = (string)($body['initialMessage'] ?? '');

            if (empty($title)) {
                return new DataResponse(['message' => 'Title is required'], 422);
            }

            $creation = $this->creations->create($user->getUID(), $title, $initialMessage);
            return new DataResponse(['creation' => $creation], 201);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Failed to create creation'], 500);
        }
    }

    #[NoAdminRequired]
    public function get(int $id): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            $creation = $this->creations->get($id, $user->getUID());
            if (!$creation) {
                return new DataResponse(['message' => 'Creation not found'], 404);
            }
            return new DataResponse(['creation' => $creation]);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Failed to fetch creation'], 500);
        }
    }

    #[NoAdminRequired]
    public function sendMessage(int $id): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            $body = $this->request->getParams();
            $message = (string)($body['message'] ?? '');
            $recall = (int)($body['recall'] ?? 0);

            if (empty($message) && $recall === 0) {
                return new DataResponse(['message' => 'Message is required'], 422);
            }

            if ($this->creations->get($id, $user->getUID()) === null) {
                return new DataResponse(['message' => 'Creation not found'], 404);
            }

            $context = $this->creations->getContext($id, $user->getUID());
            if ($recall > 0) {
                // 再叫一次：不存新消息，拿你说过的那一句去叫，上下文只到那一句为止
                $upTo = [];
                $userMessage = null;
                foreach ($context['messages'] as $item) {
                    $upTo[] = $item;
                    if ($item['id'] === $recall) {
                        $userMessage = $item;
                        break;
                    }
                }
                if ($userMessage === null || $userMessage['role'] !== 'user') {
                    return new DataResponse(['message' => 'Message not found'], 404);
                }
                $message = (string)$userMessage['content'];
                $context['messages'] = $upTo;
                $userMessage = null;
            } else {
                $userMessage = $this->creations->addMessage($id, $user->getUID(), 'user', $message);
                $context = $this->creations->getContext($id, $user->getUID());
            }
            $saved = $userMessage === null ? [] : [$userMessage];
            $replyTo = $userMessage === null ? $recall : (int)$userMessage['id'];

            try {
                $agentResponse = $this->hermes->chatCreation($id, $message, $context, $replyTo);
            } catch (\RuntimeException $error) {
                if ($error->getCode() !== ClaudeBridgeService::BUSY) {
                    return new DataResponse(['messages' => $saved, 'message' => $error->getMessage()], 502);
                }
                // 桥正忙（比如押的那位正被叫回来面对一下顶）：这句排着，它做完发回回答时接着叫
                $this->pushBack->enqueueMessage('creation', (string)$id, $replyTo);
                return new DataResponse([
                    'messages' => $saved,
                    'pending' => true,
                    'queued' => true,
                    'who' => $this->bridge->subjectFor('creation', (string)$id),
                ]);
            } catch (\Throwable $error) {
                // 原话已经留下了，只是没叫来人：把它和原因一起回给页面，别让人以为没发出去再发一遍
                return new DataResponse([
                    'messages' => $saved,
                    'message' => $error->getMessage(),
                ], 502);
            }

            if ($agentResponse === null) {
                // Claude Code 在本机接着做，做完自己把回答发回来
                return new DataResponse(['messages' => $saved, 'pending' => true, 'who' => $this->bridge->subjectFor('creation', (string)$id)]);
            }

            $agentMessage = $this->creations->addMessage($id, $user->getUID(), 'assistant', $agentResponse, $replyTo);

            return new DataResponse([
                'messages' => array_merge($saved, [$agentMessage])
            ]);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Failed to send message: ' . $error->getMessage()], 500);
        }
    }

    #[NoAdminRequired]
    public function getMessages(int $id): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            $messages = $this->creations->getMessages($id, $user->getUID());
            // 叫来的 agent 还在本机接着做（40 分钟以上就当它没做完、也没发回来）
            $since = (int)$this->config->getAppValue('cognos', 'agent_pending_creation_' . $id, '0');
            $pending = $since > 0 && time() - $since < 40 * 60;
            $who = $pending ? $this->config->getAppValue('cognos', 'agent_pending_who_creation_' . $id, '') : '';
            return new DataResponse(['messages' => $messages, 'pending' => $pending, 'pending_who' => $who !== '' ? $who : null]);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Failed to fetch messages'], 500);
        }
    }

    /**
     * 侧栏「这里」：和被叫进来的 agent（Hermes 或 Claude Code）拿到的是同一份入口。看过就记下这次来的时间。
     */
    #[NoAdminRequired]
    public function space(int $id): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        $space = $this->entries->resolve('creation', (string)$id, $user->getUID());
        if ($space === null) {
            return new DataResponse(['message' => 'Creation not found'], 404);
        }
        try {
            $entry = $this->entries->entry($space, $user->getUID());
            $this->spaces->markSeen('creation', (string)$id, $user->getUID());
            return new DataResponse(['entry' => $entry, 'text' => $this->entries->renderText($entry)]);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Failed to load space: ' . $error->getMessage()], 500);
        }
    }

    /**
     * 交互对象在侧栏里留下一条：自己的原话（意图、事实），现实碰回来的，或者对某一条的确认、否定。
     * 只能写〔原〕和〔世〕；〔AI〕只能由 agent 自己写。
     */
    #[NoAdminRequired]
    public function addSpaceTrace(int $id): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        if ($this->entries->resolve('creation', (string)$id, $user->getUID()) === null) {
            return new DataResponse(['message' => 'Creation not found'], 404);
        }
        $body = $this->request->getParams();
        $source = (string)($body['source'] ?? '原');
        if (!in_array($source, ['原', '世'], true)) {
            return new DataResponse(['message' => 'source must be 原 or 世'], 422);
        }
        if (($body['kind'] ?? '') === 'answer') {
            return new DataResponse(['message' => '面对一下顶，用 /space/hits/{hitId}/answer'], 422);
        }
        try {
            $trace = $this->spaces->addTrace('creation', (string)$id, [
                'kind' => (string)($body['kind'] ?? ''),
                'source' => $source,
                'author' => $user->getUID(),
                'content' => (string)($body['content'] ?? ''),
                'about' => $body['about'] ?? null,
                'act' => $body['act'] ?? null,
                'said_at' => isset($body['said_at']) ? (int)$body['said_at'] : time(),
                'ref_id' => isset($body['ref_id']) ? (int)$body['ref_id'] : null,
                'status' => (string)($body['status'] ?? 'said'),
                'stopped_at' => $body['stopped_at'] ?? null,
                'touched_reality' => array_key_exists('touched_reality', $body) ? (bool)$body['touched_reality'] : null,
                'governs' => $body['governs'] ?? null,
            ]);
            // 顶到一条押着的前提：去找押的那位
            $pushback = $trace['kind'] === 'hit' ? $this->pushBack->afterHit('creation', (string)$id, $user->getUID(), $trace) : null;
            return new DataResponse(['trace' => $trace, 'pushback' => $pushback], 201);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Failed to add trace'], 500);
        }
    }

    /**
     * 交互对象面对顶到自己押着的前提的一下：站住、碎了、或重新切。
     */
    #[NoAdminRequired]
    public function answerHit(int $id, int $hitId): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        if ($this->entries->resolve('creation', (string)$id, $user->getUID()) === null) {
            return new DataResponse(['message' => 'Creation not found'], 404);
        }
        $body = $this->request->getParams();
        try {
            $result = $this->spaces->answerHit(
                'creation',
                (string)$id,
                $hitId,
                '原',
                $user->getUID(),
                (string)($body['act'] ?? ''),
                (string)($body['content'] ?? ''),
                null,
                isset($body['new_premise']) ? (string)$body['new_premise'] : null,
                isset($body['governs']) ? (string)$body['governs'] : null,
            );
            return new DataResponse($result, 201);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        }
    }
}
