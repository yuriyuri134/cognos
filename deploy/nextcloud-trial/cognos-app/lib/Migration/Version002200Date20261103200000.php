<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 在生效的决定：用户本人说过、对这个项目（或列出的位置）的全部参与者都有效的话。
 *
 * - cognos_decisions：原话、原消息（Cognos 里的消息 id）或外部来源说明、原作者（只能是用户本人）、
 *   登记者（可以是代录的 AI，分开记）、适用范围（空 = 整个项目）、生效 / 撤回 / 被替代和替代关系。
 *   不先造一条想法再假装采用。
 * - cognos_run_decisions：一次运行起步时，入口里实际给了哪几条决定。
 * - cognos_run_results.decisions_missing：结果交回时，运行起步以后才有、输入里没有的适用决定（交回时记下）。
 */
class Version002200Date20261103200000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('cognos_decisions')) {
            $t = $schema->createTable('cognos_decisions');
            $t->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('project_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('content', Types::TEXT, ['notnull' => true]);
            $t->addColumn('source_message_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $t->addColumn('source_note', Types::TEXT, ['notnull' => false]);
            $t->addColumn('said_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $t->addColumn('original_author', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('recorded_by', Types::STRING, ['notnull' => true, 'length' => 128]);
            // JSON 列表；空列表 = 整个项目
            $t->addColumn('scope_location_ids', Types::TEXT, ['notnull' => true]);
            // active / revoked / superseded
            $t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
            $t->addColumn('supersedes', Types::STRING, ['notnull' => false, 'length' => 32]);
            $t->addColumn('superseded_by', Types::STRING, ['notnull' => false, 'length' => 32]);
            $t->addColumn('ended_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $t->addColumn('ended_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['project_id', 'status'], 'cognos_dec_proj_idx');
        }

        if (!$schema->hasTable('cognos_run_decisions')) {
            $t = $schema->createTable('cognos_run_decisions');
            $t->addColumn('run_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('decision_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->setPrimaryKey(['run_id', 'decision_id']);
            $t->addIndex(['decision_id'], 'cognos_rundec_dec_idx');
        }

        if ($schema->hasTable('cognos_run_results')) {
            $t = $schema->getTable('cognos_run_results');
            if (!$t->hasColumn('decisions_missing')) {
                $t->addColumn('decisions_missing', Types::TEXT, ['notnull' => false]);
            }
        }

        return $schema;
    }
}
