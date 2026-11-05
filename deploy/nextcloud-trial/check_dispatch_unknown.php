<?php
declare(strict_types=1);
define('OC_CONSOLE',1);
$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';
require '/var/www/html/lib/base.php';
set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCA\Cognos\Service\{ProjectService,RunService,DecisionService,ClaudeBridgeService,LifecycleService};
function check(bool $ok,string $what):void{if(!$ok)throw new RuntimeException($what);echo $what."\n";}
$projects=\OC::$server->get(ProjectService::class);$runs=\OC::$server->get(RunService::class);
$config=\OC::$server->get(\OCP\IConfig::class);$db=\OC::$server->get(\OCP\IDBConnection::class);
$real=\OC::$server->get(IClientService::class);
$p=$projects->create('cognos-admin','验证：桥回执未知 '.time(),bin2hex(random_bytes(16)));
$loc=$projects->location('cognos-admin',$p['location_id']);
foreach(['lost','500','409','202'] as $mode){
 $http=new class($real,$mode) implements IClientService {
  public int $calls=0;
  public function __construct(private IClientService $real,private string $mode){}
  public function newClient(?callable $handler=null):IClient{
   return $this->real->newClient(function($request,$options){$this->calls++;
    if($this->mode==='lost')return new \GuzzleHttp\Promise\RejectedPromise(new RuntimeException('simulated lost response'));
    return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response((int)$this->mode,[],json_encode(['error'=>'simulated response'])));
   });
  }
 };
 $bridge=new ClaudeBridgeService($http,$config,$runs,\OC::$server->get(DecisionService::class));
 try{$bridge->send('creation',(string)$loc['creation_id'],'回执验证 '.$mode,'',[],'只测试回执','cognos-admin');check($mode==='202','accepted response succeeds');}
 catch(RuntimeException $e){check($mode!=='202','non-accepted response reported');}
 check($http->calls===1,'one dispatch attempt, no automatic replay');
 $all=$runs->forSpace('creation',(string)$loc['creation_id']);$r=array_values(array_filter($all,fn($r)=>$r['subject']==='回执验证 '.$mode))[0];
 check($r['state']===match($mode){'lost','500'=>'unknown','409'=>'failed','202'=>'running'},$mode.' records correct state');
 if($mode==='lost'){
  $saved=$runs->receiveResult($r,bin2hex(random_bytes(16)),$r['subject'],[],fn()=>(int)\OC::$server->get(\OCA\Cognos\Service\CreationSpaceService::class)->addMessage($loc['creation_id'],'cognos-admin','assistant','替身桥返回的验证结果')['id']);
  check($saved['run']['state']==='delivered','unknown accepts late result');
  check(!$runs->dispatchUnknown($r['id'],'late transport failure'),'late transport error does not overwrite delivered');
 }
 if($mode==='202')$runs->markUnknown($r['id'],'test fixture complete');
}
// Direct modes must check the same admission gate before any AI network request.
$q=$db->getQueryBuilder();$q->update('cognos_projects')->set('state',$q->createNamedParameter('paused'))->where($q->expr()->eq('id',$q->createNamedParameter($p['project_id'])))->executeStatement();
try{\OC::$server->get(LifecycleService::class)->assertCanStart('creation',(string)$loc['creation_id']);throw new Exception('unexpected admission');}
catch(RuntimeException $e){check($e->getCode()===409,'shared chat admission rejects paused project');}
\OC::$server->get(LifecycleService::class)->assertCanStart('note','outside-project');
echo "PASS\n";