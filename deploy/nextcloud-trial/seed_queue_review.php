<?php
declare(strict_types=1);define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
use OCA\Cognos\Service\{ProjectService,RunService,DispatchQueueService,SpaceService};
$p=\OC::$server->get(ProjectService::class);$runs=\OC::$server->get(RunService::class);$queue=\OC::$server->get(DispatchQueueService::class);$spaces=\OC::$server->get(SpaceService::class);
$fixture=function(bool $associate=false)use($p,$runs,$queue,$spaces):array{
 $project=$p->create('cognos-admin','验证：独立反对未知核对 '.time(),bin2hex(random_bytes(16)));$loc=$p->location('cognos-admin',$project['location_id']);$space=(string)$loc['creation_id'];
 $premise=$spaces->addTrace('creation',$space,['kind'=>'premise','source'=>'AI','author'=>'Claude Code','content'=>'验证原前提']);$hit=$spaces->addTrace('creation',$space,['kind'=>'hit','source'=>'原','author'=>'cognos-admin','content'=>'验证原反对','ref_id'=>$premise['id']]);
 $item=$queue->enqueue('creation',$space,'hit',$hit['id']);$owner='hit-wakeup-verification';
 if($associate){
  $events=\OC::$server->get(\OCA\Cognos\Service\FollowupService::class);$chats=\OC::$server->get(\OCA\Cognos\Service\CreationSpaceService::class);
  $source=$runs->create('creation',$space,'conversation','verification','源接续验证','验证','cognos-admin',[]);$owner=bin2hex(random_bytes(16));
  $runs->receiveResult($source,$owner,$source['subject'],[],fn()=>(int)$chats->addMessage((int)$space,'cognos-admin','assistant','源接续验证结果')['id'],fn()=>$events->enqueue($owner,$source,'cognos-admin'));
 }
 $queue->claim('creation',$space,$owner);
 $run=$runs->create('creation',$space,'hit',(string)$hit['id'],'验证未知反对派发','Claude Code','cognos-admin',[],['item_id'=>$item['id'],'followup_id'=>$owner]);$runs->dispatchUnknown($run['id'],'验证：独立叫回响应丢失');
 if($associate)$events->process($owner,'cognos-admin',fn()=>throw new RuntimeException('验证接续中断'));
 return $project+['followup_id'=>$associate?$owner:null,'creation_id'=>$loc['creation_id'],'item_id'=>$item['id'],'run_id'=>$run['id']];
};
echo json_encode($fixture()+['api_fixture'=>$fixture(true)]);