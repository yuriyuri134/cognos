<?php
declare(strict_types=1);
namespace OCA\Cognos\Migration;
use Closure;
use OCP\DB\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version002901Date20261104210000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
  $s=$schemaClosure();
  if (!$s->hasTable('cognos_lifecycle_history')) {
   $t=$s->createTable('cognos_lifecycle_history');
   $t->addColumn('project_id',Types::STRING,['length'=>32,'notnull'=>true]);
   $t->addColumn('revision',Types::BIGINT,['notnull'=>true]);
   foreach (['from_state','to_state'] as $name) $t->addColumn($name,Types::STRING,['length'=>16,'notnull'=>true]);
   $t->addColumn('actor',Types::STRING,['length'=>64,'notnull'=>true]);
   foreach (['note','reopen_when'] as $name) $t->addColumn($name,Types::TEXT,['notnull'=>true]);
   $t->addColumn('created_at',Types::BIGINT,['notnull'=>true]);
   $t->setPrimaryKey(['project_id','revision']);
  }
  return $s;
 }
}
