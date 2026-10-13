<?php
declare(strict_types=1);
namespace OCA\Cognos\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version000600Date20261007030000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options): ?ISchemaWrapper {
        $schema=$schemaClosure();
        if (!$schema->hasTable('cognos_task_progress')) {
            $table=$schema->createTable('cognos_task_progress');
            $table->addColumn('card_id','bigint',['notnull'=>true]);
            $table->addColumn('progress','string',['length'=>16,'notnull'=>true]);
            $table->setPrimaryKey(['card_id']);
        }
        return $schema;
    }
}
