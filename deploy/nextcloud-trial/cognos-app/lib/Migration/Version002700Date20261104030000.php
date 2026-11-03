<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 任何 agent 走同一条路进项目（03-系统/接入一条路.md）：交回时除了「起步时还没有的决定」，
 * 再记下它报过的依据有没有在这期间被改（basis_changed），以及交回前它是不是看过这些差异（drift_seen_at）。
 */
class Version002700Date20261104030000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('cognos_run_results')) {
            $t = $schema->getTable('cognos_run_results');
            if (!$t->hasColumn('basis_changed')) {
                $t->addColumn('basis_changed', Types::TEXT, ['notnull' => false]);
            }
            if (!$t->hasColumn('drift_seen_at')) {
                $t->addColumn('drift_seen_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            }
        }
        return $schema;
    }
}
