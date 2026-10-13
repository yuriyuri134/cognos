<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version001400Date20261011060000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('cognos_participants')) {
            $table = $schema->createTable('cognos_participants');
            $table->addColumn('id', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('display_name', 'string', ['length' => 255, 'notnull' => true]);
            $table->addColumn('kind', 'string', ['length' => 32, 'notnull' => true]);
            $table->addColumn('runtime_key', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('description', 'text', ['notnull' => true]);
            $table->addColumn('created_at', 'bigint', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['runtime_key'], 'cognos_participant_runtime');
        }

        if ($schema->hasTable('cognos_activities')) {
            $table = $schema->getTable('cognos_activities');
            if (!$table->hasColumn('participant_id')) {
                $table->addColumn('participant_id', 'string', ['length' => 64, 'notnull' => false]);
            }
            if (!$table->hasColumn('participant_name')) {
                $table->addColumn('participant_name', 'string', ['length' => 255, 'notnull' => false]);
            }
            if (!$table->hasIndex('cognos_activity_participant')) {
                $table->addIndex(['participant_id', 'updated_at'], 'cognos_activity_participant');
            }
        }

        if ($schema->hasTable('cognos_activity_messages')) {
            $table = $schema->getTable('cognos_activity_messages');
            if (!$table->hasColumn('participant_id')) {
                $table->addColumn('participant_id', 'string', ['length' => 64, 'notnull' => false]);
            }
            if (!$table->hasColumn('participant_name')) {
                $table->addColumn('participant_name', 'string', ['length' => 255, 'notnull' => false]);
            }
        }

        return $schema;
    }
}
