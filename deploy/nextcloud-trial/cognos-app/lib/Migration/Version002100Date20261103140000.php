<?php
declare(strict_types=1);
namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version002100Date20261103140000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('cognos_projects')) {
            $t = $schema->createTable('cognos_projects');
            foreach (['id' => 32, 'user_id' => 64, 'title' => 200, 'root_location_id' => 32] as $name => $size) {
                $t->addColumn($name, Types::STRING, ['notnull' => true, 'length' => $size]);
            }
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['user_id'], 'cognos_proj_user_idx');
        }
        if (!$schema->hasTable('cognos_locations')) {
            $t = $schema->createTable('cognos_locations');
            foreach (['id' => 32, 'project_id' => 32, 'title' => 200, 'editor' => 64] as $name => $size) {
                $t->addColumn($name, Types::STRING, ['notnull' => true, 'length' => $size]);
            }
            $t->addColumn('parent_id', Types::STRING, ['notnull' => false, 'length' => 32]);
            $t->addColumn('content', Types::TEXT, ['notnull' => true]);
            $t->addColumn('revision', Types::INTEGER, ['notnull' => true]);
            foreach (['creation_id', 'created_at', 'updated_at'] as $name) {
                $t->addColumn($name, Types::BIGINT, ['notnull' => true]);
            }
            $t->setPrimaryKey(['id']);
            $t->addIndex(['project_id'], 'cognos_loc_proj_idx');
            $t->addUniqueIndex(['creation_id'], 'cognos_loc_chat_uniq');
        }
        if (!$schema->hasTable('cognos_location_revisions')) {
            $t = $schema->createTable('cognos_location_revisions');
            $t->addColumn('location_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('revision', Types::INTEGER, ['notnull' => true]);
            $t->addColumn('content', Types::TEXT, ['notnull' => true]);
            $t->addColumn('editor', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('source_message_ids', Types::TEXT, ['notnull' => true]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $t->setPrimaryKey(['location_id', 'revision']);
        }
        if (!$schema->hasTable('cognos_usages')) {
            $t = $schema->createTable('cognos_usages');
            $t->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('location_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('source_creation_id', Types::BIGINT, ['notnull' => true]);
            $t->addColumn('purpose', Types::TEXT, ['notnull' => true]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['location_id', 'source_creation_id'], 'cognos_usage_src_uniq');
        }
        // Receipts are scoped to a user and explicit business operation, not an operations DSL.
        if (!$schema->hasTable('cognos_project_requests')) {
            $t = $schema->createTable('cognos_project_requests');
            $t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('request_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('request_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('response', Types::TEXT, ['notnull' => true]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $t->setPrimaryKey(['user_id', 'request_id']);
        }
        return $schema;
    }
}
