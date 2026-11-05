<?php
declare(strict_types=1);define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';
set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
$db=\OC::$server->get(\OCP\IDBConnection::class);$ref=(int)$argv[1];$needle=base64_decode($argv[2]);
$q=$db->getQueryBuilder();$items=$q->select('*')->from('cognos_dispatch_queue')->where($q->expr()->eq('kind',$q->createNamedParameter('msg')))->andWhere($q->expr()->eq('ref_id',$q->createNamedParameter($ref,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeQuery()->fetchAll();
if(count($items)!==1||$items[0]['state']!=='sent'||!$items[0]['run_id'])throw new RuntimeException('actual message queue binding is not unique and sent');
$item=$items[0];$q=$db->getQueryBuilder();$run=$q->select('*')->from('cognos_runs')->where($q->expr()->eq('id',$q->createNamedParameter($item['run_id'])))->executeQuery()->fetch();$input=json_decode($run['input_snapshot'],true);
if($run['state']!=='delivered'||$input['queue_claim']['item_id']!==$item['id'])throw new RuntimeException('actual result does not match queue binding');
$history=implode("\n",array_column($input['history'],'content'));
if(!str_contains($history,$needle))throw new RuntimeException('earlier actual answer not included in queued run input');
echo json_encode(['result'=>'pass','queue_item_id'=>$item['id'],'run_id'=>$run['id'],'checks'=>['actual queued message has one sent item and one delivered bound run','immutable queued run input contains earlier actual answer']]);