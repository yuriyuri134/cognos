<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * 采用和纠正。
 *
 * - 采用：某个位置采用一样东西的具体一版：一段回答（message，原话不变所以没有版本），或某个位置当前内容的第几版
 *   （location_content）。一处一条；在甲处撤回只改甲处，用到同一样东西的别处只得到提示。
 * - 纠正：指向具体一样东西的具体一版，留下纠正的原话、谁、依据。明确依赖这一版的（采用了它的位置、交回时报了它作依据的运行）
 *   进待复核；只是相关的（采用了别的版本、在别处用作材料、它所在的讨论或当前内容）只提示。同一原因对同一目标只记一条。
 * - 面对：待复核的采用可以「仍然采用」（写回应）、撤回，或改采用别的一版；提示可以标「知道了」。
 *   纠正、回应、撤回、被替代都留着，旧的观点和反驳可读。
 * - AI 采用：交出去的事写明允许在那一处采用时，正在做这件事的 AI 可以在那一处采用，采用者记它、authority 记那次委托、
 *   source_message_id 记用户交出去时那句话；不在委托范围内（别的位置、没给采用的权限、不是交出去的事）就只记成它的提议，
 *   等用户接受（采用者变成用户，提出者留着）或不采用。
 * - 纠正多传一层：第 1 层是直接依赖那一版的（采用它的位置、报了它作依据的运行），进待复核；第 2 层是采用了它的位置上，
 *   在采用期间交回的结果、写的当前稿，也进待复核；第 3 层（别处采用了那些当前稿版本的、报了它们作依据的运行）只提示，再往下不追。
 *
 * 设计见 docs/项目管理/Cognos项目设计/02-协作/判断采用与纠正.md。
 */
final class AdoptionService {
    private const TYPES = ['message', 'location_content'];

    public function __construct(
        private IDBConnection $db,
        private ProjectService $projects,
        private CreationSpaceService $creations,
    ) {
    }

