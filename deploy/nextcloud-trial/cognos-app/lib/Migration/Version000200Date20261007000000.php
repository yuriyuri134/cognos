<?php
declare(strict_types=1);
namespace OCA\Cognos\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000200Date20261007000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('cognos_task_requests')) {
            return null;
        }
        $table = $schema->createTable('cognos_task_requests');
        $table->addColumn('actor_uid', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('request_id', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('payload_hash', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('card_id', 'bigint', ['notnull' => false]);
        $table->addColumn('created_at', 'bigint', ['notnull' => true]);
        $table->setPrimaryKey(['actor_uid', 'request_id']);
        return $schema;
    }
}
