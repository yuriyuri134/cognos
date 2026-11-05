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
        if (!$loc) { return null; }
        $project=$this->lock((string)$loc['project_id']);
        $q=$this->db->getQueryBuilder();
        $current=$q->select('project_id')->from('cognos_locations')
            ->where($q->expr()->eq('creation_id',$q->createNamedParameter((int)$id,IQueryBuilder::PARAM_INT)))->executeQuery()->fetch();
        if (!$current || $current['project_id'] !== $loc['project_id']) { throw new \RuntimeException('位置归属已变化，请重新读取入口',409); }
        return $project;
    }

    public function assertCanStart(string $type, string $id): void {
        $this->db->beginTransaction();
        try {
            $p=$this->forSpace($type,$id);
            if ($p !== null && $p['state'] !== 'active') { throw new ProjectInactiveException('项目当前为 '.$p['state'].'，已停止派新工作；已有结果仍可交回'); }
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function eligible(string $projectId, array $run): bool {
        $p = $this->lock($projectId);
        return $p['state'] === 'active' && ($run['project_id'] ?? null) === $projectId
            && (int)($run['execution_epoch'] ?? 0) === (int)$p['execution_epoch'];
    }

    /** Location/project lock is held by run admission or result application. */
    public function delegationWorkEligible(string $delegationId): bool {
        $q=$this->db->getQueryBuilder();
        $d=$q->select('work_item_id','work_epoch','location_id')->from('cognos_delegations')
            ->where($q->expr()->eq('id',$q->createNamedParameter($delegationId)))->executeQuery()->fetch();
        if (!$d || empty($d['work_item_id'])) { return true; }
        $q=$this->db->getQueryBuilder();$w=$q->select('location_id','state','execution_epoch')->from('cognos_work_items')
            ->where($q->expr()->eq('id',$q->createNamedParameter($d['work_item_id'])))->executeQuery()->fetch();
        return $w && $w['location_id']===$d['location_id'] && in_array($w['state'],['open','in_progress'],true)
            && (int)$w['execution_epoch']===(int)$d['work_epoch'];
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
        return $run && $this->eligible($projectId, $run) && $this->delegationWorkEligible($delegationId);
    }
}
