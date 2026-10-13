<?php

declare(strict_types=1);

namespace OCA\Cognos\Controller;

use OCA\Cognos\Service\ActivityChatException;
use OCA\Cognos\Service\ActivityRequestInProgressException;
use OCA\Cognos\Service\ActivitySourceService;
use OCA\Cognos\Service\HermesAgentService;
use OCA\Cognos\Service\ParticipantRuntimeUnavailableException;
use OCA\Deck\AppInfo\Application as DeckApplication;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class ActivityController extends Controller {
    public function __construct(
        IRequest $request,
        private IUserSession $users,
        private IAppManager $apps,
        private ActivitySourceService $activities,
        private HermesAgentService $hermes,
    ) {
        parent::__construct('cognos', $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function page(): TemplateResponse {
        return new TemplateResponse('cognos', 'activities', []);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listPage(): TemplateResponse {
        return $this->page();
    }

    #[NoAdminRequired]
    public function participants(): DataResponse {
        if (!$this->users->getUser()) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            return new DataResponse(['participants' => $this->activities->participants()]);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Participants unavailable'], 500);
        }
    }

    #[NoAdminRequired]
    public function createManual(): DataResponse {
        if (!$this->users->getUser()) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            $body = $this->payload();
            return new DataResponse(['activity' => $this->activities->createManual(
                (string)($body['title'] ?? ''),
                (string)($body['context'] ?? ''),
                (string)($body['participant_id'] ?? ''),
            )], 201);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Activity could not be created'], 500);
        }
    }

    #[NoAdminRequired]
    public function createFromCard(int $cardId, string $title = ''): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        $body = [];
        try {
            $body = $this->payload();
            $this->apps->loadApp('deck');
            $deck = (new DeckApplication())->getContainer();
            $cards = $deck->get(CardService::class);
            $mapper = $deck->get(CardMapper::class);
            $permissions = $deck->get(PermissionService::class);
            $boardId = $mapper->findBoardId($cardId);
            if ($boardId === null) {
                return new DataResponse(['message' => 'Card not found'], 404);
            }
            $permissions->checkPermission($mapper, $cardId, \OCA\Deck\Db\Acl::PERMISSION_READ);
            $card = $cards->find($cardId);
            $stack = $deck->get(\OCA\Deck\Db\StackMapper::class)->find($card->getStackId());
            $snapshot = [
                'kind' => 'nextcloud_deck_card',
                'board_id' => (int)$boardId,
                'card_id' => $cardId,
                'title' => $card->getTitle(),
                'description' => $card->getDescription(),
                'stack_id' => $stack->getId(),
                'stack_title' => $stack->getTitle(),
                'done' => $card->getDone()?->format(DATE_ATOM),
                'captured_at' => gmdate(DATE_ATOM),
            ];
            return new DataResponse(['activity' => $this->activities->create(
                (int)$boardId,
                $cardId,
                $title !== '' ? $title : (string)($body['title'] ?? '围绕 Deck 卡片继续'),
                $snapshot,
                isset($body['participant_id']) && trim((string)$body['participant_id']) !== ''
                    ? (string)$body['participant_id']
                    : throw new \InvalidArgumentException('请选择一个 Cognos 参与者'),
            )], 201);
        } catch (\OCA\Deck\StatusException|\OCP\AppFramework\Db\DoesNotExistException $error) {
            return new DataResponse(['message' => 'Permission denied'], 403);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Activity creation unavailable'], 500);
        }
    }

    #[NoAdminRequired]
    public function index(): DataResponse {
        if (!$this->users->getUser()) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        return new DataResponse(['activities' => $this->activities->listForUser()]);
    }

    #[NoAdminRequired]
    public function show(string $id): DataResponse {
        if (!$this->users->getUser()) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            $activity = $this->activities->getForUser($id);
            if (!$activity) {
                return new DataResponse(['message' => 'Activity not found'], 404);
            }
            return new DataResponse([
                'activity' => $activity,
                'runs' => $this->activities->runsForUser($id),
            ]);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Activity not found'], 404);
        }
    }

    #[NoAdminRequired]
    public function assignParticipant(string $id): DataResponse {
        if (!$this->users->getUser()) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            $body = $this->payload();
            return new DataResponse(['activity' => $this->activities->assignParticipant(
                $id,
                (string)($body['participant_id'] ?? ''),
            )]);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Activity not found'], 404);
        }
    }

    #[NoAdminRequired]
    public function chat(string $id): DataResponse {
        if (!$this->users->getUser()) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            $body = $this->payload();
            $message = trim((string)($body['message'] ?? ''));
            if ($message === '') {
                return new DataResponse(['message' => 'Message is required'], 400);
            }
            $response = $this->hermes->chat(
                $id,
                [['role' => 'user', 'content' => $message]],
                null,
                isset($body['request_id']) ? (string)$body['request_id'] : null,
            );
            return new DataResponse([
                'response' => $response,
                'messages' => $this->hermes->getMessages($id),
            ]);
        } catch (ActivityChatException $error) {
            return new DataResponse([
                'message' => $error->getMessage(),
                'message_id' => $error->messageId,
            ], 502);
        } catch (ParticipantRuntimeUnavailableException $error) {
            return new DataResponse(['message' => $error->getMessage(), 'code' => 'participant_runtime_unavailable'], 503);
        } catch (ActivityRequestInProgressException $error) {
            return new DataResponse([
                'message' => $error->getMessage(),
                'message_id' => $error->messageId,
            ], 409);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Hermes 暂时不可用，消息已保存或无法确认'], 502);
        }
    }

    #[NoAdminRequired]
    public function continueActivity(string $id): DataResponse {
        return $this->chat($id);
    }

    #[NoAdminRequired]
    public function updateStatus(string $id): DataResponse {
        if (!$this->users->getUser()) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            $body = $this->payload();
            return new DataResponse(['activity' => $this->activities->updateStatus(
                $id,
                (string)($body['status'] ?? ''),
                isset($body['expected_status']) ? (string)$body['expected_status'] : null,
            )]);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        } catch (\DomainException $error) {
            return new DataResponse(['message' => $error->getMessage()], 409);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Activity not found'], 404);
        }
    }

    #[NoAdminRequired]
    public function retryMessage(string $id, string $messageId): DataResponse {
        if (!$this->users->getUser()) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            return new DataResponse($this->hermes->retryFailedMessage($id, $messageId));
        } catch (ActivityChatException $error) {
            return new DataResponse([
                'message' => $error->getMessage(),
                'message_id' => $error->messageId,
            ], 502);
        } catch (ParticipantRuntimeUnavailableException $error) {
            return new DataResponse(['message' => $error->getMessage(), 'code' => 'participant_runtime_unavailable'], 503);
        } catch (ActivityRequestInProgressException $error) {
            return new DataResponse([
                'message' => $error->getMessage(),
                'message_id' => $error->messageId,
            ], 409);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Activity message not found'], 404);
        }
    }

    #[NoAdminRequired]
    public function messages(string $id): DataResponse {
        if (!$this->users->getUser()) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            return new DataResponse(['messages' => $this->hermes->getMessages($id)]);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Activity not found'], 404);
        }
    }

    private function payload(): array {
        $raw = $this->request->getContent();
        if ($raw === '') {
            return [];
        }
        try {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('Invalid JSON body');
        }
        return is_array($value) ? $value : [];
    }
}
