<?php
declare(strict_types=1);
namespace OCA\Cognos\Migration;
use Closure;use OCP\DB\Types;use OCP\DB\ISchemaWrapper;use OCP\Migration\IOutput;use OCP\Migration\SimpleMigrationStep;
class Version003005Date20261105020000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
  $s=$schemaClosure();if(!$s->hasTable('cognos_queue_imports')){
   $t=$s->createTable('cognos_queue_imports');foreach(['source_key'=>255,'source_hash'=>64,'state'=>20] as $n=>$len)$t->addColumn($n,Types::STRING,['length'=>$len,'notnull'=>true]);
   $t->addColumn('item_count',Types::INTEGER,['notnull'=>true]);$t->addColumn('note',Types::TEXT,['notnull'=>true]);$t->addColumn('checked_at',Types::BIGINT,['notnull'=>true]);$t->setPrimaryKey(['source_key']);
  }return $s;
 }
}
