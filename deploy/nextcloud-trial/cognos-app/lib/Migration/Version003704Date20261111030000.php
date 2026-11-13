<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version003704Date20261111030000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $messages = $schema->getTable('cognos_creation_messages');
        if (!$messages->hasColumn('is_archived')) {
            $messages->addColumn('is_archived', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $messages->addColumn('first_archive', Types::BIGINT, ['notnull' => false]);
        }
        if (!$schema->hasTable('cognos_creation_revisions')) {
            $revisions = $schema->createTable('cognos_creation_revisions');
            $revisions->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $revisions->addColumn('creation_id', Types::BIGINT, ['notnull' => true]);
            $revisions->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 12]);
            $revisions->addColumn('anchor_id', Types::BIGINT, ['notnull' => false]);
            $revisions->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $revisions->addColumn('message_ids', Types::TEXT, ['notnull' => true]);
            $revisions->setPrimaryKey(['id']);
            $revisions->addIndex(['creation_id', 'id'], 'cog_creation_rev');
        }
        return $schema;
    }
}
