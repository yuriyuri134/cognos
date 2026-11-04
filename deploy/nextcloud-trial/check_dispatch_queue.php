<?php
declare(strict_types=1);
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';
set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
$q=\OC::$server->get(\OCA\Cognos\Service\DispatchQueueService::class);
function check(bool $ok,string $s):void{if(!$ok)throw new RuntimeException($s);echo $s."\n";}
$space='verification-'.bin2hex(random_bytes(8));
$a=$q->enqueue('verification',$space,'msg',1);$again=$q->enqueue('verification',$space,'msg',1);check($a['id']===$again['id'],'duplicate pending reason is one item');
$b=$q->enqueue('verification',$space,'hit',2);$front=$q->enqueue('verification',$space,'msg',3,true);
$c=$q->claim('verification',$space,'intent1');check($c['id']===$front['id'],'front insertion precedes queued work');
check($q->claim('verification',$space,'intent2')===null,'second claimant cannot bypass claimed item');
check(!$q->settle($c['id'],'wrong-intent','sent'),'wrong claim identity rejected');
check($q->settle($c['id'],'intent1','queued'),'known busy rejection can requeue');
$c=$q->claim('verification',$space,'intent3');check($c['id']===$front['id'],'requeue retains original order');$q->settle($c['id'],'intent3','sent');
$new=$q->enqueue('verification',$space,'msg',3);check($new['id']!==$front['id'],'explicit later attempt has distinct history');
$c=$q->claim('verification',$space,'intent4');check($c['id']===$a['id'],'FIFO continues after front item');$q->settle($c['id'],'intent4','unknown');
check($q->claim('verification',$space,'intent5')===null,'unknown prevents automatic continuation');
$legacy='legacy-'.bin2hex(random_bytes(8));$existing=$q->enqueue('verification',$legacy,'hit',7);
$import=$q->importLegacy('verification',$legacy,[7,'msg:8']);check($import[0]['id']===$existing['id'],'legacy import matches existing pending reference');
$c=$q->claim('verification',$legacy,'legacy1');$q->settle($c['id'],'legacy1','sent');
$repeat=$q->importLegacy('verification',$legacy,[7,'msg:8']);check($repeat[0]['state']==='sent'&&$repeat[0]['id']===$existing['id'],'repeat import cannot revive sent reference');
$c=$q->claim('verification',$legacy,'legacy2');check($c['kind']==='msg'&&(int)$c['ref_id']===8,'integer hit and string msg preserve migration order');$q->settle($c['id'],'legacy2','skipped');
echo "PASS\n";