    /** 在这个位置采用一样东西的一版；$supersedes 是这里原来采用的那条（改采用别的一版）。 */
    public function adopt(string $uid, string $locationId, string $type, string $objectId, ?string $version, string $basis,
        ?string $supersedes, string $requestId): array {
        $basis = trim($basis);
        if (mb_strlen($basis) > 2000) {
            throw new \InvalidArgumentException('依据最多 2000 字');
        }
        return $this->projects->commit($uid, $requestId, ['adopt', $locationId, $type, $objectId, $version, $basis, $supersedes],
            function () use ($uid, $locationId, $type, $objectId, $version, $basis, $supersedes): array {
                $loc = $this->projects->location($uid, $locationId);
                [$objectId, $version] = $this->resolve($uid, $type, $objectId, $version, $loc);
                foreach ($this->rows(['location_id' => $locationId]) as $a) {
                    if (in_array($a['state'], ['active', 'review'], true) && $a['object_type'] === $type && $a['object_id'] === $objectId
                        && $a['object_version'] === $version && $a['id'] !== $supersedes) {
                        throw new \RuntimeException('这里已经采用了这一版', 409);
                    }
                }
                $now = time();
                $id = bin2hex(random_bytes(16));
                if ($supersedes !== null) {
                    $old = $this->get($supersedes);
                    if ($old === null || $old['location_id'] !== $locationId || !in_array($old['state'], ['active', 'review'], true)) {
                        throw new \RuntimeException('要替代的采用不在这里或已经不在生效了', 409);
                    }
                    $this->end($supersedes, 'superseded', $uid, $now, ['superseded_by' => $id]);
                    $this->resolveTargeting('adoption', $supersedes, '改采用了别的一版', $now);
                }
                $this->projects->insert('cognos_adoptions', [
                    'id' => $id, 'project_id' => $loc['project_id'], 'location_id' => $locationId, 'object_type' => $type,
                    'object_id' => $objectId, 'object_version' => $version, 'adopted_by' => $uid,
                    'basis' => $basis === '' ? null : $basis, 'state' => 'active', 'supersedes' => $supersedes,
                    'superseded_by' => null, 'response' => null, 'ended_at' => null, 'ended_by' => null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                return ['adoption_id' => $id, 'location_id' => $locationId];
            });
    }

    /** 撤回这里的采用：只改这一处；用到同一样东西的别处得到提示。 */
    public function withdraw(string $uid, string $adoptionId, string $requestId): array {
        return $this->projects->commit($uid, $requestId, ['withdraw_adoption', $adoptionId], function () use ($uid, $adoptionId): array {
            $a = $this->own($uid, $adoptionId);
            if (!in_array($a['state'], ['active', 'review'], true)) {
                throw new \RuntimeException('这条采用已经不在生效了', 409);
            }
            $now = time();
            $this->end($adoptionId, 'withdrawn', $uid, $now, []);
            $this->resolveTargeting('adoption', $adoptionId, '撤回了采用', $now);
            $where = $this->titleOf($uid, $a['location_id']);
            foreach ($this->sameObject($a['object_type'], $a['object_id']) as $other) {
                if ($other['id'] !== $adoptionId && $other['location_id'] !== $a['location_id'] && in_array($other['state'], ['active', 'review'], true)) {
                    $this->impact('withdrawal', $adoptionId, 'adoption', $other['id'], $other['location_id'], 'hint',
                        '「' . $where . '」撤回了对同一样东西的采用；这里的采用没有变', $now);
                }
            }
            return ['adoption_id' => $adoptionId, 'state' => 'withdrawn'];
        });
    }

    /** 被纠正后仍然采用：写下回应，回到生效；纠正和回应都留着。 */
    public function reaffirm(string $uid, string $adoptionId, string $response, string $requestId): array {
        $response = trim($response);
        if ($response === '' || mb_strlen($response) > 2000) {
            throw new \InvalidArgumentException('写一句为什么仍然采用（最多 2000 字）');
        }
        return $this->projects->commit($uid, $requestId, ['reaffirm_adoption', $adoptionId, $response], function () use ($uid, $adoptionId, $response): array {
            $a = $this->own($uid, $adoptionId);
            if ($a['state'] !== 'review') {
                throw new \RuntimeException('这条采用不在待复核', 409);
            }
            $now = time();
            $qb = $this->db->getQueryBuilder();
            $qb->update('cognos_adoptions')->set('state', $qb->createNamedParameter('active'))
                ->set('response', $qb->createNamedParameter($response))
                ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($adoptionId)))->executeStatement();
            $this->resolveTargeting('adoption', $adoptionId, '仍然采用：' . $response, $now);
            return ['adoption_id' => $adoptionId, 'state' => 'active'];
        });
    }

