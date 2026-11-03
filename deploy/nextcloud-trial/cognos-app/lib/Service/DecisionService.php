<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * 在生效的决定：用户本人说过、对这个项目（或列出的位置及其下面）的全部参与者都有效的话。
 *
 * - 只存用户自己的原话。原作者永远是项目的主人；登记者可以是用户自己，也可以是代录的 AI（分开记）。
 *   AI 代录必须指向对话里用户的那一句，原话要能在那一句里原样找到；撤回和替代只有用户本人能做。
 * - 不先造一条想法再假装采用：决定就是决定，有自己的生效、撤回、替代记录，旧的保留可查。
 * - 适用范围：空 = 整个项目；否则是列出的位置，连同它们下面展开的位置。
 * - 一条决定走到每个参与者哪一步，只说有证据的：运行起步时入口里给了它（进了输入），
 *   交回时自己列出依据了它（depends_on）。没有这些就是「尚无证据」，不推定已经知道或理解。
 *
 * 设计见 docs/项目管理/Cognos项目设计/02-协作/判断采用与纠正.md「在生效的决定」、共同工作怎样发生.md 六。
 */
final class DecisionService {
    private const MAX_CONTENT = 4000;

    public function __construct(
        private IDBConnection $db,
        private ProjectService $projects,
        private CreationSpaceService $creations,
        private IUserManager $users,
    ) {
    }

    /** 项目里的全部决定（生效的在前），带适用范围的名字和替代关系。 */
    public function forProject(string $uid, string $projectId): array {
        $project = $this->projects->project($uid, $projectId);
        $titles = array_column($project['locations'], 'title', 'id');
        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('*')->from('cognos_decisions')
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
            ->orderBy('created_at', 'DESC')->addOrderBy('id', 'ASC')
            ->executeQuery()->fetchAll();
        $out = array_map(fn(array $r): array => $this->row($r, $titles, $uid), $rows);
        usort($out, static fn(array $a, array $b): int => ($a['status'] !== 'active') <=> ($b['status'] !== 'active'));
        return $out;
    }

    /** 对某个位置适用、现在生效的决定（整个项目的，加上范围里含这个位置或它上面任何一层的）。 */
    public function applicable(string $uid, string $locationId): array {
        $loc = $this->projects->location($uid, $locationId);
        $chain = [$loc['id']];
        $cursor = $loc;
        while ($cursor['parent_id'] !== null && count($chain) < 22) {
            $cursor = $this->projects->location($uid, $cursor['parent_id']);
            $chain[] = $cursor['id'];
        }
        return array_values(array_filter(
            array_filter($this->forProject($uid, $loc['project_id']), static fn(array $d): bool => $d['status'] === 'active'),
            static fn(array $d): bool => $d['scope_location_ids'] === [] || array_intersect($d['scope_location_ids'], $chain) !== [],
        ));
    }

    /**
     * 登记一条决定。$recordedBy 是用户自己的 uid，或代录的 AI 的名字。
     *
     * @param list<string> $scope 空 = 整个项目
     */
    public function record(string $uid, string $projectId, string $content, array $scope, ?int $sourceMessageId,
        ?string $sourceNote, ?int $saidAt, string $recordedBy, ?string $supersedes, string $requestId): array {
        $content = trim($content);
        if ($content === '' || mb_strlen($content) > self::MAX_CONTENT) {
            throw new \InvalidArgumentException('原话要有内容，最多 ' . self::MAX_CONTENT . ' 字');
        }
        $sourceNote = $sourceNote === null || trim($sourceNote) === '' ? null : mb_substr(trim($sourceNote), 0, 1000);
        $byUser = $recordedBy === $uid;
        if (!$byUser && $sourceMessageId === null) {
            throw new \InvalidArgumentException('代录的决定要指向对话里用户说的那一句（source_message_id）');
        }
        if (!$byUser && $supersedes !== null) {
            throw new \InvalidArgumentException('替代一条决定只能由用户本人来做');
        }
        $scope = array_values(array_unique(array_map('strval', $scope)));
        sort($scope);
        return $this->projects->commit($uid, $requestId, ['record_decision', $projectId, $content, $scope, $sourceMessageId, $sourceNote, $saidAt, $recordedBy, $supersedes],
            function () use ($uid, $projectId, $content, $scope, $sourceMessageId, $sourceNote, $saidAt, $recordedBy, $supersedes): array {
                $project = $this->projects->project($uid, $projectId);
                $ids = array_column($project['locations'], 'id');
                foreach ($scope as $locationId) {
                    if (!in_array($locationId, $ids, true)) {
                        throw new \InvalidArgumentException('适用范围里的位置不属于这个项目');
                    }
                }
                if ($sourceMessageId !== null) {
                    $message = $this->userMessage($uid, $sourceMessageId);
                    if (mb_strpos($this->squash($message['content']), $this->squash($content)) === false) {
                        throw new \InvalidArgumentException('原话要能在那一句里原样找到；改写过的不是原话');
                    }
                    $saidAt = $saidAt ?? (int)$message['created_at'];
                }
                $now = time();
                $id = bin2hex(random_bytes(16));
                if ($supersedes !== null) {
                    $old = $this->get($supersedes);
                    if ($old === null || $old['project_id'] !== $projectId) {
                        throw new \RuntimeException('要替代的决定不在这个项目里', 404);
                    }
                    if ($old['status'] !== 'active') {
                        throw new \RuntimeException('要替代的决定已经不在生效了', 409);
                    }
                    $this->end($supersedes, 'superseded', $uid, $now, $id);
                }
                $this->projects->insert('cognos_decisions', [
                    'id' => $id, 'project_id' => $projectId, 'content' => $content,
                    'source_message_id' => $sourceMessageId, 'source_note' => $sourceNote, 'said_at' => $saidAt,
                    'original_author' => $uid, 'recorded_by' => mb_substr($recordedBy, 0, 128),
                    'scope_location_ids' => json_encode($scope, JSON_THROW_ON_ERROR), 'status' => 'active',
                    'supersedes' => $supersedes, 'superseded_by' => null, 'ended_at' => null, 'ended_by' => null,
                    'created_at' => $now,
                ]);
                return ['decision_id' => $id, 'project_id' => $projectId];
            });
    }

