<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 停在哪（stop）带上两样自己的判断：
 * - new_ground：口子和以前走过的像、之间又没有外面来的东西时，相比以前新增了什么依据或前提变化。
 * - next_open：还有别的没完的拉着时，下一次做哪一件；凭什么写在 judgment 里。
 * 像不像只触发复核，停不停看有没有新依据；做哪一件由它凭理由选，不按挂上的先后。
 */
class Version001901Date20261028170000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('cognos_space_traces')) {
            $table = $schema->getTable('cognos_space_traces');
            if (!$table->hasColumn('new_ground')) {
                $table->addColumn('new_ground', Types::TEXT, ['notnull' => false]);
            }
            if (!$table->hasColumn('next_open')) {
                $table->addColumn('next_open', Types::BIGINT, ['notnull' => false]);
            }
        }

        return $schema;
    }
}
