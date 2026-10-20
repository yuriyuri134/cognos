<?php
declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Activity Context Assembly: 把材料/理解/历史/遭遇分桶组装成可观察的 JSON
 * 
 * PHP port of web/context_assembly.py::assemble_activity_context()
 */
class ActivityContextAssemblyService {
	public function __construct(
		private IDBConnection $db,
	) {}

	/**
	 * Assemble context for one Activity run
	 * 
	 * @return array{
	 *   activity_id: string,
	 *   revision: int,
	 *   user_message: string,
	 *   formations: array,
	 *   dormant_formations: array,
	 *   tensions: array,
	 *   resolved_tensions: array,
	 *   moves: array,
	 *   recent_encounters: array,
	 *   affordances: array,
	 *   participant: array,
	 *   environment: array
	 * }
	 */
	public function assemble(
		string $activityId,
		string $userMessage,
		array $participant
	): array {
		$activity = $this->getActivity($activityId);
		if (!$activity) {
			throw new \RuntimeException("Activity $activityId not found");
		}

		// 重放 formations 和 tensions
		$formationsData = $this->replayFormations($activityId);
		$tensionsData = $this->replayTensions($activityId);
		
		// 获取 moves
		$moves = $this->getMoves($activityId);
		
		// 获取最近的操作结果（从 context snapshots）
		$recentEncounters = $this->getRecentEncounters($activityId);

		$context = [
			'activity_id' => $activityId,
			'revision' => (int)$activity['updated_at'], // 简化版：用 updated_at 作为 revision
			'user_message' => $userMessage,
			'focus' => $activity['title'],
			
			// Formations & Tensions（从 runs 重放出来）
			'formations' => $formationsData['active'],
			'dormant_formations' => $formationsData['dormant'],
			'formation_projection' => [
				'policy' => 'replay_from_events',
				'event_count' => $formationsData['event_count'],
				'note' => '停用不等于判假；历史版本仍保留在活动事件中',
			],
			'tensions' => $tensionsData['open'],
			'resolved_tensions' => $tensionsData['resolved'],
			
			// Moves
			'moves' => $moves,
			
			// 最近的操作结果
			'recent_encounters' => $recentEncounters,
			
			// Affordances（当前可用的操作）
			'affordances' => [
				['type' => 'ask_user', 'requires_arguments' => true],
				['type' => 'propose_formation', 'requires_arguments' => true],
				['type' => 'update_tension', 'requires_arguments' => true],
			],
			
			// Environment
			'participant' => $participant,
			'environment' => [
				'activity_id' => $activityId,
				'activity_revision' => (int)$activity['updated_at'],
			],
			
			// Assembly metadata
			'assembly' => [
				'reasons' => ['activity_context_assembly', 'named_context_buckets'],
				'assembled_at' => gmdate(DATE_ATOM),
			],
		];

		return $context;
	}

