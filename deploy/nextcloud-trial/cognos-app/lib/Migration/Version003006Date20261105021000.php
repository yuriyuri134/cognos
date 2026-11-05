<?php
declare(strict_types=1);
namespace OCA\Cognos\Migration;
use Closure;use OCP\DB\Types;use OCP\DB\ISchemaWrapper;use OCP\Migration\IOutput;use OCP\Migration\SimpleMigrationStep;
class Version003006Date20261105021000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
  $s=$schemaClosure();$t=$s->getTable('cognos_dispatch_queue');if(!$t->hasColumn('reviewed_at'))$t->addColumn('reviewed_at',Types::BIGINT,['notnull'=>false]);return $s;
 }
}