    /** 撤回一条决定。只有用户本人能撤回；旧记录留着。 */
    public function revoke(string $uid, string $decisionId, string $requestId): array {
        return $this->projects->commit($uid, $requestId, ['revoke_decision', $decisionId], function () use ($uid, $decisionId): array {
            $decision = $this->get($decisionId);
            if ($decision === null) {
                throw new \RuntimeException('决定不存在或不可访问', 404);
            }
            $this->projects->project($uid, $decision['project_id']);
            if ($decision['status'] !== 'active') {
                throw new \RuntimeException('这条决定已经不在生效了', 409);
            }
            $this->end($decisionId, 'revoked', $uid, time(), null);
            return ['decision_id' => $decisionId, 'status' => 'revoked'];
        });
    }

    /** 一次运行起步时入口里给了哪几条决定。 */
    public function recordIncluded(string $runId, array $decisionIds): void {
        foreach (array_values(array_unique($decisionIds)) as $decisionId) {
            $this->projects->insert('cognos_run_decisions', ['run_id' => $runId, 'decision_id' => (string)$decisionId]);
        }
    }

    /**
     * 运行起步以后才有、而它的输入里没有的适用决定（交回时调用）。只看现在仍生效的。
     *
     * @return list<array{id: string, content: string, created_at: int}>
     */
    public function missingForRun(string $uid, array $run): array {
        if ($run['space_type'] !== 'creation') {
            return [];
        }
        $location = $this->projects->locationForCreation($uid, (int)$run['space_id']);
        if ($location === null) {
            return [];
        }
        $included = $this->includedIn($run['id']);
        $missing = [];
        foreach ($this->applicable($uid, $location['id']) as $decision) {
            if ($decision['created_at'] >= $run['created_at'] && !in_array($decision['id'], $included, true)) {
                $missing[] = ['id' => $decision['id'], 'content' => $decision['content'], 'created_at' => $decision['created_at']];
            }
        }
        return $missing;
    }

