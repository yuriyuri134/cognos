<?php
declare(strict_types=1);
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
use OCA\Cognos\Service\{AttemptService,ProjectService};
$db=\OC::$server->get(\OCP\IDBConnection::class);$a=\OC::$server->get(AttemptService::class);$p=\OC::$server->get(ProjectService::class);$uid='cognos-admin';$rid=fn()=>bin2hex(random_bytes(16));
$mode=$argv[1]??'';$data=isset($argv[2])?json_decode(base64_decode($argv[2]),true):[];
if($mode==='setup'){$f=$p->create($uid,'验证：尝试与阶段并发 '.time(),$rid());$attempt=$a->create($uid,$f['location_id'],['title'=>'原尝试简介'],$rid());echo json_encode($f+['attempt_id'=>$attempt['id'],'token'=>bin2hex(random_bytes(8))]);exit;}
if($mode==='verify'){$d=$a->detail($uid,$data['attempt_id']);$entry=$a->entrySummary($uid,$data['location_id']);echo json_encode(['revision'=>$d['revision'],'title'=>$d['title'],'summaries'=>$d['summaries'],'entry'=>$entry['items'][0]]);exit;}
$holding=str_starts_with($mode,'hold-');$label=$holding?'先取得锁的一方':'等待锁的一方';
$operation=function(string $name)use($a,$uid,$data,$rid,$label):array{
 if($name==='append')return $a->addSummary($uid,$data['attempt_id'],['content'=>$label.'的阶段总结'],$rid());
 if($name==='update')return $a->update($uid,$data['attempt_id'],1,['title'=>$label.'的简介修改'],$rid());
 throw new RuntimeException('unknown operation');
};
if($holding){
 $db->beginTransaction();try{
  $result=$operation(substr($mode,5));echo "LOCKED\n";flush();$release='/tmp/cognos-attempt-concurrency-'.$data['token'];$deadline=microtime(true)+20;
  while(!file_exists($release)){if(microtime(true)>$deadline)throw new RuntimeException('release timeout');usleep(20000);clearstatcache(true,$release);}
  $db->commit();echo json_encode(['ok'=>true,'result'=>$result]);
 }catch(Throwable $e){$db->rollBack();throw $e;}
}else{
 echo "ATTEMPT\n";flush();try{echo json_encode(['ok'=>true,'result'=>$operation($mode)]);}catch(RuntimeException $e){echo json_encode(['ok'=>false,'code'=>$e->getCode(),'error'=>$e->getMessage()]);}
}
