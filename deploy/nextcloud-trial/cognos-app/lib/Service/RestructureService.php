<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * 重组：调整位置（同一项目里换上级）、独立成项目（一处连同下面的成为新项目）、合并项目（整个项目并到另一个项目的某处下面）。
 *
 * - 先预览：受影响的位置、决定怎么跟着走、正在做的工作、跨项目的采用，写成几句后果给人看；
 *   确认时带上预览时的 basis（受影响状态的摘要），状态变了就整批不做，请人重新看预览。
 * - 位置和项目的 id 都不变，旧链接照样打开；每个受影响的位置记下当时在哪、去了哪（cognos_location_history）。
 *   被合并的项目身份留着，标明合并到了哪。
 * - 决定不扩大也不丢：独立时，只管移出去那部分的决定跟着走，整个项目的决定不带走（列出来，需要就在新项目里重新登记）；
 *   合并时，源项目整个项目的决定改成只管原来那部分，目标项目整个项目的决定从此也管到这部分（列出来）。
 * - 正在做的、交出去还没回来的照常交回到原来那一处（位置 id 不变）。
 *
 * 设计见 docs/项目管理/Cognos项目设计/01-产品/开始与重组.md。
 */
final class RestructureService {
    public function __construct(
        private IDBConnection $db,
        private ProjectService $projects,
        private DecisionService $decisions,
    ) {
    }

    /** @return array{action: string, consequences: list<string>, basis: string} */
    public function preview(string $uid, string $action, array $params): array {
        $plan = $this->plan($uid, $action, $params);
        return ['action' => $action, 'consequences' => $plan['consequences'], 'basis' => $plan['basis']];
    }

