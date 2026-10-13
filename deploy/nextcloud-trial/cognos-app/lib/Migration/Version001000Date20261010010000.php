<?php
declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001000Date20261010010000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $table = $schema->getTable('cognos_activities');
        if (!$table->hasColumn('source_type')) {
            $table->addColumn('source_type', 'string', ['length' => 32, 'notnull' => false]);
        }
        if (!$table->hasColumn('source_id')) {
            $table->addColumn('source_id', 'string', ['length' => 128, 'notnull' => false]);
        }
        return $schema;
    }
}
