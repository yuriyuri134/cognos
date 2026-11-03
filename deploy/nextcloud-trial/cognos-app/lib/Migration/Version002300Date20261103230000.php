<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 从位置上「交出去」：
 *
 * - cognos_delegations：交出去的那句话、要做成什么、用什么、不能碰什么、什么时候回来、给不给改这一处当前内容，
 *   交出去时当前内容是哪一版，挂的运行，回来的回答；AI 写成的版本（applied_revision），
 *   或起步后有人改过 / 有了新决定而没有覆盖、留着待比较的版本（held_*）。
 * - cognos_location_revisions.delegation_id：这一版是哪次委托里写的（作者记在 editor）。
 */
class Version002300Date20261103230000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('cognos_delegations')) {
            $t = $schema->createTable('cognos_delegations');
            $t->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('project_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('location_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('ask', Types::TEXT, ['notnull' => true]);
            $t->addColumn('goal', Types::TEXT, ['notnull' => false]);
            $t->addColumn('means', Types::TEXT, ['notnull' => false]);
            $t->addColumn('forbidden', Types::TEXT, ['notnull' => false]);
            $t->addColumn('return_by', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $t->addColumn('may_edit', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $t->addColumn('base_revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            // sending / starting / sent / not_taken / returned
            $t->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16]);
            $t->addColumn('note', Types::TEXT, ['notnull' => false]);
            $t->addColumn('run_id', Types::STRING, ['notnull' => false, 'length' => 32]);
            $t->addColumn('message_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $t->addColumn('result_message_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $t->addColumn('applied_revision', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
            $t->addColumn('held_content', Types::TEXT, ['notnull' => false]);
            $t->addColumn('held_base_revision', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
            $t->addColumn('held_reason', Types::TEXT, ['notnull' => false]);
            $t->addColumn('held_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['location_id', 'created_at'], 'cognos_dlg_loc_idx');
            $t->addIndex(['run_id'], 'cognos_dlg_run_idx');
        }

        if ($schema->hasTable('cognos_location_revisions')) {
            $t = $schema->getTable('cognos_location_revisions');
            if (!$t->hasColumn('delegation_id')) {
                $t->addColumn('delegation_id', Types::STRING, ['notnull' => false, 'length' => 32]);
            }
        }

        return $schema;
    }
}