    public function apply(string $uid, string $action, array $params, string $basis, string $requestId): array {
        return $this->projects->commit($uid, $requestId, ['restructure', $action, $params, $basis], function () use ($uid, $action, $params, $basis): array {
            $plan = $this->plan($uid, $action, $params);
            if (!hash_equals($plan['basis'], $basis)) {
                throw new \RuntimeException('预览以后这里有了变化，没有做任何改动。请重新看一遍预览再确认。', 409);
            }
            $now = time();
            $change = bin2hex(random_bytes(16));
            $this->projects->insert('cognos_restructures', ['id' => $change, 'user_id' => $uid, 'action' => $action,
                'params' => json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'consequences' => json_encode($plan['consequences'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => $now]);
            ($plan['do'])($change, $now);
            return ['change_id' => $change, 'project_id' => $plan['project_id'], 'location_id' => $plan['location_id']];
        });
    }

    /** 一个位置的归属经过，和一个项目移出去 / 合并进来的经过。 */
    public function historyForLocation(string $uid, string $locationId): array {
        $this->projects->location($uid, $locationId);
        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('h.*')->from('cognos_location_history', 'h')
            ->where($qb->expr()->eq('h.location_id', $qb->createNamedParameter($locationId)))
            ->orderBy('h.changed_at', 'DESC')->addOrderBy('h.id', 'DESC')->executeQuery()->fetchAll();
        return $this->describeRows($uid, $rows);
    }

    public function historyForProject(string $uid, string $projectId): array {
        $this->projects->project($uid, $projectId);
        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('h.*')->from('cognos_location_history', 'h')
            ->where($qb->expr()->orX(
                $qb->expr()->eq('h.from_project_id', $qb->createNamedParameter($projectId)),
                $qb->expr()->eq('h.to_project_id', $qb->createNamedParameter($projectId))))
            ->orderBy('h.changed_at', 'DESC')->addOrderBy('h.id', 'DESC')->setMaxResults(200)->executeQuery()->fetchAll();
        return $this->describeRows($uid, $rows);
    }

    private function plan(string $uid, string $action, array $params): array {
        return match ($action) {
            'move' => $this->planMove($uid, (string)($params['location_id'] ?? ''), (string)($params['parent_id'] ?? '')),
            'independent' => $this->planIndependent($uid, (string)($params['location_id'] ?? '')),
            'merge' => $this->planMerge($uid, (string)($params['project_id'] ?? ''), (string)($params['target_parent_id'] ?? '')),
            default => throw new \InvalidArgumentException('重组只有调整位置（move）、独立成项目（independent）、合并（merge）'),
        };
    }

    private function planMove(string $uid, string $locationId, string $parentId): array {
        $loc = $this->projects->location($uid, $locationId);
        $project = $this->live($uid, $loc['project_id']);
        if ($loc['parent_id'] === null) { throw new \InvalidArgumentException('项目的根位置不能调整；要并到别的项目里，用合并'); }
        $parent = $this->projects->location($uid, $parentId);
        if ($parent['project_id'] !== $loc['project_id']) { throw new \InvalidArgumentException('只能在同一个项目里调整；要去别的项目，用独立或合并'); }
        $subtree = $this->subtree($project['locations'], $locationId);
        if (in_array($parentId, $subtree, true)) { throw new \InvalidArgumentException('不能放到它自己或它下面的位置下面'); }
        if ($parentId === $loc['parent_id']) { throw new \InvalidArgumentException('它已经在那里了'); }
        $titles = array_column($project['locations'], 'title', 'id');
        $lines = [sprintf('「%s」从「%s」下面移到「%s」下面%s。', $loc['title'], $this->path($project['locations'], $loc['parent_id']),
            $this->path($project['locations'], $parentId), count($subtree) > 1 ? sprintf('，连同它下面的 %d 处一起', count($subtree) - 1) : ''),
            '位置的链接和讨论都不变，旧链接照样打开；它的归属经过里会记下这次调整。'];
        $lines = array_merge($lines, $this->scopeShift($uid, $project, $locationId, $loc['parent_id'], $parentId, $titles), $this->workLines($uid, $subtree));
        return ['consequences' => $lines, 'basis' => $this->basis($uid, [$project['id']]), 'project_id' => $project['id'], 'location_id' => $locationId,
            'do' => function (string $change, int $now) use ($uid, $loc, $parentId, $project): void {
                $this->setLocation($loc['id'], ['parent_id' => $parentId]);
                $this->history($loc['id'], $change, 'move', $project['id'], $project['id'], $loc['parent_id'], $parentId, $uid, $now);
            }];
    }

    private function planIndependent(string $uid, string $locationId): array {
        $loc = $this->projects->location($uid, $locationId);
        $project = $this->live($uid, $loc['project_id']);
        if ($loc['parent_id'] === null) { throw new \InvalidArgumentException('它已经是一个项目的根位置了'); }
        $subtree = $this->subtree($project['locations'], $locationId);
        $parents = array_column($project['locations'], 'parent_id', 'id');
        $lines = [sprintf('「%s」%s成为新项目「%s」；它成为新项目的根位置。', $loc['title'],
            count($subtree) > 1 ? sprintf('连同它下面的 %d 处，', count($subtree) - 1) : '', $loc['title']),
            sprintf('原项目「%s」里不再有这几处；原项目总览的经过里记着它们去了哪。位置的链接和讨论都不变。', $project['title'])];
        $moving = []; $staying = [];
        foreach ($this->decisions->forProject($uid, $project['id']) as $d) {
            if ($d['status'] !== 'active') { continue; }
            if ($d['scope_location_ids'] !== [] && array_diff($d['scope_location_ids'], $subtree) === []) { $moving[] = $d; }
            elseif ($d['scope_location_ids'] === [] || array_intersect($d['scope_location_ids'], $this->ancestors($parents, $locationId)) !== []) { $staying[] = $d; }
        }
        if ($moving !== []) { $lines[] = '只管这几处的决定跟着走：' . $this->quoteList($moving) . '。'; }
        if ($staying !== []) { $lines[] = '这些决定留在原项目，以后不再出现在这几处的入口里（需要的话在新项目里重新登记）：' . $this->quoteList($staying) . '。'; }
        $cross = $this->crossAdoptions($subtree);
        if ($cross > 0) { $lines[] = sprintf('这几处有 %d 条采用指向原项目里别处的内容，照样保留，成为跨项目的采用。', $cross); }
        $lines = array_merge($lines, $this->workLines($uid, $subtree));
        $newId = bin2hex(random_bytes(16));
        return ['consequences' => $lines, 'basis' => $this->basis($uid, [$project['id']]), 'project_id' => $newId, 'location_id' => $locationId,
            'do' => function (string $change, int $now) use ($uid, $loc, $project, $subtree, $moving, $newId, $parents): void {
                $this->projects->insert('cognos_projects', ['id' => $newId, 'user_id' => $uid, 'title' => $loc['title'],
                    'root_location_id' => $loc['id'], 'created_at' => $now, 'merged_into' => null, 'merged_at' => null]);
                foreach ($subtree as $id) {
                    $this->setLocation($id, ['project_id' => $newId] + ($id === $loc['id'] ? ['parent_id' => null] : []));
                    $this->history($id, $change, 'independent', $project['id'], $newId, $parents[$id], $id === $loc['id'] ? null : $parents[$id], $uid, $now);
                }
                foreach ($moving as $d) { $this->setDecisionProject($d['id'], $newId, null); }
                $this->moveRows($subtree, $newId);
            }];
    }

    private function planMerge(string $uid, string $sourceId, string $targetParentId): array {
        $source = $this->live($uid, $sourceId);
        $parent = $this->projects->location($uid, $targetParentId);
        $target = $this->live($uid, $parent['project_id']);
        if ($target['id'] === $source['id']) { throw new \InvalidArgumentException('不能合并到自己里面'); }
        $root = (string)$source['root_location_id'];
        $lines = [sprintf('项目「%s」的全部 %d 处并到项目「%s」的「%s」下面；原来的根位置「%s」成为那里的一处。', $source['title'], count($source['locations']),
            $target['title'], $this->path($target['locations'], $targetParentId), $this->titleIn($source['locations'], $root)),
            sprintf('项目「%s」的身份留着，标为已合并到「%s」，它的链接打开后指向那里；位置的链接和讨论都不变。', $source['title'], $target['title'])];
        $wide = array_values(array_filter($this->decisions->forProject($uid, $source['id']), static fn(array $d): bool => $d['status'] === 'active' && $d['scope_location_ids'] === []));
        if ($wide !== []) { $lines[] = '原项目整个项目的决定，合并后只管原来那部分：' . $this->quoteList($wide) . '。'; }
        $targetWide = array_values(array_filter($this->decisions->forProject($uid, $target['id']), static fn(array $d): bool => $d['status'] === 'active' && $d['scope_location_ids'] === []));
        if ($targetWide !== []) { $lines[] = '目标项目整个项目的决定，从此也出现在这部分的入口里：' . $this->quoteList($targetWide) . '。'; }
        $all = array_column($source['locations'], 'id');
        $lines = array_merge($lines, $this->workLines($uid, $all));
        $parents = array_column($source['locations'], 'parent_id', 'id');
        return ['consequences' => $lines, 'basis' => $this->basis($uid, [$source['id'], $target['id']]), 'project_id' => $target['id'], 'location_id' => $root,
            'do' => function (string $change, int $now) use ($uid, $source, $target, $root, $targetParentId, $all, $parents): void {
                foreach ($all as $id) {
                    $this->setLocation($id, ['project_id' => $target['id']] + ($id === $root ? ['parent_id' => $targetParentId] : []));
                    $this->history($id, $change, 'merge', $source['id'], $target['id'], $parents[$id], $id === $root ? $targetParentId : $parents[$id], $uid, $now);
                }
                foreach ($this->decisions->forProject($uid, $source['id']) as $d) {
                    $this->setDecisionProject($d['id'], $target['id'], $d['scope_location_ids'] === [] ? [$root] : null);
                }
                $this->moveRows($all, $target['id']);
                $qb = $this->db->getQueryBuilder();
                $qb->update('cognos_projects')->set('merged_into', $qb->createNamedParameter($target['id']))
                    ->set('merged_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($source['id'])))->executeStatement();
            }];
    }

    /** 调整位置以后，入口里会多出 / 少掉哪些按范围登记的决定。 */
    private function scopeShift(string $uid, array $project, string $locationId, string $oldParent, string $newParent, array $titles): array {
        $parents = array_column($project['locations'], 'parent_id', 'id');
        $before = array_merge([$locationId], $this->ancestors($parents, $locationId));
        $parents[$locationId] = $newParent;
        $after = array_merge([$locationId], $this->ancestors($parents, $locationId));
        $gain = []; $lose = [];
        foreach ($this->decisions->forProject($uid, $project['id']) as $d) {
            if ($d['status'] !== 'active' || $d['scope_location_ids'] === []) { continue; }
            $was = array_intersect($d['scope_location_ids'], $before) !== [];
            $will = array_intersect($d['scope_location_ids'], $after) !== [];
            if ($will && !$was) { $gain[] = $d; }
            if ($was && !$will) { $lose[] = $d; }
        }
        $lines = [];
        if ($gain !== []) { $lines[] = '移过去以后，这些决定开始管到它：' . $this->quoteList($gain) . '。'; }
        if ($lose !== []) { $lines[] = '移过去以后，这些决定不再管到它：' . $this->quoteList($lose) . '。'; }
        return $lines;
    }

    private function workLines(string $uid, array $locationIds): array {
        $chats = []; $open = 0;
        $qb = $this->db->getQueryBuilder();
        foreach ($qb->select('creation_id')->from('cognos_locations')
            ->where($qb->expr()->in('id', $qb->createNamedParameter($locationIds, IQueryBuilder::PARAM_STR_ARRAY)))->executeQuery()->fetchAll() as $r) {
            $chats[] = (string)$r['creation_id'];
        }
        $qb = $this->db->getQueryBuilder();
        $running = (int)$qb->select($qb->func()->count('*', 'n'))->from('cognos_runs')
            ->where($qb->expr()->eq('space_type', $qb->createNamedParameter('creation')))
            ->andWhere($qb->expr()->in('space_id', $qb->createNamedParameter($chats, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->in('state', $qb->createNamedParameter(['queued', 'claimed', 'running', 'result_pending', 'unknown'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->executeQuery()->fetchOne();
        $qb = $this->db->getQueryBuilder();
        $open = (int)$qb->select($qb->func()->count('*', 'n'))->from('cognos_delegations')
            ->where($qb->expr()->in('location_id', $qb->createNamedParameter($locationIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->in('state', $qb->createNamedParameter(['sending', 'starting', 'sent'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->executeQuery()->fetchOne();
        return $running + $open > 0
            ? [sprintf('这些位置上有 %d 件正在做、%d 件交出去还没回来；结果照常交回到原来那一处。', $running, $open)]
            : [];
    }

    private function crossAdoptions(array $subtree): int {
        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('object_id')->from('cognos_adoptions')
            ->where($qb->expr()->in('location_id', $qb->createNamedParameter($subtree, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter('location_content')))
            ->andWhere($qb->expr()->in('state', $qb->createNamedParameter(['active', 'review'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->executeQuery()->fetchAll();
        return count(array_filter(array_column($rows, 'object_id'), static fn($id): bool => !in_array((string)$id, $subtree, true)));
    }

    /** 受影响项目的结构和决定的摘要；预览和确认之间变了，就不做。 */
    private function basis(string $uid, array $projectIds): string {
        $state = [];
        foreach ($projectIds as $pid) {
            $p = $this->projects->project($uid, $pid);
            $locs = array_map(static fn(array $l): array => [$l['id'], $l['parent_id']], $p['locations']);
            sort($locs);
            $decs = array_map(static fn(array $d): array => [$d['id'], $d['status'], $d['scope_location_ids']], $this->decisions->forProject($uid, $pid));
            sort($decs);
            $state[] = [$pid, $p['merged_into'] ?? null, $locs, $decs];
        }
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function live(string $uid, string $projectId): array {
        $p = $this->projects->project($uid, $projectId);
        if (!empty($p['merged_into'])) { throw new \RuntimeException('项目「' . $p['title'] . '」已经合并到别处了', 409); }
        return $p;
    }

    private function subtree(array $locations, string $id): array {
        $out = [$id];
        for ($i = 0; $i < count($out); $i++) {
            foreach ($locations as $l) { if ($l['parent_id'] === $out[$i]) { $out[] = (string)$l['id']; } }
        }
        return $out;
    }

    private function ancestors(array $parents, string $id): array {
        $out = [];
        for ($p = $parents[$id] ?? null; $p !== null && count($out) < 25; $p = $parents[$p] ?? null) { $out[] = (string)$p; }
        return $out;
    }

    private function path(array $locations, string $id): string {
        $parents = array_column($locations, 'parent_id', 'id');
        $names = array_map(fn(string $x): string => $this->titleIn($locations, $x), array_reverse(array_merge([$id], $this->ancestors($parents, $id))));
        return implode(' / ', $names);
    }

    private function titleIn(array $locations, string $id): string {
        return (string)(array_column($locations, 'title', 'id')[$id] ?? $id);
    }

    private function quoteList(array $decisions): string {
        return implode('、', array_map(static fn(array $d): string => '「' . $d['content'] . '」', $decisions));
    }

    private function setLocation(string $id, array $values): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('cognos_locations');
        foreach ($values as $k => $v) { $qb->set($k, $qb->createNamedParameter($v, $v === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR)); }
        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
    }

    private function setDecisionProject(string $id, string $projectId, ?array $scope): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('cognos_decisions')->set('project_id', $qb->createNamedParameter($projectId));
        if ($scope !== null) { $qb->set('scope_location_ids', $qb->createNamedParameter(json_encode($scope, JSON_THROW_ON_ERROR))); }
        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
    }

    /** 跟着位置走的委托、采用改记到新项目（位置 id 不变）。 */
    private function moveRows(array $locationIds, string $projectId): void {
        foreach (['cognos_delegations', 'cognos_adoptions'] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->update($table)->set('project_id', $qb->createNamedParameter($projectId))
                ->where($qb->expr()->in('location_id', $qb->createNamedParameter($locationIds, IQueryBuilder::PARAM_STR_ARRAY)))->executeStatement();
        }
    }

    private function history(string $locationId, string $change, string $action, string $from, string $to, ?string $fromParent, ?string $toParent, string $uid, int $now): void {
        $this->projects->insert('cognos_location_history', ['location_id' => $locationId, 'change_id' => $change, 'action' => $action,
            'from_project_id' => $from, 'to_project_id' => $to, 'from_parent_id' => $fromParent, 'to_parent_id' => $toParent,
            'changed_by' => $uid, 'changed_at' => $now]);
    }

    private function describeRows(string $uid, array $rows): array {
        $projectTitle = function (string $id) use ($uid): string {
            try { return (string)$this->projects->project($uid, $id)['title']; } catch (\RuntimeException) { return '（不可访问的项目）'; }
        };
        $locationTitle = function (?string $id) use ($uid): ?string {
            if ($id === null) { return null; }
            try { return (string)$this->projects->location($uid, $id)['title']; } catch (\RuntimeException) { return '（不可访问的位置）'; }
        };
        return array_map(static fn(array $r): array => [
            'location_id' => (string)$r['location_id'], 'location_title' => $locationTitle((string)$r['location_id']),
            'action' => (string)$r['action'], 'change_id' => (string)$r['change_id'],
            'from_project_id' => (string)$r['from_project_id'], 'from_project_title' => $projectTitle((string)$r['from_project_id']),
            'to_project_id' => (string)$r['to_project_id'], 'to_project_title' => $projectTitle((string)$r['to_project_id']),
            'from_parent_title' => $locationTitle($r['from_parent_id']), 'to_parent_title' => $locationTitle($r['to_parent_id']),
            'changed_by' => (string)$r['changed_by'], 'changed_at' => (int)$r['changed_at'],
        ], $rows);
    }
}
