<?php
declare(strict_types=1);
namespace OCA\Cognos\Service;

use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Project working content is versioned; source conversations retain their own identity. */
final class ProjectService {
    public function __construct(private IDBConnection $db, private CreationSpaceService $creations, private LifecycleService $lifecycle) {}


    /** Owner-checked lock for business operations already inside commit(). */
    public function lockedProject(string $uid, string $id): array {
        $this->project($uid,$id);
        return $this->lifecycle->lock($id);
    }

    public function lockedLocation(string $uid, string $id): array {
        $before=$this->location($uid,$id);
        $this->lockedProject($uid,$before['project_id']);
        $after=$this->location($uid,$id);
        if ($before['project_id'] !== $after['project_id']) { throw new \RuntimeException('位置归属已变化，请重新读取入口',409); }
        return $after;
    }

    public function lifecycleHistory(string $uid, string $projectId): array {
        $this->project($uid, $projectId);
        $q = $this->db->getQueryBuilder();
        return $q->select('*')->from('cognos_lifecycle_history')
            ->where($q->expr()->eq('project_id', $q->createNamedParameter($projectId)))
            ->orderBy('revision', 'DESC')->setMaxResults(100)->executeQuery()->fetchAll();
    }

    public function changeLifecycle(string $uid, string $id, string $action, int $expected, string $requestId, string $note, string $reopenWhen): array {
        $targets = ['pause'=>'paused', 'end'=>'ended', 'explore'=>'exploring', 'resume'=>'active'];
        if (!isset($targets[$action]) || $expected < 1 || mb_strlen($note) > 4000 || mb_strlen($reopenWhen) > 2000) {
            throw new \InvalidArgumentException('状态操作或说明无效');
        }
        return $this->commit($uid, $requestId, ['lifecycle',$id,$action,$expected,$note,$reopenWhen],
            function () use ($uid,$id,$action,$expected,$note,$reopenWhen,$targets): array {
                $this->project($uid,$id);
                $p = $this->lifecycle->lock($id);
                if (!empty($p['merged_into'])) { throw new \RuntimeException('项目已经合并，请到目标项目调整状态',409); }
                if ((int)$p['lifecycle_revision'] !== $expected) { throw new \RuntimeException('项目状态已变化，请查看最新记录再操作',409); }
                $next=$targets[$action];
                if ($p['state'] === $next) { throw new \RuntimeException('项目已经处于这个状态',409); }
                $revision=$expected+1; $epoch=(int)$p['execution_epoch']+1;
                $q=$this->db->getQueryBuilder();
                $q->update('cognos_projects')->set('state',$q->createNamedParameter($next))
                    ->set('lifecycle_revision',$q->createNamedParameter($revision,IQueryBuilder::PARAM_INT))
                    ->set('execution_epoch',$q->createNamedParameter($epoch,IQueryBuilder::PARAM_INT))
                    ->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();
                $this->insert('cognos_lifecycle_history',['project_id'=>$id,'revision'=>$revision,'from_state'=>$p['state'],
                    'to_state'=>$next,'actor'=>$uid,'note'=>$note,'reopen_when'=>$reopenWhen,'created_at'=>time()]);
                return ['project_id'=>$id,'state'=>$next,'lifecycle_revision'=>$revision,'execution_epoch'=>$epoch];
            });
    }

    public function projects(string $uid): array {
        $qb = $this->db->getQueryBuilder();
        return $qb->select('id', 'title', 'root_location_id', 'merged_into')->from('cognos_projects')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->orderBy('created_at', 'DESC')->addOrderBy('id', 'ASC')->executeQuery()->fetchAll();
    }

