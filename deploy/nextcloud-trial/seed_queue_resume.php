<?php
declare(strict_types=1);define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
use OCA\Cognos\Service\{ProjectService,RunService,DispatchQueueService,CreationSpaceService,FollowupDispatcher};
$p=\OC::$server->get(ProjectService::class);$runs=\OC::$server->get(RunService::class);$queue=\OC::$server->get(DispatchQueueService::class);$chats=\OC::$server->get(CreationSpaceService::class);
if(($argv[1]??'')==='process'){$data=json_decode(base64_decode($argv[2]),true);\OC::$server->get(FollowupDispatcher::class)->process($data['followup_id'],'cognos-admin');echo "processed\n";exit;}
$fixture=function()use($p,$runs,$queue,$chats):array{
 $project=$p->create('cognos-admin','验证：旧项明确继续 '.time(),bin2hex(random_bytes(16)));$loc=$p->location('cognos-admin',$project['location_id']);$space=(string)$loc['creation_id'];
 $a=$chats->addMessage((int)$space,'cognos-admin','user','这是旧项核对验证，请仅回答一句：第一项保留，不执行。');$b=$chats->addMessage((int)$space,'cognos-admin','user','这是第二项核对验证，仅回答一句确认，不用任何外部工具。');
 $items=$queue->importHeldLegacy('creation',$space,['msg:'.$a['id'],'msg:'.$b['id']]);
 $blocker=$runs->create('creation',$space,'conversation','verification','仅为验证暂缓后台，不调用模型','旧项核对占位验证','cognos-admin',[]);$runs->startedByAgent($blocker['id']);
 return $project+['creation_id'=>(int)$space,'first_item_id'=>$items[0]['id'],'item_id'=>$items[1]['id'],'blocker_id'=>$blocker['id']];
};echo json_encode($fixture()+['api_fixture'=>$fixture()]);