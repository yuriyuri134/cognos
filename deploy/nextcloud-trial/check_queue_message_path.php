<?php
declare(strict_types=1);
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';
set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
use OCP\Http\Client\{IClient,IClientService};
use OCA\Cognos\Service\{ProjectService,RunService,FollowupService,FollowupDispatcher,PushBackService,HermesAgentService,ClaudeBridgeService,CreationSpaceService,DecisionService,DispatchQueueService};
function check(bool $ok,string $text):void{if(!$ok)throw new RuntimeException($text);echo $text."\n";}
$real=\OC::$server->get(IClientService::class);
$http=new class($real) implements IClientService {
 public int $status=409;public array $bodies=[];
 public function __construct(private IClientService $real){}
 public function newClient(?callable $handler=null):IClient{return $this->real->newClient(function($request){$this->bodies[]=json_decode((string)$request->getBody(),true);return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response($this->status,[],json_encode(['error'=>'verification response'])));});}
};
$projects=\OC::$server->get(ProjectService::class);$runs=\OC::$server->get(RunService::class);$events=\OC::$server->get(FollowupService::class);$dispatcher=\OC::$server->get(FollowupDispatcher::class);$push=\OC::$server->get(PushBackService::class);$hermes=\OC::$server->get(HermesAgentService::class);$chats=\OC::$server->get(CreationSpaceService::class);$queue=\OC::$server->get(DispatchQueueService::class);$users=\OC::$server->get(\OCP\IUserSession::class);
$config=\OC::$server->get(\OCP\IConfig::class);check($config->getAppValue('cognos','creation_agent','hermes')==='claude-code','current trial uses Claude bridge');
$bridge=new ClaudeBridgeService($http,$config,$runs,\OC::$server->get(DecisionService::class));
(new ReflectionProperty($hermes,'bridge'))->setValue($hermes,$bridge);(new ReflectionProperty($push,'bridge'))->setValue($push,$bridge);
$fixture=function()use($projects,$runs,$events,$push,$chats):array{
 $p=$projects->create('cognos-admin','验证：持久队列实际消息路径 '.time(),bin2hex(random_bytes(16)));$loc=$projects->location('cognos-admin',$p['location_id']);$space=(string)$loc['creation_id'];
 $prior=$chats->addMessage($loc['creation_id'],'cognos-admin','user','更早的问题');
 $msg=$chats->addMessage($loc['creation_id'],'cognos-admin','user','排队的验证原话');$push->enqueueMessage('creation',$space,(int)$msg['id']);
 $chats->addMessage($loc['creation_id'],'cognos-admin','user','后来的新请求不应进入本次输入');
 $chats->addMessage($loc['creation_id'],'cognos-admin','assistant','更早问题的已完成回答',(int)$prior['id']);
 $source=$runs->create('creation',$space,'conversation','verification','source','验证','cognos-admin',[]);$result=bin2hex(random_bytes(16));
 $runs->receiveResult($source,$result,$source['subject'],[],fn()=>(int)$chats->addMessage($loc['creation_id'],'cognos-admin','assistant','前一件的验证结果')['id'],fn()=>$events->enqueue($result,$source,'cognos-admin'));
 return $p+['space'=>$space,'message_id'=>(int)$msg['id'],'result_id'=>$result];
};
$first=$fixture();$before=$users->getUser();$dispatcher->process($first['result_id'],'cognos-admin');
check($events->get($first['result_id'])['state']==='pending','explicit busy rejection retains followup pending');
$http->status=202;$dispatcher->process($first['result_id'],'cognos-admin');
check($events->get($first['result_id'])['state']==='attempted','next accepted dispatch finishes scheduling once');
check(count($http->bodies)===2,'one busy attempt and one accepted attempt');
$history=json_encode($http->bodies[1]['history'],JSON_UNESCAPED_UNICODE);check(str_contains($history,'更早问题的已完成回答')&&!str_contains($history,'后来的新请求'),'queued input includes earlier completed answer but excludes later user request');
$runId=$http->bodies[1]['run_id'];$db=\OC::$server->get(\OCP\IDBConnection::class);$q=$db->getQueryBuilder();$item=$q->select('*')->from('cognos_dispatch_queue')->where($q->expr()->eq('run_id',$q->createNamedParameter($runId)))->executeQuery()->fetch();
check($item&&$item['state']==='sent'&&(int)$item['ref_id']===$first['message_id'],'accepted run is bound to the original queued message');
$dispatcher->process($first['result_id'],'cognos-admin');check(count($http->bodies)===2,'repeated recovery does not redispatch');
$run=$runs->get($runId);$runs->receiveResult($run,bin2hex(random_bytes(16)),$run['subject'],[],fn()=>(int)$chats->addMessage((int)$first['space'],'cognos-admin','assistant','排队工作结果')['id']);
$second=$fixture();$http->status=500;$dispatcher->process($second['result_id'],'cognos-admin');
check($events->get($second['result_id'])['state']==='unknown','uncertain dispatch leaves followup unknown');
$unknown=$runs->get($http->bodies[2]['run_id']);check($unknown['state']==='unknown','server error preserves actual run uncertainty');
$q=$db->getQueryBuilder();$item=$q->select('*')->from('cognos_dispatch_queue')->where($q->expr()->eq('run_id',$q->createNamedParameter($unknown['id'])))->executeQuery()->fetch();check($item['state']==='unknown','uncertain dispatch blocks queue');
$projects->commit('cognos-admin',bin2hex(random_bytes(16)),['verification-close',$second['result_id']],fn()=>$events->close($second['result_id'],'cognos-admin','unknown','验证核对：关闭本次接续，不重放'));
check($queue->get($item['id'])['state']==='reconciled','owner reconciliation closes associated unknown queue item');
check($runs->get($unknown['id'])['state']==='unknown','reconciliation does not fabricate run completion');
$third=$projects->create('cognos-admin','验证：持久队列反对路径 '.time(),bin2hex(random_bytes(16)));$loc=$projects->location('cognos-admin',$third['location_id']);$space=(string)$loc['creation_id'];
$spaces=\OC::$server->get(\OCA\Cognos\Service\SpaceService::class);
$premise=$spaces->addTrace('creation',$space,['kind'=>'premise','source'=>'AI','author'=>'Claude Code','content'=>'验证用前提']);
$hit=$spaces->addTrace('creation',$space,['kind'=>'hit','source'=>'原','author'=>'cognos-admin','content'=>'验证用反对','ref_id'=>$premise['id'],'said_at'=>time()]);
$http->status=409;$called=$push->afterHit('creation',$space,'cognos-admin',$hit);check(!$called['called'],'immediate hit busy refusal remains queued');
$source=$runs->create('creation',$space,'conversation','test','hit source','验证','cognos-admin',[]);$result=bin2hex(random_bytes(16));
$runs->receiveResult($source,$result,$source['subject'],[],fn()=>(int)$chats->addMessage($loc['creation_id'],'cognos-admin','assistant','触发反对队列恢复')['id'],fn()=>$events->enqueue($result,$source,'cognos-admin'));
$http->status=202;$dispatcher->process($result,'cognos-admin');$body=end($http->bodies);
check((int)$body['hit_id']===$hit['id'],'queued hit dispatches its original trace');
$q=$db->getQueryBuilder();$hitItem=$q->select('*')->from('cognos_dispatch_queue')->where($q->expr()->eq('run_id',$q->createNamedParameter($body['run_id'])))->executeQuery()->fetch();
check($hitItem['kind']==='hit'&&$hitItem['state']==='sent','hit dispatch carries durable queue binding');
check($events->get($result)['state']==='attempted','hit recovery finishes one scheduling intent');
$runs->markUnknown($body['run_id'],'验证桥没有真正运行外部 AI，等待核对');
check($users->getUser()===$before,'caller identity restored');echo "PASS\n";