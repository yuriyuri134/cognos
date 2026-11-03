<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * 运行：一次实际发生的工作过程，叫桥之前先记下来，结果回来时只收一次。
 *
 * - 挂在哪（target_type + target_id）和为了什么（purpose）分开记。挂的可以是一件没完的（open）、一条反对（hit）、
 *   一段会话（conversation），以后还有尝试、工作项；不要求先做成任务。
 * - 状态：queued（记下了还没送出）→ running（桥收下了，带租约）→ delivered（结果收到了）；
 *   送不出去是 failed；租约过了还没回来、或者桥重启时发现它做到一半没了，是 unknown：不知道做没做完，不当作没做。
 *   unknown 的运行后来结果回来了，照样收下。
 * - 结果按 result_id 只收一次：同一个结果发两次，第二次拿回第一次存下的那条消息，不再存、不再叫下一件。
 * - 结果只收这次登记的参与者（subject）署名的。
 *
 * 设计见 docs/项目管理/Cognos项目设计/03-系统/现有代码对照.md「桥」一节、02-协作/共同工作怎样发生.md 二、三。
 */
final class RunService {
    public const STATES = ['queued', 'claimed', 'running', 'result_pending', 'delivered', 'failed', 'unknown'];
    /** 还在做的：租约过了就算 unknown */
    private const LIVE = ['claimed', 'running'];
    /** 桥一次最多做 30 分钟（COGNOS_BRIDGE_TIMEOUT），再留 10 分钟发回 */
    private const LEASE_DEFAULT = 2400;

    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
    ) {
    }

    /**
     * @param array<string, mixed> $input 实际给出去的东西（系统提示、消息……），原样存一份
     */
    public function create(string $spaceType, string $spaceId, string $targetType, string $targetId, string $purpose, string $subject, ?string $caller, array $input): array {
        $id = bin2hex(random_bytes(16));
        $now = time();
        $snapshot = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $row = [
            'id' => $id,
            'space_type' => $spaceType,
            'space_id' => $spaceId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'purpose' => $purpose,
            'subject' => mb_substr($subject, 0, 128),
            'caller' => $caller === null ? null : mb_substr($caller, 0, 128),
            'state' => 'queued',
            'input_hash' => hash('sha256', $snapshot),
            'input_snapshot' => $snapshot,
            'lease_until' => null,
            'error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $ints = ['created_at', 'updated_at'];
        $qb = $this->db->getQueryBuilder();
        $values = [];
        foreach ($row as $column => $value) {
            $type = $value === null ? IQueryBuilder::PARAM_NULL : (in_array($column, $ints, true) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
            $values[$column] = $qb->createNamedParameter($value, $type);
        }
        $qb->insert('cognos_runs')->values($values)->executeStatement();
        return $this->publicRow($row);
    }

    /** 桥收下了：开始做，带租约。 */
    public function accepted(string $runId): void {
        $lease = (int)$this->config->getAppValue('cognos', 'run_lease_seconds', (string)self::LEASE_DEFAULT);
        $this->moveFrom($runId, ['queued'], 'running', null, time() + max(1, $lease));
    }

    /** 没送出去（桥没起来、正忙）：桥那边什么都没做。 */
    public function failed(string $runId, string $error): void {
        $this->moveFrom($runId, ['queued'], 'failed', $error, null);
    }

    /** 桥重启时发现这一件做到一半没了：不知道做没做完。 */
    public function markUnknown(string $runId, string $reason): bool {
        return $this->moveFrom($runId, self::LIVE, 'unknown', $reason, null);
    }

    public function get(string $runId): ?array {
        $this->expireLeases();
        $qb = $this->db->getQueryBuilder();
        $row = $qb->select('*')->from('cognos_runs')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($runId)))
            ->executeQuery()->fetch();
        return $row === false ? null : $this->publicRow($row);
    }

    /** 这片空间最近的运行（新的在前）。 */
    public function forSpace(string $spaceType, string $spaceId, int $limit = 20): array {
        $this->expireLeases();
        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('*')->from('cognos_runs')
            ->where($qb->expr()->eq('space_type', $qb->createNamedParameter($spaceType)))
            ->andWhere($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId)))
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()->fetchAll();
        return array_map(fn(array $row): array => $this->publicRow($row), $rows);
    }

    /** Current work is independent of the recent-history page size. */
    public function activeForSpace(string $spaceType, string $spaceId): array {
        $this->expireLeases();
        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('*')->from('cognos_runs')
            ->where($qb->expr()->eq('space_type', $qb->createNamedParameter($spaceType)))
            ->andWhere($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId)))
            ->andWhere($qb->expr()->in('state', $qb->createNamedParameter(['queued', 'claimed', 'running', 'result_pending'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')->executeQuery()->fetchAll();
        return array_map(fn(array $r): array => $this->publicRow($r), $rows);
    }

    /**
     * 收一个结果。第一次收：在同一个事务里登记 result_id、调 $store 存消息、记依据、把运行标成 delivered。
     * 已经收过这个 result_id：什么都不做，返回第一次存下的消息 id。
     *
     * @param callable(): int $store 存下这个结果（对话里的一条消息），返回消息 id
     * @param list<array{type?: mixed, id?: mixed, version?: mixed, note?: mixed}> $dependsOn
     * @return array{duplicate: bool, message_id: ?int, run: array}
     * @throws \InvalidArgumentException 不是这次运行登记的参与者、这次运行已经收过另一个结果、或依据写得不对
     */
    public function receiveResult(array $run, string $resultId, string $subject, array $dependsOn, callable $store): array {
        if (!preg_match('/^[a-f0-9]{32}$/', $resultId)) {
            throw new \InvalidArgumentException('result_id must be 32 hex chars');
        }
        $existing = $this->result($resultId);
        if ($existing !== null) {
            if ($existing['run_id'] !== $run['id']) {
                throw new \InvalidArgumentException('result_id already belongs to another run');
            }
            return ['duplicate' => true, 'message_id' => $existing['message_id'], 'run' => $this->get($run['id']) ?? $run];
        }
        if ($subject !== $run['subject']) {
            throw new \DomainException(sprintf('这次运行登记的参与者是 %s，不收 %s 署名的结果', $run['subject'], $subject === '' ? '（没署名）' : $subject));
        }
        $deps = $this->normalizeDependencies($dependsOn);

        $this->db->beginTransaction();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('cognos_run_results')->values([
                'id' => $qb->createNamedParameter($resultId),
                'run_id' => $qb->createNamedParameter($run['id']),
                'received_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            ])->executeStatement();
        } catch (DbException $error) {
            $this->db->rollBack();
            if ($error->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $error;
            }
            // 同时到了两次（同一个 result_id），或者这次运行已经收过别的结果
            $existing = $this->result($resultId);
            if ($existing !== null && $existing['run_id'] === $run['id']) {
                return ['duplicate' => true, 'message_id' => $existing['message_id'], 'run' => $this->get($run['id']) ?? $run];
            }
            throw new \InvalidArgumentException('this run already has a different result');
        }
        try {
            $messageId = $store();
            $qb = $this->db->getQueryBuilder();
            $qb->update('cognos_run_results')
                ->set('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($resultId)))
                ->executeStatement();
            foreach ($deps as $dep) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('cognos_run_dependencies')->values([
                    'run_id' => $qb->createNamedParameter($run['id']),
                    'object_type' => $qb->createNamedParameter($dep['type']),
                    'object_id' => $qb->createNamedParameter($dep['id']),
                    'version' => $qb->createNamedParameter($dep['version']),
                    'note' => $qb->createNamedParameter($dep['note']),
                ])->executeStatement();
            }
            $qb = $this->db->getQueryBuilder();
            $qb->update('cognos_runs')
                ->set('state', $qb->createNamedParameter('delivered'))
                ->set('lease_until', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
                ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($run['id'])))
                ->executeStatement();
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        return ['duplicate' => false, 'message_id' => $messageId, 'run' => $this->get($run['id']) ?? $run];
    }

    /** 结果交回时记下：运行起步以后才有、输入里没有的适用决定。 */
    public function setDecisionsMissing(string $runId, array $missing): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('cognos_run_results')
            ->set('decisions_missing', $qb->createNamedParameter(json_encode(array_values($missing), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)))
            ->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
            ->executeStatement();
    }

    /**
     * 运行进行中，做事的那位自己报一条依据（同一条只记一次）。只收还在做的运行。
     *
     * @return bool 新记下返回 true，已经记过返回 false
     */
    public function addDependency(string $runId, string $type, string $id, ?string $version, ?string $note): bool {
        $dep = $this->normalizeDependencies([['type' => $type, 'id' => $id, 'version' => $version, 'note' => $note]])[0];
        foreach ($this->dependencies($runId) as $have) {
            if ($have['type'] === $dep['type'] && $have['id'] === $dep['id'] && $have['version'] === $dep['version']) {
                return false;
            }
        }
        $qb = $this->db->getQueryBuilder();
        $qb->insert('cognos_run_dependencies')->values([
            'run_id' => $qb->createNamedParameter($runId),
            'object_type' => $qb->createNamedParameter($dep['type']),
            'object_id' => $qb->createNamedParameter($dep['id']),
            'version' => $qb->createNamedParameter($dep['version']),
            'note' => $qb->createNamedParameter($dep['note']),
        ])->executeStatement();
        return true;
    }

    /** @return list<array{type: string, id: string, version: ?string, note: ?string}> */
    public function dependencies(string $runId): array {
        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('object_type', 'object_id', 'version', 'note')->from('cognos_run_dependencies')
            ->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
            ->orderBy('id', 'ASC')
            ->executeQuery()->fetchAll();
        return array_map(static fn(array $r): array => [
            'type' => (string)$r['object_type'],
            'id' => (string)$r['object_id'],
            'version' => $r['version'] === null ? null : (string)$r['version'],
            'note' => $r['note'] === null ? null : (string)$r['note'],
        ], $rows);
    }

    private function result(string $resultId): ?array {
        $qb = $this->db->getQueryBuilder();
        $row = $qb->select('id', 'run_id', 'message_id')->from('cognos_run_results')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($resultId)))
            ->executeQuery()->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'id' => (string)$row['id'],
            'run_id' => (string)$row['run_id'],
            'message_id' => $row['message_id'] === null ? null : (int)$row['message_id'],
        ];
    }

    /** 租约过了还没回来的：标成 unknown。只改仍在 claimed/running 的，不和同时到的结果抢。 */
    private function expireLeases(): void {
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->update('cognos_runs')
            ->set('state', $qb->createNamedParameter('unknown'))
            ->set('error', $qb->createNamedParameter('租约到了还没收到结果：不知道做没做完。'))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->in('state', $qb->createNamedParameter(self::LIVE, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->isNotNull('lease_until'))
            ->andWhere($qb->expr()->lt('lease_until', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    private function moveFrom(string $runId, array $from, string $to, ?string $error, ?int $leaseUntil): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update('cognos_runs')
            ->set('state', $qb->createNamedParameter($to))
            ->set('lease_until', $qb->createNamedParameter($leaseUntil, $leaseUntil === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($runId)))
            ->andWhere($qb->expr()->in('state', $qb->createNamedParameter($from, IQueryBuilder::PARAM_STR_ARRAY)));
        if ($error !== null) {
            $qb->set('error', $qb->createNamedParameter($error));
        }
        return $qb->executeStatement() > 0;
    }

    /** @return list<array{type: string, id: string, version: ?string, note: ?string}> */
    private function normalizeDependencies(array $dependsOn): array {
        $deps = [];
        foreach ($dependsOn as $dep) {
            if (!is_array($dep)) {
                throw new \InvalidArgumentException('each depends_on item needs type and id');
            }
            $type = trim((string)($dep['type'] ?? ''));
            $id = trim((string)($dep['id'] ?? ''));
            if ($type === '' || $id === '' || mb_strlen($type) > 32 || mb_strlen($id) > 64) {
                throw new \InvalidArgumentException('each depends_on item needs type (≤32) and id (≤64)');
            }
            $version = isset($dep['version']) && $dep['version'] !== null && $dep['version'] !== '' ? mb_substr((string)$dep['version'], 0, 64) : null;
            $note = isset($dep['note']) && trim((string)$dep['note']) !== '' ? trim((string)$dep['note']) : null;
            $deps[] = ['type' => $type, 'id' => $id, 'version' => $version, 'note' => $note];
        }
        return $deps;
    }

    private function publicRow(array $row): array {
        return [
            'id' => (string)$row['id'],
            'space_type' => (string)$row['space_type'],
            'space_id' => (string)$row['space_id'],
            'target_type' => (string)$row['target_type'],
            'target_id' => (string)$row['target_id'],
            'purpose' => (string)$row['purpose'],
            'subject' => (string)$row['subject'],
            'caller' => $row['caller'] === null ? null : (string)$row['caller'],
            'state' => (string)$row['state'],
            'input_hash' => (string)$row['input_hash'],
            'lease_until' => $row['lease_until'] === null ? null : (int)$row['lease_until'],
            'error' => $row['error'] === null ? null : (string)$row['error'],
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
        ];
    }
}
