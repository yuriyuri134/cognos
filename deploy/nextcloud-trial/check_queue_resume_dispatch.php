<?php
declare(strict_types=1);define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
use OCP\Http\Client\{IClient,IClientService};use OCA\Cognos\Service\{FollowupDispatcher,FollowupService,RunService,DispatchQueueService,CreationSpaceService,HermesAgentService,PushBackService,ClaudeBridgeService,DecisionService};
function check(bool $ok,string $s):void{if(!$ok)throw new RuntimeException($s);echo $s."\n";}
$data=json_decode(base64_decode($argv[1]),true);$p=$data['api_fixture'];$runs=\OC::$server->get(RunService::class);$queue=\OC::$server->get(DispatchQueueService::class);$events=\OC::$server->get(FollowupService::class);$chats=\OC::$server->get(CreationSpaceService::class);
$blocker=$runs->get($p['blocker_id']);if($blocker['state']!=='delivered')$runs->receiveResult($blocker,bin2hex(random_bytes(16)),$blocker['subject'],[],fn()=>(int)$chats->addMessage($p['creation_id'],'cognos-admin','assistant','验证占位结束，没有调用模型')['id']);
$http=new class(\OC::$server->get(IClientService::class)) implements IClientService {
 public int $calls=0;public function __construct(private IClientService $real){}
 public function newClient(?callable $handler=null):IClient{return $this->real->newClient(function(){$this->calls++;return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(202,[],'{}'));});}
};
$bridge=new ClaudeBridgeService($http,\OC::$server->get(\OCP\IConfig::class),$runs,\OC::$server->get(DecisionService::class));
(new ReflectionProperty(HermesAgentService::class,'bridge'))->setValue(\OC::$server->get(HermesAgentService::class),$bridge);(new ReflectionProperty(PushBackService::class,'bridge'))->setValue(\OC::$server->get(PushBackService::class),$bridge);
$dispatcher=\OC::$server->get(FollowupDispatcher::class);$id=$data['new_followup']['followup_id'];check($dispatcher->process($id,'cognos-admin'),'durable reviewed intent is recoverable');
check($events->get($id)['state']==='attempted'&&$http->calls===1,'one reviewed dispatch attempt');
$selected=$queue->get($p['item_id']);check($selected['state']==='sent'&&$selected['run_id']!==null,'selected second item bound and dispatched');
check($queue->get($p['first_item_id'])['state']==='held','unselected first item stays held');
$dispatcher->process($id,'cognos-admin');check($http->calls===1,'recovery retry does not dispatch again');
$runs->markUnknown($selected['run_id'],'替身桥验证结束，无真实外部模型执行');$projects=\OC::$server->get(\OCA\Cognos\Service\ProjectService::class);$isolated=$projects->create('cognos-admin','验证：许可不能被普通接续绕过 '.time(),bin2hex(random_bytes(16)));$loc=$projects->location('cognos-admin',$isolated['location_id']);$sid=(string)$loc['creation_id'];
$msg=$chats->addMessage((int)$sid,'cognos-admin','user','验证单项许可');$one=$queue->importHeldLegacy('creation',$sid,['msg:'.$msg['id']])[0];$queue->releaseHeld($one['id'],$sid,'cognos-admin','服务层隔离检查，不实际派发');
check($queue->claim('creation',$sid,'ordinary-auto')===null,'ordinary automatic selection cannot take a reviewed legacy item');
$owned=$queue->claim('creation',$sid,'explicit-owner',$one['id'],true);check($owned!==null,'explicit selected permission may claim it');$queue->settle($one['id'],'explicit-owner','skipped','隔离检查结束，未实际派发');
echo "PASS\n";