	/**
	 * 重放 formations: 从所有 runs 的 events_json 里提取 formation_updates，按 ID 累积
	 * 
	 * 逻辑参考 web/cognitive_runtime.py::project_updates()
	 */
	private function replayFormations(string $activityId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('events_json', 'created_at')
			->from('cognos_activity_runs')
			->where($qb->expr()->eq('activity_id', $qb->createNamedParameter($activityId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('completed')))
			->orderBy('created_at', 'ASC');
		
		$result = $qb->executeQuery();
		$runs = $result->fetchAll();
		$result->closeCursor();

		$formationsByKey = []; // formation_id => {id, status, text, refs, revision}
		$eventCount = 0;

		foreach ($runs as $run) {
			$events = json_decode($run['events_json'], true);
			if (!isset($events['formation_updates'])) {
				continue;
			}
			foreach ($events['formation_updates'] as $update) {
				$eventCount++;
				$key = $update['id'];
				if ($update['status'] === 'changed') {
					// 修订：创建新版本
					$formationsByKey[$key] = [
						'id' => $key,
						'status' => 'active',
						'text' => $update['text'],
						'refs' => $update['refs'] ?? [],
						'revision' => $run['created_at'],
						'source' => 'run_event',
					];
				} elseif ($update['status'] === 'active') {
					// 新增或重新激活
					$formationsByKey[$key] = [
						'id' => $key,
						'status' => 'active',
						'text' => $update['text'],
						'refs' => $update['refs'] ?? [],
						'revision' => $run['created_at'],
						'source' => 'run_event',
					];
				} elseif ($update['status'] === 'dormant') {
					// 暂停
					if (isset($formationsByKey[$key])) {
						$formationsByKey[$key]['status'] = 'dormant';
					}
				}
			}
		}

		$active = array_values(array_filter($formationsByKey, fn($f) => $f['status'] === 'active'));
		$dormant = array_values(array_filter($formationsByKey, fn($f) => $f['status'] === 'dormant'));

		return [
			'active' => $active,
			'dormant' => $dormant,
			'event_count' => $eventCount,
		];
	}

	/**
	 * 重放 tensions
	 */
	private function replayTensions(string $activityId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('events_json', 'created_at')
			->from('cognos_activity_runs')
			->where($qb->expr()->eq('activity_id', $qb->createNamedParameter($activityId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('completed')))
			->orderBy('created_at', 'ASC');
		
		$result = $qb->executeQuery();
		$runs = $result->fetchAll();
		$result->closeCursor();

		$tensionsByKey = []; // tension_id => {id, status, text, refs}

		foreach ($runs as $run) {
			$events = json_decode($run['events_json'], true);
			if (!isset($events['tension_updates'])) {
				continue;
			}
			foreach ($events['tension_updates'] as $update) {
				$key = $update['id'];
				if ($update['status'] === 'open') {
					$tensionsByKey[$key] = [
						'id' => $key,
						'status' => 'open',
						'text' => $update['text'],
						'refs' => $update['refs'] ?? [],
					];
				} elseif ($update['status'] === 'resolved') {
					if (isset($tensionsByKey[$key])) {
						$tensionsByKey[$key]['status'] = 'resolved';
					}
				}
			}
		}

		$open = array_values(array_filter($tensionsByKey, fn($t) => $t['status'] === 'open'));
		$resolved = array_values(array_filter($tensionsByKey, fn($t) => $t['status'] === 'resolved'));

		return ['open' => $open, 'resolved' => $resolved];
	}

	/**
	 * 获取 moves
	 */
	private function getMoves(string $activityId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'proposer', 'proposer_participant_id', 'basis_revision', 
		           'intent', 'execution_json', 'state_json', 'occurrence_id')
			->from('cognos_activity_moves')
			->where($qb->expr()->eq('activity_id', $qb->createNamedParameter($activityId)))
			->orderBy('created_at', 'ASC');
		
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$moves = [];
		foreach ($rows as $row) {
			$state = json_decode($row['state_json'], true);
			$moves[] = [
				'id' => $row['id'],
				'proposer' => $row['proposer'],
				'proposer_participant_id' => $row['proposer_participant_id'],
				'occurrence_id' => $row['occurrence_id'],
				'basis_revision' => (int)$row['basis_revision'],
				'intent' => $row['intent'],
				'status' => $state['status'] ?? 'proposed',
				'results' => $state['results'] ?? [],
			];
		}

		return $moves;
	}

	/**
	 * 获取最近的操作结果（从 context snapshots 或 messages）
	 */
	private function getRecentEncounters(string $activityId, int $limit = 5): array {
		// 简化版：暂时返回空数组
		return [];
	}

	private function getActivity(string $activityId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('cognos_activities')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($activityId)));
		
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row ?: null;
	}

	/**
	 * 渲染 prompt: 把 context 序列化成 JSON 发给模型
	 */
	public function renderPrompt(array $context, string $systemPrompt = ''): string {
		$json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
		
		return $systemPrompt . "\n\n本次处境（内容材料）：\n" . $json;
	}
}
