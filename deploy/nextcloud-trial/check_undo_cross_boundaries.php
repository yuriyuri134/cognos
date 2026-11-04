<?php
declare(strict_types=1);
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';
set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
use OCA\Cognos\Service\{ProjectService,RestructureService,DecisionService,RunService,LifecycleService,CreationSpaceService};
$db=\OC::$server->get(\OCP\IDBConnection::class);$p=\OC::$server->get(ProjectService::class);$s=\OC::$server->get(RestructureService::class);$d=\OC::$server->get(DecisionService::class);$runs=\OC::$server->get(RunService::class);$life=\OC::$server->get(LifecycleService::class);$cs=\OC::$server->get(CreationSpaceService::class);
$uid='cognos-admin';$request=fn()=>bin2hex(random_bytes(16));
function check(bool $yes,string $text):void{if(!$yes)throw new RuntimeException($text);echo $text."\n";}
function conflict(callable $fn,string $text):void{try{$fn();}catch(RuntimeException $e){check($e->getCode()===409,$text);return;}throw new RuntimeException('not rejected: '.$text);}
$apply=function(string $action,array $params)use($s,$uid,$request){return $s->apply($uid,$action,$params,$s->preview($uid,$action,$params)['basis'],$request());};
$eligible=function(string $pid,array $run)use($db,$life){$db->beginTransaction();try{$ok=$life->eligible($pid,$run);$db->commit();return $ok;}catch(Throwable $e){$db->rollBack();throw $e;}};
$run=function(string $loc)use($p,$runs,$uid){$l=$p->location($uid,$loc);return $runs->create('creation',(string)$l['creation_id'],'conversation','undo-permission','验证资格，不调用外部 AI','验证参与者',$uid,[]);};
$A=$p->create($uid,'验证：撤回资格源 '.time(),$request());$B=$p->create($uid,'验证：撤回资格目标 '.time(),$request());
$before=$run($A['location_id']);$targetBefore=$run($B['location_id']);
$m=$apply('merge',['project_id'=>$A['project_id'],'target_parent_id'=>$B['location_id']]);
check(!$eligible($B['project_id'],$targetBefore),'receiving project prior permission expires at merge');
$during=$run($A['location_id']);check($eligible($B['project_id'],$during),'fresh work under merged project qualifies');
$apply('undo',['change_id'=>$m['change_id']]);
check(!$eligible($A['project_id'],$before)&&!$eligible($A['project_id'],$during),'restoring original project identity does not revive either pre-merge or merged-work permission');
$fresh=$run($A['location_id']);check($eligible($A['project_id'],$fresh),'newly registered work under restored project qualifies');
$loc=$p->location($uid,$A['location_id']);$received=$runs->receiveResult($before,$request(),$before['subject'],[],fn()=>(int)$cs->addMessage($loc['creation_id'],$uid,'assistant','旧运行结果仍能交回，但不代表有改稿资格')['id']);
check(!$received['duplicate']&&$received['run']['state']==='delivered','old result is still durably received after undo');
$branch=$p->addLocation($uid,$A['project_id'],$A['location_id'],'独立后归回',$request());$beforeSplit=$run($branch['location_id']);
$i=$apply('independent',['location_id'=>$branch['location_id']]);$apply('undo',['change_id'=>$i['change_id']]);
check(!$eligible($A['project_id'],$beforeSplit),'undo independence never restores the old original-project permission');
// Decision spanning both sides cannot be silently dropped or broadened.
$C=$p->create($uid,'验证：撤回范围边界 '.time(),$request());$merge=$apply('merge',['project_id'=>$C['project_id'],'target_parent_id'=>$B['location_id']]);
$cross=$d->record($uid,$B['project_id'],'同时管两边的决定',[$C['location_id'],$B['location_id']],null,'',null,$uid,null,$request());
conflict(fn()=>$s->preview($uid,'undo',['change_id'=>$merge['change_id']]),'active decision crossing returning and remaining parts blocks undo');
$d->revoke($uid,$cross['decision_id'],$request());
check($s->preview($uid,'undo',['change_id'=>$merge['change_id']])['action']==='undo','revoking the ambiguous decision leaves its history and permits explicit re-preview');
$db->beginTransaction();try{$q=$db->getQueryBuilder();$q->update('cognos_restructures')->set('undo_snapshot',$q->createNamedParameter(null,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL))->where($q->expr()->eq('id',$q->createNamedParameter($merge['change_id'])))->executeStatement();conflict(fn()=>$s->preview($uid,'undo',['change_id'=>$merge['change_id']]),'missing legacy snapshot is rejected without guessing');}finally{$db->rollBack();}
$state=$p->project($uid,$A['project_id']);$p->changeLifecycle($uid,$A['project_id'],'pause',(int)$state['lifecycle_revision'],$request(),'暂停后不因撤回合并自动重开','明确再开');
$pausedMerge=$apply('merge',['project_id'=>$A['project_id'],'target_parent_id'=>$B['location_id']]);$apply('undo',['change_id'=>$pausedMerge['change_id']]);
check($p->project($uid,$A['project_id'])['state']==='paused','undo merge preserves original paused state instead of inheriting target active state');
conflict(fn()=>$run($A['location_id']),'paused restored project does not start new work');
echo "PASS\n";
