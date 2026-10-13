<?php
declare(strict_types=1);
namespace OCA\Cognos\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version000400Date20261007020000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema=$schemaClosure(); $table=$schema->getTable('cognos_task_requests');
        if (!$table->hasColumn('response_json')) $table->addColumn('response_json','text',['notnull'=>false]);
        return $schema;
    }
}
