<?php
declare(strict_types=1);
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';
set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
$q=\OC::$server->get(\OCA\Cognos\Service\DispatchQueueService::class);
function check(bool $ok,string $s):void{if(!$ok)throw new RuntimeException($s);echo $s."\n";}

$mode=$argv[1];$data=json_decode(base64_decode($argv[2]),true);$db=\OC::$server->get(\OCP\IDBConnection::class);
$hold=str_starts_with($mode,'hold-');$op=$hold?substr($mode,5):$mode;
if($hold)$db->beginTransaction();else{echo "ATTEMPT\n";flush();}
try{
 $value=$op==='enqueue'?$q->enqueue('verification',$data['space'],'msg',$hold?1:2):$q->claim('verification',$data['space'],$hold?'holder':'worker');
 if($hold){echo "LOCKED\n";flush();$end=microtime(true)+20;$f='/tmp/cognos-queue-release-'.$data['token'];while(!file_exists($f)){if(microtime(true)>$end)throw new RuntimeException('release timeout');usleep(20000);clearstatcache(true,$f);}$db->commit();}
 echo json_encode($value);
}catch(Throwable $e){if($hold)$db->rollBack();throw $e;}
