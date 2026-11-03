<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 采用和纠正（判断采用与纠正.md）：
 *
 * - cognos_adoptions：某个位置采用了哪一样东西的哪一版（一段回答、当前内容的某一版）。一处一条，
 *   一处撤回不连带别处。状态 active / review（被纠正，待复核）/ withdrawn / superseded，旧记录留着。
 *   想法、反对、回应仍是只增不改的发生表，这里不复制它们（11/03 小林同意只把采用另开一张表）。
 * - cognos_corrections：纠正指向具体的一样东西、具体一版，带纠正的原话、谁、依据。
 * - cognos_impacts：一次纠正或撤回影响到了谁：明确依赖的进待复核（effect = review），只是相关的只提示（hint）。
 *   同一原因对同一目标只记一条；处理时写下怎么处理的。
 */
class Version002500Date20261104010000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('cognos_adoptions')) {
            $t = $schema->createTable('cognos_adoptions');
            $t->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('project_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('location_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            // message / location_content
            $t->addColumn('object_type', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('object_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('object_version', Types::STRING, ['notnull' => false, 'length' => 64]);
            $t->addColumn('adopted_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('basis', Types::TEXT, ['notnull' => false]);
            // active / review / withdrawn / superseded
            $t->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16]);
            $t->addColumn('supersedes', Types::STRING, ['notnull' => false, 'length' => 32]);
            $t->addColumn('superseded_by', Types::STRING, ['notnull' => false, 'length' => 32]);
            $t->addColumn('response', Types::TEXT, ['notnull' => false]);
            $t->addColumn('ended_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $t->addColumn('ended_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['location_id', 'state'], 'cognos_adopt_loc_idx');
            $t->addIndex(['object_type', 'object_id'], 'cognos_adopt_obj_idx');
        }

        if (!$schema->hasTable('cognos_corrections')) {
            $t = $schema->createTable('cognos_corrections');
            $t->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('object_type', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('object_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('object_version', Types::STRING, ['notnull' => false, 'length' => 64]);
            $t->addColumn('content', Types::TEXT, ['notnull' => true]);
            $t->addColumn('basis', Types::TEXT, ['notnull' => false]);
            $t->addColumn('author', Types::STRING, ['notnull' => true, 'length' => 128]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['object_type', 'object_id'], 'cognos_corr_obj_idx');
        }

        if (!$schema->hasTable('cognos_impacts')) {
            $t = $schema->createTable('cognos_impacts');
            $t->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 32]);
            // correction / withdrawal
            $t->addColumn('cause_type', Types::STRING, ['notnull' => true, 'length' => 16]);
            $t->addColumn('cause_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            // adoption / run / usage
            $t->addColumn('target_kind', Types::STRING, ['notnull' => true, 'length' => 16]);
            $t->addColumn('target_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('location_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            // review / hint
            $t->addColumn('effect', Types::STRING, ['notnull' => true, 'length' => 16]);
            $t->addColumn('note', Types::TEXT, ['notnull' => false]);
            $t->addColumn('resolved_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $t->addColumn('resolution', Types::TEXT, ['notnull' => false]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['cause_type', 'cause_id', 'target_kind', 'target_id'], 'cognos_impact_uniq');
            $t->addIndex(['location_id', 'resolved_at'], 'cognos_impact_loc_idx');
        }

        return $schema;
    }
}
