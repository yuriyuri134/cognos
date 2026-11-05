<?php
/** Read-only, repeatable-read fingerprints. Emits hashes/counts/column names, never row data or credentials.
 * php check_backup_state.php [--output=/tmp/state.json]
 */
declare(strict_types=1);
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';
set_exception_handler(function($e){fwrite(STDERR,'Backup fingerprint failed: '.get_class($e).' (no row data emitted)'.PHP_EOL);exit(1);});
$db=\OC::$server->get(\OCP\IDBConnection::class);$config=\OC::$server->get(\OCP\IConfig::class);
$prefix=(string)$config->getSystemValue('dbtableprefix','oc_');
if(!preg_match('/^[A-Za-z0-9_]+$/D',$prefix))throw new RuntimeException('Unsupported database prefix');
$identity=['users','accounts','accounts_data','groups','group_user','group_admin','share','share_external','authtoken','preferences','appconfig','storages','mounts'];
$db->beginTransaction();
try{
 $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
 $rows=$db->executeQuery("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll();
 $tables=[];$found=[];
 foreach($rows as $t){
  $table=(string)$t['table_name'];if(!str_starts_with($table,$prefix))continue;$logical=substr($table,strlen($prefix));
  if(!str_starts_with($logical,'cognos_')&&!in_array($logical,$identity,true))continue;
  if(!preg_match('/^[A-Za-z0-9_]+$/D',$logical))throw new RuntimeException('Unsupported table name');
  $found[]=$logical;
  $columns=$db->executeQuery("SELECT column_name,data_type,udt_name,is_nullable,column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position",[$table])->fetchAll();
  $names=array_column($columns,'column_name');$canonical=[];
  $q=$db->getQueryBuilder();$result=$q->select('*')->from($logical)->executeQuery();
  while($row=$result->fetch()){
   ksort($row,SORT_STRING);$safe=[];
   foreach($row as $key=>$value){if(is_resource($value))$value=stream_get_contents($value);if(is_bool($value))$value=$value?'1':'0';$safe[$key]=$value===null?null:base64_encode((string)$value);}
   $canonical[]=json_encode($safe,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
  }
  $result->closeCursor();sort($canonical,SORT_STRING);$hash=hash_init('sha256');
  foreach($canonical as $line){hash_update($hash,(string)strlen($line).':'.$line."\n");}
  $tables[$table]=['category'=>str_starts_with($logical,'cognos_')?'cognos':'identity_permissions_config','count'=>count($canonical),'sha256'=>hash_final($hash),'schema_sha256'=>hash('sha256',json_encode($columns,JSON_THROW_ON_ERROR)),'columns'=>$names];
 }
 $db->commit();
 $missing=array_values(array_diff($identity,$found));ksort($tables,SORT_STRING);
 $report=['format'=>'cognos-backup-state-v1','snapshot'=>'repeatable_read_read_only','database'=>'postgresql','table_prefix'=>$prefix,'tables'=>$tables,'optional_tables_absent'=>$missing,'aggregate_sha256'=>hash('sha256',json_encode($tables,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))];
 $json=json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
 $options=getopt('',['output:']);if(isset($options['output'])&&file_put_contents($options['output'],$json)===false)throw new RuntimeException('Unable to save fingerprint');
 echo $json;
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
