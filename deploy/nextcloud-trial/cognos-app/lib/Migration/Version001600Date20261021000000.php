<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001600Date20261021000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Create cognos_creations table
        if (!$schema->hasTable('cognos_creations')) {
            $table = $schema->createTable('cognos_creations');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('title', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'cognos_creations_user_idx');
            $table->addIndex(['updated_at'], 'cognos_creations_updated_idx');
        }

        // Create cognos_creation_messages table
        if (!$schema->hasTable('cognos_creation_messages')) {
            $table = $schema->createTable('cognos_creation_messages');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('creation_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('role', Types::STRING, [
                'notnull' => true,
                'length' => 32,
            ]);
            $table->addColumn('content', Types::TEXT, [
                'notnull' => true,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['creation_id'], 'cognos_creation_msgs_creation_idx');
            $table->addIndex(['created_at'], 'cognos_creation_msgs_created_idx');
        }

        return $schema;
    }
}
