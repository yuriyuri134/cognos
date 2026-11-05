<?php
declare(strict_types=1);
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
use OCA\Cognos\Service\{ProjectService,WorkItemService,RunService,LifecycleService,DecisionService,WorkDriftException};
$db=\OC::$server->get(\OCP\IDBConnection::class);$p=\OC::$server->get(ProjectService::class);$work=\OC::$server->get(WorkItemService::class);$runs=\OC::$server->get(RunService::class);$decisions=\OC::$server->get(DecisionService::class);
$uid='cognos-admin';$rid=fn()=>bin2hex(random_bytes(16));
function check(bool $yes,string $text):void{if(!$yes)throw new RuntimeException($text);echo $text."\n";}
function rejects(callable $fn,string $text,string $type=RuntimeException::class):?Throwable{try{$fn();}catch(Throwable $e){check($e instanceof $type,$text.' ('.get_class($e).')');return $e;}throw new RuntimeException('not rejected: '.$text);}
function fields(array $w):array{return array_intersect_key($w,array_flip(['description','owner','deliverable','acceptance']));}
function grant(array $f,bool $allow,string $subject):array{
 global $db,$p,$work,$runs,$uid,$rid;
 $id=$rid();$w=$work->get($uid,$f['work_id']);$now=time();$db->beginTransaction();try{
  $epoch=$work->attachDelegation($uid,$w['id'],$f['location_id'],$w['revision'],$id);
  $p->insert('cognos_delegations',['id'=>$id,'project_id'=>$f['project_id'],'location_id'=>$f['location_id'],'ask'=>'验证：只在这份工作授权内维护安排','goal'=>null,'means'=>null,'forbidden'=>null,'return_by'=>$now+600,'may_edit'=>0,'may_adopt'=>0,'may_manage_work'=>$allow?1:0,'base_revision'=>1,'state'=>'sent','note'=>null,'run_id'=>null,'message_id'=>0,'result_message_id'=>null,'applied_revision'=>null,'held_content'=>null,'held_base_revision'=>null,'held_reason'=>null,'held_at'=>null,'created_by'=>$uid,'created_at'=>$now,'updated_at'=>$now,'work_item_id'=>$w['id'],'work_epoch'=>$epoch]);
  $run=$runs->create('creation',(string)$f['space_id'],'delegation',$id,'数据库权限验证',$subject,$uid,[]);
  $q=$db->getQueryBuilder();$q->update('cognos_delegations')->set('run_id',$q->createNamedParameter($run['id']))->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();$db->commit();
 }catch(Throwable $e){$db->rollBack();throw $e;}
 $runs->accepted($run['id']);return ['run_id'=>$run['id'],'delegation_id'=>$id,'subject'=>$subject,'location_id'=>$f['location_id']];
}
function fixture(bool $allow=true,string $subject='工作维护验证 AI'):array{
 global $p,$work,$uid,$rid;
 $f=$p->create($uid,'验证：AI 工作授权 '.time(),$rid());$loc=$p->location($uid,$f['location_id']);$w=$work->create($uid,$loc['id'],['description'=>'核对来源范围','owner'=>'原安排','deliverable'=>'核验稿','acceptance'=>'可回查原文'],$rid());
 $f+=['work_id'=>$w['id'],'space_id'=>(string)$loc['creation_id']];$f['authority']=grant($f,$allow,$subject);return $f;
}
if(($argv[1]??'')==='seed'){$f=fixture();$f['denied']=fixture(false);echo json_encode($f,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;}
$f=fixture();$a=$f['authority'];$second=grant($f,true,'另一个维护 AI');$w=$work->get($uid,$f['work_id']);
$modified=fields($w);$modified['deliverable']='由本次 AI 补充的核验稿';$request=$rid();
$res=$work->update($uid,$w['id'],$w['revision'],$modified,'in_progress','','明确委托内补充交付',$request,$a);
$repeat=$work->update($uid,$w['id'],$w['revision'],$modified,'in_progress','','明确委托内补充交付',$request,$a);check($repeat===$res,'same agent update is idempotent');
$latest=$work->detail($uid,$w['id']);$e=$latest['events'][0];
check($e['actor']===$uid&&$e['actor_subject']===$a['subject']&&$e['run_id']===$a['run_id']&&$e['delegation_id']===$a['delegation_id'],'actual actor, account, run and delegation are separate provenance');
rejects(fn()=>$work->update($uid,$w['id'],$latest['revision'],fields($latest),'in_progress','','other stale grant',$rid(),$second),'other grant stays stale after acting agent changes scope');
$w=$work->get($uid,$w['id']);$work->update($uid,$w['id'],$w['revision'],fields($w),'in_progress','','own current grant continues',$rid(),$a);
check($work->get($uid,$w['id'])['revision']===$w['revision']+1,'acting grant may continue its own allowed maintenance');
$decisions->record($uid,$f['project_id'],'验证：新增决定必须指出实际取得范围',[],null,'',null,$uid,null,$rid());
$w=$work->get($uid,$w['id']);$err=rejects(fn()=>$work->update($uid,$w['id'],$w['revision'],fields($w),'in_progress','','must face changes',$rid(),$a),'new decision blocks unacknowledged work update',WorkDriftException::class);
check($work->get($uid,$w['id'])['revision']===$w['revision'],'drift refusal writes no work version');
$ack=$a+['acknowledged'=>true,'drift_token'=>$err->drift['drift_token']];
$work->update($uid,$w['id'],$w['revision'],fields($w),'in_progress','','received current change',$rid(),$ack);
check($work->detail($uid,$w['id'])['events'][0]['drift_token']===$err->drift['drift_token'],'exact compared drift is retained with the work event');
$w=$work->get($uid,$w['id']);$parts=[['description'=>'子项一'],['description'=>'子项二']];$preview=$work->previewSplit($uid,$w['id'],$parts);$splitRequest=$rid();$split=$work->split($uid,$w['id'],$parts,$preview['basis'],$splitRequest,$ack);
check($split===$work->split($uid,$w['id'],$parts,$preview['basis'],$splitRequest,$ack),'same split returns the same child ids after parent authorization has ended');
foreach($split['children'] as $child){$c=$work->detail($uid,$child['id']);check($c['created_subject']===$a['subject']&&$c['events'][0]['actor_subject']===$a['subject']&&$c['delegations']===[],'child records actual AI author and inherits no delegation');rejects(fn()=>$work->update($uid,$c['id'],1,fields($c),'open','','cannot inherit parent authority',$rid(),$a),'parent authority cannot maintain a child',DomainException::class);}
$no=fixture(false);$n=$work->get($uid,$no['work_id']);rejects(fn()=>$work->update($uid,$n['id'],$n['revision'],fields($n),'open','','not granted',$rid(),$no['authority']),'missing explicit maintenance permission is denied',DomainException::class);
$h=fixture();$w=$work->get($uid,$h['work_id']);$pfields=fields($w);$pfields['owner']='用户重新安排';$work->update($uid,$w['id'],$w['revision'],$pfields,'open','','用户调整',$rid());$w=$work->get($uid,$w['id']);rejects(fn()=>$work->update($uid,$w['id'],$w['revision'],fields($w),'open','','stale after human change',$rid(),$h['authority']),'human scope change invalidates the old acting grant');
$finish=fixture();$w=$work->get($uid,$finish['work_id']);$doneId=$rid();$done=$work->update($uid,$w['id'],$w['revision'],fields($w),'done','核验稿已交付并逐项回查','明确记录交付',$doneId,$finish['authority']);check($done===$work->update($uid,$w['id'],$w['revision'],fields($w),'done','核验稿已交付并逐项回查','明确记录交付',$doneId,$finish['authority']),'completed work operation remains receiptable');
$w=$work->get($uid,$w['id']);rejects(fn()=>$work->update($uid,$w['id'],$w['revision'],fields($w),'open','','cannot self-reopen',$rid(),$finish['authority']),'completion does not renew the acting grant');
$paused=fixture();$p->changeLifecycle($uid,$paused['project_id'],'pause',1,$rid(),'暂停验证','');$w=$work->get($uid,$paused['work_id']);rejects(fn()=>$work->update($uid,$w['id'],$w['revision'],fields($w),'open','','paused',$rid(),$paused['authority']),'paused project blocks work maintenance');
echo "PASS\n";
