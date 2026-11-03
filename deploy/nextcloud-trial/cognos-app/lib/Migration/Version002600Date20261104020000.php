<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 重组（开始与重组.md）：调整位置、独立成项目、合并项目。位置和项目的 id 都不变，旧链接不断。
 *
 * - cognos_restructures：一次重组本身：做了什么、确认时看到的后果、谁、什么时候。
 * - cognos_location_history：每个受影响的位置当时在哪个项目、哪个上级下面，后来去了哪。
 * - cognos_projects.merged_into / merged_at：被合并的项目身份留着，标明合并到了哪。
 */
class Version002600Date20261104020000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('cognos_restructures')) {
            $t = $schema->createTable('cognos_restructures');
            $t->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            // move / independent / merge
            $t->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 16]);
            $t->addColumn('params', Types::TEXT, ['notnull' => true]);
            // 确认时看到的后果（JSON 列表）
            $t->addColumn('consequences', Types::TEXT, ['notnull' => true]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $t->setPrimaryKey(['id']);
        }

        if (!$schema->hasTable('cognos_location_history')) {
            $t = $schema->createTable('cognos_location_history');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $t->addColumn('location_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('change_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 16]);
            $t->addColumn('from_project_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('to_project_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('from_parent_id', Types::STRING, ['notnull' => false, 'length' => 32]);
            $t->addColumn('to_parent_id', Types::STRING, ['notnull' => false, 'length' => 32]);
            $t->addColumn('changed_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('changed_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['location_id'], 'cognos_lochist_loc_idx');
            $t->addIndex(['from_project_id'], 'cognos_lochist_from_idx');
        }

        if ($schema->hasTable('cognos_projects')) {
            $t = $schema->getTable('cognos_projects');
            if (!$t->hasColumn('merged_into')) {
                $t->addColumn('merged_into', Types::STRING, ['notnull' => false, 'length' => 32]);
            }
            if (!$t->hasColumn('merged_at')) {
                $t->addColumn('merged_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            }
        }

        return $schema;
    }
}
