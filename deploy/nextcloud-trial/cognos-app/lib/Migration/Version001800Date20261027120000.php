<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 押着的（kind=premise）要连着它管着的东西：哪段代码、哪个做法。顶到它，改的就是那里。
 * 见项目文件 框架审视/会不会全错-2026-10-27.md 第十一节。
 */
class Version001800Date20261027120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('cognos_space_traces')) {
            $table = $schema->getTable('cognos_space_traces');
            if (!$table->hasColumn('governs')) {
                $table->addColumn('governs', Types::TEXT, ['notnull' => false]);
            }
        }

        return $schema;
    }
}
