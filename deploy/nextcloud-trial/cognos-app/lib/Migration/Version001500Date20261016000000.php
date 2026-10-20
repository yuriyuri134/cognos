<?php
declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Cognos Activity Harness: runs, events, context snapshots
 */
class Version001500Date20261016000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// cognos_activity_runs: 每次对话/操作的执行记录
		if (!$schema->hasTable('cognos_activity_runs')) {
			$table = $schema->createTable('cognos_activity_runs');
			$table->addColumn('id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('activity_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('request_id', Types::STRING, ['length' => 100, 'notnull' => true]);
			$table->addColumn('kind', Types::STRING, ['length' => 50, 'notnull' => true]); // continue/answer/formation_update
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true]); // activity revision at run start
			$table->addColumn('initiator', Types::STRING, ['length' => 200, 'notnull' => true]);
			$table->addColumn('participant_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('status', Types::STRING, ['length' => 50, 'notnull' => true]); // running/completed/failed/conflict
			$table->addColumn('events_json', Types::TEXT, ['notnull' => true, 'default' => '[]']); // formation_updates/tension_updates/results
			$table->addColumn('error', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('completed_at', Types::DATETIME, ['notnull' => false]);
			
			$table->setPrimaryKey(['id']);
			$table->addIndex(['activity_id'], 'cognos_runs_activity');
			$table->addUniqueIndex(['activity_id', 'request_id'], 'cognos_runs_idempotent');
		}

		// cognos_activity_context_snapshots: 每次调用模型时的完整上下文快照
		if (!$schema->hasTable('cognos_activity_context_snapshots')) {
			$table = $schema->createTable('cognos_activity_context_snapshots');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('run_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('activity_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('message_id', Types::BIGINT, ['notnull' => false]); // cognos_activity_messages.id
			$table->addColumn('participant_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('context_json', Types::TEXT, ['notnull' => true]); // full assemble_activity_context() output
			$table->addColumn('prompt_text', Types::TEXT, ['notnull' => false]); // rendered prompt (optional)
			$table->addColumn('model', Types::STRING, ['length' => 100, 'notnull' => false]);
			$table->addColumn('occurred_at', Types::DATETIME, ['notnull' => true]);
			
			$table->setPrimaryKey(['id']);
			$table->addIndex(['run_id'], 'cognos_ctx_run');
			$table->addIndex(['activity_id'], 'cognos_ctx_activity');
			$table->addIndex(['message_id'], 'cognos_ctx_message');
		}

		// cognos_activity_moves: Agent 提议的操作（不是"等待确认"，而是执行记录）
		if (!$schema->hasTable('cognos_activity_moves')) {
			$table = $schema->createTable('cognos_activity_moves');
			$table->addColumn('id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('activity_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('request_id', Types::STRING, ['length' => 100, 'notnull' => true]);
			$table->addColumn('proposer', Types::STRING, ['length' => 200, 'notnull' => true]);
			$table->addColumn('proposer_participant_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$table->addColumn('basis_revision', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('intent', Types::TEXT, ['notnull' => true]); // 意图文字
			$table->addColumn('execution_json', Types::TEXT, ['notnull' => true]); // 步骤列表
			$table->addColumn('state_json', Types::TEXT, ['notnull' => true]); // {status: proposed/executing/waiting_user/done/failed, results: [...]}
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('occurrence_id', Types::STRING, ['length' => 64, 'notnull' => false]); // history message ID
			
			$table->setPrimaryKey(['id']);
			$table->addIndex(['activity_id'], 'cognos_moves_activity');
			$table->addUniqueIndex(['activity_id', 'request_id'], 'cognos_moves_idempotent');
		}

		return $schema;
	}
}
