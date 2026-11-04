<?php
declare(strict_types=1);
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
$f=json_decode(file_get_contents('/tmp/codex-entry.json'),true,512,JSON_THROW_ON_ERROR);$db=\OC::$server->get(\OCP\IDBConnection::class);
function check(bool $yes,string $text):void{if(!$yes)throw new RuntimeException($text);echo $text."\n";}
$q=$db->getQueryBuilder();$run=$q->select('*')->from('cognos_runs')->where($q->expr()->eq('id',$q->createNamedParameter($f['codex_run_id'])))->executeQuery()->fetch();
$input=json_decode($run['input_snapshot'],true,512,JSON_THROW_ON_ERROR);
check($run['subject']==='Codex'&&$run['state']==='delivered','actual run is attributed to Codex and delivered');
check(str_contains($input['system'],$f['work_id'])&&str_contains($input['system'],'work_items'),'initial immutable input contains work context');
check(in_array($f['initial_decision'],$input['decision_ids'],true)&&!in_array($f['new_decision'],$input['decision_ids'],true),'new decision was genuinely absent from the initial decision snapshot');
$q=$db->getQueryBuilder();$receipts=$q->select('*')->from('cognos_run_results')->where($q->expr()->eq('run_id',$q->createNamedParameter($f['codex_run_id'])))->executeQuery()->fetchAll();
check(count($receipts)===1&&$receipts[0]['id']===$f['result_id']&&(int)$receipts[0]['message_id']===(int)$f['result_message_id'],'one durable result id links the same Codex run to exactly the shown message');
check($receipts[0]['drift_seen_at']!==null,'drift acknowledgment is persisted against the returned result');
$changes=json_decode($receipts[0]['basis_changed'],true);check((int)$changes[0]['version']===2&&(int)$changes[0]['current']===3,'actual compared basis is version 2 to version 3');
$q=$db->getQueryBuilder();$messages=$q->select('id','content')->from('cognos_creation_messages')->where($q->expr()->eq('id',$q->createNamedParameter((int)$f['result_message_id'],\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeQuery()->fetchAll();
check(count($messages)===1&&str_contains($messages[0]['content'],'取得范围'),'the returned review discusses the new acquisition-scope requirement');
echo "PASS\n";
