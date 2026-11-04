<?php
declare(strict_types=1);
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';
set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
$db=\OC::$server->get(\OCP\IDBConnection::class);
$f=json_decode(file_get_contents('/tmp/undo-move-api.json'),true,512,JSON_THROW_ON_ERROR);
function record(string $id):array{global $db;$q=$db->getQueryBuilder();$r=$q->select('*')->from('cognos_restructures')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeQuery()->fetch();if(!$r)throw new RuntimeException('missing restructure');return $r;}
function check(bool $yes,string $text):void{if(!$yes)throw new RuntimeException($text);echo $text."\n";}
$original=record($f['original_change']);$undo=record($f['undo_change']);
check($undo['reverts_change_id']===$original['id'],'undo refers to the retained original change');
foreach([$original,$undo,record($f['independent_change'])] as $r){$s=json_decode($r['undo_snapshot'],true,512,JSON_THROW_ON_ERROR);check(isset($s['before'],$s['after']) && count($s['before'])>0 && count($s['after'])>0,'before/after structural snapshots stored atomically for '.$r['action']);}
$s=json_decode(record($f['independent_change'])['undo_snapshot'],true,512,JSON_THROW_ON_ERROR);
check(count($s['before'])===1 && count($s['after'])===2,'independence snapshot includes the original and newly established project');
$g=json_decode(file_get_contents('/tmp/restructure-api.json'),true,512,JSON_THROW_ON_ERROR);
$q=$db->getQueryBuilder();$h=$q->select('change_id')->from('cognos_location_history')->where($q->expr()->eq('location_id',$q->createNamedParameter($g['c_root'])))->andWhere($q->expr()->eq('action',$q->createNamedParameter('merge')))->executeQuery()->fetch();
$s=json_decode(record($h['change_id'])['undo_snapshot'],true,512,JSON_THROW_ON_ERROR);
$before=array_values(array_filter($s['before'],fn($p)=>$p['id']===$g['project_c']))[0];
$after=array_values(array_filter($s['after'],fn($p)=>$p['id']===$g['project_a']))[0];
$wide=array_values(array_filter($before['decisions'],fn($d)=>$d['scope_location_ids']===[]))[0];
$scoped=array_values(array_filter($after['decisions'],fn($d)=>$d['id']===$wide['id']))[0];
check($scoped['scope_location_ids']===[$g['c_root']],'merge snapshot retains original wide scope and actual narrowed scope for the same decision');
$projects=\OC::$server->get(\OCA\Cognos\Service\ProjectService::class);
$restructures=\OC::$server->get(\OCA\Cognos\Service\RestructureService::class);
$creations=\OC::$server->get(\OCA\Cognos\Service\CreationSpaceService::class);
$uid='cognos-admin';$request=fn()=>bin2hex(random_bytes(16));
$p=$projects->create($uid,'验证：撤回保留讨论 '.time(),$request());
$a=$projects->addLocation($uid,$p['project_id'],$p['location_id'],'原上级',$request());
$b=$projects->addLocation($uid,$p['project_id'],$p['location_id'],'新上级',$request());
$l=$projects->addLocation($uid,$p['project_id'],$a['location_id'],'讨论原处',$request());
$params=['location_id'=>$l['location_id'],'parent_id'=>$b['location_id']];
$change=$restructures->apply($uid,'move',$params,$restructures->preview($uid,'move',$params)['basis'],$request());
$loc=$projects->location($uid,$l['location_id']);
$message=$creations->addMessage($loc['creation_id'],$uid,'user','移动以后增加的真实讨论消息，不触发 AI');
$undo=['change_id'=>$change['change_id']];
$restructures->apply($uid,'undo_move',$undo,$restructures->preview($uid,'undo_move',$undo)['basis'],$request());
$messages=$creations->getMessages($loc['creation_id'],$uid);
check(count(array_filter($messages,fn($m)=>(int)$m['id']===(int)$message['id'] && $m['content']===$message['content']))===1,'a real discussion message added after moving remains exactly once after undo');
echo "PASS\n";
