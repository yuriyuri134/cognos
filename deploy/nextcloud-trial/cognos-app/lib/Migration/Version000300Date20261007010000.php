<?php
declare(strict_types=1);
namespace OCA\Cognos\Migration;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000300Date20261007010000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('cognos_task_events')) return null;
        $table = $schema->createTable('cognos_task_events');
        $table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('board_id', 'bigint', ['notnull' => true]);
        $table->addColumn('card_id', 'bigint', ['notnull' => true]);
        $table->addColumn('actor_uid', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('actor_name', 'string', ['length' => 256, 'notnull' => true]);
        $table->addColumn('kind', 'string', ['length' => 32, 'notnull' => true]);
        $table->addColumn('before_state', 'text', ['notnull' => false]);
        $table->addColumn('after_state', 'text', ['notnull' => true]);
        $table->addColumn('created_at', 'bigint', ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['card_id', 'id'], 'cognos_task_events_card');
        $table->addIndex(['board_id', 'id'], 'cognos_task_events_board');
        return $schema;
    }
}
