<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 运行一定回得来：叫桥之前先记一次运行，结果按 result_id 只收一次。
 *
 * - cognos_runs：一次实际发生的工作过程。挂在哪（target_type + target_id：一件没完的 open、一条反对、一段会话……，
 *   不强制是工作项）和为了什么分开记；给出去的输入原样存一份；租约过了还没回来就算 unknown，不当作没做。
 * - cognos_run_results：收到的结果，result_id 是桥生成的，同一个结果发两次只收一次。
 * - cognos_run_dependencies：交回时做事的那位自己列的「这次真正依据的是哪几条」，没列就没有。
 *
 * 之前的运行没有记录，不补造。
 */
class Version002000Date20261103120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('cognos_runs')) {
            $table = $schema->createTable('cognos_runs');
            $table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('space_type', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('space_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('target_type', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('target_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('purpose', Types::TEXT, ['notnull' => true]);
            // 这次登记的参与者：结果只收它署名的
            $table->addColumn('subject', Types::STRING, ['notnull' => true, 'length' => 128]);
            $table->addColumn('caller', Types::STRING, ['notnull' => false, 'length' => 128]);
            // queued / claimed / running / result_pending / delivered / failed / unknown
            $table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16]);
            $table->addColumn('input_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('input_snapshot', Types::TEXT, ['notnull' => true]);
            $table->addColumn('lease_until', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->addColumn('error', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['space_type', 'space_id', 'created_at'], 'cognos_run_space_idx');
            $table->addIndex(['state', 'lease_until'], 'cognos_run_state_idx');
        }

        if (!$schema->hasTable('cognos_run_results')) {
            $table = $schema->createTable('cognos_run_results');
            $table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('run_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('message_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->addColumn('received_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            // 一次运行只有一个结果
            $table->addUniqueIndex(['run_id'], 'cognos_result_run_uniq');
        }

        if (!$schema->hasTable('cognos_run_dependencies')) {
            $table = $schema->createTable('cognos_run_dependencies');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('run_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('object_type', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('object_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('version', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('note', Types::TEXT, ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['run_id'], 'cognos_rundep_run_idx');
            $table->addIndex(['object_type', 'object_id'], 'cognos_rundep_obj_idx');
        }

        return $schema;
    }
}
