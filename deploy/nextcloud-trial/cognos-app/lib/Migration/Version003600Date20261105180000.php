<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Norm 里的项目：主项目置顶，其他项目在下面；每个项目三部分（历史阶段、分支和部分拆解、关系图），先存成 JSON。 */
final class Version003600Date20261105180000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('cognos_norm_projects')) {
            $t = $schema->createTable('cognos_norm_projects');
            $t->addColumn('id', Types::STRING, ['length' => 32, 'notnull' => true]);
            $t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
            $t->addColumn('title', Types::STRING, ['length' => 200, 'notnull' => true]);
            $t->addColumn('is_main', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
            $t->addColumn('stages', Types::TEXT, ['notnull' => false]);
            $t->addColumn('branches', Types::TEXT, ['notnull' => false]);
            $t->addColumn('relations', Types::TEXT, ['notnull' => false]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $t->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['user_id'], 'cognos_norm_proj_user');
        }
        return $schema;
    }
}
