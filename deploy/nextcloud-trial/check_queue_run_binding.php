<?php
declare(strict_types=1);
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';
set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
use OCA\Cognos\Service\{ProjectService,RunService,DispatchQueueService,CreationSpaceService};
$projects=\OC::$server->get(ProjectService::class);$runs=\OC::$server->get(RunService::class);$queue=\OC::$server->get(DispatchQueueService::class);$chats=\OC::$server->get(CreationSpaceService::class);$db=\OC::$server->get(\OCP\IDBConnection::class);
function check(bool $ok,string $note):void{if(!$ok)throw new RuntimeException($note);echo $note."\n";}
$p=$projects->create('cognos-admin','验证：队列运行原子关联 '.time(),bin2hex(random_bytes(16)));$loc=$projects->location('cognos-admin',$p['location_id']);$space=(string)$loc['creation_id'];
$message=$chats->addMessage($loc['creation_id'],'cognos-admin','user','验证用原消息');$ref=(int)$message['id'];
$item=$queue->enqueue('creation',$space,'msg',$ref);$claim=$queue->claim('creation',$space,'verify-owner');
$start=fn(array $extra,array $claim,string $which='')=>$runs->create('creation',$which?:$space,'conversation','creation:'.($which?:$space),'原子绑定验证','绑定验证','cognos-admin',['extra'=>$extra],$claim);
$owner=['item_id'=>$item['id'],'followup_id'=>'verify-owner'];
foreach([['extra'=>['reply_to'=>$ref],'claim'=>$owner+['unused'=>1],'space'=>'999999'],['extra'=>['reply_to'=>$ref+1],'claim'=>$owner],['extra'=>['reply_to'=>$ref],'claim'=>['item_id'=>$item['id'],'followup_id'=>'wrong-owner']]] as $bad){
 try{$start($bad['extra'],$bad['claim'],$bad['space']??'');throw new Exception('bad binding accepted');}catch(RuntimeException $e){check($e->getCode()===409,'wrong space/reference/claim is rejected');}
}
check($runs->forSpace('creation',$space)===[],'binding failure rolls back newly inserted run');check($queue->get($item['id'])['run_id']===null,'binding failure leaves claim unchanged');
$db->beginTransaction();$temporary=$start(['reply_to'=>$ref],$owner);check($queue->get($item['id'])['run_id']===$temporary['id'],'binding visible with run inside transaction');$db->rollBack();
check($runs->get($temporary['id'])===null&&$queue->get($item['id'])['run_id']===null,'outer rollback removes run and binding together');
$valid=$start(['reply_to'=>$ref],$owner);check($queue->get($item['id'])['run_id']===$valid['id'],'run and queue association commit together');
try{$start(['reply_to'=>$ref],$owner);throw new Exception('double binding accepted');}catch(RuntimeException $e){check($e->getCode()===409,'same claimed item cannot create a second run');}
check(count($runs->forSpace('creation',$space))===1,'duplicate dispatch leaves exactly one run');
$runs->failed($valid['id'],'verification complete');$queue->settle($item['id'],'verify-owner','skipped','验证完成，不派发');
$hit=$queue->enqueue('creation',$space,'hit',999999);$queue->claim('creation',$space,'hit-owner');
$hitRun=$runs->create('creation',$space,'hit','999999','反对引用绑定验证','绑定验证','cognos-admin',[],['item_id'=>$hit['id'],'followup_id'=>'hit-owner']);
check($queue->get($hit['id'])['run_id']===$hitRun['id'],'hit reference binds through the same transaction');
$q=$db->getQueryBuilder();$snapshot=$q->select('input_snapshot')->from('cognos_runs')->where($q->expr()->eq('id',$q->createNamedParameter($hitRun['id'])))->executeQuery()->fetchOne();
check(json_decode($snapshot,true)['queue_claim']['item_id']===$hit['id'],'immutable input snapshot retains queue claim');
$runs->failed($hitRun['id'],'verification complete');$queue->settle($hit['id'],'hit-owner','skipped','验证完成，不派发');
echo "PASS\n";