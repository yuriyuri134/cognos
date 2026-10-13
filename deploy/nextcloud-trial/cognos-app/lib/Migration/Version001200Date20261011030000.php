<?php
declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001200Date20261011030000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('cognos_activities')) {
            $table = $schema->getTable('cognos_activities');
            if (!$table->hasColumn('status')) {
                $table->addColumn('status', 'string', ['length' => 32, 'notnull' => true, 'default' => 'in_progress']);
            }
            if (!$table->hasColumn('updated_at')) {
                $table->addColumn('updated_at', 'bigint', ['notnull' => true, 'default' => 0]);
            }
            if (!$table->hasIndex('cognos_activities_status')) {
                $table->addIndex(['status', 'updated_at'], 'cognos_activities_status');
            }
        }
        if ($schema->hasTable('cognos_activity_messages')) {
            $table = $schema->getTable('cognos_activity_messages');
            if (!$table->hasColumn('state')) {
                $table->addColumn('state', 'string', ['length' => 16, 'notnull' => true, 'default' => 'succeeded']);
            }
            if (!$table->hasColumn('request_id')) {
                $table->addColumn('request_id', 'string', ['length' => 64, 'notnull' => false]);
            }
            if (!$table->hasColumn('error_text')) {
                $table->addColumn('error_text', 'text', ['notnull' => false]);
            }
            if (!$table->hasColumn('reply_to')) {
                $table->addColumn('reply_to', 'string', ['length' => 64, 'notnull' => false]);
            }
            if (!$table->hasIndex('cognos_activity_message_request')) {
                $table->addIndex(['activity_id', 'request_id'], 'cognos_activity_message_request');
            }
        }
        return $schema;
    }
}
