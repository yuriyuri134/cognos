<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 创建项目空间和组织空间的数据表
 */
class Version001700Date20261021120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        // 项目空间元数据表（Deck 集成）
        if (!$schema->hasTable('cognos_projects')) {
            $table = $schema->createTable('cognos_projects');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('deck_board_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('title', Types::STRING, [
                'notnull' => true,
                'length' => 500,
            ]);
            $table->addColumn('description', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('metadata_json', Types::TEXT, [
                'notnull' => false,
                'default' => '',
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'idx_cognos_projects_user');
            $table->addIndex(['deck_board_id'], 'idx_cognos_projects_deck');
            $table->addUniqueIndex(['user_id', 'deck_board_id'], 'uniq_cognos_projects_user_deck');
        }

        // Agent 项目建议记录表
        if (!$schema->hasTable('cognos_project_suggestions')) {
            $table = $schema->createTable('cognos_project_suggestions');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('project_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('suggestion_type', Types::STRING, [
                'notnull' => true,
                'length' => 50,
            ]);
            $table->addColumn('content', Types::TEXT, [
                'notnull' => true,
            ]);
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 20,
                'default' => 'pending',
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['project_id'], 'idx_cognos_proj_sugg_project');
            $table->addIndex(['user_id', 'created_at'], 'idx_cognos_proj_sugg_user');
        }

        // 组织知识库表（Talk 集成）
        if (!$schema->hasTable('cognos_org_knowledge')) {
            $table = $schema->createTable('cognos_org_knowledge');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('talk_token', Types::STRING, [
                'notnull' => false,
                'length' => 100,
            ]);
            $table->addColumn('title', Types::STRING, [
                'notnull' => true,
                'length' => 500,
            ]);
            $table->addColumn('content', Types::TEXT, [
                'notnull' => true,
            ]);
            $table->addColumn('knowledge_type', Types::STRING, [
                'notnull' => true,
                'length' => 50,
            ]);
            $table->addColumn('tags', Types::TEXT, [
                'notnull' => false,
                'default' => '[]',
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'idx_cognos_org_know_user');
            $table->addIndex(['talk_token'], 'idx_cognos_org_know_talk');
            $table->addIndex(['knowledge_type'], 'idx_cognos_org_know_type');
        }

        // Agent 会议总结记录表
        if (!$schema->hasTable('cognos_meeting_summaries')) {
            $table = $schema->createTable('cognos_meeting_summaries');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('talk_token', Types::STRING, [
                'notnull' => true,
                'length' => 100,
            ]);
            $table->addColumn('meeting_title', Types::STRING, [
                'notnull' => true,
                'length' => 500,
            ]);
            $table->addColumn('summary', Types::TEXT, [
                'notnull' => true,
            ]);
            $table->addColumn('action_items', Types::TEXT, [
                'notnull' => false,
                'default' => '[]',
            ]);
            $table->addColumn('participants', Types::TEXT, [
                'notnull' => false,
                'default' => '[]',
            ]);
            $table->addColumn('meeting_date', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'idx_cognos_meet_summ_user');
            $table->addIndex(['talk_token'], 'idx_cognos_meet_summ_talk');
            $table->addIndex(['meeting_date'], 'idx_cognos_meet_summ_date');
        }

        // 向量存储表（用于 RAG）
        if (!$schema->hasTable('cognos_vectors')) {
            $table = $schema->createTable('cognos_vectors');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('source_type', Types::STRING, [
                'notnull' => true,
                'length' => 50,
            ]);
            $table->addColumn('source_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('content', Types::TEXT, [
                'notnull' => true,
            ]);
            $table->addColumn('embedding', Types::TEXT, [
                'notnull' => true,
            ]);
            $table->addColumn('metadata_json', Types::TEXT, [
                'notnull' => false,
                'default' => '{}',
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id', 'source_type'], 'idx_cognos_vectors_user_type');
            $table->addIndex(['source_type', 'source_id'], 'idx_cognos_vectors_source');
        }

        // 长期记忆表
        if (!$schema->hasTable('cognos_memories')) {
            $table = $schema->createTable('cognos_memories');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('context_type', Types::STRING, [
                'notnull' => true,
                'length' => 50,
            ]);
            $table->addColumn('context_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('memory_type', Types::STRING, [
                'notnull' => true,
                'length' => 50,
            ]);
            $table->addColumn('content', Types::TEXT, [
                'notnull' => true,
            ]);
            $table->addColumn('importance', Types::INTEGER, [
                'notnull' => true,
                'default' => 5,
            ]);
            $table->addColumn('access_count', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('last_accessed_at', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id', 'context_type'], 'idx_cognos_mem_user_ctx');
            $table->addIndex(['context_type', 'context_id'], 'idx_cognos_mem_context');
            $table->addIndex(['importance'], 'idx_cognos_mem_importance');
        }

        return $schema;
    }
}
