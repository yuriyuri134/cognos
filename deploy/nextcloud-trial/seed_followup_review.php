<?php
declare(strict_types=1);
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';
set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
use OCA\Cognos\Service\{ProjectService,RunService,FollowupService,FollowupDispatcher};
$projects=\OC::$server->get(ProjectService::class);$runs=\OC::$server->get(RunService::class);$events=\OC::$server->get(FollowupService::class);$dispatcher=\OC::$server->get(FollowupDispatcher::class);$chats=\OC::$server->get(\OCA\Cognos\Service\CreationSpaceService::class);$users=\OC::$server->get(\OCP\IUserSession::class);
function check(bool $ok,string $note):void{if(!$ok)throw new RuntimeException($note);echo $note."\n";}
$fixture=function()use($projects,$runs,$events,$chats):array{
 $p=$projects->create('cognos-admin','验证：后台接续恢复 '.time(),bin2hex(random_bytes(16)));$loc=$projects->location('cognos-admin',$p['location_id']);
 $r=$runs->create('creation',(string)$loc['creation_id'],'conversation','recovery-test','后台恢复验证','恢复验证','cognos-admin',[]);$id=bin2hex(random_bytes(16));
 $runs->receiveResult($r,$id,$r['subject'],[],fn()=>(int)$chats->addMessage($loc['creation_id'],'cognos-admin','assistant','验证：已存结果，尚未领取接续')['id'],fn()=>$events->enqueue($id,$r,'cognos-admin'));
 return $p+['result_id'=>$id,'creation_id'=>$loc['creation_id']];
};

$first=$fixture();$second=$fixture();
foreach([$first,$second] as $p){$events->process($p['result_id'],'cognos-admin',fn()=>throw new RuntimeException('验证：领取后失联'));}
echo json_encode($second+['api_fixture'=>$first]);
