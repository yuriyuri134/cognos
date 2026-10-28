<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 发生带上「判断」（judgment）：做之前自己下的判断，要能被现实对上。
 * 尝试上是预判：这一步做下去，现实会碰回什么；卡住上是自己的判断：要是我来定，选哪个、凭什么。
 * 对没对上另记一条 checked（ref_id 指向带判断的那条）。
 */
class Version001900Date20261028160000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('cognos_space_traces')) {
            $table = $schema->getTable('cognos_space_traces');
            if (!$table->hasColumn('judgment')) {
                $table->addColumn('judgment', Types::TEXT, ['notnull' => false]);
            }
        }

        return $schema;
    }
}
