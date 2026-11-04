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
            $ids=$this->affectedProjects($uid,$params);
            foreach ($ids as $id) { $this->projects->lockedProject($uid,$id); }
            if ($ids !== $this->affectedProjects($uid,$params)) { throw new \RuntimeException('位置归属已变化，请重新预览',409); }
            $plan = $this->plan($uid, $action, $params);
            if (!hash_equals($plan['basis'], $basis)) {
                throw new \RuntimeException('预览以后这里有了变化，没有做任何改动。请重新看一遍预览再确认。', 409);
            }
            $before = $this->snapshot($uid, $ids);
            $now = time();
            $change = bin2hex(random_bytes(16));
            $this->projects->insert('cognos_restructures', ['id' => $change, 'user_id' => $uid, 'action' => $action,
                'params' => json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'consequences' => json_encode($plan['consequences'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => $now,
                'reverts_change_id' => in_array($action, ['undo_move', 'undo'], true) ? $params['change_id'] : null]);
            ($plan['do'])($change, $now);
            $after = $this->snapshot($uid, array_values(array_unique(array_merge($ids, [$plan['project_id']]))));
            $q = $this->db->getQueryBuilder();
            $q->update('cognos_restructures')->set('undo_snapshot', $q->createNamedParameter(
                json_encode(['before' => $before, 'after' => $after], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)))
                ->where($q->expr()->eq('id', $q->createNamedParameter($change)))->executeStatement();
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

    private function affectedProjects(string $uid,array $params): array {
        $ids=[];
        if (!empty($params['change_id'])) {
            $change = $this->undoableChange($uid, (string)$params['change_id']);
            if ($change['action'] === 'move') {
                $old = $this->undoMoveState($uid, $change['id']);
                $ids[] = $old['location']['project_id'];
            } else {
                $old = $this->undoCrossState($uid, $change);
                $ids = [$old['carrier']['id'], $old['destination']['id']];
            }
        }
        if (!empty($params['project_id'])) { $ids[]=$this->projects->project($uid,(string)$params['project_id'])['id']; }
        foreach (['location_id','parent_id','target_parent_id'] as $key) {
            if (!empty($params[$key])) { $ids[]=$this->projects->location($uid,(string)$params[$key])['project_id']; }
        }
        $ids=array_values(array_unique($ids));sort($ids,SORT_STRING);return $ids;
    }

    private function stateLabel(string $state): string {
        return ['active'=>'正在推进','paused'=>'已暂停','ended'=>'已结束','exploring'=>'自由探索'][$state] ?? $state;
    }

    private function plan(string $uid, string $action, array $params): array {
        return match ($action) {
            'undo' => $this->planUndo($uid, (string)($params['change_id'] ?? '')),
            'undo_move' => $this->planUndoMove($uid, (string)($params['change_id'] ?? '')),
            'move' => $this->planMove($uid, (string)($params['location_id'] ?? ''), (string)($params['parent_id'] ?? '')),
            'independent' => $this->planIndependent($uid, (string)($params['location_id'] ?? '')),
            'merge' => $this->planMerge($uid, (string)($params['project_id'] ?? ''), (string)($params['target_parent_id'] ?? '')),
            default => throw new \InvalidArgumentException('重组只有调整位置（move）、独立成项目（independent）、合并（merge）'),
        };
    }

    /** Structural evidence only: future undo must preserve later content and decisions. */
    private function snapshot(string $uid, array $ids): array {
        $out = [];
        foreach ($ids as $id) {
            $p = $this->projects->project($uid, $id);
            $q = $this->db->getQueryBuilder();
            $locations = $q->select('id', 'project_id', 'parent_id', 'revision')->from('cognos_locations')
                ->where($q->expr()->eq('project_id', $q->createNamedParameter($id)))
                ->orderBy('id')->executeQuery()->fetchAll();
            $decisions = array_map(static fn(array $d): array => [
                'id' => $d['id'], 'scope_location_ids' => $d['scope_location_ids'], 'status' => $d['status'],
            ], $this->decisions->forProject($uid, $id));
            $out[] = ['id' => $id, 'root_location_id' => $p['root_location_id'],
                'merged_into' => $p['merged_into'] ?? null, 'state' => $p['state'],
                'locations' => $locations, 'decisions' => $decisions];
        }
        return $out;
    }

    private function undoableChange(string $uid, string $changeId): array {
        $q = $this->db->getQueryBuilder();
        $change = $q->select('*')->from('cognos_restructures')
            ->where($q->expr()->eq('id', $q->createNamedParameter($changeId)))
            ->andWhere($q->expr()->eq('user_id', $q->createNamedParameter($uid)))->executeQuery()->fetch();
        if (!$change) { throw new \RuntimeException('重组记录不存在或不可访问', 404); }
        if (!in_array($change['action'], ['move', 'independent', 'merge'], true)) {
            throw new \RuntimeException('这条记录不能再次撤回；请按现状调整', 409);
        }
        $q = $this->db->getQueryBuilder();
        $reverted = $q->select('id')->from('cognos_restructures')
            ->where($q->expr()->eq('reverts_change_id', $q->createNamedParameter($changeId)))->executeQuery()->fetch();
        if ($reverted) { throw new \RuntimeException('这次调整已经撤回', 409); }
        return $change;
    }

    private function undoMoveState(string $uid, string $changeId): array {
        $change = $this->undoableChange($uid, $changeId);
        if ($change['action'] !== 'move') { throw new \RuntimeException('请使用重组撤回入口', 409); }
        $q = $this->db->getQueryBuilder();
        $h = $q->select('*')->from('cognos_location_history')
            ->where($q->expr()->eq('change_id', $q->createNamedParameter($changeId)))->executeQuery()->fetch();
        if (!$h || $h['from_parent_id'] === null) {
            throw new \RuntimeException('缺少原上级记录，不能自动还原', 409);
        }
        $loc = $this->projects->location($uid, $h['location_id']);
        $parent = $this->projects->location($uid, $h['from_parent_id']);
        if ($loc['project_id'] !== $h['to_project_id'] || $parent['project_id'] !== $loc['project_id']
            || $loc['parent_id'] !== $h['to_parent_id']) {
            throw new \RuntimeException('后来归属已经变化，请按现状重新预览调整', 409);
        }
        return ['history' => $h, 'location' => $loc, 'parent' => $parent];
    }

    private function planUndoMove(string $uid, string $changeId): array {
        $old = $this->undoMoveState($uid, $changeId);
        $plan = $this->planMove($uid, $old['location']['id'], $old['parent']['id']);
        array_unshift($plan['consequences'], '以一条新变更撤回这次调整；旧经过保留，不回滚后来成果。',
            '当前稿、讨论、采用和后来展开的位置保留；当前子树随位置一起移回。');
        return $plan;
    }

    private function planUndo(string $uid, string $changeId): array {
        $change = $this->undoableChange($uid, $changeId);
        if ($change['action'] === 'move') { return $this->planUndoMove($uid, $changeId); }
        $old = $this->undoCrossState($uid, $change);
        if ($change['action'] === 'independent') {
            // The current independent project (including new work) returns as a part.
            $plan = $this->planMerge($uid, $old['carrier']['id'], $old['parent']['id']);
            array_unshift($plan['consequences'], '撤回独立：以新变更把当前全部内容接回原上级，不回滚后来成果；独立项目身份保留为已归回。',
                '独立后新增的决定保留：整个独立项目的决定只管归回的这部分，不扩大到原项目其他位置。');
            return $plan;
        }
        return $this->planUndoMerge($uid, $old);
    }

    /** Cross-project undo requires the actual original scope evidence, never a guessed reconstruction. */
    private function undoCrossState(string $uid, array $change): array {
        $snapshot = json_decode((string)($change['undo_snapshot'] ?? ''), true);
        if (!is_array($snapshot) || !isset($snapshot['before'], $snapshot['after'])) {
            throw new \RuntimeException('旧重组缺少前后关系和决定范围记录，不能自动撤回；可以按现状预览独立或合并', 409);
        }
        $params = json_decode($change['params'], true, 512, JSON_THROW_ON_ERROR);
        if ($change['action'] === 'independent') {
            $rootId = $params['location_id'];
            $before = null; $originalLocation = null;
            foreach ($snapshot['before'] as $p) {
                foreach ($p['locations'] as $l) {
                    if ($l['id'] === $rootId) { $before = $p; $originalLocation = $l; }
                }
            }
            if (!$before || !$originalLocation || !$originalLocation['parent_id']) {
                throw new \RuntimeException('原上级记录不完整，不能自动撤回', 409);
            }
            $destination = $this->live($uid, $before['id']);
            $parent = $this->projects->location($uid, $originalLocation['parent_id']);
            $root = $this->projects->location($uid, $rootId);
            $carrier = $this->live($uid, $root['project_id']);
            $after = array_values(array_filter($snapshot['after'], static fn(array $p): bool => $p['id'] === $carrier['id']));
            if (!$after || $carrier['root_location_id'] !== $rootId || $root['parent_id'] !== null
                || $parent['project_id'] !== $destination['id'] || $carrier['id'] === $destination['id']) {
                throw new \RuntimeException('独立后的归属已变化，请按现状重新组织', 409);
            }
            $originalIds = array_column($after[0]['locations'], 'id');
        } else {
            $before = array_values(array_filter($snapshot['before'], static fn(array $p): bool => $p['id'] === $params['project_id']));
            if (!$before) { throw new \RuntimeException('缺少原项目记录，不能自动撤回', 409); }
            $before = $before[0]; $rootId = $before['root_location_id'];
            $destination = $this->projects->project($uid, $before['id']);
            $root = $this->projects->location($uid, $rootId);
            $carrier = $this->live($uid, $root['project_id']);
            $parent = null;
            if ($destination['merged_into'] !== $carrier['id'] || $destination['root_location_id'] !== $rootId
                || $root['parent_id'] !== $params['target_parent_id'] || $destination['locations'] !== []) {
                throw new \RuntimeException('合并后的归属已变化，请按现状重新组织', 409);
            }
            $originalIds = array_column($before['locations'], 'id');
        }
        $subtree = $this->subtree($carrier['locations'], $rootId);
        if (array_diff($originalIds, $subtree) !== []) {
            throw new \RuntimeException('原来的一部分后来已移到别处，不能替它决定归回；请先处理后续重组', 409);
        }
        return compact('change', 'snapshot', 'before', 'root', 'carrier', 'destination', 'parent', 'subtree');
    }

    private function planUndoMerge(string $uid, array $old): array {
        ['snapshot' => $snapshot, 'before' => $before, 'root' => $root,
            'carrier' => $carrier, 'destination' => $destination, 'subtree' => $subtree] = $old;
        $original = array_column($before['decisions'], null, 'id');
        $afterDecisions = [];
        foreach ($snapshot['after'] as $p) {
            foreach ($p['decisions'] as $d) { $afterDecisions[$d['id']] = $d; }
        }
        $current = array_column($this->decisions->forProject($uid, $carrier['id']), null, 'id');
        $moving = [];
        foreach ($original as $id => $d) {
            if (!isset($current[$id], $afterDecisions[$id])
                || $current[$id]['scope_location_ids'] !== $afterDecisions[$id]['scope_location_ids']) {
                throw new \RuntimeException('原决定的归属或范围后来已改变，不能用旧范围覆盖；请先处理决定范围', 409);
            }
            // Preserve revoked/superseded status and links; only reverse the structural narrowing.
            $moving[$id] = $d['scope_location_ids'];
        }
        foreach ($current as $id => $d) {
            if (array_key_exists($id, $moving) || $d['scope_location_ids'] === []) { continue; }
            $inside = array_intersect($d['scope_location_ids'], $subtree);
            if ($inside !== [] && array_diff($d['scope_location_ids'], $subtree) === []) {
                $moving[$id] = $d['scope_location_ids'];
            } elseif ($inside !== [] && $d['status'] === 'active') {
                throw new \RuntimeException('有生效决定同时管到归回部分和留在这里的部分，请先明确拆开它的范围', 409);
            }
        }
        $lines = [sprintf('撤回合并：当前「%s」及下面共 %d 处回到原项目「%s」，恢复原项目身份；当前稿、讨论、采用和新增子项不回滚。',
            $root['title'], count($subtree), $destination['title']),
            '原项目的决定恢复当时的适用范围，后来撤回或被替代的仍保持该状态；后来只管归回部分的决定跟着走。',
            '目标项目自己的全项目决定留在目标，不带走；跨两边的生效决定必须先明确范围。',
            '恢复项目状态：'.$this->stateLabel($destination['state']).'；原项目运行资格更新，合并前后的旧运行仍可交回结果，但直接改稿或采用需重新登记。'];
        if ($moving !== []) {
            $lines[] = '随当前部分归回、保留现有生效状态的决定：' . $this->quoteList(array_values(array_intersect_key($current, $moving))) . '。';
        }
        $restoredWide = array_values(array_filter($original, static fn(array $d): bool => $d['scope_location_ids'] === []));
        if ($restoredWide !== []) {
            $wideQuotes = array_map(static fn(array $d): array => $current[$d['id']], $restoredWide);
            $lines[] = '恢复为原项目全范围（不改变当前生效/撤回/替代状态）的决定：' . $this->quoteList($wideQuotes) . '。';
        }
        $stayingWide = array_values(array_filter($current, static fn(array $d): bool => $d['scope_location_ids'] === [] && !array_key_exists($d['id'], $moving)));
        if ($stayingWide !== []) { $lines[] = '留在目标项目、归回部分不再沿用：' . $this->quoteList($stayingWide) . '。'; }
        $lines = array_merge($lines, $this->workLines($uid, $subtree));
        $parents = array_column($carrier['locations'], 'parent_id', 'id');
        return ['consequences' => $lines, 'basis' => $this->basis($uid, [$carrier['id'], $destination['id']]),
            'project_id' => $destination['id'], 'location_id' => $root['id'],
            'do' => function (string $changeId, int $now) use ($uid, $carrier, $destination, $root, $subtree, $moving, $parents): void {
                foreach ($subtree as $id) {
                    $this->setLocation($id, ['project_id' => $destination['id']] + ($id === $root['id'] ? ['parent_id' => null] : []));
                    $this->history($id, $changeId, 'undo_merge', $carrier['id'], $destination['id'], $parents[$id],
                        $id === $root['id'] ? null : $parents[$id], $uid, $now);
                }
                foreach ($moving as $id => $scope) { $this->setDecisionProject($id, $destination['id'], $scope); }
                $this->moveRows($subtree, $destination['id']);
                $q = $this->db->getQueryBuilder();
                $q->update('cognos_projects')->set('merged_into', $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
                    ->set('merged_at', $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
                    ->where($q->expr()->eq('id', $q->createNamedParameter($destination['id'])))->executeStatement();
                $this->renewEpoch($destination['id']);
            }];
    }

    /** A returning project identity must not reactivate any old run's write permission. */
    private function renewEpoch(string $projectId): void {
        $q = $this->db->getQueryBuilder();
        $epoch = (int)$q->select('execution_epoch')->from('cognos_projects')
            ->where($q->expr()->eq('id', $q->createNamedParameter($projectId)))->executeQuery()->fetchOne();
        $q = $this->db->getQueryBuilder();
        $q->update('cognos_projects')->set('execution_epoch', $q->createNamedParameter($epoch + 1, IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('id', $q->createNamedParameter($projectId)))->executeStatement();
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
        $lines[] = '新项目继承原来的推进状态：' . $this->stateLabel($project['state']) . '；需要重新推进时，在新项目明确再开。旧运行的结果仍可交回，直接改稿或采用的资格不会跟过去。';
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
                    'root_location_id' => $loc['id'], 'created_at' => $now, 'merged_into' => null, 'merged_at' => null,
                    'state' => $project['state'], 'lifecycle_revision' => 1, 'execution_epoch' => 1]);
                $previous = $this->projects->lifecycleHistory($uid,$project['id']);
                $this->projects->insert('cognos_lifecycle_history',['project_id'=>$newId,'revision'=>1,'from_state'=>$project['state'],
                    'to_state'=>$project['state'],'actor'=>$uid,'note'=>'系统记录：独立时继承原项目「'.$project['title'].'」的推进状态；这不是新的用户决定。',
                    'reopen_when'=>(string)($previous[0]['reopen_when'] ?? ''),'created_at'=>$now]);
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
        $lines[] = '原项目状态：'.$this->stateLabel($source['state']).'；合并后这些位置遵循目标项目状态：'.$this->stateLabel($target['state']).'。原运行仍可交回结果，直接改稿或采用的资格不会迁移；需要继续工作时重新登记或委托。';
        $wide = array_values(array_filter($this->decisions->forProject($uid, $source['id']), static fn(array $d): bool => $d['status'] === 'active' && $d['scope_location_ids'] === []));
        if ($wide !== []) { $lines[] = '原项目整个项目的决定，合并后只管原来那部分：' . $this->quoteList($wide) . '。'; }
        $lines[] = '接收项目的运行资格更新，旧运行不因位置回来而恢复权限；目标项目现有运行仍可交回结果，继续直接改稿或采用需重新登记。';
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
                $this->renewEpoch($target['id']);
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
            $state[] = [$pid, $p['merged_into'] ?? null, $p['state'], (int)$p['execution_epoch'], $locs, $decs];
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
        $ids = array_values(array_unique(array_column($rows, 'change_id')));
        $can = []; $reverts = []; $undoActions = [];
        if ($ids !== []) {
            $q = $this->db->getQueryBuilder();
            $changes = $q->select('id', 'action', 'reverts_change_id', 'undo_snapshot')->from('cognos_restructures')
                ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
                ->andWhere($q->expr()->orX(
                    $q->expr()->in('id', $q->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)),
                    $q->expr()->in('reverts_change_id', $q->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY))))
                ->executeQuery()->fetchAll();
            $reverted = array_filter(array_column($changes, 'reverts_change_id'));
            foreach ($changes as $c) {
                $can[$c['id']] = $c['action'] === 'move' && !in_array($c['id'], $reverted, true);
                $reverts[$c['id']] = $c['reverts_change_id'];
                $undoActions[$c['id']] = !in_array($c['id'], $reverted, true) &&
                    ($c['action'] === 'move' || (in_array($c['action'], ['independent', 'merge'], true) && $c['undo_snapshot'])) ? 'undo' : null;
            }
        }
        return array_map(static fn(array $r): array => [
            'can_undo_move' => $can[$r['change_id']] ?? false,
            'undo_action' => $undoActions[$r['change_id']] ?? null,
            'reverts_change_id' => $reverts[$r['change_id']] ?? null,
            'location_id' => (string)$r['location_id'], 'location_title' => $locationTitle((string)$r['location_id']),
            'action' => (string)$r['action'], 'change_id' => (string)$r['change_id'],
            'from_project_id' => (string)$r['from_project_id'], 'from_project_title' => $projectTitle((string)$r['from_project_id']),
            'to_project_id' => (string)$r['to_project_id'], 'to_project_title' => $projectTitle((string)$r['to_project_id']),
            'from_parent_title' => $locationTitle($r['from_parent_id']), 'to_parent_title' => $locationTitle($r['to_parent_id']),
            'changed_by' => (string)$r['changed_by'], 'changed_at' => (int)$r['changed_at'],
        ], $rows);
    }
}
