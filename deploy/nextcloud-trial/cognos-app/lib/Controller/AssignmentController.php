<?php
declare(strict_types=1);
namespace OCA\Cognos\Controller;
use OCA\Cognos\Service\TaskAuditService;
use OCA\Deck\AppInfo\Application as DeckApplication;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\AssignmentService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\StatusException;
use OCP\App\IAppManager;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;

class AssignmentController extends OCSController {
    public function __construct(IRequest $request, private IDBConnection $db, private IUserSession $users,
        private IAppManager $apps, private TaskAuditService $audit) { parent::__construct('cognos', $request); }
    #[NoAdminRequired]
    public function assign(int $cardId, string $userId, int $type = 0, ?string $requestId = null, ?int $boardId = null): DataResponse {
        return $this->mutate($cardId, $userId, $type, $requestId, $boardId, true);
    }
    #[NoAdminRequired]
    public function unassign(int $cardId, string $userId, int $type = 0, ?string $requestId = null, ?int $boardId = null): DataResponse {
        return $this->mutate($cardId, $userId, $type, $requestId, $boardId, false);
    }
    private function mutate(int $cardId, string $userId, int $type, ?string $requestId, ?int $boardId, bool $assign): DataResponse {
        $actor = $this->users->getUser();
        if (!$actor) return new DataResponse(['message' => 'Authentication required'], 401);
        if ($requestId === null || !preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $requestId)
            || $userId === '' || strlen($userId) > 64 || !in_array($type, [Assignment::TYPE_USER, Assignment::TYPE_GROUP, Assignment::TYPE_CIRCLE], true)) {
            return new DataResponse(['message' => 'Invalid assignment operation'], 400);
        }
        if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_POSTGRES || $this->db->inTransaction()) {
            return new DataResponse(['message' => 'Transaction unavailable'], 503);
        }
        $this->apps->loadApp('deck'); $this->apps->loadApp('activity');
        $deck = (new DeckApplication())->getContainer();
        $cards = $deck->get(CardService::class); $mapper = $deck->get(CardMapper::class);
        $permissions = $deck->get(PermissionService::class);
        $assignments = $deck->get(AssignmentMapper::class);
        $service = $deck->get(AssignmentService::class);
        $stacks = $deck->get(StackMapper::class);
        $committing = false;
        $this->db->beginTransaction();
        try {
            $q = $this->db->executeQuery('SELECT s.board_id FROM *PREFIX*deck_cards c JOIN *PREFIX*deck_stacks s ON s.id=c.stack_id WHERE c.id=?', [$cardId]);
            $localBoardId = $q->fetchOne(); $q->closeCursor();
            if ($localBoardId === false) { $this->db->rollBack(); return new DataResponse(['message'=>'Permission denied'],403); }
            $q = $this->db->executeQuery('SELECT id FROM *PREFIX*deck_boards WHERE id=? FOR UPDATE', [$localBoardId]); $q->fetchOne(); $q->closeCursor();
            $q = $this->db->executeQuery('SELECT id FROM *PREFIX*deck_cards WHERE id=? FOR UPDATE', [$cardId]); $q->fetchOne(); $q->closeCursor();
            $permissions->checkPermission($mapper, $cardId, Acl::PERMISSION_EDIT);
            $card = $cards->find($cardId);
            if ($card->getArchived() || $deck->get(BoardService::class)->isArchived($mapper,$cardId)) {
                $this->db->rollBack(); return new DataResponse(['message'=>'Archived task is read-only'],409);
            }
            if ($mapper->findBoardId($cardId) !== (int)$localBoardId || ($boardId !== null && $boardId !== (int)$localBoardId)) {
                $this->db->rollBack(); return new DataResponse(['message'=>'Task moved; reload before retry'],409);
            }
            $hash = hash('sha256', json_encode(['assignment',$cardId,$assign,$userId,$type],JSON_THROW_ON_ERROR));
            $inserted = $this->db->executeStatement('INSERT INTO *PREFIX*cognos_task_requests (actor_uid,request_id,payload_hash,created_at) VALUES (?,?,?,?) ON CONFLICT (actor_uid,request_id) DO NOTHING',
                [$actor->getUID(),$requestId,$hash,time()]);
            if ($inserted === 0) {
                $q = $this->db->executeQuery('SELECT payload_hash,card_id,response_json FROM *PREFIX*cognos_task_requests WHERE actor_uid=? AND request_id=?',[$actor->getUID(),$requestId]);
                $saved = $q->fetchAssociative(); $q->closeCursor();
                if (!$saved || !hash_equals($saved['payload_hash'],$hash) || (int)$saved['card_id']!==$cardId || $saved['response_json']===null) {
                    $this->db->rollBack(); return new DataResponse(['message'=>'Request ID conflict'],409);
                }
                $currentlyAssigned = false;
                foreach ($assignments->findAll($cardId) as $item) {
                    if ($item->getParticipant()===$userId && $item->getType()===$type) { $currentlyAssigned=true; break; }
                }
                if ($currentlyAssigned !== $assign) {
                    $this->db->rollBack();
                    $response=new DataResponse(['message'=>'Earlier operation confirmed; assignment has since changed'],409);
                    $response->addHeader('X-Cognos-Operation-Confirmed','true'); return $response;
                }
                $response = new DataResponse(json_decode($saved['response_json'],true,flags:JSON_THROW_ON_ERROR));
                $response->addHeader('X-Cognos-Replayed','true');
            } else {
                $before = $this->audit->snapshot($card,$stacks->find($card->getStackId()));
                $existing = null;
                foreach ($assignments->findAll($cardId) as $item) {
                    if ($item->getParticipant()===$userId && $item->getType()===$type) { $existing=$item; break; }
                }
                $changed = $assign ? $existing===null : $existing!==null;
                if ($changed) {
                    $result = $assign ? $service->assignUser($cardId,$userId,$type) : $service->unassignUser($cardId,$userId,$type);
                    $afterCard = $cards->find($cardId);
                    $after = $this->audit->snapshot($afterCard,$stacks->find($afterCard->getStackId()));
                    $this->audit->append((int)$localBoardId,$cardId,$actor->getUID(),$actor->getDisplayName(),$assign?'assigned':'unassigned',$before,$after);
                } else {
                    // A transport acknowledgement for an absent assignment is not a database assignment.
                    $result = $existing ?? ['id'=>0,'cardId'=>$cardId,'participant'=>['uid'=>$userId],'type'=>$type];
                }
                $json = json_encode($result,JSON_THROW_ON_ERROR);
                $this->db->executeStatement('UPDATE *PREFIX*cognos_task_requests SET card_id=?,response_json=? WHERE actor_uid=? AND request_id=?',[$cardId,$json,$actor->getUID(),$requestId]);
                $response = new DataResponse($result);
            }
            $committing=true; $this->db->commit(); return $response;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($committing) return new DataResponse(['message'=>'Result unknown; retry the same operation'],503);
            if ($error instanceof StatusException) return new DataResponse(['message'=>'Operation rejected'],$error->getStatus());
            if ($error instanceof DoesNotExistException) return new DataResponse(['message'=>'Permission denied'],403);
            return new DataResponse(['message'=>'Assignment failed; no change saved'],500);
        }
    }
}
