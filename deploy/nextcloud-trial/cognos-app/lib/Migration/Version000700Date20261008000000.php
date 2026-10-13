<?php
declare(strict_types=1);
namespace OCA\Cognos\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version000700Date20261008000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema=$schemaClosure();
        if($schema->hasTable('cognos_activities')) return $schema;
        $table=$schema->createTable('cognos_activities');
        $table->addColumn('id','string',['length'=>64]);
        $table->addColumn('actor_uid','string',['length'=>64]);
        $table->addColumn('actor_name','string',['length'=>255]);
        $table->addColumn('board_id','bigint',['notnull'=>true]);
        $table->addColumn('card_id','bigint',['notnull'=>true]);
        $table->addColumn('title','string',['length'=>255]);
        $table->addColumn('source_snapshot','text',['notnull'=>true]);
        $table->addColumn('created_at','bigint',['notnull'=>true]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['actor_uid','created_at'],'cognos_activities_actor');
        $table->addIndex(['board_id','card_id'],'cognos_activities_source');
        return $schema;
    }
}