    public function project(string $uid, string $id): array {
        $qb = $this->db->getQueryBuilder();
        $p = $qb->select('*')->from('cognos_projects')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))->executeQuery()->fetch();
        if (!$p) { throw new \RuntimeException('项目不存在或不可访问', 404); }
        $qb = $this->db->getQueryBuilder();
        $p['locations'] = $qb->select('id', 'parent_id', 'title')->from('cognos_locations')
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($id)))
            ->orderBy('created_at', 'ASC')->addOrderBy('id', 'ASC')->executeQuery()->fetchAll();
        return $p;
    }

    public function location(string $uid, string $id): array {
        $qb = $this->db->getQueryBuilder();
        $r = $qb->select('l.*')->from('cognos_locations', 'l')
            ->innerJoin('l', 'cognos_projects', 'p', $qb->expr()->eq('p.id', 'l.project_id'))
            ->where($qb->expr()->eq('l.id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('p.user_id', $qb->createNamedParameter($uid)))->executeQuery()->fetch();
        if (!$r) { throw new \RuntimeException('位置不存在或不可访问', 404); }
        foreach (['revision', 'creation_id', 'created_at', 'updated_at'] as $key) { $r[$key] = (int)$r[$key]; }
        return $r;
    }

    public function detail(string $uid, string $id): array {
        $loc = $this->location($uid, $id);
        $revision = $this->revision($uid, $id, $loc['revision']);
        return ['location' => $loc, 'project' => $this->project($uid, $loc['project_id']),
            'usages' => $this->usages($uid, $id), 'source_message_ids' => $revision['source_message_ids']];
    }

    public function create(string $uid, string $title, string $requestId): array {
        $title = $this->title($title);
        return $this->commit($uid, $requestId, ['create_project', $title], fn() => $this->newProject($uid, $title));
    }

    /** Create a project and its root location inside the caller's commit (InitiumService forms one from a conversation). */
    public function newProject(string $uid, string $title): array {
        $title = $this->title($title);
        $id = bin2hex(random_bytes(16));
        $root = bin2hex(random_bytes(16));
        $this->insert('cognos_projects', ['id' => $id, 'user_id' => $uid, 'title' => $title,
            'root_location_id' => $root, 'created_at' => time()]);
        $this->newLocation($uid, $id, $root, null, $title);
        return ['project_id' => $id, 'location_id' => $root];
    }

    public function addLocation(string $uid, string $projectId, string $parentId, string $title, string $requestId): array {
        $title = $this->title($title);
        return $this->commit($uid, $requestId, ['add_location', $projectId, $parentId, $title], function () use ($uid, $projectId, $parentId, $title) {
            $this->lockedProject($uid, $projectId);
            $parent = $this->location($uid, $parentId);
            if ($parent['project_id'] !== $projectId) { throw new \InvalidArgumentException('上级位置不属于此项目'); }
            $depth = 1;
            while ($parent['parent_id'] !== null) {
                if (++$depth > 20) { throw new \InvalidArgumentException('层级过深，请在已有位置中展开'); }
                $parent = $this->location($uid, $parent['parent_id']);
            }
            $id = bin2hex(random_bytes(16));
            $this->newLocation($uid, $projectId, $id, $parentId, $title);
            return ['project_id' => $projectId, 'location_id' => $id];
        });
    }

    private function newLocation(string $uid, string $projectId, string $id, ?string $parentId, string $title): void {
        // Dedicated discussion, never repurpose a source conversation shared by several locations.
        $chat = $this->creations->create($uid, $title);
        $now = time();
        $this->insert('cognos_locations', ['id' => $id, 'project_id' => $projectId, 'parent_id' => $parentId,
            'title' => $title, 'content' => '', 'revision' => 1, 'creation_id' => $chat['id'],
            'editor' => $uid, 'created_at' => $now, 'updated_at' => $now]);
        $this->insert('cognos_location_revisions', ['location_id' => $id, 'revision' => 1,
            'content' => '', 'editor' => $uid, 'source_message_ids' => '[]', 'created_at' => $now]);
    }

    /** $editor：写这一版的是谁，默认是用户本人；交出去的事里 AI 写的版本记 AI 的名字和那次委托。 */
    public function updateContent(string $uid, string $id, int $expected, string $content, array $sources, string $requestId,
        string $editor = '', ?string $delegationId = null): array {
        $editor = $editor === '' ? $uid : mb_substr($editor, 0, 64);
        if ($expected < 1 || mb_strlen($content) > 100000) { throw new \InvalidArgumentException('版本无效或内容超过 10 万字'); }
        if (count($sources) > 50) { throw new \InvalidArgumentException('一次最多关联 50 条原消息'); }
        foreach ($sources as $v) {
            if (!is_int($v) || $v < 1) { throw new \InvalidArgumentException('来源消息编号无效'); }
        }
        $sources = array_values(array_unique($sources)); sort($sources);
        return $this->commit($uid, $requestId, ['update_content', $id, $expected, $content, $sources, $editor, $delegationId],
            fn() => $this->saveContent($uid, $id, $expected, $content, $sources, $editor, $delegationId));
    }

    /** Write a new revision with an expected-revision check, inside the caller's commit. */
    public function saveContent(string $uid, string $id, int $expected, string $content, array $sources, string $editor, ?string $delegationId): array {
        $loc = $this->lockedLocation($uid, $id);
        if ($delegationId !== null && !$this->lifecycle->delegationEligible($loc['project_id'], $delegationId)) {
            throw new \RuntimeException('项目已暂停、结束或这次运行属于旧的推进安排，结果留作比较', 409);
        }
        if ($loc['revision'] !== $expected) { throw new \RuntimeException('这里已有新版本，你的文本仍保留。请比较后再保存。', 409); }
        $allowed = [$loc['creation_id']];
        foreach ($this->usages($uid, $id) as $usage) { $allowed[] = $usage['source_creation_id']; }
        foreach ($sources as $messageId) {
            $qb = $this->db->getQueryBuilder();
            $m = $qb->select('creation_id')->from('cognos_creation_messages')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
                ->executeQuery()->fetch();
            if (!$m || !in_array((int)$m['creation_id'], $allowed, true)
                || !$this->creations->get((int)$m['creation_id'], $uid)) {
                throw new \InvalidArgumentException('来源消息不在此处可访问的讨论或材料中');
            }
        }
        $now = time(); $next = $expected + 1;
        $qb = $this->db->getQueryBuilder();
        $n = $qb->update('cognos_locations')->set('content', $qb->createNamedParameter($content))
            ->set('revision', $qb->createNamedParameter($next, IQueryBuilder::PARAM_INT))
            ->set('editor', $qb->createNamedParameter($editor))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($expected, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
        if ($n !== 1) { throw new \RuntimeException('保存时出现新版本，请比较后再保存。', 409); }
        $this->insert('cognos_location_revisions', ['location_id' => $id, 'revision' => $next,
            'content' => $content, 'editor' => $editor, 'source_message_ids' => $this->json($sources), 'created_at' => $now,
            'delegation_id' => $delegationId]);
        return ['location_id' => $id, 'revision' => $next];
    }

    public function history(string $uid, string $id, int $before = 0): array {
        $this->location($uid, $id);
        $qb = $this->db->getQueryBuilder();
        $qb->select('revision', 'editor', 'created_at', 'source_message_ids', 'delegation_id')->from('cognos_location_revisions')
            ->where($qb->expr()->eq('location_id', $qb->createNamedParameter($id)));
        if ($before > 0) { $qb->andWhere($qb->expr()->lt('revision', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT))); }
        $rows = $qb->orderBy('revision', 'DESC')->setMaxResults(30)->executeQuery()->fetchAll();
        return array_map(fn(array $r): array => $this->revisionRow($r), $rows);
    }

    public function revision(string $uid, string $id, int $revision): array {
        $this->location($uid, $id);
        $qb = $this->db->getQueryBuilder();
        $row = $qb->select('*')->from('cognos_location_revisions')
            ->where($qb->expr()->eq('location_id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($revision, IQueryBuilder::PARAM_INT)))
            ->executeQuery()->fetch();
        if (!$row) { throw new \RuntimeException('版本不存在', 404); }
        return $this->revisionRow($row);
    }

    private function revisionRow(array $r): array {
        $r['revision'] = (int)$r['revision']; $r['created_at'] = (int)$r['created_at'];
        $r['source_message_ids'] = json_decode($r['source_message_ids'], true, 512, JSON_THROW_ON_ERROR);
        return $r;
    }

    public function usages(string $uid, string $locationId): array {
        $this->location($uid, $locationId);
        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('u.*', 'c.title')->from('cognos_usages', 'u')
            ->innerJoin('u', 'cognos_creations', 'c', $qb->expr()->eq('c.id', 'u.source_creation_id'))
            ->where($qb->expr()->eq('u.location_id', $qb->createNamedParameter($locationId)))
            ->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($uid)))
            ->orderBy('u.created_at', 'ASC')->addOrderBy('u.id', 'ASC')->executeQuery()->fetchAll();
        foreach ($rows as &$r) { $r['source_creation_id'] = (int)$r['source_creation_id']; }
        return $rows;
    }

    public function addUsage(string $uid, string $id, int $sourceId, string $purpose, string $requestId): array {
        $purpose = trim($purpose);
        if (mb_strlen($purpose) > 2000) { throw new \InvalidArgumentException('用途说明最多 2000 字'); }
        return $this->commit($uid, $requestId, ['add_usage', $id, $sourceId, $purpose], fn() => $this->newUsage($uid, $id, $sourceId, $purpose));
    }

    /** Relate an existing conversation to a location inside the caller's commit; the conversation is not moved. */
    public function newUsage(string $uid, string $id, int $sourceId, string $purpose): array {
        $loc = $this->location($uid, $id);
        if (!$this->creations->get($sourceId, $uid)) { throw new \RuntimeException('材料不存在或不可访问', 404); }
        if ($loc['creation_id'] === $sourceId) { throw new \InvalidArgumentException('这就是当前位置的讨论'); }
        foreach ($this->usages($uid, $id) as $u) {
            if ($u['source_creation_id'] === $sourceId) { throw new \RuntimeException('这份材料已经关联在这里', 409); }
        }
        $usageId = bin2hex(random_bytes(16));
        $this->insert('cognos_usages', ['id' => $usageId, 'location_id' => $id, 'source_creation_id' => $sourceId,
            'purpose' => $purpose, 'created_at' => time()]);
        return ['usage_id' => $usageId, 'location_id' => $id];
    }

    /** The location whose dedicated discussion is this conversation, or null (a plain creation space or a shared source). */
    public function locationForCreation(string $uid, int $creationId): ?array {
        $qb = $this->db->getQueryBuilder();
        $row = $qb->select('l.id')->from('cognos_locations', 'l')
            ->innerJoin('l', 'cognos_projects', 'p', $qb->expr()->eq('l.project_id', 'p.id'))
            ->where($qb->expr()->eq('l.creation_id', $qb->createNamedParameter($creationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('p.user_id', $qb->createNamedParameter($uid)))->executeQuery()->fetch();
        return $row ? $this->location($uid, $row['id']) : null;
    }

    /**
     * 谁在动什么：项目各位置上还在做、或不知道做没做完的运行（按参与者），最近交回的几次
     * （带「起步时还没有这条决定」），以及最近改了哪一处当前内容。
     */
    public function activity(string $uid, string $projectId): array {
        $project = $this->project($uid, $projectId);
        $qb = $this->db->getQueryBuilder();
        $locations = $qb->select('id', 'title', 'creation_id')->from('cognos_locations')
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))->executeQuery()->fetchAll();
        $byChat = [];
        foreach ($locations as $l) { $byChat[(string)$l['creation_id']] = ['id' => $l['id'], 'title' => $l['title']]; }
        $runs = []; $recent = [];
        if ($byChat !== []) {
            $qb = $this->db->getQueryBuilder();
            $rows = $qb->select('r.id', 'r.space_id', 'r.subject', 'r.purpose', 'r.state', 'r.created_at', 'r.updated_at', 'x.decisions_missing',
                'x.basis_changed', 'x.drift_seen_at')
                ->from('cognos_runs', 'r')
                ->leftJoin('r', 'cognos_run_results', 'x', $qb->expr()->eq('x.run_id', 'r.id'))
                ->where($qb->expr()->eq('r.space_type', $qb->createNamedParameter('creation')))
                ->andWhere($qb->expr()->in('r.space_id', $qb->createNamedParameter(array_map('strval', array_keys($byChat)), IQueryBuilder::PARAM_STR_ARRAY)))
                ->orderBy('r.created_at', 'DESC')->addOrderBy('r.id', 'DESC')->setMaxResults(200)
                ->executeQuery()->fetchAll();
            foreach ($rows as $r) {
                $item = ['run_id' => (string)$r['id'], 'subject' => (string)$r['subject'], 'purpose' => (string)$r['purpose'],
                    'state' => (string)$r['state'], 'location' => $byChat[(string)$r['space_id']] ?? null,
                    'created_at' => (int)$r['created_at'], 'updated_at' => (int)$r['updated_at'],
                    'decisions_missing' => $r['decisions_missing'] === null ? [] : (json_decode((string)$r['decisions_missing'], true) ?: []),
                    'basis_changed' => $r['basis_changed'] === null ? [] : (json_decode((string)$r['basis_changed'], true) ?: []),
                    'drift_seen_at' => $r['drift_seen_at'] === null ? null : (int)$r['drift_seen_at']];
                if (in_array($item['state'], ['queued', 'claimed', 'running', 'result_pending', 'unknown'], true)) {
                    $runs[] = $item;
                } elseif (count($recent) < 5 && $item['state'] === 'delivered') {
                    $recent[] = $item;
                }
            }
        }
        $edits = [];
        if ($locations !== []) {
            $qb = $this->db->getQueryBuilder();
            $rows = $qb->select('location_id', 'revision', 'editor', 'created_at')->from('cognos_location_revisions')
                ->where($qb->expr()->in('location_id', $qb->createNamedParameter(array_column($locations, 'id'), IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->gt('revision', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
                ->orderBy('created_at', 'DESC')->addOrderBy('revision', 'DESC')->setMaxResults(5)
                ->executeQuery()->fetchAll();
            $titles = array_column($project['locations'], 'title', 'id');
            foreach ($rows as $r) {
                $edits[] = ['location' => ['id' => $r['location_id'], 'title' => $titles[$r['location_id']] ?? ''],
                    'revision' => (int)$r['revision'], 'editor' => (string)$r['editor'], 'created_at' => (int)$r['created_at']];
            }
        }
        // 最近交回的几次各自上报了什么依据；没报就是空的（「未上报依据」，不等于没有依赖）
        if ($recent !== []) {
            $qb = $this->db->getQueryBuilder();
            $deps = $qb->select('run_id', 'object_type', 'object_id', 'version')->from('cognos_run_dependencies')
                ->where($qb->expr()->in('run_id', $qb->createNamedParameter(array_column($recent, 'run_id'), IQueryBuilder::PARAM_STR_ARRAY)))
                ->orderBy('id', 'ASC')->executeQuery()->fetchAll();
            $byRun = [];
            foreach ($deps as $d) {
                $byRun[$d['run_id']][] = ['type' => (string)$d['object_type'], 'id' => (string)$d['object_id'], 'version' => $d['version']];
            }
            foreach ($recent as &$item) { $item['basis'] = $byRun[$item['run_id']] ?? []; }
            unset($item);
        }
        return ['runs' => $runs, 'recent' => $recent, 'edits' => $edits, 'creation_ids' => array_map('strval', array_keys($byChat))];
    }

    /** 项目总览里各位置现在怎样：当前内容第几版、谁最后改的、什么时候。 */
    public function locationStates(string $uid, string $projectId): array {
        $this->project($uid, $projectId);
        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('id', 'parent_id', 'title', 'revision', 'editor', 'updated_at', 'content')->from('cognos_locations')
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
            ->orderBy('created_at', 'ASC')->addOrderBy('id', 'ASC')->executeQuery()->fetchAll();
        return array_map(static fn(array $r): array => ['id' => (string)$r['id'], 'parent_id' => $r['parent_id'], 'title' => (string)$r['title'],
            'revision' => (int)$r['revision'], 'editor' => (string)$r['editor'], 'updated_at' => (int)$r['updated_at'],
            'length' => mb_strlen((string)$r['content'])], $rows);
    }

    /** Snapshot content for a verified owner's dedicated conversation; references stay references. */
    public function contextForCreation(string $uid, int $creationId): ?array {
        $qb = $this->db->getQueryBuilder();
        $row = $qb->select('l.id')->from('cognos_locations', 'l')
            ->innerJoin('l', 'cognos_projects', 'p', $qb->expr()->eq('l.project_id', 'p.id'))
            ->where($qb->expr()->eq('l.creation_id', $qb->createNamedParameter($creationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('p.user_id', $qb->createNamedParameter($uid)))->executeQuery()->fetch();
        if (!$row) { return null; }
        $loc = $this->location($uid, $row['id']);
        $project = $this->project($uid, $loc['project_id']);
        $path = []; $cursor = $loc;
        do {
            $path[] = ['id' => $cursor['id'], 'title' => $cursor['title'], 'revision' => $cursor['revision']];
            $cursor = $cursor['parent_id'] === null ? null : $this->location($uid, $cursor['parent_id']);
        } while ($cursor !== null && count($path) <= 21);
        $sources = []; $usages = $this->usages($uid, $loc['id']);
        foreach (array_slice($usages, 0, 8) as $u) {
            $messages = $this->creations->getMessages($u['source_creation_id'], $uid);
            $taken = array_slice($messages, -8);
            $text = $this->json($taken);
            $sources[] = ['conversation_id' => $u['source_creation_id'], 'title' => $u['title'],
                'purpose_here' => $u['purpose'], 'version_policy' => 'latest_at_entry',
                'total_messages' => count($messages), 'selected_message_ids' => array_column($taken, 'id'),
                'selection_hash' => hash('sha256', $text), 'excerpt' => mb_substr($text, 0, 4000),
                'truncated' => count($taken) < count($messages) || mb_strlen($text) > 4000];
        }
        return ['project_id' => $project['id'], 'project_title' => $project['title'],
            'state' => $project['state'], 'execution_epoch' => (int)$project['execution_epoch'],
            'location_id' => $loc['id'], 'path' => array_reverse($path), 'revision' => $loc['revision'],
            'working_content' => mb_substr($loc['content'], 0, 16000), 'editor' => $loc['editor'],
            'content_truncated' => mb_strlen($loc['content']) > 16000, 'content_hash' => hash('sha256', $loc['content']),
            'sources' => $sources, 'sources_total' => count($usages),
            'sources_omitted' => max(0, count($usages) - count($sources))];
    }

    private function title(string $title): string {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) { throw new \InvalidArgumentException('请填写 1–200 字的名字'); }
        return $title;
    }

    /** Reserve a durable receipt and perform the domain change in the same transaction (also used by DecisionService). */
    public function commit(string $uid, string $requestId, array $payload, callable $work): array {
        if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $requestId)) { throw new \InvalidArgumentException('请求编号无效'); }
        $hash = hash('sha256', $this->json($payload));
        $prior = $this->receipt($uid, $requestId);
        if ($prior !== null) { return $this->replay($prior, $hash); }
        $this->db->beginTransaction();
        try {
            $this->insert('cognos_project_requests', ['user_id' => $uid, 'request_id' => $requestId,
                'request_hash' => $hash, 'response' => '{}', 'created_at' => time()]);
            $response = $work();
            $qb = $this->db->getQueryBuilder();
            $qb->update('cognos_project_requests')->set('response', $qb->createNamedParameter($this->json($response)))
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId)))->executeStatement();
            $this->db->commit();
            return $response;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e instanceof DbException && $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                $prior = $this->receipt($uid, $requestId);
                if ($prior !== null) { return $this->replay($prior, $hash); }
                throw new \RuntimeException('另一项相同关联已保存，请刷新查看。', 409);
            }
            throw $e;
        }
    }

    private function receipt(string $uid, string $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $r = $qb->select('request_hash', 'response')->from('cognos_project_requests')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('request_id', $qb->createNamedParameter($id)))->executeQuery()->fetch();
        return $r === false ? null : $r;
    }
    private function replay(array $r, string $hash): array {
        if (!hash_equals($r['request_hash'], $hash)) { throw new \RuntimeException('同一请求编号不能用于不同内容', 409); }
        return json_decode($r['response'], true, 512, JSON_THROW_ON_ERROR);
    }
    public function insert(string $table, array $values): void {
        $qb = $this->db->getQueryBuilder(); $params = [];
        foreach ($values as $key => $value) {
            $params[$key] = $qb->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : (is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
        }
        $qb->insert($table)->values($params)->executeStatement();
    }
    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
}
