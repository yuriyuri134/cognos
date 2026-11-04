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
  private IUserSession $users,private IUserManager $userManager){}
 public function recover():void {
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
   $run=$this->runs->get($event['run_id']);
   if(!$run||$run['state']!=='delivered'||($project!==null&&($project['state']!=='active'||$run['project_id']!==$project['id']||(int)$run['execution_epoch']!==(int)$project['execution_epoch']))){
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
   $this->callNext($event['space_type'],$event['space_id'],$uid,$event['open_id']===null?null:(int)$event['open_id']);
   $this->events->finish($id);
  }catch(\Throwable $e){$this->events->unknown($id);}finally{$this->users->setUser($previous);}
  return true;
 }
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
            if ($error instanceof \RuntimeException && !($error instanceof ProjectInactiveException) && $error->getCode() === ClaudeBridgeService::BUSY) {
                $this->pushBack->enqueueMessage($spaceType, $spaceId, $messageId, true);
            }
            // 别的原因叫不上：那句还在对话里、没人回，页面上能「再叫一次」
        }
    }

}
