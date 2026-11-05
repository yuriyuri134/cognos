<?php
declare(strict_types=1);
namespace OCA\Cognos\Migration;
use Closure;
use OCP\DB\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version002907Date20261105000000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
  $s=$schemaClosure();
  if(!$s->hasTable('cognos_followups')){
   $t=$s->createTable('cognos_followups');
   foreach(['result_id'=>32,'run_id'=>32,'user_id'=>64,'space_type'=>32,'space_id'=>64,'state'=>16] as $name=>$len){$t->addColumn($name,Types::STRING,['length'=>$len,'notnull'=>true]);}
   $t->addColumn('open_id',Types::BIGINT,['notnull'=>false]);
   $t->addColumn('note',Types::TEXT,['notnull'=>true]);
   foreach(['created_at','updated_at'] as $name)$t->addColumn($name,Types::BIGINT,['notnull'=>true]);
   $t->setPrimaryKey(['result_id']);$t->addIndex(['state','updated_at'],'cognos_followup_state');
  }
  return $s;
 }
}