    /**
     * 每条决定对每个参与者走到哪一步，只列有证据的：进了输入（哪次运行、什么时候）、依据了它（depends_on）。
     * 参与者是在这个项目里跑过的那几位；从没在这里跑过的不出现，不等于它不知道。
     *
     * @param list<string> $creationIds 项目各位置的对话
     * @return array<string, list<array{subject: string, included_at: ?int, included_run: ?string, depended_at: ?int}>>
     */
    public function progress(array $decisionIds, array $creationIds): array {
        if ($decisionIds === [] || $creationIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $runs = $qb->select('id', 'subject', 'created_at')->from('cognos_runs')
            ->where($qb->expr()->eq('space_type', $qb->createNamedParameter('creation')))
            ->andWhere($qb->expr()->in('space_id', $qb->createNamedParameter($creationIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->executeQuery()->fetchAll();
        $subjects = array_values(array_unique(array_column($runs, 'subject')));
        $runById = array_column($runs, null, 'id');
        $out = [];
        foreach ($decisionIds as $decisionId) {
            $out[$decisionId] = array_map(static fn(string $s): array => ['subject' => $s, 'included_at' => null, 'included_run' => null, 'depended_at' => null], $subjects);
        }
        if ($runs === []) {
            return $out;
        }
        $index = array_flip($subjects);
        foreach (array_chunk(array_keys($runById), 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $rows = $qb->select('run_id', 'decision_id')->from('cognos_run_decisions')
                ->where($qb->expr()->in('run_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->in('decision_id', $qb->createNamedParameter($decisionIds, IQueryBuilder::PARAM_STR_ARRAY)))
                ->executeQuery()->fetchAll();
            foreach ($rows as $r) {
                $run = $runById[$r['run_id']];
                $slot = &$out[$r['decision_id']][$index[$run['subject']]];
                if ($slot['included_at'] === null || (int)$run['created_at'] > $slot['included_at']) {
                    $slot['included_at'] = (int)$run['created_at'];
                    $slot['included_run'] = (string)$run['id'];
                }
                unset($slot);
            }
            $qb = $this->db->getQueryBuilder();
            $rows = $qb->select('run_id', 'object_id')->from('cognos_run_dependencies')
                ->where($qb->expr()->in('run_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter('decision')))
                ->andWhere($qb->expr()->in('object_id', $qb->createNamedParameter($decisionIds, IQueryBuilder::PARAM_STR_ARRAY)))
                ->executeQuery()->fetchAll();
            foreach ($rows as $r) {
                $run = $runById[$r['run_id']];
                $slot = &$out[$r['object_id']][$index[$run['subject']]];
                $slot['depended_at'] = max((int)$run['created_at'], (int)($slot['depended_at'] ?? 0));
                unset($slot);
            }
        }
        return $out;
    }

    private function includedIn(string $runId): array {
        $qb = $this->db->getQueryBuilder();
        return array_column($qb->select('decision_id')->from('cognos_run_decisions')
            ->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
            ->executeQuery()->fetchAll(), 'decision_id');
    }

    private function userMessage(string $uid, int $messageId): array {
        $qb = $this->db->getQueryBuilder();
        $m = $qb->select('id', 'creation_id', 'role', 'content', 'created_at')->from('cognos_creation_messages')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
            ->executeQuery()->fetch();
        if ($m === false || $this->creations->get((int)$m['creation_id'], $uid) === null) {
            throw new \InvalidArgumentException('原消息不存在或不可访问');
        }
        if ($m['role'] !== 'user') {
            throw new \InvalidArgumentException('原消息要是用户说的那一句，不能是 AI 的回答');
        }
        return $m;
    }

    private function get(string $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $r = $qb->select('*')->from('cognos_decisions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeQuery()->fetch();
        return $r === false ? null : $r;
    }

    private function end(string $id, string $status, string $uid, int $now, ?string $supersededBy): void {
        $qb = $this->db->getQueryBuilder();
        $n = $qb->update('cognos_decisions')
            ->set('status', $qb->createNamedParameter($status))
            ->set('ended_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('ended_by', $qb->createNamedParameter($uid))
            ->set('superseded_by', $qb->createNamedParameter($supersededBy, $supersededBy === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')))
            ->executeStatement();
        if ($n !== 1) {
            throw new \RuntimeException('这条决定刚刚被别处改过，请刷新再看', 409);
        }
    }

    /** 空白差异不算改写：比较原话时把连续空白当成一个。 */
    private function squash(string $text): string {
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    private function row(array $r, array $titles, string $uid): array {
        $scope = json_decode((string)$r['scope_location_ids'], true) ?: [];
        $author = (string)$r['original_author'];
        return [
            'id' => (string)$r['id'],
            'project_id' => (string)$r['project_id'],
            'content' => (string)$r['content'],
            'source_message_id' => $r['source_message_id'] === null ? null : (int)$r['source_message_id'],
            'source_note' => $r['source_note'] === null ? null : (string)$r['source_note'],
            'said_at' => $r['said_at'] === null ? null : (int)$r['said_at'],
            'original_author' => $author,
            'author_name' => $this->users->get($author)?->getDisplayName() ?? $author,
            'recorded_by' => (string)$r['recorded_by'],
            'recorded_by_ai' => (string)$r['recorded_by'] !== $author,
            'scope_location_ids' => $scope,
            'scope_titles' => array_values(array_map(static fn(string $id): string => (string)($titles[$id] ?? $id), $scope)),
            'status' => (string)$r['status'],
            'supersedes' => $r['supersedes'] === null ? null : (string)$r['supersedes'],
            'superseded_by' => $r['superseded_by'] === null ? null : (string)$r['superseded_by'],
            'ended_at' => $r['ended_at'] === null ? null : (int)$r['ended_at'],
            'created_at' => (int)$r['created_at'],
        ];
    }
}
