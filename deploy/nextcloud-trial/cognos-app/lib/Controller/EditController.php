<?php
declare(strict_types=1);
namespace OCA\Cognos\Controller;
use OCA\Cognos\Service\TaskAuditService;
use OCA\Deck\AppInfo\Application as DeckApplication;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Model\OptionalNullableValue;
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

class EditController extends OCSController {
    private const FIELDS = ['title','description','duedate','startdate','color'];
    public function __construct(IRequest $request, private IDBConnection $db, private IUserSession $users,
        private IAppManager $apps, private TaskAuditService $audit) { parent::__construct('cognos',$request); }
    private function normalize(string $field, mixed $value): mixed {
        if (!in_array($field,self::FIELDS,true)) throw new \InvalidArgumentException();
        if (in_array($field,['duedate','startdate'],true)) {
            if ($value===null || $value==='') return null;
            if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2}))?$/D',$value)) throw new \InvalidArgumentException();
            $date=new \DateTimeImmutable($value,new \DateTimeZone('UTC')); $errors=\DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) throw new \InvalidArgumentException();
            return $date->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
        }
        if ($field==='color') {
            if ($value===null || $value==='') return null;
            if (!is_string($value) || !preg_match('/^#?[a-fA-F0-9]{6}$/D',$value)) throw new \InvalidArgumentException();
            return $value;
        }
        if (!is_string($value) || ($field==='title' && (trim($value)==='' || mb_strlen($value)>255))) throw new \InvalidArgumentException();
        return $value;
    }
    #[NoAdminRequired]
    public function edit(int $cardId, array $changes, ?string $requestId=null, array $expected=[], ?int $boardId=null): DataResponse {
        $actor=$this->users->getUser();
        if (!$actor) return new DataResponse(['message'=>'Authentication required'],401);
        if ($requestId===null || !preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$requestId)) return new DataResponse(['message'=>'Invalid edit operation'],400);
        try {
            foreach ($changes as $field=>&$value) $value=$this->normalize((string)$field,$value); unset($value);
            foreach ($expected as $field=>&$value) { if (!array_key_exists($field,$changes)) throw new \InvalidArgumentException(); $value=$this->normalize((string)$field,$value); } unset($value);
            ksort($changes); ksort($expected);
        } catch (\Throwable $error) { return new DataResponse(['message'=>'Invalid edit fields'],400); }
        if ($this->db->getDatabaseProvider()!==IDBConnection::PLATFORM_POSTGRES || $this->db->inTransaction()) return new DataResponse(['message'=>'Transaction unavailable'],503);
        $this->apps->loadApp('deck'); $this->apps->loadApp('activity'); $deck=(new DeckApplication())->getContainer();
        $cards=$deck->get(CardService::class); $mapper=$deck->get(CardMapper::class); $stacks=$deck->get(StackMapper::class);
        $permissions=$deck->get(PermissionService::class); $committing=false; $this->db->beginTransaction();
        try {
            $q=$this->db->executeQuery('SELECT s.board_id FROM *PREFIX*deck_cards c JOIN *PREFIX*deck_stacks s ON s.id=c.stack_id WHERE c.id=?',[$cardId]); $localBoardId=$q->fetchOne();$q->closeCursor();
            if ($localBoardId===false) { $this->db->rollBack();return new DataResponse(['message'=>'Permission denied'],403); }
            $q=$this->db->executeQuery('SELECT id FROM *PREFIX*deck_boards WHERE id=? FOR UPDATE',[$localBoardId]);$q->fetchOne();$q->closeCursor();
            $q=$this->db->executeQuery('SELECT id FROM *PREFIX*deck_cards WHERE id=? FOR UPDATE',[$cardId]);$q->fetchOne();$q->closeCursor();
            $permissions->checkPermission($mapper,$cardId,Acl::PERMISSION_EDIT);$card=$cards->find($cardId);
            if ($card->getArchived() || $deck->get(BoardService::class)->isArchived($mapper,$cardId)) {
                $this->db->rollBack(); return new DataResponse(['message'=>'Archived task is read-only'],409);
            }
            if ($mapper->findBoardId($cardId)!==(int)$localBoardId || ($boardId!==null && $boardId!==(int)$localBoardId)) { $this->db->rollBack();return new DataResponse(['message'=>'Task moved; reload before retry'],409); }
            $hash=hash('sha256',json_encode(['edit',$cardId,$changes,$expected],JSON_THROW_ON_ERROR));
            $inserted=$this->db->executeStatement('INSERT INTO *PREFIX*cognos_task_requests (actor_uid,request_id,payload_hash,created_at) VALUES (?,?,?,?) ON CONFLICT (actor_uid,request_id) DO NOTHING',[$actor->getUID(),$requestId,$hash,time()]);
            if ($inserted===0) {
                $q=$this->db->executeQuery('SELECT payload_hash,card_id FROM *PREFIX*cognos_task_requests WHERE actor_uid=? AND request_id=?',[$actor->getUID(),$requestId]);$saved=$q->fetchAssociative();$q->closeCursor();
                if (!$saved || !hash_equals($saved['payload_hash'],$hash) || (int)$saved['card_id']!==$cardId) { $this->db->rollBack();return new DataResponse(['message'=>'Request ID conflict'],409); }
                $response=new DataResponse($card);$response->addHeader('X-Cognos-Replayed','true');
            } else {
                $before=$this->audit->snapshot($card,$stacks->find($card->getStackId()));
                foreach ($expected as $field=>$value) {
                    $current=$this->normalize($field,$before[$field]);
                    if ($current!==$value) { $this->db->rollBack();return new DataResponse(['message'=>'Field changed since editing began','conflictFields'=>[$field]],409); }
                }
                $values=[];$changed=false;
                foreach (self::FIELDS as $field) {
                    $values[$field]=array_key_exists($field,$changes)?$changes[$field]:$before[$field];
                    if (array_key_exists($field,$changes) && $this->normalize($field,$before[$field])!==$values[$field]) $changed=true;
                }
                if ($changed) {
                    $card=$cards->update($cardId,$values['title'],$card->getStackId(),$card->getType(),$card->getOwner(),
                        $values['description'],$card->getOrder(),$values['duedate'],null,null,
                        new OptionalNullableValue($card->getDone()),$values['startdate'],new OptionalNullableValue($values['color']));
                    $after=$this->audit->snapshot($card,$stacks->find($card->getStackId()));
                    $this->audit->append((int)$localBoardId,$cardId,$actor->getUID(),$actor->getDisplayName(),'edited',$before,$after);
                }
                $this->db->executeStatement('UPDATE *PREFIX*cognos_task_requests SET card_id=? WHERE actor_uid=? AND request_id=?',[$cardId,$actor->getUID(),$requestId]);
                $response=new DataResponse($card);
            }
            $committing=true;$this->db->commit();return $response;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($committing) return new DataResponse(['message'=>'Result unknown; retry the same operation'],503);
            if ($error instanceof StatusException) return new DataResponse(['message'=>'Operation rejected'],$error->getStatus());
            if ($error instanceof DoesNotExistException) return new DataResponse(['message'=>'Permission denied'],403);
            return new DataResponse(['message'=>'Edit failed; no change saved'],500);
        }
    }
}
