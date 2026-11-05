<?php
declare(strict_types=1);
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';
set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
use OCA\Cognos\Service\{ProjectService,WorkItemService,RunService,LifecycleService,AdoptionService,DelegationService,CreationSpaceService};
$db=\OC::$server->get(\OCP\IDBConnection::class);$p=\OC::$server->get(ProjectService::class);$work=\OC::$server->get(WorkItemService::class);$runs=\OC::$server->get(RunService::class);$life=\OC::$server->get(LifecycleService::class);$uid='cognos-admin';$req=fn()=>bin2hex(random_bytes(16));
function check(bool $yes,string $text):void{if(!$yes)throw new RuntimeException($text);echo $text."\n";}
function denied(callable $f,string $text):void{try{$f();}catch(RuntimeException $e){check($e->getCode()===409,$text);return;}throw new RuntimeException('operation was not rejected: '.$text);}
$P=$p->create($uid,'验证：拆分授权 '.time(),$req());$loc=$p->location($uid,$P['location_id']);$W=$work->create($uid,$loc['id'],['description'=>'拆分前的工作','owner'=>'原负责人'],$req());
$d=$req();$db->beginTransaction();try{
 $epoch=$work->attachDelegation($uid,$W['id'],$loc['id'],1,$d);$now=time();
 $p->insert('cognos_delegations',['id'=>$d,'project_id'=>$P['project_id'],'location_id'=>$loc['id'],'ask'=>'只验证数据库，不调用外部 AI','goal'=>null,'means'=>null,'forbidden'=>null,'return_by'=>$now+600,'may_edit'=>1,'may_adopt'=>1,'base_revision'=>1,'state'=>'sent','note'=>null,'run_id'=>null,'message_id'=>0,'result_message_id'=>null,'applied_revision'=>null,'held_content'=>null,'held_base_revision'=>null,'held_reason'=>null,'held_at'=>null,'created_by'=>$uid,'created_at'=>$now,'updated_at'=>$now,'work_item_id'=>$W['id'],'work_epoch'=>$epoch]);
 $run=$runs->create('creation',(string)$loc['creation_id'],'delegation',$d,'拆分资格验证','验证参与者',$uid,[]);
 $q=$db->getQueryBuilder();$q->update('cognos_delegations')->set('run_id',$q->createNamedParameter($run['id']))->where($q->expr()->eq('id',$q->createNamedParameter($d)))->executeStatement();$db->commit();
}catch(Throwable $e){$db->rollBack();throw $e;}
$db->beginTransaction();check($life->delegationEligible($P['project_id'],$d),'original linked delegation initially qualifies');$db->commit();
$parts=[['description'=>'子项甲'],['description'=>'子项乙']];$plan=$work->previewSplit($uid,$W['id'],$parts);$split=$work->split($uid,$W['id'],$parts,$plan['basis'],$req());
$db->beginTransaction();check(!$life->delegationEligible($P['project_id'],$d),'split revokes original work write/adopt permission');$db->commit();
denied(fn()=>$p->updateContent($uid,$loc['id'],1,'旧工作不应覆盖当前稿',[],$req(),'验证参与者',$d),'old delegated write cannot modify location after split');
$adopt=\OC::$server->get(AdoptionService::class)->agentAdopt($uid,$loc['id'],$run,\OC::$server->get(DelegationService::class)->forRun($run['id']),'location_content',$loc['id'],'1','旧工作采用不得直接生效');
check($adopt['state']==='proposed','old delegation can leave a proposal but cannot directly adopt after split');
denied(fn()=>$runs->create('creation',(string)$loc['creation_id'],'delegation',$d,'不得重派','验证参与者',$uid,[]),'old work delegation cannot start another run after split');
foreach($split['children'] as $child){$w=$work->detail($uid,$child['id']);check($w['owner']===''&&$w['delegations']===[],'child inherits neither responsible person nor any original delegation');}
$cs=\OC::$server->get(CreationSpaceService::class);$result=$runs->receiveResult($run,$req(),$run['subject'],[],fn()=>(int)$cs->addMessage($loc['creation_id'],$uid,'assistant','原工作迟到结果仍保留在原处')['id']);
check($result['run']['state']==='delivered'&&$work->get($uid,$W['id'])['state']==='split','late original result is received and does not mark any work complete');
// A changed scope and a later reopen cannot reactivate the old delegation.
$C=$work->create($uid,$loc['id'],['description'=>'改范围的工作'],$req());
$w=$work->get($uid,$C['id']);$work->update($uid,$w['id'],1,['description'=>$w['description']],'cancelled','','明确取消',$req());
$w=$work->get($uid,$C['id']);$work->update($uid,$w['id'],$w['revision'],['description'=>$w['description']],'open','','重新安排范围',$req());
check($work->get($uid,$C['id'])['execution_epoch']>1,'cancel and reopen retain history and advance work permission epoch');
echo "PASS\n";
