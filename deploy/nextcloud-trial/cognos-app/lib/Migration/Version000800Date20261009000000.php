<?php
declare(strict_types=1);
namespace OCA\Cognos\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version000800Date20261009000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema=$schemaClosure();
        if($schema->hasTable('cognos_activity_runs')) return $schema;
        $table=$schema->createTable('cognos_activity_runs');
        $table->addColumn('id','string',['length'=>64]);
        $table->addColumn('activity_id','string',['length'=>64]);
        $table->addColumn('actor_uid','string',['length'=>64]);
        $table->addColumn('request_id','string',['length'=>128]);
        $table->addColumn('input_text','text',['notnull'=>true]);
        $table->addColumn('created_at','bigint',['notnull'=>true]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['actor_uid','activity_id','request_id'],'cognos_run_req');
        $table->addIndex(['activity_id','created_at'],'cognos_activity_run_activity');
        return $schema;
    }
}
