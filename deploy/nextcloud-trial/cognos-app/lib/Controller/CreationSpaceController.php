<?php

declare(strict_types=1);

namespace OCA\Cognos\Controller;

use OCA\Cognos\Service\CreationSpaceService;
use OCA\Cognos\Service\HermesAgentService;
use OCA\Cognos\Service\SpaceEntryService;
use OCA\Cognos\Service\SpaceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
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

            if (empty($message)) {
                return new DataResponse(['message' => 'Message is required'], 422);
            }

            if ($this->creations->get($id, $user->getUID()) === null) {
                return new DataResponse(['message' => 'Creation not found'], 404);
            }

            // Save user message
            $userMessage = $this->creations->addMessage($id, $user->getUID(), 'user', $message);

            // Get context for Agent
            $context = $this->creations->getContext($id, $user->getUID());

            // Get Agent response
            $agentResponse = $this->hermes->chatCreation($id, $message, $context);

            // Save Agent message
            $agentMessage = $this->creations->addMessage($id, $user->getUID(), 'assistant', $agentResponse);

            return new DataResponse([
                'messages' => [$userMessage, $agentMessage]
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
            return new DataResponse(['messages' => $messages]);
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
            ]);
            return new DataResponse(['trace' => $trace], 201);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Failed to add trace'], 500);
        }
    }
}
