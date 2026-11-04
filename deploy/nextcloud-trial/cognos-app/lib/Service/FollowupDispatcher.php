<?php
declare(strict_types=1);
namespace OCA\Cognos\Service;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\IConfig;
final class FollowupDispatcher {
 public function __construct(private IDBConnection $db,private FollowupService $events,private RunService $runs,
  private LifecycleService $lifecycle,private CreationSpaceService $creations,private PushBackService $pushBack,
  private ContinuationService $continuation,private HermesAgentService $hermes,private IConfig $config,
  private IUserSession $users,private IUserManager $userManager,private DispatchQueueService $dispatchQueue,private LegacyQueueImportService $legacyImports){}
 public function recover():void {
  $this->legacyImports->scan();
  $this->dispatchQueue->expireClaims(time()-1800);
  $this->events->expireClaims(time()-1800);
  foreach($this->events->pending() as $event)$this->process($event['result_id'],$event['user_id']);
 }
 public function process(string $id,string $uid):bool {
  $event=$this->events->get($id);
  if(!$event||$event['state']!=='pending'||$event['user_id']!==$uid)return false;
  $user=$this->userManager->get($uid);
  if(!$user||!$user->isEnabled()||$event['space_type']!=='creation'||!$this->creations->get((int)$event['space_id'],$uid)){
   $this->events->cancel($id,'账号或会话已不可访问，不再自动接续');return false;
  }
  $this->db->beginTransaction();
  try {
   $project=$this->lifecycle->forSpace($event['space_type'],$event['space_id']);
   // Serialize different followup intents for the same conversation, including Initium.
   $q=$this->db->getQueryBuilder();$q->update('cognos_creations')->set('updated_at','updated_at')->where($q->expr()->eq('id',$q->createNamedParameter((int)$event['space_id'],\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeStatement();
   $manual=($event['origin']??'result')==='queue_review';
   $run=$event['run_id']===null?null:$this->runs->get($event['run_id']);
   $selected=$manual?$this->dispatchQueue->get((string)$event['queue_item_id']):null;
   $validSource=$manual?($event['run_id']===null&&$selected!==null&&$selected['state']==='queued'&&$selected['reviewed_at']!==null):($run!==null&&$run['state']==='delivered');
   $expectedProject=$manual?$event['project_id']:($run['project_id']??null);$expectedEpoch=$manual?$event['execution_epoch']:($run['execution_epoch']??null);
   if(!$validSource||($project!==null&&($project['state']!=='active'||$expectedProject!==$project['id']||(int)$expectedEpoch!==(int)$project['execution_epoch']))){
    $this->events->cancel($id,'项目安排或归属已变化，不自动延续旧安排');$this->db->commit();return false;
   }
   $q=$this->db->getQueryBuilder();$claimed=$q->select('result_id')->from('cognos_followups')->where($q->expr()->eq('space_type',$q->createNamedParameter($event['space_type'])))->andWhere($q->expr()->eq('space_id',$q->createNamedParameter($event['space_id'])))->andWhere($q->expr()->in('state',$q->createNamedParameter(['claimed','unknown'],\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))->setMaxResults(1)->executeQuery()->fetch();
   $q=$this->db->getQueryBuilder();$unknownRun=$q->select('id')->from('cognos_runs')->where($q->expr()->eq('space_type',$q->createNamedParameter($event['space_type'])))->andWhere($q->expr()->eq('space_id',$q->createNamedParameter($event['space_id'])))->andWhere($q->expr()->eq('state',$q->createNamedParameter('unknown')))->setMaxResults(1)->executeQuery()->fetch();
   if($claimed||$unknownRun||$this->runs->activeForSpace($event['space_type'],$event['space_id'])!==[]){$this->events->defer($id,'会话仍有工作或未核清的接续，暂不领取');$this->db->commit();return false;}
   $event=$this->events->claim($id,$uid);$this->db->commit();
  }catch(\RuntimeException $e){$this->db->rollBack();return false;}catch(\Throwable $e){$this->db->rollBack();throw $e;}
  if(!$event)return false;
  $previous=$this->users->getUser();
  try{
   $this->users->setUser($user);
   $this->config->deleteAppValue('cognos','agent_pending_creation_'.(int)$event['space_id']);
   $this->config->deleteAppValue('cognos','agent_pending_who_creation_'.(int)$event['space_id']);
   $this->callNext($event['space_type'],$event['space_id'],$uid,$event['open_id']===null?null:(int)$event['open_id'],$id,$event['queue_item_id']??null);
   $this->events->finish($id);
  }catch(\Throwable $e){if($e instanceof QueueHeldException)$this->events->retryClaim($id,'旧积压需先核对，未派发');elseif($e instanceof ProjectInactiveException)$this->events->cancelClaim($id);elseif($e instanceof \RuntimeException&&$e->getCode()===ClaudeBridgeService::BUSY)$this->events->retryClaim($id);else $this->events->unknown($id);}finally{$this->users->setUser($previous);}
  return true;
 }
    private function callNext(string $spaceType, string $spaceId, string $uid, ?int $justRan,string $followupId,?string $selectedItem = null): void {
        $next = $this->pushBack->drain($spaceType, $spaceId, $uid,$followupId,$selectedItem);
        if ($next === null) {
            if($selectedItem!==null)return;
            $this->continuation->resume($spaceType, $spaceId, $uid, $justRan);
            return;
        }
        [$what, $messageId,$claim] = $next;
        if($what==='held')throw new QueueHeldException('旧积压需核对');
        if($what==='busy')throw new \RuntimeException('桥明确忙拒绝',ClaudeBridgeService::BUSY);
        if(in_array($what,['unknown','blocked'],true))throw new \RuntimeException('队列有未核清派发，不能继续');
        if ($what !== 'msg') {
            return;
        }
        try {
            $answer = $this->hermes->chatCreationFromMessage((int)$spaceId, $messageId,['queue_claim'=>$claim]);
            if ($answer !== null) {
                $this->dispatchQueue->settle($claim['item_id'],$followupId,'sent','直调 AI 已返回回答');
                // 叫来的是 Hermes：回答当场就有，没人占着桥，没完的接着走
                $this->creations->addMessage((int)$spaceId, $uid, 'assistant', $answer, $messageId);
                if($selectedItem===null)$this->continuation->resume($spaceType, $spaceId, $uid, $justRan);
            }
        } catch (\Throwable $error) {
            if($error instanceof ProjectInactiveException){$this->dispatchQueue->settle($claim['item_id'],$followupId,'queued','项目暂停，未创建运行');throw $error;}
            if($error instanceof \RuntimeException&&$error->getCode()===ClaudeBridgeService::BUSY){$this->dispatchQueue->settle($claim['item_id'],$followupId,'queued','明确忙拒绝');throw $error;}
            $item=$this->dispatchQueue->get($claim['item_id']);
            if($item['state']==='skipped')return;
            $this->dispatchQueue->settle($claim['item_id'],$followupId,'unknown','派发中断，不自动重放');throw $error;
        }
    }

}
