<?php
declare(strict_types=1);
namespace OCA\Cognos\Migration;
use Closure;use OCP\DB\Types;use OCP\DB\ISchemaWrapper;use OCP\Migration\IOutput;use OCP\Migration\SimpleMigrationStep;
class Version003007Date20261105030000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
  $s=$schemaClosure();$t=$s->getTable('cognos_followups');$t->changeColumn('run_id',['notnull'=>false]);
  foreach(['origin'=>[16,'result'],'project_id'=>[32,null],'queue_item_id'=>[32,null]] as $n=>[$len,$default])if(!$t->hasColumn($n))$t->addColumn($n,Types::STRING,['length'=>$len,'notnull'=>$default!==null,'default'=>$default]);
  if(!$t->hasColumn('execution_epoch'))$t->addColumn('execution_epoch',Types::BIGINT,['notnull'=>false]);
  if(!$t->hasColumn('request_note'))$t->addColumn('request_note',Types::TEXT,['notnull'=>false]);return $s;
 }
}
