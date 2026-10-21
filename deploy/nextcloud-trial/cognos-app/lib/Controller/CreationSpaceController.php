<?php

declare(strict_types=1);

namespace OCA\Cognos\Controller;

use OCA\Cognos\Service\CreationSpaceService;
use OCA\Cognos\Service\HermesAgentService;
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
            $body = json_decode($this->request->getContent(), true) ?? [];
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
            $body = json_decode($this->request->getContent(), true) ?? [];
            $message = (string)($body['message'] ?? '');

            if (empty($message)) {
                return new DataResponse(['message' => 'Message is required'], 422);
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
}
