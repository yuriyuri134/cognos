<?php
declare(strict_types=1);
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';
set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
use OCA\Cognos\Service\{ProjectService,RunService,DispatchQueueService,CreationSpaceService};
$projects=\OC::$server->get(ProjectService::class);$runs=\OC::$server->get(RunService::class);$queue=\OC::$server->get(DispatchQueueService::class);$chats=\OC::$server->get(CreationSpaceService::class);$db=\OC::$server->get(\OCP\IDBConnection::class);
function check(bool $ok,string $note):void{if(!$ok)throw new RuntimeException($note);echo $note."\n";}
$p=$projects->create('cognos-admin','验证：运行队列结果同步 '.time(),bin2hex(random_bytes(16)));$loc=$projects->location('cognos-admin',$p['location_id']);$space=(string)$loc['creation_id'];
$make=function(string $owner)use($queue,$runs,$chats,$loc,$space):array{
 $msg=$chats->addMessage($loc['creation_id'],'cognos-admin','user','队列结果验证 '.$owner);$item=$queue->enqueue('creation',$space,'msg',(int)$msg['id']);$queue->claim('creation',$space,$owner);
 $run=$runs->create('creation',$space,'conversation','creation:'.$space,'验证','结果验证','cognos-admin',['extra'=>['reply_to'=>(int)$msg['id']]],['item_id'=>$item['id'],'followup_id'=>$owner]);return [$item,$run,(int)$msg['id']];
};
$store=fn()=>(int)$chats->addMessage($loc['creation_id'],'cognos-admin','assistant','验证结果')['id'];
[$item,$first,$ref]=$make('first');
$runs->failed($first['id'],'明确忙拒绝',true);$row=$queue->get($item['id']);check($row['state']==='queued'&&$row['run_id']===null,'busy refusal requeues original work without retaining dispatch authority');
$queue->claim('creation',$space,'second');$second=$runs->create('creation',$space,'conversation','creation:'.$space,'重排验证','结果验证','cognos-admin',['extra'=>['reply_to'=>$ref]],['item_id'=>$item['id'],'followup_id'=>'second']);
$runs->accepted($second['id']);check($queue->get($item['id'])['state']==='sent','accepted run marks its bound item sent');
$runs->receiveResult($first,bin2hex(random_bytes(16)),$first['subject'],[],$store);check($queue->get($item['id'])['run_id']===$second['id'],'late old receipt cannot overwrite new binding');
$runs->markUnknown($second['id'],'模拟失联');check($queue->get($item['id'])['state']==='unknown','lost running work makes queue unknown');
$runs->receiveResult($second,bin2hex(random_bytes(16)),$second['subject'],[],$store);check($queue->get($item['id'])['state']==='sent','late result resolves its own unknown queue state');
[$third,$r3]=$make('third');$runs->failed($r3['id'],'明确无效请求');check($queue->get($third['id'])['state']==='skipped','non-retryable refusal does not loop forever');
[$fourth,$r4]=$make('fourth');$db->beginTransaction();$runs->accepted($r4['id']);$db->rollBack();
check($runs->get($r4['id'])['state']==='queued'&&$queue->get($fourth['id'])['state']==='claimed','run and queue outcome roll back together');
check(!$queue->settle($fourth['id'],'fourth','queued'),'generic queue settlement cannot override a bound run');
$runs->accepted($r4['id']);$q=$db->getQueryBuilder();$q->update('cognos_runs')->set('lease_until',$q->createNamedParameter(1,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))->where($q->expr()->eq('id',$q->createNamedParameter($r4['id'])))->executeStatement();
check($runs->get($r4['id'])['state']==='unknown'&&$queue->get($fourth['id'])['state']==='unknown','lease expiry changes run and queue together');
echo "PASS\n";