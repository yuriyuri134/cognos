<?php
declare(strict_types=1);
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';
set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
use OCA\Cognos\Service\{ProjectService,RunService,LifecycleService,AdoptionService,DelegationService,RestructureService};
$db=\OC::$server->get(\OCP\IDBConnection::class);$projects=\OC::$server->get(ProjectService::class);$runs=\OC::$server->get(RunService::class);$life=\OC::$server->get(LifecycleService::class);
 $p=$projects->create('cognos-admin','验证：结果事务故障 '.time(),bin2hex(random_bytes(16)));$loc=$projects->location('cognos-admin',$p['location_id']);
 $target=$projects->create('cognos-admin','验证：合并目标 '.time(),bin2hex(random_bytes(16)));
 $d=bin2hex(random_bytes(16));$run=$runs->create('creation',(string)$loc['creation_id'],'delegation',$d,'并发验证','并发验证','cognos-admin',[]);
 $now=time();$projects->insert('cognos_delegations',['id'=>$d,'project_id'=>$p['project_id'],'location_id'=>$p['location_id'],'ask'=>'并发验证，不调用外部 AI','goal'=>null,'means'=>null,'forbidden'=>null,'return_by'=>$now+600,'may_edit'=>1,'may_adopt'=>1,'base_revision'=>1,'state'=>'sent','note'=>null,'run_id'=>$run['id'],'message_id'=>0,'result_message_id'=>null,'applied_revision'=>null,'held_content'=>null,'held_base_revision'=>null,'held_reason'=>null,'held_at'=>null,'created_by'=>'cognos-admin','created_at'=>$now,'updated_at'=>$now]);

$creations=\OC::$server->get(\OCA\Cognos\Service\CreationSpaceService::class);
$delegations=\OC::$server->get(DelegationService::class);
function check(bool $ok,string $text):void{if(!$ok)throw new RuntimeException($text);echo $text."\n";}
$followups=\OC::$server->get(\OCA\Cognos\Service\FollowupService::class);
$resultId=bin2hex(random_bytes(16));$fail=true;$stores=0;$finishes=0;
$store=function()use($creations,$loc,&$stores):int{$stores++;return (int)$creations->addMessage($loc['creation_id'],'cognos-admin','assistant','验证：结果收尾原子保存')['id'];};
$finish=function(int $messageId)use($runs,$delegations,$run,$d,$loc,$followups,$resultId,&$fail,&$finishes):void{
 $finishes++;
 $followups->enqueue($resultId,$run,'cognos-admin');
 check(count($runs->dependencies($run['id']))===1,'finalizer observes incoming dependency inside transaction');
 $runs->setDrift($run['id'],[['location_id'=>$loc['id'],'title'=>$loc['title'],'version'=>1,'current'=>2,'editor'=>'验证']],time());
 $delegations->returned($d,$messageId);
 if($fail)throw new RuntimeException('injected completion failure');
};
$deps=[['type'=>'location_content','id'=>$loc['id'],'version'=>'1','note'=>'验证事务依据']];
try{$runs->receiveResult($run,$resultId,$run['subject'],$deps,$store,$finish);throw new Exception('fault not triggered');}
catch(RuntimeException $e){check($e->getMessage()==='injected completion failure','completion fault injected');}
check($creations->getMessages($loc['creation_id'],'cognos-admin')===[],'result message rolled back');
check($runs->get($run['id'])['state']==='queued','delivered state rolled back');
check($runs->dependencies($run['id'])===[],'incoming dependencies rolled back');
check($delegations->forRun($run['id'])['state']==='sent','delegation return rolled back');
$q=$db->getQueryBuilder();$count=(int)$q->selectAlias($q->func()->count('*'),'n')->from('cognos_run_results')->where($q->expr()->eq('id',$q->createNamedParameter($resultId)))->executeQuery()->fetchOne();
check($count===0,'receipt and drift rolled back');
check($followups->get($resultId)===null,'followup intent rolled back with failed receipt');
$fail=false;$saved=$runs->receiveResult($run,$resultId,$run['subject'],$deps,$store,$finish);
check(!$saved['duplicate'] && $saved['run']['state']==='delivered','same result id retries to a complete commit');
check(count($creations->getMessages($loc['creation_id'],'cognos-admin'))===1,'exactly one committed result');
check($delegations->forRun($run['id'])['result_message_id']==$saved['message_id'],'delegation points to committed result');
$q=$db->getQueryBuilder();$receipt=$q->select('basis_changed','drift_seen_at')->from('cognos_run_results')->where($q->expr()->eq('id',$q->createNamedParameter($resultId)))->executeQuery()->fetch();
check(!empty($receipt['basis_changed'])&&!empty($receipt['drift_seen_at']),'drift and acknowledgment commit with result');
$duplicate=$runs->receiveResult($run,$resultId,$run['subject'],$deps,fn()=>throw new RuntimeException('must not store duplicate'),fn()=>throw new RuntimeException('must not finalize duplicate'));
check($duplicate['duplicate'] && $duplicate['message_id']===$saved['message_id'],'duplicate replays receipt without repeating completion');
check($stores===2&&$finishes===2,'one failed attempt and one successful completion');

check($followups->get($resultId)['state']==='pending','unclaimed intent survives successful receipt');
check(!$followups->process($resultId,'wrong-user',fn()=>throw new RuntimeException('must not dispatch')),'another identity cannot claim intent');
$count=0;check($followups->process($resultId,'cognos-admin',function($event)use(&$count){$count++;}),'pending intent can be recovered');
check($followups->get($resultId)['state']==='attempted','completed scheduling attempt is recorded');
check(!$followups->process($resultId,'cognos-admin',function()use(&$count){$count++;})&&$count===1,'receipt retry cannot repeat scheduling');
$r2=$runs->create('creation',(string)$loc['creation_id'],'conversation','test','故障接续','原子接续验证','cognos-admin',[]);
$id2=bin2hex(random_bytes(16));
$runs->receiveResult($r2,$id2,$r2['subject'],[],$store,fn()=>$followups->enqueue($id2,$r2,'cognos-admin'));
$followups->process($id2,'cognos-admin',fn()=>throw new RuntimeException('simulated interruption after claim'));
check($followups->get($id2)['state']==='unknown','interrupted scheduling becomes unknown');
check(!$followups->process($id2,'cognos-admin',fn()=>throw new RuntimeException('must not replay')),'unknown scheduling is not automatically replayed');
$q=$db->getQueryBuilder();$q->update('cognos_followups')->set('state',$q->createNamedParameter('claimed'))->set('updated_at',$q->createNamedParameter(1,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))->where($q->expr()->eq('result_id',$q->createNamedParameter($id2)))->executeStatement();
$followups->expireClaims(2);
check($followups->get($id2)['state']==='unknown','abandoned claim expires to unknown rather than pending');
echo "PASS\n";
