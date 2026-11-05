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
$p=$fixture();$before=$users->getUser();
check(!$dispatcher->process($p['result_id'],'other-user'),'other identity cannot recover');
check($events->get($p['result_id'])['state']==='pending','unauthorized attempt leaves intent pending');
check($dispatcher->process($p['result_id'],'cognos-admin'),'unclaimed committed intent recovered without resending result');
check($events->get($p['result_id'])['state']==='attempted','empty scheduling choice is recorded once');
check($users->getUser()===$before,'caller identity restored after background-style processing');
check(!$dispatcher->process($p['result_id'],'cognos-admin'),'completed intent not replayed');
$p=$fixture();$projects->changeLifecycle('cognos-admin',$p['project_id'],'pause',1,bin2hex(random_bytes(16)),'暂停验证','');
check(!$dispatcher->process($p['result_id'],'cognos-admin')&&$events->get($p['result_id'])['state']==='cancelled','paused project cancels old scheduling intent');
$p=$fixture();$projects->changeLifecycle('cognos-admin',$p['project_id'],'pause',1,bin2hex(random_bytes(16)),'','');$projects->changeLifecycle('cognos-admin',$p['project_id'],'resume',2,bin2hex(random_bytes(16)),'','');
check(!$dispatcher->process($p['result_id'],'cognos-admin')&&$events->get($p['result_id'])['state']==='cancelled','resume does not revive an old-epoch intent');
$p=$fixture();$busy=$runs->create('creation',(string)$p['creation_id'],'conversation','busy','验证忙时延后','恢复验证','cognos-admin',[]);
check(!$dispatcher->process($p['result_id'],'cognos-admin')&&$events->get($p['result_id'])['state']==='pending','active run defers scheduling instead of dropping it');
$runs->failed($busy['id'],'verification complete');
check($dispatcher->process($p['result_id'],'cognos-admin'),'deferred intent recovers once work clears');
check($users->getUser()===$before,'identity remains unchanged after all cases');
$p=$fixture();$uncertain=$runs->create('creation',(string)$p['creation_id'],'conversation','uncertain','验证未知工作阻挡接续','恢复验证','cognos-admin',[]);
$runs->accepted($uncertain['id']);$runs->markUnknown($uncertain['id'],'替身失联');
check(!$dispatcher->process($p['result_id'],'cognos-admin')&&$events->get($p['result_id'])['state']==='pending','unknown run blocks later automatic scheduling');
$runs->receiveResult($uncertain,bin2hex(random_bytes(16)),$uncertain['subject'],[],fn()=>(int)$chats->addMessage($p['creation_id'],'cognos-admin','assistant','替身迟到结果')['id']);
check($dispatcher->process($p['result_id'],'cognos-admin'),'late receipt clears unknown run barrier');
echo "PASS\n";