    /**
     * 纠正一样东西的具体一版。明确依赖它的进待复核，只是相关的只提示。
     *
     * @return array{correction_id: string, review: int, hint: int}
     */
    public function correct(string $uid, string $type, string $objectId, ?string $version, string $content, string $basis, string $requestId): array {
        $content = trim($content);
        $basis = trim($basis);
        if ($content === '' || mb_strlen($content) > 4000 || mb_strlen($basis) > 2000) {
            throw new \InvalidArgumentException('写下纠正（最多 4000 字），依据最多 2000 字');
        }
        return $this->projects->commit($uid, $requestId, ['correct', $type, $objectId, $version, $content, $basis],
            function () use ($uid, $type, $objectId, $version, $content, $basis): array {
                [$objectId, $version, $home] = $this->resolveAnywhere($uid, $type, $objectId, $version);
                $now = time();
                $id = bin2hex(random_bytes(16));
                $this->projects->insert('cognos_corrections', [
                    'id' => $id, 'user_id' => $uid, 'object_type' => $type, 'object_id' => $objectId, 'object_version' => $version,
                    'content' => $content, 'basis' => $basis === '' ? null : $basis, 'author' => $uid, 'created_at' => $now,
                ]);
                $what = $this->describe($uid, $type, $objectId, $version);
                $review = 0; $hint = 0; $reviewed = []; $direct = [];
                // 明确依赖：采用了这一版的位置
                foreach ($this->sameObject($type, $objectId) as $a) {
                    if (!in_array($a['state'], ['active', 'review'], true)) { continue; }
                    if ($a['object_version'] === $version) {
                        $direct[] = $a;
                        $this->setState($a['id'], 'review', $now);
                        $review += $this->impact('correction', $id, 'adoption', $a['id'], $a['location_id'], 'review', '这里采用的' . $what . '被纠正了', $now);
                        $reviewed[] = $a['location_id'];
                    } else {
                        $hint += $this->impact('correction', $id, 'adoption', $a['id'], $a['location_id'], 'hint',
                            $what . '被纠正了；这里采用的是另一版，没有变', $now);
                    }
                }
                // 明确依赖：交回时报了它作依据的运行
                $qb = $this->db->getQueryBuilder();
                $qb->select('d.run_id', 'r.space_type', 'r.space_id', 'r.subject')->from('cognos_run_dependencies', 'd')
                    ->innerJoin('d', 'cognos_runs', 'r', $qb->expr()->eq('r.id', 'd.run_id'))
                    ->where($qb->expr()->eq('d.object_type', $qb->createNamedParameter($type)))
                    ->andWhere($qb->expr()->eq('d.object_id', $qb->createNamedParameter($objectId)));
                $qb->andWhere($version === null ? $qb->expr()->isNull('d.version') : $qb->expr()->eq('d.version', $qb->createNamedParameter($version)));
                foreach ($qb->executeQuery()->fetchAll() as $dep) {
                    $loc = $dep['space_type'] === 'creation' ? $this->projects->locationForCreation($uid, (int)$dep['space_id']) : null;
                    if ($loc !== null) {
                        $review += $this->impact('correction', $id, 'run', (string)$dep['run_id'], $loc['id'], 'review',
                            $dep['subject'] . ' 交回的结果报了依据' . $what . '，它被纠正了', $now);
                        $reviewed[] = $loc['id'];
                    }
                }
                // 只是相关：它所在的那一处，和把它所在的会话用作材料的别处
                if ($home !== null && !in_array($home, $reviewed, true)) {
                    $hint += $this->impact('correction', $id, $type === 'message' ? 'discussion' : 'content', $objectId . ':' . ($version ?? ''),
                        $home, 'hint', $what . '被纠正了', $now);
                }
                if ($type === 'message') {
                    $creationId = (int)$this->message($objectId)['creation_id'];
                    $qb = $this->db->getQueryBuilder();
                    $usages = $qb->select('id', 'location_id')->from('cognos_usages')
                        ->where($qb->expr()->eq('source_creation_id', $qb->createNamedParameter($creationId, IQueryBuilder::PARAM_INT)))
                        ->executeQuery()->fetchAll();
                    foreach ($usages as $u) {
                        if (!in_array($u['location_id'], $reviewed, true)) {
                            $hint += $this->impact('correction', $id, 'usage', (string)$u['id'], (string)$u['location_id'], 'hint',
                                '这里用作材料的会话里，' . $what . '被纠正了；这里没有采用它', $now);
                        }
                    }
                }
                [$review2, $hint3] = $this->further($uid, $id, $direct, $what, $now);
                return ['correction_id' => $id, 'review' => $review, 'hint' => $hint, 'review_depth2' => $review2, 'hint_depth3' => $hint3];
            });
    }

