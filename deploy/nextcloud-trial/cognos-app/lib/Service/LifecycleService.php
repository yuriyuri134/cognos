<?php
declare(strict_types=1);
namespace OCA\Cognos\Service;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Execution eligibility. Call lock/eligible only within a database transaction. */
final class LifecycleService {
    public function __construct(private IDBConnection $db) {}

    public function lock(string $projectId): array {
        // A no-op UPDATE holds the same row lock as a lifecycle transition.
        $q = $this->db->getQueryBuilder();
        $q->update('cognos_projects')->set('execution_epoch', 'execution_epoch')
            ->where($q->expr()->eq('id', $q->createNamedParameter($projectId)))->executeStatement();
        $q = $this->db->getQueryBuilder();
        $p = $q->select('*')->from('cognos_projects')
            ->where($q->expr()->eq('id', $q->createNamedParameter($projectId)))->executeQuery()->fetch();
        if (!$p) { throw new \RuntimeException('项目不存在', 404); }
        return $p;
    }

    public function forSpace(string $type, string $id): ?array {
        if ($type !== 'creation' || !ctype_digit($id)) { return null; }
        $q = $this->db->getQueryBuilder();
        $loc = $q->select('project_id')->from('cognos_locations')
            ->where($q->expr()->eq('creation_id', $q->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))
            ->executeQuery()->fetch();
        return $loc ? $this->lock((string)$loc['project_id']) : null;
    }

    public function eligible(string $projectId, array $run): bool {
        $p = $this->lock($projectId);
        return $p['state'] === 'active' && ($run['project_id'] ?? null) === $projectId
            && (int)($run['execution_epoch'] ?? 0) === (int)$p['execution_epoch'];
    }

    public function delegationEligible(string $projectId, string $delegationId): bool {
        $q = $this->db->getQueryBuilder();
        $run = $q->select('r.*')->from('cognos_runs', 'r')
            ->innerJoin('r', 'cognos_delegations', 'd', $q->expr()->eq('d.run_id', 'r.id'))
            ->where($q->expr()->eq('d.id', $q->createNamedParameter($delegationId)))->executeQuery()->fetch();
        // During dispatch the delegation may not yet have its run_id.
        if (!$run) {
            $q = $this->db->getQueryBuilder();
            $run = $q->select('*')->from('cognos_runs')
                ->where($q->expr()->eq('target_type', $q->createNamedParameter('delegation')))
                ->andWhere($q->expr()->eq('target_id', $q->createNamedParameter($delegationId)))
                ->orderBy('created_at', 'DESC')->setMaxResults(1)->executeQuery()->fetch();
        }
        return $run && $this->eligible($projectId, $run);
    }
}
