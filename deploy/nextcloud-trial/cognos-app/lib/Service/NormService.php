<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Norm（示范空间）：一段和 agent 的对话，加上一个个空间。
 *
 * 空间存在 cognos_norm_projects 里（表名是第一版留下的）。一个空间要么是现成的样子（board：项目、笔记、学习、组织），
 * 要么带着自己的做法（board 为空）：它挑了哪几块（parts），agent 在这里怎么工作（way）。
 * 几块是：演变（stages）、拆解（branches）、关系（relations）、并排对照（compare）、文档（doc）；对话每个空间都有。
 * 每个空间有自己的一段对话（conversation_id，是一段 creation），agent 在那里能读能改这几块（见 NormApiController）。
 */
final class NormService {
    /** 现成的样子：各自默认用哪几块、是什么样的东西。读者放东西时照这个挑。 */
    public const BOARDS = [
        '项目' => ['parts' => ['stages', 'branches', 'relations'], 'about' => '要做成一件事：有阶段，能拆开，各部分之间有关系。往「做成」推。'],
        '笔记' => ['parts' => ['doc', 'relations'], 'about' => '要留住、理清一种理解：写成文字，连到别的理解。不往「做成」推。'],
        '学习' => ['parts' => ['stages', 'doc'], 'about' => '人在学一样东西：记的是人怎么变，从不会到会。'],
        '组织' => ['parts' => ['branches', 'relations'], 'about' => '几个人或几个 agent 怎么分工、谁接谁的结果。'],
    ];
    public const PARTS = ['stages' => '演变', 'branches' => '拆解', 'relations' => '关系', 'compare' => '并排对照', 'doc' => '文档'];
    public const STATES = ['active', 'withdrawn', 'back'];

    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
        private CreationSpaceService $creations,
    ) {}

    /** Norm 自己的那段对话：每个账号一段，第一次打开时建。 */
    public function conversationId(string $uid): int {
        $id = (int)$this->config->getUserValue($uid, 'cognos', 'norm_creation_id', '0');
        if ($id > 0 && $this->creations->get($id, $uid) !== null) {
            return $id;
        }
        $creation = $this->creations->create($uid, 'Norm');
        $this->config->setUserValue($uid, 'cognos', 'norm_creation_id', (string)$creation['id']);
        return (int)$creation['id'];
    }

    public function conversationIdIfAny(string $uid): int {
        return (int)$this->config->getUserValue($uid, 'cognos', 'norm_creation_id', '0');
    }

    /** Initium 列表里不显示的对话：Norm 自己的，和还在 Norm 里的空间各自的那段。 */
    public function hiddenConversationIds(string $uid): array {
        $q = $this->db->getQueryBuilder();
        $ids = $q->select('conversation_id')->from('cognos_norm_projects')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->isNotNull('conversation_id'))
            ->andWhere($q->expr()->orX($q->expr()->isNull('state'), $q->expr()->eq('state', $q->createNamedParameter('active'))))
            ->executeQuery()->fetchAll();
        $ids = array_map(static fn(array $r): int => (int)$r['conversation_id'], $ids);
        $ids[] = $this->conversationIdIfAny($uid);
        return array_values(array_filter($ids));
    }

    /** 撤回了、退回 Initium 的空间各自的那段对话。 */
    public function inactiveConversationIds(string $uid): array {
        $q = $this->db->getQueryBuilder();
        $rows = $q->select('conversation_id')->from('cognos_norm_projects')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->isNotNull('conversation_id'))
            ->andWhere($q->expr()->neq('state', $q->createNamedParameter('active')))
            ->executeQuery()->fetchAll();
        return array_values(array_filter(array_map(static fn(array $r): int => (int)$r['conversation_id'], $rows)));
    }

    /** @return list<array> 主项目在前，其余按最近更新；只列还在 Norm 里的。 */
    public function projects(string $uid): array {
        $q = $this->db->getQueryBuilder();
        $rows = $q->select('*')->from('cognos_norm_projects')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->orX($q->expr()->isNull('state'), $q->expr()->eq('state', $q->createNamedParameter('active'))))
            ->orderBy('is_main', 'DESC')->addOrderBy('updated_at', 'DESC')
            ->executeQuery()->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $s = $this->row($r, false);
            $s['waiting'] = $this->countEvents($uid, $s['id'], 'decide', true);
            $s['new'] = $this->countSince($uid, $s['id'], $s['last_seen_at']);
            $out[] = $s;
        }
        return $out;
    }

    public function project(string $uid, string $id): ?array {
        $r = $this->fetch($uid, $id);
        return $r === null ? null : $this->row($r, true);
    }

    /** 建一个项目（现成的样子「项目」）；$main 为真时它成为主项目，原来的主项目退到下面。 */
    public function create(string $uid, string $title, bool $main, array $stages = [], array $branches = [], array $relations = []): string {
        return $this->createSpace($uid, $title, '项目', [], [], '', $main, ['stages' => $stages, 'branches' => $branches, 'relations' => $relations]);
    }

    /**
     * 建一个空间。$board 为 null 时它带着自己的做法：$parts 必须挑过，$way 写 agent 在这里怎么工作。
     * @param array{away?: string, progress?: string, ask?: string} $way
     */
    public function createSpace(string $uid, string $title, ?string $board, array $parts, array $way, string $summary, bool $main = false, array $content = []): string {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) {
            throw new \InvalidArgumentException('名字 1 到 200 字');
        }
        if ($board !== null && !isset(self::BOARDS[$board])) {
            $board = null;
        }
        $parts = $this->cleanParts($parts);
        if ($parts === []) {
            $parts = $board !== null ? self::BOARDS[$board]['parts'] : ['stages', 'doc'];
        }
        $id = bin2hex(random_bytes(16));
        $now = time();
        if ($main) {
            $u = $this->db->getQueryBuilder();
            $u->update('cognos_norm_projects')->set('is_main', $u->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
                ->where($u->expr()->eq('user_id', $u->createNamedParameter($uid)))->executeStatement();
        }
        $q = $this->db->getQueryBuilder();
        $q->insert('cognos_norm_projects')->values([
            'id' => $q->createNamedParameter($id),
            'user_id' => $q->createNamedParameter($uid),
            'title' => $q->createNamedParameter($title),
            'is_main' => $q->createNamedParameter($main, IQueryBuilder::PARAM_BOOL),
            'board' => $q->createNamedParameter($board, $board === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
            'parts' => $q->createNamedParameter(json_encode($parts)),
            'way' => $q->createNamedParameter(json_encode($this->cleanWay($way), JSON_UNESCAPED_UNICODE)),
            'summary' => $q->createNamedParameter(mb_substr(trim($summary), 0, 2000)),
            'stages' => $q->createNamedParameter(json_encode($content['stages'] ?? [], JSON_UNESCAPED_UNICODE)),
            'branches' => $q->createNamedParameter(json_encode($content['branches'] ?? [], JSON_UNESCAPED_UNICODE)),
            'relations' => $q->createNamedParameter(json_encode(($content['relations'] ?? []) ?: ['nodes' => [], 'edges' => []], JSON_UNESCAPED_UNICODE)),
            'compare' => $q->createNamedParameter(json_encode($content['compare'] ?? [], JSON_UNESCAPED_UNICODE)),
            'doc' => $q->createNamedParameter((string)($content['doc'] ?? '')),
            'state' => $q->createNamedParameter('active'),
            'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        return $id;
    }

    public function findByTitle(string $uid, string $title): ?string {
        $q = $this->db->getQueryBuilder();
        $r = $q->select('id')->from('cognos_norm_projects')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('title', $q->createNamedParameter($title)))
            ->setMaxResults(1)->executeQuery()->fetchOne();
        return $r === false ? null : (string)$r;
    }

    /** 这个空间自己的那段对话：第一次用时建。 */
    public function spaceConversation(string $uid, string $id): int {
        $r = $this->fetch($uid, $id);
        if ($r === null) {
            throw new \InvalidArgumentException('没有这个空间');
        }
        $cid = (int)($r['conversation_id'] ?? 0);
        if ($cid > 0 && $this->creations->get($cid, $uid) !== null) {
            return $cid;
        }
        $cid = (int)$this->creations->create($uid, (string)$r['title'])['id'];
        $this->update($uid, $id, ['conversation_id' => $cid], false);
        return $cid;
    }

    /**
     * 这段对话是哪个还在 Norm 里的空间的（空间自己的那段）。
     * 撤回了、退回 Initium 的空间不算：那段对话回到 Initium，agent 不再把它当成 Norm 空间去读写，读者照 Initium 的对话读它。
     */
    public function spaceByConversation(string $uid, int $creationId): ?array {
        $q = $this->db->getQueryBuilder();
        $r = $q->select('*')->from('cognos_norm_projects')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('conversation_id', $q->createNamedParameter($creationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->orX($q->expr()->isNull('state'), $q->expr()->eq('state', $q->createNamedParameter('active'))))
            ->setMaxResults(1)->executeQuery()->fetch();
        return $r === false ? null : $this->row($r, true);
    }

    /**
     * 改一块的全部内容。改之前把原来的存成一版（能换回去），并在空间里记一条「谁改了哪一块」，你回来时看得到。
     */
    public function writePart(string $uid, string $id, string $part, mixed $content, string $who = 'agent'): void {
        if (!isset(self::PARTS[$part])) {
            throw new \InvalidArgumentException('没有这一块：' . $part . '（只有 ' . implode('、', array_keys(self::PARTS)) . '）');
        }
        if ($part === 'doc') {
            if (!is_string($content)) {
                throw new \InvalidArgumentException('文档是一段文字');
            }
            $value = $content;
        } else {
            if (is_string($content)) {
                $content = json_decode($content, true);
            }
            if (!is_array($content)) {
                throw new \InvalidArgumentException(self::PARTS[$part] . '要是 JSON');
            }
            if ($part === 'relations' && (!isset($content['nodes']) || !is_array($content['nodes']))) {
                throw new \InvalidArgumentException('关系要有 nodes 和 edges');
            }
            $value = json_encode($content, JSON_UNESCAPED_UNICODE);
        }
        $r = $this->fetch($uid, $id);
        if ($r === null) {
            throw new \InvalidArgumentException('没有这个空间');
        }
        $old = $r[$part] ?? null;
        $same = $part === 'doc' ? (string)$old === $value
            : json_decode((string)($old ?? ''), true) == $content;
        if ($same) {
            return;
        }
        $this->saveVersion($uid, $id, $part, $old, $who);
        $this->update($uid, $id, [$part => $value]);
        $this->noteChange($uid, $id, $who . ' 改了「' . self::PARTS[$part] . '」。', $who);
    }

    /** @return array<string, list<array{id: int, who: string, created_at: int}>> 每一块最近几次改之前的版本，新的在前 */
    public function versions(string $uid, string $id, int $limit = 5): array {
        $q = $this->db->getQueryBuilder();
        $rows = $q->select('id', 'part', 'who', 'created_at')->from('cognos_norm_part_versions')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($id)))
            ->orderBy('id', 'DESC')->setMaxResults(200)->executeQuery()->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $p = (string)$r['part'];
            if (count($out[$p] ?? []) < $limit) {
                $out[$p][] = ['id' => (int)$r['id'], 'who' => (string)($r['who'] ?? ''), 'created_at' => (int)$r['created_at']];
            }
        }
        return $out;
    }

    /** 把一块换回某一版。换下来的现在这版也存起来，所以换回去也能再换回来。 */
    public function restorePart(string $uid, string $id, int $versionId): string {
        $q = $this->db->getQueryBuilder();
        $v = $q->select('*')->from('cognos_norm_part_versions')
            ->where($q->expr()->eq('id', $q->createNamedParameter($versionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($id)))
            ->executeQuery()->fetch();
        if ($v === false || !isset(self::PARTS[(string)$v['part']])) {
            throw new \InvalidArgumentException('没有这一版');
        }
        $part = (string)$v['part'];
        $r = $this->fetch($uid, $id);
        if ($r === null) {
            throw new \InvalidArgumentException('没有这个空间');
        }
        $this->saveVersion($uid, $id, $part, $r[$part] ?? null, '你');
        $this->update($uid, $id, [$part => $v['content']]);
        $this->addEvent($uid, $id, 'happened', '你把「' . self::PARTS[$part] . '」换回了 ' . date('n/j H:i', (int)$v['created_at']) . ' 改之前的那一版。', [], '你');
        return $part;
    }

    private function saveVersion(string $uid, string $id, string $part, ?string $content, string $who): void {
        $q = $this->db->getQueryBuilder();
        $q->insert('cognos_norm_part_versions')->values([
            'user_id' => $q->createNamedParameter($uid),
            'space_id' => $q->createNamedParameter($id),
            'part' => $q->createNamedParameter($part),
            'content' => $q->createNamedParameter($content, $content === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
            'who' => $q->createNamedParameter(mb_substr($who, 0, 64)),
            'created_at' => $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ])->executeStatement();
    }

    /** 记一条「谁改了什么」；同一位十分钟内改同一块，不重复记。 */
    private function noteChange(string $uid, string $id, string $text, string $who): void {
        foreach ($this->events($uid, $id, 'happened', 5) as $e) {
            if ($e['text'] === $text && $e['who'] === $who && time() - $e['created_at'] < 600) {
                return;
            }
        }
        $this->addEvent($uid, $id, 'happened', $text, [], $who);
    }

    /** 改它的样子：换成现成的 board，或带着自己的做法（board 为 null，parts 和 way 一起给）。 */
    public function reshape(string $uid, string $id, ?string $board, array $parts, ?array $way): void {
        if ($board !== null && !isset(self::BOARDS[$board])) {
            throw new \InvalidArgumentException('没有这种样子：' . $board);
        }
        $parts = $this->cleanParts($parts) ?: ($board !== null ? self::BOARDS[$board]['parts'] : []);
        if ($parts === []) {
            throw new \InvalidArgumentException('要挑几块');
        }
        $set = ['board' => $board, 'parts' => json_encode($parts)];
        if ($way !== null) {
            $set['way'] = json_encode($this->cleanWay($way), JSON_UNESCAPED_UNICODE);
        }
        $this->update($uid, $id, $set);
    }

    public function setWay(string $uid, string $id, array $way): void {
        $this->update($uid, $id, ['way' => json_encode($this->cleanWay($way), JSON_UNESCAPED_UNICODE)]);
    }

    public function setState(string $uid, string $id, string $state): void {
        if (!in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException('state');
        }
        $this->update($uid, $id, ['state' => $state]);
    }

    public function setKeepReason(string $uid, string $id, string $reason): void {
        $this->update($uid, $id, ['keep_reason' => mb_substr(trim($reason), 0, 2000)], false);
    }

    // ---- 连着的 Initium 对话 ----

    public function link(string $uid, string $spaceId, int $creationId, ?int $readingId): int {
        $q = $this->db->getQueryBuilder();
        $q->insert('cognos_norm_links')->values([
            'user_id' => $q->createNamedParameter($uid),
            'space_id' => $q->createNamedParameter($spaceId),
            'creation_id' => $q->createNamedParameter($creationId, IQueryBuilder::PARAM_INT),
            'reading_id' => $q->createNamedParameter($readingId, $readingId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
            'created_at' => $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        return (int)$q->getLastInsertId();
    }

    public function unlink(string $uid, int $linkId): void {
        $q = $this->db->getQueryBuilder();
        $q->update('cognos_norm_links')->set('undone_at', $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('id', $q->createNamedParameter($linkId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('user_id', $q->createNamedParameter($uid)))->executeStatement();
    }

    /** 撤回过的一条连线再接上（撤回「挪到别的空间」时，把原来那条接回去）。 */
    public function relink(string $uid, int $linkId): void {
        $q = $this->db->getQueryBuilder();
        $q->update('cognos_norm_links')->set('undone_at', $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($q->expr()->eq('id', $q->createNamedParameter($linkId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('user_id', $q->createNamedParameter($uid)))->executeStatement();
    }

    /** 这段 Initium 对话现在连在哪个空间上（最近的一条没撤回的）。 */
    public function linkedSpace(string $uid, int $creationId): ?array {
        $q = $this->db->getQueryBuilder();
        $r = $q->select('l.id', 'l.space_id')->from('cognos_norm_links', 'l')
            ->innerJoin('l', 'cognos_norm_projects', 'p', $q->expr()->eq('p.id', 'l.space_id'))
            ->where($q->expr()->eq('l.user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('l.creation_id', $q->createNamedParameter($creationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->isNull('l.undone_at'))
            ->andWhere($q->expr()->orX($q->expr()->isNull('p.state'), $q->expr()->eq('p.state', $q->createNamedParameter('active'))))
            ->orderBy('l.id', 'DESC')->setMaxResults(1)->executeQuery()->fetch();
        if ($r === false) {
            return null;
        }
        $space = $this->project($uid, (string)$r['space_id']);
        return $space === null ? null : ['link_id' => (int)$r['id'], 'space' => $space];
    }

    /** @return list<array{creation_id: int, title: string}> */
    public function links(string $uid, string $spaceId): array {
        $q = $this->db->getQueryBuilder();
        $rows = $q->select('l.creation_id', 'c.title')->from('cognos_norm_links', 'l')
            ->leftJoin('l', 'cognos_creations', 'c', $q->expr()->eq('c.id', 'l.creation_id'))
            ->where($q->expr()->eq('l.user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('l.space_id', $q->createNamedParameter($spaceId)))
            ->andWhere($q->expr()->isNull('l.undone_at'))
            ->orderBy('l.id', 'ASC')->executeQuery()->fetchAll();
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $cid = (int)$r['creation_id'];
            if (isset($seen[$cid])) {
                continue;
            }
            $seen[$cid] = true;
            $out[] = ['creation_id' => $cid, 'title' => (string)($r['title'] ?? '')];
        }
        return $out;
    }

    // ---- 发生了什么、只有你能定的 ----

    public function addEvent(string $uid, string $spaceId, string $kind, string $text, array $options = [], string $who = 'agent'): int {
        if (!in_array($kind, ['happened', 'decide'], true)) {
            throw new \InvalidArgumentException('kind 是 happened 或 decide');
        }
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('要写一句');
        }
        $options = array_values(array_filter(array_map(static fn($o): string => mb_substr(trim((string)$o), 0, 60), $options)));
        if ($kind === 'decide' && count($options) < 2) {
            $options = ['好', '先不'];
        }
        $q = $this->db->getQueryBuilder();
        $q->insert('cognos_norm_events')->values([
            'user_id' => $q->createNamedParameter($uid),
            'space_id' => $q->createNamedParameter($spaceId),
            'kind' => $q->createNamedParameter($kind),
            'who' => $q->createNamedParameter(mb_substr($who, 0, 64)),
            'text' => $q->createNamedParameter(mb_substr($text, 0, 4000)),
            'options' => $q->createNamedParameter($kind === 'decide' ? json_encode(array_slice($options, 0, 4), JSON_UNESCAPED_UNICODE) : null,
                $kind === 'decide' ? IQueryBuilder::PARAM_STR : IQueryBuilder::PARAM_NULL),
            'created_at' => $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $this->touch($uid, $spaceId);
        return (int)$q->getLastInsertId();
    }

    public function answer(string $uid, int $eventId, string $answer): array {
        $q = $this->db->getQueryBuilder();
        $r = $q->select('*')->from('cognos_norm_events')
            ->where($q->expr()->eq('id', $q->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->executeQuery()->fetch();
        if ($r === false || $r['kind'] !== 'decide') {
            throw new \InvalidArgumentException('没有这一件');
        }
        if ($r['answer'] !== null) {
            throw new \InvalidArgumentException('这一件已经定过了：' . $r['answer']);
        }
        $answer = mb_substr(trim($answer), 0, 200);
        if ($answer === '') {
            throw new \InvalidArgumentException('要选一个');
        }
        $u = $this->db->getQueryBuilder();
        $u->update('cognos_norm_events')->set('answer', $u->createNamedParameter($answer))
            ->set('answered_at', $u->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($u->expr()->eq('id', $u->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))->executeStatement();
        return ['space_id' => (string)$r['space_id'], 'text' => (string)$r['text'], 'answer' => $answer];
    }

    /** @return list<array> 新的在前 */
    public function events(string $uid, string $spaceId, ?string $kind = null, int $limit = 50): array {
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('cognos_norm_events')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId)));
        if ($kind !== null) {
            $q->andWhere($q->expr()->eq('kind', $q->createNamedParameter($kind)));
        }
        $rows = $q->orderBy('id', 'DESC')->setMaxResults($limit)->executeQuery()->fetchAll();
        return array_map(static fn(array $r): array => [
            'id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'who' => (string)($r['who'] ?? ''), 'text' => (string)$r['text'],
            'options' => json_decode((string)($r['options'] ?? '[]'), true) ?: [],
            'answer' => $r['answer'] !== null ? (string)$r['answer'] : null,
            'answered_at' => $r['answered_at'] !== null ? (int)$r['answered_at'] : null,
            'created_at' => (int)$r['created_at'],
        ], $rows);
    }

    /**
     * 你回来时：上次看过以后发生的（最多 12 条，按时间先后），和还等你定的第一件。看完就记成看过。
     * @return array{since: ?int, items: list<array>, decide: ?array}
     */
    public function arrive(string $uid, string $spaceId): array {
        $r = $this->fetch($uid, $spaceId);
        if ($r === null) {
            throw new \InvalidArgumentException('没有这个空间');
        }
        $since = $r['last_seen_at'] !== null ? (int)$r['last_seen_at'] : null;
        $items = array_values(array_filter($this->events($uid, $spaceId, 'happened', 40),
            static fn(array $e): bool => $since === null || $e['created_at'] > $since));
        $items = array_reverse(array_slice($items, 0, 12));
        $waiting = array_values(array_filter($this->events($uid, $spaceId, 'decide', 40), static fn(array $e): bool => $e['answer'] === null));
        $u = $this->db->getQueryBuilder();
        $u->update('cognos_norm_projects')->set('last_seen_at', $u->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($u->expr()->eq('id', $u->createNamedParameter($spaceId)))->executeStatement();
        return ['since' => $since, 'items' => $items, 'decide' => $waiting === [] ? null : end($waiting), 'waiting' => count($waiting)];
    }

    /** 给被叫进这个空间（或 Norm 自己的对话）的 agent：这里是什么、怎么工作、能用哪几个工具。没有就是空的。 */
    public function agentLines(string $uid, int $creationId): array {
        $space = $this->spaceByConversation($uid, $creationId);
        if ($space !== null) {
            $lines = ['这里是 Norm 里的一个空间「' . $space['title'] . '」，' . ($space['board'] !== null
                ? '样子是「' . $space['board'] . '」：' . self::BOARDS[$space['board']]['about']
                : '它没有套现成的样子，带着自己的做法。')];
            if ($space['summary'] !== '') {
                $lines[] = '它是什么（读者读出来的）：' . $space['summary'];
            }
            $way = $space['way'];
            foreach (['away' => '交互对象不在的时候你做什么', 'progress' => '怎样算往前走了', 'ask' => '什么时候找交互对象'] as $k => $label) {
                if (($way[$k] ?? '') !== '') {
                    $lines[] = $label . '：' . $way[$k];
                }
            }
            $lines[] = '它用这几块：' . implode('、', array_map(static fn(string $p): string => self::PARTS[$p] . '（' . $p . '）', $space['parts'])) . '。';
            foreach ($this->links($uid, $space['id']) as $l) {
                $lines[] = '连着 Initium 里的对话「' . $l['title'] . '」（creation ' . $l['creation_id'] . '）。';
            }
            foreach (array_slice(array_filter($this->events($uid, $space['id'], 'decide', 20), static fn(array $e): bool => $e['answer'] !== null), 0, 5) as $e) {
                $lines[] = '交互对象定过：「' . $e['text'] . '」→ ' . $e['answer'];
            }
            if (($space['keep_reason'] ?? '') !== '') {
                $lines[] = '读者上次觉得这里变了样，交互对象说还是原样，理由：' . $space['keep_reason'];
            }
        } elseif ($creationId === $this->conversationIdIfAny($uid)) {
            $lines = ['这里是 Norm 自己的对话。Norm 里现在有这些空间（read_space 时填 norm_space）：'];
            foreach ($this->projects($uid) as $s) {
                $lines[] = '- ' . $s['id'] . '：「' . $s['title'] . '」' . ($s['board'] ?? '带着自己的做法') . ($s['is_main'] ? '，主项目' : '');
            }
        } else {
            $linked = $this->linkedSpace($uid, $creationId);
            if ($linked === null) {
                return [];
            }
            $lines = ['这段对话已经放进 Norm 的「' . $linked['space']['title'] . '」。'];
        }
        $lines[] = 'read_space：读这个空间现在的样子和每一块的内容。write_part：改一块（先读再写，写的是改完的全部内容）。'
            . 'note_for_you：留给交互对象回来时看的一条，kind=happened 是你做了什么、发生了什么；kind=decide 是只有他能定的一件，给两三个选项。';
        $lines[] = '改理想、改目标、把一段理解改成新的、换这个空间的样子，这些不要直接改，用 note_for_you(kind=decide) 提出来，由交互对象定。';
        return $lines;
    }

    // ---- 内部 ----

    private function fetch(string $uid, string $id): ?array {
        $q = $this->db->getQueryBuilder();
        $r = $q->select('*')->from('cognos_norm_projects')
            ->where($q->expr()->eq('id', $q->createNamedParameter($id)))
            ->andWhere($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->executeQuery()->fetch();
        return $r === false ? null : $r;
    }

    private function row(array $r, bool $full): array {
        $board = $r['board'] ?? null;
        // 第一版建的项目没有 board：它们是项目
        if ($board === null && ($r['parts'] ?? null) === null) {
            $board = '项目';
        }
        $parts = json_decode((string)($r['parts'] ?? ''), true);
        if (!is_array($parts) || $parts === []) {
            $parts = $board !== null && isset(self::BOARDS[$board]) ? self::BOARDS[$board]['parts'] : ['stages', 'branches', 'relations'];
        }
        $out = [
            'id' => (string)$r['id'], 'title' => (string)$r['title'], 'is_main' => (bool)$r['is_main'],
            'board' => $board, 'parts' => array_values($parts),
            'way' => json_decode((string)($r['way'] ?? ''), true) ?: [],
            'summary' => (string)($r['summary'] ?? ''),
            'state' => (string)($r['state'] ?? 'active') ?: 'active',
            'conversation_id' => isset($r['conversation_id']) ? (int)$r['conversation_id'] : null,
            'last_seen_at' => isset($r['last_seen_at']) ? (int)$r['last_seen_at'] : null,
            'created_at' => (int)$r['created_at'],
            'updated_at' => (int)$r['updated_at'],
        ];
        if ($full) {
            $out += [
                'stages' => json_decode((string)($r['stages'] ?? '[]'), true) ?: [],
                'branches' => json_decode((string)($r['branches'] ?? '[]'), true) ?: [],
                'relations' => json_decode((string)($r['relations'] ?? '{}'), true) ?: ['nodes' => [], 'edges' => []],
                'compare' => json_decode((string)($r['compare'] ?? '[]'), true) ?: [],
                'doc' => (string)($r['doc'] ?? ''),
                'keep_reason' => (string)($r['keep_reason'] ?? ''),
            ];
        }
        return $out;
    }

    private function update(string $uid, string $id, array $set, bool $touch = true): void {
        $q = $this->db->getQueryBuilder();
        $q->update('cognos_norm_projects');
        foreach ($set as $col => $value) {
            $type = $value === null ? IQueryBuilder::PARAM_NULL : (is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
            $q->set($col, $q->createNamedParameter($value, $type));
        }
        if ($touch) {
            $q->set('updated_at', $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT));
        }
        $n = $q->where($q->expr()->eq('id', $q->createNamedParameter($id)))
            ->andWhere($q->expr()->eq('user_id', $q->createNamedParameter($uid)))->executeStatement();
        if ($n === 0 && $this->fetch($uid, $id) === null) {
            throw new \InvalidArgumentException('没有这个空间');
        }
    }

    private function touch(string $uid, string $id): void {
        $q = $this->db->getQueryBuilder();
        $q->update('cognos_norm_projects')->set('updated_at', $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('id', $q->createNamedParameter($id)))
            ->andWhere($q->expr()->eq('user_id', $q->createNamedParameter($uid)))->executeStatement();
    }

    private function countEvents(string $uid, string $spaceId, string $kind, bool $open): int {
        $q = $this->db->getQueryBuilder();
        $q->select($q->func()->count('*'))->from('cognos_norm_events')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId)))
            ->andWhere($q->expr()->eq('kind', $q->createNamedParameter($kind)));
        if ($open) {
            $q->andWhere($q->expr()->isNull('answer'));
        }
        return (int)$q->executeQuery()->fetchOne();
    }

    private function countSince(string $uid, string $spaceId, ?int $since): int {
        $q = $this->db->getQueryBuilder();
        $q->select($q->func()->count('*'))->from('cognos_norm_events')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId)))
            ->andWhere($q->expr()->eq('kind', $q->createNamedParameter('happened')));
        if ($since !== null) {
            $q->andWhere($q->expr()->gt('created_at', $q->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
        }
        return (int)$q->executeQuery()->fetchOne();
    }

    private function cleanParts(array $parts): array {
        return array_values(array_unique(array_filter(array_map('strval', $parts), static fn(string $p): bool => isset(self::PARTS[$p]))));
    }

    private function cleanWay(array $way): array {
        $out = [];
        foreach (['away', 'progress', 'ask'] as $k) {
            $out[$k] = mb_substr(trim((string)($way[$k] ?? '')), 0, 1000);
        }
        return $out;
    }
}
