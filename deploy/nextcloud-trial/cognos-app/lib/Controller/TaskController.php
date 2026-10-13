<?php
declare(strict_types=1);
namespace OCA\Cognos\Controller;

use OCA\Deck\AppInfo\Application as DeckApplication;
use OCA\Cognos\Service\TaskAuditService;
use OCA\Deck\Service\AssignmentService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\StatusException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCSController;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;

/** Local Deck 1.19 adapter; native Deck endpoints are not changed. */
class TaskController extends OCSController {
    public function __construct(IRequest $request, private IDBConnection $db, private IUserSession $users,
        private IAppManager $apps, private TaskAuditService $audit) {
        parent::__construct('cognos', $request);
    }

    #[NoAdminRequired]
    public function create(string $title, int $stackId, ?int $boardId = null, string $type = 'plain',
        int $order = 999, string $description = '', array $labels = [], array $users = [],
        ?string $duedate = null, ?string $startdate = null, ?string $color = null,
        ?string $requestId = null): DataResponse {
        $actor = $this->users->getUser();
        if ($actor === null) {
            throw new OCSException('Authentication required', 401);
        }
        if ($requestId === null || !preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $requestId)) {
            throw new OCSException('A valid requestId is required', 400);
        }
        if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_POSTGRES) {
            throw new OCSException('This trial requires PostgreSQL', 503);
        }
        if (!$this->apps->isEnabledForUser('deck') || !$this->apps->isEnabledForUser('activity')) {
            throw new OCSException('Task dependencies unavailable', 503);
        }
        $this->apps->loadApp('deck');
        $this->apps->loadApp('activity'); // register the native history consumer in this OCS request
        // Resolve Deck services in Deck's container, retaining its actor and permission rules.
        $deck = (new DeckApplication())->getContainer();
        if ($deck->get(IDBConnection::class) !== $this->db || $this->db->inTransaction()) {
            throw new OCSException('Transaction unavailable', 503);
        }
        $cards = $deck->get(CardService::class);
        $assignments = $deck->get(AssignmentService::class);
        $stacks = $deck->get(StackMapper::class);
        $permissions = $deck->get(PermissionService::class);
        $this->db->beginTransaction();
        $committing = false;
        try {
            $permissions->checkPermission($stacks, $stackId, Acl::PERMISSION_EDIT);
            // Do not hydrate the column's cards here: Deck caches this object and serializes it into history.
            $stack = $stacks->find($stackId);
            if ($boardId !== null && $stack->getBoardId() !== $boardId) {
                throw new OCSException('Stack does not belong to this board', 400);
            }
            $payloadHash = hash('sha256', json_encode([
                $title, $stackId, $stack->getBoardId(), $type, $order, $description,
                $labels, $users, $duedate, $startdate, $color,
            ], JSON_THROW_ON_ERROR));
            // PostgreSQL waits for an in-flight conflicting reservation to commit or roll back.
            $inserted = $this->db->executeStatement(
                'INSERT INTO *PREFIX*cognos_task_requests (actor_uid, request_id, payload_hash, created_at) '
                . 'VALUES (?, ?, ?, ?) ON CONFLICT (actor_uid, request_id) DO NOTHING',
                [$actor->getUID(), $requestId, $payloadHash, time()]
            );
            if ($inserted === 0) {
                $saved = $this->db->executeQuery(
                    'SELECT payload_hash, card_id FROM *PREFIX*cognos_task_requests WHERE actor_uid = ? AND request_id = ?',
                    [$actor->getUID(), $requestId]
                );
                $row = $saved->fetchAssociative();
                $saved->closeCursor();
                if (!$row || !hash_equals($row['payload_hash'], $payloadHash)) {
                    throw new OCSException('Request ID already used for different task content', 409);
                }
                if ($row['card_id'] === null) {
                    throw new OCSException('Task creation result unknown; retry the same request', 503);
                }
                $card = $cards->find((int)$row['card_id']); // re-check current read permissions
                $response = new DataResponse($card);
                $response->addHeader('X-Cognos-Replayed', 'true');
                $committing = true;
                $this->db->commit();
                return $response;
            }
            $card = $cards->create($title, $stackId, $type, $order, $actor->getUID(), $description, $duedate, $startdate, $color);
            foreach ($labels as $label) {
                if (!is_array($label) || !isset($label['id']) || !is_int($label['id'])) {
                    throw new OCSException('Invalid label', 400);
                }
                $cards->assignLabel($card->getId(), $label['id']);
            }
            foreach ($users as $user) {
                if (!is_array($user) || !isset($user['id'], $user['type'])
                    || !is_string($user['id']) || !is_int($user['type'])) {
                    throw new OCSException('Invalid assignee', 400);
                }
                $assignments->assignUser($card->getId(), $user['id'], $user['type']);
            }
            // Deck can swallow activity errors. Require the actor's copy of every requested operation.
            foreach (['card_create' => 1, 'card_user_assign' => count($users), 'label_assign' => count($labels)] as $subject => $minimum) {
                if ($minimum === 0) {
                    continue;
                }
                $q = $this->db->getQueryBuilder();
                $q->select($q->func()->count('activity_id'))->from('activity')
                ->where($q->expr()->eq('app', $q->createNamedParameter('deck')))
                ->andWhere($q->expr()->eq('object_type', $q->createNamedParameter('deck_card')))
                ->andWhere($q->expr()->eq('object_id', $q->createNamedParameter($card->getId())))
                ->andWhere($q->expr()->eq('subject', $q->createNamedParameter($subject)))
                ->andWhere($q->expr()->eq('user', $q->createNamedParameter($actor->getUID())))
                ->andWhere($q->expr()->eq('affecteduser', $q->createNamedParameter($actor->getUID())));
                $result = $q->executeQuery();
                $count = (int)$result->fetchOne();
                $result->closeCursor();
                if ($count < $minimum) {
                    throw new OCSException('Creation history unavailable; task was not saved', 503);
                }
            }
            [$card] = $cards->enrichCards([$card]);
            $this->audit->append($stack->getBoardId(), $card->getId(), $actor->getUID(), $actor->getDisplayName(),
                'created', null, $this->audit->snapshot($card, $stack));
            $updated = $this->db->executeStatement(
                'UPDATE *PREFIX*cognos_task_requests SET card_id = ? WHERE actor_uid = ? AND request_id = ?',
                [$card->getId(), $actor->getUID(), $requestId]
            );
            if ($updated !== 1) {
                throw new OCSException('Task request could not be saved', 503);
            }
            $response = new DataResponse($card);
            $committing = true;
            $this->db->commit(); // before Nextcloud Dispatcher checks for unfinished transactions
            return $response;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($committing) {
                throw new OCSException('Task creation result unknown; check task list before retrying', 503);
            }
            if ($error instanceof OCSException) {
                throw $error;
            }
            if ($error instanceof StatusException) {
                throw new OCSException('Task request rejected; no task was saved', $error->getStatus());
            }
            if ($error instanceof DoesNotExistException) {
                throw new OCSException('Permission denied', 403);
            }
            throw new OCSException('Task creation failed; no task was saved', 500);
        }
    }
}
