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

$mode=$argv[1]??'';$data=isset($argv[2])?json_decode(base64_decode($argv[2]),true):[];
if($mode==='setup'){
 $f=fixture();$w=$work->get($uid,$f['work_id']);$parts=[['description'=>'并发拆出甲'],['description'=>'并发拆出乙']];
 echo json_encode($f+['expected'=>$w['revision'],'fields'=>fields($w),'parts'=>$parts,'basis'=>$work->previewSplit($uid,$w['id'],$parts)['basis'],'token'=>bin2hex(random_bytes(8))]);exit;
}
$op=function(string $name)use($data,$p,$work,$runs,$uid,$rid):array{
 if($name==='manage'){$fields=$data['fields'];$fields['deliverable']='并发验证修改后的交付';return $work->update($uid,$data['work_id'],$data['expected'],$fields,'in_progress','','并发验证授权内维护',$rid(),$data['authority']);}
 if($name==='split')return $work->split($uid,$data['work_id'],$data['parts'],$data['basis'],$rid(),$data['authority']);
 if($name==='pause')return $p->changeLifecycle($uid,$data['project_id'],'pause',1,$rid(),'并发验证暂停','');
 if($name==='unknown'){$runs->markUnknown($data['authority']['run_id'],'验证：当前运行失联');return ['state'=>$runs->get($data['authority']['run_id'])['state']];}
 if($name==='finish'){
  $run=$runs->get($data['authority']['run_id']);$creations=\OC::$server->get(\OCA\Cognos\Service\CreationSpaceService::class);
  return $runs->receiveResult($run,$rid(),$run['subject'],[],fn():int=>(int)$creations->addMessage((int)$data['space_id'],$uid,'assistant','验证：授权内工作与结果交回交错')['id']);
 }
 throw new RuntimeException('unknown operation');
};
if(str_starts_with($mode,'hold-')){
 $db->beginTransaction();try{
  $result=$op(substr($mode,5));echo "LOCKED\n";flush();$release='/tmp/cognos-work-management-concurrency-'.$data['token'];$deadline=microtime(true)+20;
  while(!file_exists($release)){if(microtime(true)>$deadline)throw new RuntimeException('release timeout');usleep(20000);clearstatcache(true,$release);}
  $db->commit();echo json_encode(['ok'=>true,'result'=>$result]);
 }catch(Throwable $e){$db->rollBack();throw $e;}
}elseif($mode==='verify'){
 $w=$work->detail($uid,$data['work_id']);$run=$runs->get($data['authority']['run_id']);
 $q=$db->getQueryBuilder();$count=(int)$q->selectAlias($q->func()->count('*'),'n')->from('cognos_run_results')->where($q->expr()->eq('run_id',$q->createNamedParameter($run['id'])))->executeQuery()->fetchOne();
 echo json_encode(['work_revision'=>$w['revision'],'work_state'=>$w['state'],'children'=>count($w['children']),'run_state'=>$run['state'],'receipts'=>$count,'project_state'=>$p->project($uid,$data['project_id'])['state']]);
}else{
 echo "ATTEMPT\n";flush();try{echo json_encode(['ok'=>true,'result'=>$op($mode)]);}catch(RuntimeException $e){echo json_encode(['ok'=>false,'code'=>$e->getCode(),'error'=>$e->getMessage()]);}
}
