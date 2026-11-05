<?php
declare(strict_types=1);
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
use OCA\Cognos\Service\{RestoreGuardService,RunService,FollowupDispatcher,FollowupService,ProjectService,CreationSpaceService,ClaudeBridgeService,HermesAgentService};
$db=\OC::$server->get(\OCP\IDBConnection::class);$config=\OC::$server->get(\OCP\IConfig::class);$guard=\OC::$server->get(RestoreGuardService::class);
// Never enable quarantine on a production instance just to run a check.
if(!$guard->isQuarantined())throw new RuntimeException('Refusing check: this must already be an explicitly quarantined restore copy.');
$prior=$config->getSystemValue('cognos_restore_quarantine',false);$checks=[];$rid=fn()=>bin2hex(random_bytes(16));$uid='cognos-admin';
function check(bool $ok,string $text):void{global $checks;if(!$ok)throw new RuntimeException($text);$checks[]=$text;}
function blocked(callable $fn,string $text):void{try{$fn();}catch(RuntimeException $e){if($e->getCode()!==409)throw $e;check(true,$text);return;}throw new RuntimeException('not blocked: '.$text);}
function scheduleSnapshot():string{
 global $db;$records=[];
 foreach(['cognos_followups'=>'result_id','cognos_dispatch_queue'=>'id','cognos_queue_imports'=>'source_key'] as $table=>$key){$q=$db->getQueryBuilder();$records[$table]=$q->select('*')->from($table)->orderBy($key,'ASC')->executeQuery()->fetchAll();}
 return hash('sha256',json_encode($records,JSON_THROW_ON_ERROR));
}
$config->setSystemValue('cognos_restore_quarantine',true);$db->beginTransaction();
try{
 $runs=\OC::$server->get(RunService::class);$dispatcher=\OC::$server->get(FollowupDispatcher::class);$events=\OC::$server->get(FollowupService::class);$projects=\OC::$server->get(ProjectService::class);$creations=\OC::$server->get(CreationSpaceService::class);
 $before=scheduleSnapshot();$dispatcher->recover();check($before===scheduleSnapshot(),'recovery in quarantine neither scans/imports nor claims/expires preserved scheduling records');
 check(!$dispatcher->process(str_repeat('a',32),$uid)&&$before===scheduleSnapshot(),'direct followup processing is a no-op while quarantined');
 blocked(fn()=>$guard->assertExecutionAllowed(),'shared local restore policy denies execution with 409');
 $p=$projects->create($uid,'验证：恢复隔离结果回执',$rid());$loc=$projects->location($uid,$p['location_id']);
 $q=$db->getQueryBuilder();$countBefore=(int)$q->selectAlias($q->func()->count('*'),'n')->from('cognos_runs')->executeQuery()->fetchOne();
 blocked(fn()=>$runs->create('creation',(string)$loc['creation_id'],'conversation','creation:'.$loc['creation_id'],'不能新派发','恢复隔离验证 AI',$uid,[]),'new run admission is blocked before creating a run record');
 $q=$db->getQueryBuilder();check((int)$q->selectAlias($q->func()->count('*'),'n')->from('cognos_runs')->executeQuery()->fetchOne()===$countBefore,'blocked run admission leaves no extra execution record');
 $bridge=\OC::$server->get(ClaudeBridgeService::class);blocked(fn()=>$bridge->send('creation',(string)$loc['creation_id'],'恢复隔离验证 AI','',[],'不能发送',$uid),'bridge sending is blocked before credentials or provider calls');
 $hermes=\OC::$server->get(HermesAgentService::class);$call=new ReflectionMethod(HermesAgentService::class,'callHermes');blocked(fn()=>$call->invoke($hermes,'restore-check',[],null),'Hermes provider gateway is blocked before network sending');
 // Exercise the independent embedding boundary without requiring or loading an API credential.
 $embeddingClass=new ReflectionClass(\OCA\Cognos\Service\RAG\EmbeddingService::class);$embedding=$embeddingClass->newInstanceWithoutConstructor();$embeddingClass->getProperty('restoreGuard')->setValue($embedding,$guard);$embed=$embeddingClass->getMethod('callEmbeddingAPI');blocked(fn()=>$embed->invoke($embedding,['隔离检查']),'independent embedding gateway is also blocked without a provider call');
 $id=$rid();$now=time();$projects->insert('cognos_runs',['id'=>$id,'project_id'=>$p['project_id'],'execution_epoch'=>1,'space_type'=>'creation','space_id'=>(string)$loc['creation_id'],'target_type'=>'conversation','target_id'=>'creation:'.$loc['creation_id'],'purpose'=>'恢复旧运行fixture：不会派发','subject'=>'恢复隔离验证 AI','caller'=>$uid,'state'=>'running','input_hash'=>hash('sha256','{}'),'input_snapshot'=>'{}','lease_until'=>$now-7200,'error'=>null,'created_at'=>$now-8000,'updated_at'=>$now-8000]);
 $restored=$runs->get($id);$runs->forSpace('creation',(string)$loc['creation_id']);$runs->activeForSpace('creation',(string)$loc['creation_id']);
 check($restored['state']==='running'&&$runs->get($id)['state']==='running','reading restored expired leases preserves the backed-up run state');
 $resultId=$rid();$stores=0;$store=function()use($creations,$loc,$uid,&$stores):int{$stores++;return (int)$creations->addMessage($loc['creation_id'],$uid,'assistant','隔离副本收下旧结果，但不接着派发')['id'];};
 $received=$runs->receiveResult($restored,$resultId,$restored['subject'],[],$store,fn(int $messageId)=>$events->enqueue($resultId,$restored,$uid));
 check(!$received['duplicate']&&$received['run']['state']==='delivered','late pending result can be received while execution remains quarantined');
 check(!$dispatcher->process($resultId,$uid)&&$events->get($resultId)['state']==='pending','late result leaves its durable followup pending without dispatching');
 $replayed=$runs->receiveResult($restored,$resultId,$restored['subject'],[],$store);
 check($replayed['duplicate']&&$replayed['message_id']===$received['message_id']&&$stores===1,'same result id replays its original receipt without duplicating content');
 check($guard->isQuarantined(),'result acceptance does not lift restore quarantine');
}finally{
 $db->rollBack();$config->setSystemValue('cognos_restore_quarantine',$prior);
}
echo json_encode(['result'=>'pass','checks'=>$checks,'fixture_rolled_back'=>true,'quarantine_preserved'=>$guard->isQuarantined()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
