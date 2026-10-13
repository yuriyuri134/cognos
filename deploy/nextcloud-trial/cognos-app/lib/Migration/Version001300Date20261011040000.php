<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001300Date20261011040000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('cognos_activities')) {
            return $schema;
        }

        $table = $schema->getTable('cognos_activities');
        if (!$table->hasColumn('status')) {
            $table->addColumn('status', 'string', [
                'length' => 32,
                'notnull' => true,
                'default' => 'in_progress',
            ]);
        }
        if (!$table->hasColumn('updated_at')) {
            $table->addColumn('updated_at', 'bigint', [
                'notnull' => true,
                'default' => 0,
            ]);
        }
        return $schema;
    }
}
