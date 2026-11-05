<?php
declare(strict_types=1);
// docker cp this file to /tmp; run php as www-data from /var/www/html.
define('OC_CONSOLE', 1);
$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';
require '/var/www/html/lib/base.php';
set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
$db=\OC::$server->get(\OCP\IDBConnection::class);
$projects=\OC::$server->get(\OCA\Cognos\Service\ProjectService::class);
$runs=\OC::$server->get(\OCA\Cognos\Service\RunService::class);
$life=\OC::$server->get(\OCA\Cognos\Service\LifecycleService::class);
function check(bool $ok,string $what):void { if(!$ok)throw new \RuntimeException($what); echo $what."\n"; }
$p=$projects->create('cognos-admin','验证：生命周期执行资格 '.time(),bin2hex(random_bytes(16)));
$loc=$projects->location('cognos-admin',$p['location_id']);
$start=fn()=>$runs->create('creation',(string)$loc['creation_id'],'conversation','lifecycle-test','资格核验','生命周期验证','cognos-admin',[]);
$set=function(string $state,int $epoch)use($db,$p):void {
 $q=$db->getQueryBuilder();$q->update('cognos_projects')->set('state',$q->createNamedParameter($state))
 ->set('execution_epoch',$q->createNamedParameter($epoch,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
 ->where($q->expr()->eq('id',$q->createNamedParameter($p['project_id'])))->executeStatement();
};
$eligible=function(array $r)use($db,$life,$p):bool {
 $db->beginTransaction();try{return $life->eligible($p['project_id'],$r);}finally{$db->rollBack();}
};
try {
 $r=$start();check($r['project_id']===$p['project_id']&&$r['execution_epoch']===1,'new run records project and epoch');
 check($eligible($r),'active current run eligible');
 $set('paused',2);
 try{$start();throw new \Exception('paused start unexpectedly succeeded');}catch(\RuntimeException $e){check($e->getCode()===409,'paused start rejected');}
 check(!$eligible($r),'paused run ineligible');
 $set('active',3);check(!$eligible($r),'resume does not renew old authority');
 $fresh=$start();check($fresh['execution_epoch']===3&&$eligible($fresh),'new run after resume eligible');
 $legacy=$r;unset($legacy['execution_epoch']);check(!$eligible($legacy),'legacy run does not inherit new authority');
 $foreign=$fresh;$foreign['project_id']='other';check(!$eligible($foreign),'other project cannot supply execution authority');
 $free=$runs->create('note','lifecycle-fixture','conversation','free','free','生命周期验证','cognos-admin',[]);
 check($free['project_id']===null&&$free['execution_epoch']===null,'non-project run remains independent');
 echo "PASS\n";
} finally { $set('active',3);foreach($runs->forSpace('creation',(string)$loc['creation_id']) as $r){$runs->failed($r['id'],'contract test fixture completed');}if(isset($free))$runs->failed($free['id'],'contract test fixture completed'); }