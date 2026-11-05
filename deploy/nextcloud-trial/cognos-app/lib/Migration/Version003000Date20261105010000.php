<?php
declare(strict_types=1);
namespace OCA\Cognos\Migration;
use Closure;
use OCP\DB\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version003000Date20261105010000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
  $s=$schemaClosure();
  if(!$s->hasTable('cognos_queue_scopes')){$t=$s->createTable('cognos_queue_scopes');$t->addColumn('id',Types::STRING,['length'=>64,'notnull'=>true]);$t->addColumn('sequence',Types::BIGINT,['notnull'=>true,'default'=>0]);$t->setPrimaryKey(['id']);}
  if(!$s->hasTable('cognos_dispatch_queue')){
   $t=$s->createTable('cognos_dispatch_queue');
   foreach(['id'=>32,'scope_id'=>64,'kind'=>8,'state'=>16] as $n=>$len)$t->addColumn($n,Types::STRING,['length'=>$len,'notnull'=>true]);
   foreach(['followup_id'=>32,'run_id'=>32,'legacy_key'=>64] as $n=>$len)$t->addColumn($n,Types::STRING,['length'=>$len,'notnull'=>false]);
   foreach(['ref_id','position','created_at','updated_at'] as $n)$t->addColumn($n,Types::BIGINT,['notnull'=>true]);
   $t->addColumn('note',Types::TEXT,['notnull'=>true]);$t->setPrimaryKey(['id']);$t->addUniqueIndex(['legacy_key'],'cognos_queue_legacy');$t->addIndex(['scope_id','state','position'],'cognos_queue_ready');
  }return $s;
 }
}
