<?php
declare(strict_types=1);
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';
set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
use OCA\Cognos\Service\{ProjectService,RunService,LifecycleService,AdoptionService,DelegationService,RestructureService};
$db=\OC::$server->get(\OCP\IDBConnection::class);$projects=\OC::$server->get(ProjectService::class);$runs=\OC::$server->get(RunService::class);$life=\OC::$server->get(LifecycleService::class);
$mode=$argv[1];$data=isset($argv[2])?json_decode(base64_decode($argv[2]),true):[];
if($mode==='setup'){
 $p=$projects->create('cognos-admin','验证：交错事务 '.time(),bin2hex(random_bytes(16)));$loc=$projects->location('cognos-admin',$p['location_id']);
 $target=$projects->create('cognos-admin','验证：合并目标 '.time(),bin2hex(random_bytes(16)));
 $d=bin2hex(random_bytes(16));$run=$runs->create('creation',(string)$loc['creation_id'],'delegation',$d,'并发验证','并发验证','cognos-admin',[]);
 $now=time();$projects->insert('cognos_delegations',['id'=>$d,'project_id'=>$p['project_id'],'location_id'=>$p['location_id'],'ask'=>'并发验证，不调用外部 AI','goal'=>null,'means'=>null,'forbidden'=>null,'return_by'=>$now+600,'may_edit'=>1,'may_adopt'=>1,'base_revision'=>1,'state'=>'sent','note'=>null,'run_id'=>$run['id'],'message_id'=>0,'result_message_id'=>null,'applied_revision'=>null,'held_content'=>null,'held_base_revision'=>null,'held_reason'=>null,'held_at'=>null,'created_by'=>'cognos-admin','created_at'=>$now,'updated_at'=>$now]);
 echo json_encode($p+['creation_id'=>$loc['creation_id'],'delegation_id'=>$d,'run_id'=>$run['id'],'target_location_id'=>$target['location_id'],'token'=>bin2hex(random_bytes(8))]);exit;
}
$merge=function()use($data){$service=\OC::$server->get(RestructureService::class);$params=['project_id'=>$data['project_id'],'target_parent_id'=>$data['target_location_id']];$preview=$service->preview('cognos-admin','merge',$params);return $service->apply('cognos-admin','merge',$params,$preview['basis'],bin2hex(random_bytes(16)));};
$op=function(string $name)use($projects,$runs,$data,$merge){
 return match($name){
 'merge'=>$merge(),
 'pause'=>$projects->changeLifecycle('cognos-admin',$data['project_id'],'pause',1,bin2hex(random_bytes(16)),'并发测试',''),
 'start'=>$runs->create('creation',(string)$data['creation_id'],'conversation','concurrency','并发验证','并发验证','cognos-admin',[]),
 'adopt'=>\OC::$server->get(AdoptionService::class)->agentAdopt('cognos-admin',$data['location_id'],$runs->get($data['run_id']),\OC::$server->get(DelegationService::class)->forRun($data['run_id']),'location_content',$data['location_id'],'1','并发采用验证'),
 'write'=>$projects->updateContent('cognos-admin',$data['location_id'],1,'并发测试写入',[],bin2hex(random_bytes(16)),'并发验证',$data['delegation_id']),
 default=>throw new RuntimeException('unknown operation')};
};
if(str_starts_with($mode,'hold-')){
 $db->beginTransaction();
 try{
  $result=$op(substr($mode,5));echo "LOCKED\n";flush();
  $release='/tmp/cognos-concurrency-'.$data['token'];$deadline=microtime(true)+20;
  while(!file_exists($release)){if(microtime(true)>$deadline)throw new RuntimeException('release timeout');usleep(20000);clearstatcache(true,$release);}
  $db->commit();echo json_encode(['ok'=>true,'result'=>$result]);
 }catch(Throwable $e){$db->rollBack();throw $e;}
}else{
 echo "ATTEMPT\n";flush();
 try{echo json_encode(['ok'=>true,'result'=>$op($mode)]);}catch(RuntimeException $e){echo json_encode(['ok'=>false,'code'=>$e->getCode(),'error'=>$e->getMessage()]);}
}