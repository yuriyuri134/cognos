<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version003100Date20261105040000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $table = $schema->getTable('cognos_restructures');
        if (!$table->hasColumn('reverts_change_id')) {
            $table->addColumn('reverts_change_id', Types::STRING, ['length' => 32, 'notnull' => false]);
        }
        if (!$table->hasIndex('cognos_restructure_revert')) {
            $table->addUniqueIndex(['reverts_change_id'], 'cognos_restructure_revert');
        }
        if (!$table->hasColumn('undo_snapshot')) {
            $table->addColumn('undo_snapshot', Types::TEXT, ['notnull' => false]);
        }
        return $schema;
    }
}