    /**
     * 第 2 层：采用了被纠正那一版的位置上，在采用期间交回的结果、写下的当前稿，进待复核。
     * 第 3 层：别处采用了那些当前稿版本的、报了它们作依据的运行，只提示。再往下不追。
     *
     * @return array{0: int, 1: int}
     */
    private function further(string $uid, string $correctionId, array $direct, string $what, int $now): array {
        $review = 0; $hint = 0;
        foreach ($direct as $a) {
            $loc = $this->projects->location($uid, $a['location_id']);
            $since = (int)$a['created_at'];
            $qb = $this->db->getQueryBuilder();
            $runs = $qb->select('id', 'subject', 'created_at')->from('cognos_runs')
                ->where($qb->expr()->eq('space_type', $qb->createNamedParameter('creation')))
                ->andWhere($qb->expr()->eq('space_id', $qb->createNamedParameter((string)$loc['creation_id'])))
                ->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('delivered')))
                ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
                ->executeQuery()->fetchAll();
            foreach ($runs as $r) {
                $review += $this->impact('correction', $correctionId, 'run', (string)$r['id'], $loc['id'], 'review',
                    '第 2 层：' . $r['subject'] . ' 这次交回的结果，是在这里采用' . $what . '期间做的', $now, 2);
            }
            $qb = $this->db->getQueryBuilder();
            $later = array_map('intval', array_column($qb->select('revision')->from('cognos_location_revisions')
                ->where($qb->expr()->eq('location_id', $qb->createNamedParameter($loc['id'])))
                ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
                ->executeQuery()->fetchAll(), 'revision'));
            if ($later === []) { continue; }
            $review += $this->impact('correction', $correctionId, 'content', $loc['id'] . ':' . max($later), $loc['id'], 'review',
                sprintf('第 2 层：这里当前稿的版本 %s 是在采用%s之后写的', implode('、', $later), $what), $now, 2);
            // 第 3 层：只提示
            foreach ($this->sameObject('location_content', $loc['id']) as $b) {
                if (in_array((int)$b['object_version'], $later, true) && in_array($b['state'], ['active', 'review'], true) && $b['location_id'] !== $loc['id']) {
                    $hint += $this->impact('correction', $correctionId, 'adoption', $b['id'], $b['location_id'], 'hint',
                        sprintf('第 3 层（只提示）：这里采用的「%s」当前稿版本 %s，是那一处采用%s之后写的', $loc['title'], $b['object_version'], $what), $now, 3);
                }
            }
            $qb = $this->db->getQueryBuilder();
            $deps = $qb->select('d.run_id', 'd.version', 'r.space_id', 'r.subject')->from('cognos_run_dependencies', 'd')
                ->innerJoin('d', 'cognos_runs', 'r', $qb->expr()->eq('r.id', 'd.run_id'))
                ->where($qb->expr()->eq('d.object_type', $qb->createNamedParameter('location_content')))
                ->andWhere($qb->expr()->eq('d.object_id', $qb->createNamedParameter($loc['id'])))->executeQuery()->fetchAll();
            foreach ($deps as $d) {
                $where = $this->projects->locationForCreation($uid, (int)$d['space_id']);
                if ($where !== null && in_array((int)$d['version'], $later, true)) {
                    $hint += $this->impact('correction', $correctionId, 'run', (string)$d['run_id'], $where['id'], 'hint',
                        sprintf('第 3 层（只提示）：%s 报了依据「%s」当前稿版本 %s，那一版是采用%s之后写的', $d['subject'], $loc['title'], $d['version'], $what), $now, 3);
                }
            }
        }
        return [$review, $hint];
    }

    /**
     * 正在做事的 AI 在某一处采用。它正在做的是交给它的、写明允许在这一处采用的事，就直接采用（采用者记它，authority 记那次委托，
     * 原话记用户交出去时那句）；否则只记成它的提议，等用户定。
     *
     * @param ?array $delegation 这次运行挂着的委托（DelegationService::forRun），没有就是 null
     */
    public function agentAdopt(string $uid, string $locationId, array $run, ?array $delegation, string $type, string $objectId,
        ?string $version, string $basis): array {
        $basis = mb_substr(trim($basis), 0, 2000);
        $requestId = 'agt-' . substr(hash('sha256', json_encode([$run['id'], $locationId, $type, $objectId, $version], JSON_THROW_ON_ERROR)), 0, 60);
        return $this->projects->commit($uid, $requestId, ['agent_adopt', $run['id'], $locationId, $type, $objectId, $version],
            function () use ($uid, $locationId, $run, $delegation, $type, $objectId, $version, $basis): array {
                $loc = $this->projects->location($uid, $locationId);
                [$objectId, $version] = $this->resolve($uid, $type, $objectId, $version, $loc);
                foreach ($this->rows(['location_id' => $locationId]) as $a) {
                    if (in_array($a['state'], ['active', 'review', 'proposed'], true) && $a['object_type'] === $type
                        && $a['object_id'] === $objectId && $a['object_version'] === $version) {
                        throw new \RuntimeException('这里已经采用或提议过这一版', 409);
                    }
                }
                $allowed = $delegation !== null && (int)$delegation['may_adopt'] === 1 && $delegation['location_id'] === $locationId;
                $now = time();
                $id = bin2hex(random_bytes(16));
                $this->projects->insert('cognos_adoptions', [
                    'id' => $id, 'project_id' => $loc['project_id'], 'location_id' => $locationId, 'object_type' => $type,
                    'object_id' => $objectId, 'object_version' => $version, 'adopted_by' => $run['subject'],
                    'basis' => $basis === '' ? null : $basis, 'state' => $allowed ? 'active' : 'proposed', 'supersedes' => null,
                    'superseded_by' => null, 'response' => null, 'ended_at' => null, 'ended_by' => null, 'created_at' => $now, 'updated_at' => $now,
                    'authority' => $allowed ? 'delegation:' . $delegation['id'] : null, 'proposed_by' => $allowed ? null : $run['subject'],
                    'source_message_id' => $allowed ? (int)$delegation['message_id'] : null,
                ]);
                return ['adoption_id' => $id, 'state' => $allowed ? 'active' : 'proposed'];
            });
    }

    /** 用户接受 AI 的提议：采用者变成用户，提出者留着。 */
    public function acceptProposal(string $uid, string $adoptionId, string $requestId): array {
        return $this->projects->commit($uid, $requestId, ['accept_proposal', $adoptionId], function () use ($uid, $adoptionId): array {
            $a = $this->own($uid, $adoptionId);
            if ($a['state'] !== 'proposed') { throw new \RuntimeException('这条不是待定的提议', 409); }
            $qb = $this->db->getQueryBuilder();
            $qb->update('cognos_adoptions')->set('state', $qb->createNamedParameter('active'))
                ->set('adopted_by', $qb->createNamedParameter($uid))
                ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($adoptionId)))->executeStatement();
            return ['adoption_id' => $adoptionId, 'state' => 'active'];
        });
    }

    /** 用户不采用 AI 的提议：提议留着，标为不采用。 */
    public function declineProposal(string $uid, string $adoptionId, string $requestId): array {
        return $this->projects->commit($uid, $requestId, ['decline_proposal', $adoptionId], function () use ($uid, $adoptionId): array {
            $a = $this->own($uid, $adoptionId);
            if ($a['state'] !== 'proposed') { throw new \RuntimeException('这条不是待定的提议', 409); }
            $qb = $this->db->getQueryBuilder();
            $qb->update('cognos_adoptions')->set('state', $qb->createNamedParameter('declined'))
                ->set('ended_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
                ->set('ended_by', $qb->createNamedParameter($uid))
                ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($adoptionId)))->executeStatement();
            return ['adoption_id' => $adoptionId, 'state' => 'declined'];
        });
    }

    /** 提示标「知道了」，或运行结果的待复核写下怎么处理了。 */
    public function resolveImpact(string $uid, string $impactId, string $resolution, string $requestId): array {
        $resolution = trim($resolution) === '' ? '知道了' : mb_substr(trim($resolution), 0, 2000);
        return $this->projects->commit($uid, $requestId, ['resolve_impact', $impactId, $resolution], function () use ($uid, $impactId, $resolution): array {
            $qb = $this->db->getQueryBuilder();
            $i = $qb->select('*')->from('cognos_impacts')->where($qb->expr()->eq('id', $qb->createNamedParameter($impactId)))->executeQuery()->fetch();
            if ($i === false) { throw new \RuntimeException('不存在或不可访问', 404); }
            $this->projects->location($uid, (string)$i['location_id']);
            if ($i['target_kind'] === 'adoption' && $i['effect'] === 'review') {
                throw new \RuntimeException('待复核的采用要在采用上处理：仍然采用、撤回或改采用别的一版', 409);
            }
            if ($i['resolved_at'] !== null) { throw new \RuntimeException('已经处理过了', 409); }
            $qb = $this->db->getQueryBuilder();
            $qb->update('cognos_impacts')->set('resolved_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
                ->set('resolution', $qb->createNamedParameter($resolution))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($impactId)))->executeStatement();
            return ['impact_id' => $impactId];
        });
    }

    /** 这个位置的采用（含已撤回、被替代的）、每样东西的纠正经过，和还没处理的影响。 */
    public function forLocation(string $uid, string $locationId): array {
        $loc = $this->projects->location($uid, $locationId);
        $adoptions = [];
        foreach ($this->rows(['location_id' => $locationId]) as $a) {
            $a['preview'] = $this->preview($uid, $a['object_type'], $a['object_id'], $a['object_version']);
            $a['corrections'] = $this->correctionsOf($a['object_type'], $a['object_id']);
            $adoptions[] = $a;
        }
        usort($adoptions, static fn(array $x, array $y): int => [!in_array($x['state'], ['review', 'active'], true), $x['state'] !== 'review', -$x['created_at']]
            <=> [!in_array($y['state'], ['review', 'active'], true), $y['state'] !== 'review', -$y['created_at']]);
        $qb = $this->db->getQueryBuilder();
        $impacts = $qb->select('*')->from('cognos_impacts')
            ->where($qb->expr()->eq('location_id', $qb->createNamedParameter($locationId)))
            ->andWhere($qb->expr()->isNull('resolved_at'))
            ->orderBy('created_at', 'DESC')->executeQuery()->fetchAll();
        $impacts = array_map(function (array $i): array {
            $cause = $i['cause_type'] === 'correction' ? $this->correction((string)$i['cause_id']) : null;
            return ['id' => (string)$i['id'], 'effect' => (string)$i['effect'], 'target_kind' => (string)$i['target_kind'],
                'target_id' => (string)$i['target_id'], 'note' => $i['note'], 'cause_type' => (string)$i['cause_type'],
                'depth' => (int)($i['depth'] ?? 1),
                'correction' => $cause, 'created_at' => (int)$i['created_at']];
        }, $impacts);
        return ['adoptions' => $adoptions, 'impacts' => $impacts, 'revision' => $loc['revision']];
    }

    /** @return array<string, int> 每个位置还没处理的待复核件数 */
    public function reviewCounts(string $projectId): array {
        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('i.location_id')->from('cognos_impacts', 'i')
            ->innerJoin('i', 'cognos_locations', 'l', $qb->expr()->eq('l.id', 'i.location_id'))
            ->where($qb->expr()->eq('l.project_id', $qb->createNamedParameter($projectId)))
            ->andWhere($qb->expr()->isNull('i.resolved_at'))
            ->andWhere($qb->expr()->eq('i.effect', $qb->createNamedParameter('review')))
            ->executeQuery()->fetchAll();
        return array_count_values(array_map('strval', array_column($rows, 'location_id')));
    }

    /** 采用前核对：东西存在、在这个位置可用（这里的讨论或这里用作材料的会话里的话；同一项目里某处当前内容的某一版）。 */
    private function resolve(string $uid, string $type, string $objectId, ?string $version, array $loc): array {
        if ($type === 'message') {
            $m = $this->message($objectId);
            $allowed = array_merge([$loc['creation_id']], array_column($this->projects->usages($uid, $loc['id']), 'source_creation_id'));
            if ($m === null || !in_array((int)$m['creation_id'], array_map('intval', $allowed), true) || $this->creations->get((int)$m['creation_id'], $uid) === null) {
                throw new \InvalidArgumentException('这句话不在这里的讨论或这里用作材料的会话里');
            }
            return [(string)(int)$objectId, null];
        }
        if ($type === 'location_content') {
            $target = $this->projects->location($uid, $objectId);
            if ($target['project_id'] !== $loc['project_id']) {
                throw new \InvalidArgumentException('只能采用同一项目里某处当前内容的某一版');
            }
            $v = (int)$version;
            if ($v < 1 || $v > $target['revision']) {
                throw new \InvalidArgumentException(sprintf('那一处的当前内容只有版本 1 到 %d', $target['revision']));
            }
            return [$objectId, (string)$v];
        }
        throw new \InvalidArgumentException('现在能采用的是一段回答（message）或某处当前内容的一版（location_content）');
    }

    /** 纠正前核对：东西存在且可访问；返回它所在的位置（如果在某个项目位置里）。 */
    private function resolveAnywhere(string $uid, string $type, string $objectId, ?string $version): array {
        if ($type === 'message') {
            $m = $this->message($objectId);
            if ($m === null || $this->creations->get((int)$m['creation_id'], $uid) === null) {
                throw new \RuntimeException('那句话不存在或不可访问', 404);
            }
            $home = $this->projects->locationForCreation($uid, (int)$m['creation_id']);
            return [(string)(int)$objectId, null, $home['id'] ?? null];
        }
        if ($type === 'location_content') {
            $target = $this->projects->location($uid, $objectId);
            $v = (int)$version;
            if ($v < 1 || $v > $target['revision']) {
                throw new \InvalidArgumentException(sprintf('那一处的当前内容只有版本 1 到 %d', $target['revision']));
            }
            return [$objectId, (string)$v, $objectId];
        }
        throw new \InvalidArgumentException('现在能纠正的是一段回答（message）或某处当前内容的一版（location_content）');
    }

    private function preview(string $uid, string $type, string $objectId, ?string $version): array {
        if ($type === 'message') {
            $m = $this->message($objectId);
            $creation = $m === null ? null : $this->creations->get((int)$m['creation_id'], $uid);
            return $creation === null ? ['text' => '（不可访问）'] : ['text' => mb_substr((string)$m['content'], 0, 1200),
                'role' => (string)$m['role'], 'creation_id' => (int)$m['creation_id'], 'creation_title' => $creation['title'],
                'created_at' => (int)$m['created_at']];
        }
        $loc = $this->projects->location($uid, $objectId);
        $rev = $this->projects->revision($uid, $objectId, (int)$version);
        return ['text' => mb_substr((string)$rev['content'], 0, 1200), 'location_title' => $loc['title'], 'editor' => $rev['editor'],
            'current_revision' => $loc['revision'], 'created_at' => $rev['created_at']];
    }

    private function describe(string $uid, string $type, string $objectId, ?string $version): string {
        if ($type === 'message') {
            $m = $this->message($objectId);
            return '对话里的一句（' . ($m['role'] === 'user' ? '你说的' : 'AI 的回答') . '「' . mb_substr((string)$m['content'], 0, 30) . '…」）';
        }
        return '「' . $this->titleOf($uid, $objectId) . '」当前内容的版本 ' . $version;
    }

    private function titleOf(string $uid, string $locationId): string {
        return (string)$this->projects->location($uid, $locationId)['title'];
    }

    private function correctionsOf(string $type, string $objectId): array {
        $qb = $this->db->getQueryBuilder();
        return array_map(static fn(array $c): array => ['id' => (string)$c['id'], 'version' => $c['object_version'], 'content' => (string)$c['content'],
            'basis' => $c['basis'], 'author' => (string)$c['author'], 'created_at' => (int)$c['created_at']],
            $qb->select('*')->from('cognos_corrections')
                ->where($qb->expr()->eq('object_type', $qb->createNamedParameter($type)))
                ->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($objectId)))
                ->orderBy('created_at', 'ASC')->executeQuery()->fetchAll());
    }

    private function correction(string $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $c = $qb->select('*')->from('cognos_corrections')->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeQuery()->fetch();
        return $c === false ? null : ['id' => (string)$c['id'], 'object_type' => (string)$c['object_type'], 'object_id' => (string)$c['object_id'],
            'version' => $c['object_version'], 'content' => (string)$c['content'], 'basis' => $c['basis'], 'author' => (string)$c['author'],
            'created_at' => (int)$c['created_at']];
    }

    private function message(string $id): ?array {
        if (!ctype_digit($id)) { return null; }
        $qb = $this->db->getQueryBuilder();
        $m = $qb->select('id', 'creation_id', 'role', 'content', 'created_at')->from('cognos_creation_messages')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))->executeQuery()->fetch();
        return $m === false ? null : $m;
    }

    /** @return 1 新记了一条，0 同一原因对同一目标已经记过 */
    private function impact(string $causeType, string $causeId, string $kind, string $targetId, string $locationId, string $effect, string $note, int $now, int $depth = 1): int {
        $qb = $this->db->getQueryBuilder();
        $have = $qb->select('id')->from('cognos_impacts')
            ->where($qb->expr()->eq('cause_type', $qb->createNamedParameter($causeType)))
            ->andWhere($qb->expr()->eq('cause_id', $qb->createNamedParameter($causeId)))
            ->andWhere($qb->expr()->eq('target_kind', $qb->createNamedParameter($kind)))
            ->andWhere($qb->expr()->eq('target_id', $qb->createNamedParameter($targetId)))->executeQuery()->fetch();
        if ($have !== false) { return 0; }
        $this->projects->insert('cognos_impacts', ['id' => bin2hex(random_bytes(16)), 'cause_type' => $causeType, 'cause_id' => $causeId,
            'target_kind' => $kind, 'target_id' => $targetId, 'location_id' => $locationId, 'effect' => $effect, 'note' => $note,
            'resolved_at' => null, 'resolution' => null, 'created_at' => $now, 'depth' => $depth]);
        return 1;
    }

    private function resolveTargeting(string $kind, string $targetId, string $resolution, int $now): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('cognos_impacts')->set('resolved_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('resolution', $qb->createNamedParameter($resolution))
            ->where($qb->expr()->eq('target_kind', $qb->createNamedParameter($kind)))
            ->andWhere($qb->expr()->eq('target_id', $qb->createNamedParameter($targetId)))
            ->andWhere($qb->expr()->isNull('resolved_at'))->executeStatement();
    }

    private function sameObject(string $type, string $objectId): array {
        return $this->rows(['object_type' => $type, 'object_id' => $objectId]);
    }

    private function rows(array $where): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('cognos_adoptions');
        foreach ($where as $k => $v) { $qb->andWhere($qb->expr()->eq($k, $qb->createNamedParameter($v))); }
        return array_map(static fn(array $r): array => [
            'id' => (string)$r['id'], 'project_id' => (string)$r['project_id'], 'location_id' => (string)$r['location_id'],
            'object_type' => (string)$r['object_type'], 'object_id' => (string)$r['object_id'], 'object_version' => $r['object_version'],
            'adopted_by' => (string)$r['adopted_by'], 'basis' => $r['basis'], 'state' => (string)$r['state'],
            'supersedes' => $r['supersedes'], 'superseded_by' => $r['superseded_by'], 'response' => $r['response'],
            'authority' => $r['authority'] ?? null, 'proposed_by' => $r['proposed_by'] ?? null,
            'source_message_id' => isset($r['source_message_id']) ? (int)$r['source_message_id'] : null,
            'ended_at' => $r['ended_at'] === null ? null : (int)$r['ended_at'], 'created_at' => (int)$r['created_at'],
        ], $qb->orderBy('created_at', 'DESC')->executeQuery()->fetchAll());
    }

    private function get(string $id): ?array {
        $rows = $this->rows(['id' => $id]);
        return $rows[0] ?? null;
    }

    private function own(string $uid, string $id): array {
        $a = $this->get($id);
        if ($a === null) { throw new \RuntimeException('采用不存在或不可访问', 404); }
        $this->projects->location($uid, $a['location_id']);
        return $a;
    }

    private function setState(string $id, string $state, int $now): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('cognos_adoptions')->set('state', $qb->createNamedParameter($state))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
    }

    private function end(string $id, string $state, string $uid, int $now, array $extra): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('cognos_adoptions')->set('state', $qb->createNamedParameter($state))
            ->set('ended_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('ended_by', $qb->createNamedParameter($uid))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
        foreach ($extra as $k => $v) { $qb->set($k, $qb->createNamedParameter($v)); }
        $n = $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->in('state', $qb->createNamedParameter(['active', 'review'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->executeStatement();
        if ($n !== 1) { throw new \RuntimeException('这条采用刚刚被别处改过，请刷新再看', 409); }
    }
}
