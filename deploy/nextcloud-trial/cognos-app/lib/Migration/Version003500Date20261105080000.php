<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version003500Date20261105080000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $delegations = $schema->getTable('cognos_delegations');
        if (!$delegations->hasColumn('executor')) {
            $delegations->addColumn('executor', Types::STRING, ['length' => 20, 'default' => 'claude-code', 'notnull' => true]);
        }
        return $schema;
    }
}
