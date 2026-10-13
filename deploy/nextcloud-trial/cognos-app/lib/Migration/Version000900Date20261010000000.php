<?php
declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000900Date20261010000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('cognos_note_spaces')) {
            $table = $schema->createTable('cognos_note_spaces');
            $table->addColumn('id', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('owner_uid', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('title', 'string', ['length' => 255, 'notnull' => true]);
            $table->addColumn('description', 'text', ['notnull' => true]);
            $table->addColumn('created_at', 'bigint', ['notnull' => true]);
            $table->addColumn('updated_at', 'bigint', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['owner_uid', 'updated_at'], 'cognos_note_space_owner');
        }

        if (!$schema->hasTable('cognos_notes')) {
            $table = $schema->createTable('cognos_notes');
            $table->addColumn('id', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('space_id', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('owner_uid', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('title', 'string', ['length' => 255, 'notnull' => true]);
            $table->addColumn('content', 'text', ['notnull' => true]);
            $table->addColumn('source_json', 'text', ['notnull' => true]);
            $table->addColumn('revision', 'integer', ['notnull' => true]);
            $table->addColumn('content_hash', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('created_at', 'bigint', ['notnull' => true]);
            $table->addColumn('updated_at', 'bigint', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['space_id', 'updated_at'], 'cognos_note_space_notes');
            $table->addIndex(['owner_uid', 'updated_at'], 'cognos_note_owner');
        }

        if (!$schema->hasTable('cognos_note_links')) {
            $table = $schema->createTable('cognos_note_links');
            $table->addColumn('id', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('space_id', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('source_note_id', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('target_note_id', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('label', 'string', ['length' => 255, 'notnull' => true]);
            $table->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('created_at', 'bigint', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['space_id', 'created_at'], 'cognos_note_link_space');
            $table->addIndex(['source_note_id'], 'cognos_note_link_source');
            $table->addIndex(['target_note_id'], 'cognos_note_link_target');
        }

        return $schema;
    }
}
