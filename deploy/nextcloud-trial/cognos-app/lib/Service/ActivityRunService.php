<?php
declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Activity Run Service: 处理 runs、事件记录、Agent 回复解析
 */
class ActivityRunService {
	public function __construct(
		private IDBConnection $db,
		private ActivityContextAssemblyService $contextAssembly,
		private HermesAgentService $hermes,
	) {}

	/**
	 * 开始一个新的 run
	 */
	public function beginRun(
		string $activityId,
		string $requestId,
		string $kind, // 'continue' / 'answer' / 'formation_update'
		string $initiator,
		?string $participantId = null
	): string {
		$runId = bin2hex(random_bytes(16));
		$now = time();

		$qb = $this->db->getQueryBuilder();
		$qb->insert('cognos_activity_runs')
			->values([
				'id' => $qb->createNamedParameter($runId),
				'activity_id' => $qb->createNamedParameter($activityId),
				'request_id' => $qb->createNamedParameter($requestId),
				'kind' => $qb->createNamedParameter($kind),
				'revision' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'initiator' => $qb->createNamedParameter($initiator),
				'participant_id' => $qb->createNamedParameter($participantId),
				'status' => $qb->createNamedParameter('running'),
				'events_json' => $qb->createNamedParameter('{}'),
				'created_at' => $qb->createNamedParameter(gmdate('Y-m-d H:i:s', $now)),
			]);
		
		$qb->executeStatement();

		return $runId;
	}

	/**
	 * 完成 run 并记录事件
	 */
	public function completeRun(string $runId, array $events): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('cognos_activity_runs')
			->set('status', $qb->createNamedParameter('completed'))
			->set('events_json', $qb->createNamedParameter(json_encode($events)))
			->set('completed_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($runId)));
		
		$qb->executeStatement();
	}

	/**
	 * Run 失败
	 */
	public function failRun(string $runId, string $error): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('cognos_activity_runs')
			->set('status', $qb->createNamedParameter('failed'))
			->set('error', $qb->createNamedParameter($error))
			->set('completed_at', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($runId)));
		
		$qb->executeStatement();
	}

	/**
	 * 保存 context snapshot
	 */
	public function saveContextSnapshot(
		string $runId,
		string $activityId,
		?int $messageId,
		string $participantId,
		array $context,
		?string $promptText = null,
		?string $model = null
	): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('cognos_activity_context_snapshots')
			->values([
				'run_id' => $qb->createNamedParameter($runId),
				'activity_id' => $qb->createNamedParameter($activityId),
				'message_id' => $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT),
				'participant_id' => $qb->createNamedParameter($participantId),
				'context_json' => $qb->createNamedParameter(json_encode($context)),
				'prompt_text' => $qb->createNamedParameter($promptText),
				'model' => $qb->createNamedParameter($model),
				'occurred_at' => $qb->createNamedParameter(gmdate('Y-m-d H:i:s')),
			]);
		
		$qb->executeStatement();
	}

	/**
	 * 解析 Agent 回复：提取 message / formation_updates / tension_updates / move
	 * 
	 * Agent 的回复可能是：
	 * 1. 纯文本消息
	 * 2. JSON 信封（包含 message/formations/tensions/move）
	 */
	public function parseAgentReply(string $reply): array {
		// 尝试解析 JSON
		$decoded = json_decode($reply, true);
		if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
			return [
				'message' => $decoded['message'] ?? '',
				'formation_updates' => $decoded['formation_updates'] ?? $decoded['formations'] ?? [],
				'tension_updates' => $decoded['tension_updates'] ?? $decoded['tensions'] ?? [],
				'move' => $decoded['move'] ?? null,
			];
		}

		// 纯文本：作为 message
		return [
			'message' => $reply,
			'formation_updates' => [],
			'tension_updates' => [],
			'move' => null,
		];
	}

	/**
	 * 执行一次 continue: 组装上下文 → 调用 Agent → 解析回复 → 记录事件
	 */
	public function executeContinue(
		string $activityId,
		string $userMessage,
		string $participantId,
		string $requestId,
		string $initiator
	): array {
		// 1. 开始 run
		$runId = $this->beginRun($activityId, $requestId, 'continue', $initiator, $participantId);

		try {
			// 2. 组装上下文
			$participant = $this->getParticipant($participantId);
			$context = $this->contextAssembly->assemble($activityId, $userMessage, $participant);

			// 3. 渲染 prompt
			$systemPrompt = $this->buildSystemPrompt($activityId);
			$promptText = $this->contextAssembly->renderPrompt($context, $systemPrompt);

			// 4. 调用 Hermes
			$messages = [
				['role' => 'system', 'content' => $promptText],
				['role' => 'user', 'content' => $userMessage],
			];

			$sessionId = 'activity-' . $activityId;
			$hermesResponse = $this->hermes->callAgent($sessionId, $messages, $participant['model'] ?? null, $participantId);
			$assistantReply = $hermesResponse['content'] ?? '';

			// 5. 解析回复
			$parsed = $this->parseAgentReply($assistantReply);

			// 6. 记录事件
			$events = [
				'formation_updates' => $parsed['formation_updates'],
				'tension_updates' => $parsed['tension_updates'],
			];
			if ($parsed['move']) {
				$events['move_proposed'] = $parsed['move'];
			}

			$this->completeRun($runId, $events);

			// 7. 保存 context snapshot
			$this->saveContextSnapshot(
				$runId,
				$activityId,
				null, // messageId will be set by caller
				$participantId,
				$context,
				$promptText,
				$participant['model'] ?? 'hermes-cognos'
			);

			return [
				'run_id' => $runId,
				'message' => $parsed['message'],
				'formation_updates' => $parsed['formation_updates'],
				'tension_updates' => $parsed['tension_updates'],
				'move' => $parsed['move'],
				'context' => $context,
			];

		} catch (\Throwable $error) {
			$this->failRun($runId, $error->getMessage());
			throw $error;
		}
	}

	private function getParticipant(string $participantId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('cognos_participants')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($participantId)));
		
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if (!$row) {
			throw new \InvalidArgumentException("Participant $participantId not found");
		}

		return [
			'id' => $row['id'],
			'display_name' => $row['name'],
			'model' => $row['route_model'] ?? 'hermes-cognos',
		];
	}

	private function buildSystemPrompt(string $activityId): string {
		return <<<'PROMPT'
这里是 Cognos 的一个 Activity。

你的回复可以是：
1. 纯文本消息
2. JSON 信封（包含多个部分）：
{
  "message": "你说的话",
  "formation_updates": [
    {"id": "f1", "status": "active", "text": "我的理解是...", "refs": []}
  ],
  "tension_updates": [
    {"id": "t1", "status": "open", "text": "未安顿的问题", "refs": []}
  ],
  "move": {
    "intent": "提议创建任务",
    "execution": [{"step": "create_task", "params": {...}}]
  }
}

Formation 和 Tension 的意义：
- Formation: 你提出的理解（可能有多个，可以暂缓/重启）
- Tension: 未安顿的问题（不是错误，是尚未整合的东西）
- Move: 提议的操作（会被记录，不是立刻执行）

当前上下文包含：
- formations: 活跃的理解
- dormant_formations: 暂停的理解
- tensions: 未安顿的问题
- moves: 之前的提议和结果

优先用纯文本回复，只在真正需要提出多个理解/记录问题/提议操作时才用 JSON 信封。
PROMPT;
	}
}
