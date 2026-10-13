<?php
declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001100Date20261010020000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('cognos_activity_messages')) {
            $table = $schema->createTable('cognos_activity_messages');
            $table->addColumn('id', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('activity_id', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('role', 'string', ['length' => 16, 'notnull' => true]);
            $table->addColumn('content', 'text', ['notnull' => true]);
            $table->addColumn('created_at', 'bigint', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['activity_id', 'created_at'], 'cognos_activity_message_activity');
        }
        return $schema;
    }
}
