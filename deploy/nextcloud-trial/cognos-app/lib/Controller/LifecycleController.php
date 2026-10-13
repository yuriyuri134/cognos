<?php
declare(strict_types=1);
namespace OCA\Cognos\Controller;
use OCA\Cognos\Service\TaskAuditService;
use OCA\Cognos\Service\TaskProgressService;
use OCA\Cognos\Service\TaskEventsService;
use OCA\Deck\Db\ChangeHelper;
use OCA\Deck\AppInfo\Application as DeckApplication;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Db\Acl;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\StatusException;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;

class LifecycleController extends Controller {
    public function __construct(IRequest $request, private IDBConnection $db, private IUserSession $users,
        private IAppManager $apps, private TaskAuditService $audit, private TaskProgressService $progress, private TaskEventsService $events) { parent::__construct('cognos', $request); }

    private function deck(): \Psr\Container\ContainerInterface {
        $this->apps->loadApp('deck');
        $this->apps->loadApp('activity');
        return (new DeckApplication())->getContainer();
    }

    #[NoAdminRequired]
    public function change(int $cardId, string $operation, ?string $requestId = null, ?int $stackId = null, ?string $status = null, ?string $expectedStatus = null): DataResponse {
        $phaseOperation = $operation === 'state';
        $action = $phaseOperation ? ($status === 'done' ? 'done' : 'undone') : $operation;
        $actor = $this->users->getUser();
        if (!$actor) return new DataResponse(['message' => 'Authentication required'], 401);
        if (($phaseOperation && (!in_array($status,['open','in_progress','blocked','done'],true) || ($expectedStatus !== null && !in_array($expectedStatus,['open','in_progress','blocked','done'],true))))
            || !in_array($action, ['done', 'undone'], true) || $requestId === null
            || !preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $requestId)) {
            return new DataResponse(['message' => 'Invalid state operation'], 400);
        }
        if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_POSTGRES || $this->db->inTransaction()) {
            return new DataResponse(['message' => 'Transaction unavailable'], 503);
        }
        $deck = $this->deck();
        $cards = $deck->get(CardService::class);
        $mapper = $deck->get(CardMapper::class);
        $stacks = $deck->get(StackMapper::class);
        $permissions = $deck->get(PermissionService::class);
        $committing = false;
        $this->db->beginTransaction();
        try {
            // Serialize these mutations within a board; reopening can reorder other cards in the column.
            $boardQuery = $this->db->executeQuery('SELECT s.board_id FROM *PREFIX*deck_cards c JOIN *PREFIX*deck_stacks s ON s.id = c.stack_id WHERE c.id = ?', [$cardId]);
            $lockBoardId = $boardQuery->fetchOne(); $boardQuery->closeCursor();
            if ($lockBoardId === false) { $this->db->rollBack(); return new DataResponse(['message' => 'Permission denied'], 403); }
            $boardLock = $this->db->executeQuery('SELECT id FROM *PREFIX*deck_boards WHERE id = ? FOR UPDATE', [$lockBoardId]);
            $boardLock->fetchOne(); $boardLock->closeCursor();
            $locked = $this->db->executeQuery('SELECT id FROM *PREFIX*deck_cards WHERE id = ? FOR UPDATE', [$cardId]);
            $exists = $locked->fetchOne(); $locked->closeCursor();
            if ($exists === false) { $this->db->rollBack(); return new DataResponse(['message' => 'Permission denied'], 403); }
            $permissions->checkPermission($mapper, $cardId, Acl::PERMISSION_EDIT);
            $card = $cards->find($cardId);
            if ($card->getArchived() || $deck->get(BoardService::class)->isArchived($mapper,$cardId)) {
                $this->db->rollBack(); return new DataResponse(['message'=>'Archived task is read-only'],409);
            }
            $boardId = $mapper->findBoardId($cardId);
            if ($boardId !== (int)$lockBoardId) { $this->db->rollBack(); return new DataResponse(['message' => 'Task moved; retry'], 409); }
            $hash = hash('sha256', json_encode($phaseOperation ? ['state',$cardId,$status,$expectedStatus,$stackId] : ['completion', $cardId, $action, $stackId], JSON_THROW_ON_ERROR));
            $inserted = $this->db->executeStatement(
                'INSERT INTO *PREFIX*cognos_task_requests (actor_uid, request_id, payload_hash, created_at) VALUES (?, ?, ?, ?) '
                . 'ON CONFLICT (actor_uid, request_id) DO NOTHING', [$actor->getUID(), $requestId, $hash, time()]);
            if ($inserted === 0) {
                $q = $this->db->executeQuery('SELECT payload_hash, card_id FROM *PREFIX*cognos_task_requests WHERE actor_uid = ? AND request_id = ?', [$actor->getUID(), $requestId]);
                $saved = $q->fetchAssociative(); $q->closeCursor();
                if (!$saved || !hash_equals($saved['payload_hash'], $hash) || (int)$saved['card_id'] !== $cardId) {
                    $this->db->rollBack(); return new DataResponse(['message' => 'Request ID conflict'], 409);
                }
                $response = new DataResponse($phaseOperation ? ['card'=>$card,'currentState'=>$this->audit->snapshot($card,$stacks->find($card->getStackId()))] : $card);
                $response->addHeader('X-Cognos-Replayed', 'true');
            } else {
                $before = $this->audit->snapshot($card, $stacks->find($card->getStackId()));
                if ($phaseOperation && $expectedStatus !== null && $before['status'] !== $expectedStatus) {
                    $this->db->rollBack(); return new DataResponse(['message'=>'Task state changed; reload before retry','currentStatus'=>$before['status']],409);
                }
                $binaryChanged = ($card->getDone() !== null) !== ($action === 'done');
                $progressChanged = $phaseOperation && $status !== 'done' && $this->progress->progress($cardId) !== $status;
                $changed = $binaryChanged || $progressChanged;
                if ($changed) {
                    $reopenStackId = $stackId;
                    $reopenOrder = $card->getOrder();
                    if ($binaryChanged && $action === 'undone' && $stacks->find($card->getStackId())->getIsDoneColumn()) {
                        if ($reopenStackId === null) {
                            $prior = $this->db->executeQuery("SELECT before_state FROM *PREFIX*cognos_task_events WHERE card_id = ? AND (kind = ? OR (kind = 'status_changed' AND before_state::jsonb->>'done' IS NULL AND after_state::jsonb->>'done' IS NOT NULL)) ORDER BY id DESC LIMIT 1", [$cardId, 'completed']);
                            $value = $prior->fetchOne(); $prior->closeCursor();
                            if ($value !== false) {
                                $previous = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
                                $reopenStackId = $previous['stack']['id']; $reopenOrder = $previous['order'];
                            }
                        }
                        if ($reopenStackId === null) {
                            $choices = array_values(array_map(fn($s) => ['id' => $s->getId(), 'title' => $s->getTitle()],
                                array_filter($stacks->findAll($boardId), fn($s) => !$s->getIsDoneColumn() && $s->getDeletedAt() === 0)));
                            $this->db->rollBack();
                            return new DataResponse(['message' => 'Choose a column for reopening', 'reopenColumns' => $choices], 409);
                        }
                    }
                    if ($action === 'undone' && $reopenStackId !== null) {
                        $permissions->checkPermission($stacks, $reopenStackId, Acl::PERMISSION_EDIT);
                        $destination = $stacks->find($reopenStackId);
                        if ($destination->getBoardId() !== $boardId || $destination->getIsDoneColumn() || $destination->getDeletedAt() !== 0) {
                            $this->db->rollBack(); return new DataResponse(['message' => 'Invalid reopening column'], 400);
                        }
                    }
                    if ($binaryChanged) $card = $action === 'done' ? $cards->done($cardId) : $cards->undone($cardId);
                    if ($progressChanged) {
                        $this->progress->set($cardId,$status);
                        $deck->get(ChangeHelper::class)->cardChanged($cardId);
                    }
                    if ($action === 'undone' && $reopenStackId !== null && $card->getStackId() !== $reopenStackId) {
                        $cards->reorder($cardId, $reopenStackId, $reopenOrder);
                        $card = $cards->find($cardId);
                    }
                    [$card] = $cards->enrichCards([$card]);
                    $after = $this->audit->snapshot($card, $stacks->find($card->getStackId()));
                    $this->audit->append($boardId, $cardId, $actor->getUID(), $actor->getDisplayName(),
                        $phaseOperation ? 'status_changed' : ($action === 'done' ? 'completed' : 'reopened'), $before, $after);
                }
                $this->db->executeStatement('UPDATE *PREFIX*cognos_task_requests SET card_id = ? WHERE actor_uid = ? AND request_id = ?', [$cardId, $actor->getUID(), $requestId]);
                $response = new DataResponse($phaseOperation ? ['card'=>$card,'currentState'=>$this->audit->snapshot($card,$stacks->find($card->getStackId()))] : $card);
            }
            $committing = true;
            $this->db->commit();
            return $response;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($committing) return new DataResponse(['message' => 'Result unknown; retry the same operation'], 503);
            if ($error instanceof StatusException) return new DataResponse(['message' => 'Operation rejected'], $error->getStatus());
            if ($error instanceof DoesNotExistException) return new DataResponse(['message' => 'Permission denied'], 403);
            return new DataResponse(['message' => 'State change failed; no change saved'], 500);
        }
    }

    #[NoAdminRequired]
    public function events(int $cardId, ?int $beforeId = null): DataResponse {
        if ($beforeId !== null && $beforeId < 1) return new DataResponse(['message'=>'Invalid event cursor'],400);
        try {
            $deck = $this->deck(); $card = $deck->get(CardService::class)->find($cardId);
            $readableBoards=[];$permissionService=$deck->get(PermissionService::class);
            foreach($deck->get(BoardService::class)->findAll(-1,false,true) as $board){
                if($permissionService->getPermissions($board->getId())[Acl::PERMISSION_READ]??false)$readableBoards[]=$board->getId();
            }
            $page = $this->events->forCard($cardId, $beforeId, $readableBoards);
            $mapper=$deck->get(CardMapper::class); $permissions=$deck->get(PermissionService::class)->getPermissions($mapper->findBoardId($cardId));
            $canEdit=($permissions[Acl::PERMISSION_EDIT] ?? false) && !$card->getArchived() && !$deck->get(BoardService::class)->isArchived($mapper,$cardId);
            return new DataResponse(['canEdit'=>$canEdit,'currentState' => $this->audit->snapshot($card, $deck->get(StackMapper::class)->find($card->getStackId())), 'completed' => $card->getDone() !== null, ] + $page);
        } catch (\Throwable $error) {
            if ($error instanceof StatusException || $error instanceof DoesNotExistException) return new DataResponse(['message' => 'Permission denied'], 403);
            return new DataResponse(['message' => 'History unavailable'], 500);
        }
    }
}
