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
 *
 * 每个空间还有一份自述，是这个空间的主体（在这里说话、读东西的 agent）写给自己的：它在维持什么、怎样算往前走、
 * 它变过什么、它放不下什么。自述只由它自己写（重读自己的经历时、认领 Initium 的一段时、在对话里），一版一版追加，
 * 旧的不改不删，存在 cognos_norm_events 里（kind=self，options 里是这一版改了什么、为什么、什么没变、从哪来）。
 * 它每次说话、读新东西之前先读最新的一版：它的目的从它自己的经历里来，不由别人从外面写进去。
 *
 * 还有它自己的历史和总观，也是它读自己全部的原话以后写的（ReaderService::readSpace），同样存在 cognos_norm_events 里：
 * - 历史的条目（kind=hist_entry）：一处经历，那时在做什么、信什么、转在哪，指着几句原话（refs）和一字不差摘的几句（quotes）。只追加。
 * - 历史的分段（kind=hist_stages）：每读一次存一版整的：哪几段，每段那时在做什么、什么让它转了、转完什么没变、放下了哪些路，
 *   各有哪几条。已经转过的段不悄悄改：要改得写明为什么（corrections），没写为什么的不改。还没转的那段（现在这一段）可以接着写。
 * - 总观（kind=overview）：每次读完重写一版：它现在是什么、手上几条线各停在哪、等什么、什么时候值得回来、哪几件只有你能定。旧的都留着。
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
    /** 自述最长多少字（写给自己看的，不是总结；读者提示里要的是 700 字以内，这里留宽） */
    public const SELF_MAX = 3000;
    /** 自述在发生记录里的署名 */
    public const SELF_WHO = '它自己';
    public const HISTORY_ENTRY = 'hist_entry';
    public const HISTORY_STAGES = 'hist_stages';
    public const OVERVIEW = 'overview';
    /** 交互对象纠正了 agent 的一次：他那句话、agent 前面那条回答、漏在哪 */
    public const CORRECTION = 'correction';
    /**
     * 漏的两股力（2026-11-09 从小林一年多的 19 条纠正里看出来的，见 docs/纠正标本/AI漏在哪-2026-11-09.md）。
     * 收：想尽快有一个做完了的东西。窄：只在已经给到手里的东西里转。
     */
    public const PULLS = [
        '收' => '想尽快有一个做完了的东西：换成好做的题（挑能建的、能测的、改动小的，把大概念收成机制，去验证手段，换个名字），步子太小，跳到结论，或者把问题原样推回给他',
        '窄' => '只在已经给到手里的东西里转：答字面、答旁边的题，猜了意图就动手或交回去，不看他已有的材料，只搜他说过的词，不往外找',
    ];
    /** 这几种存在同一张表里，但不是「发生了什么」：events() 不给它们 */
    private const NOT_EVENTS = ['self', self::HISTORY_ENTRY, self::HISTORY_STAGES, self::OVERVIEW, self::CORRECTION];
    /** 历史条目的几种 */
    public const ENTRY_KINDS = ['做了', '转了', '放下', '定了', '信'];

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
        if ($r === null) {
            return null;
        }
        $out = $this->row($r, true);
        $out['self_history'] = $this->selfHistory($uid, $id, 12);
        $out['self'] = $out['self_history'][0] ?? null;
        $out['overview'] = $this->overview($uid, $id);
        $out['history_meta'] = $this->historyMeta($uid, $id);
        $out['corrections'] = $this->corrections($uid, $id, 60);
        return $out;
    }

    // ---- 纠正：交互对象说 agent 上一条没走好的那几次 ----

    /**
     * 记下一次纠正（只追加）。同一句话（said_id）记过就不再记，返回原来那条。
     * @param array{pull: string, how: string, said: string, said_id?: int, said_at?: int, before?: string, before_id?: int, before_who?: string, conversation?: int, title?: string, source?: string, who?: string} $c
     * @return array{id: int, new: bool}
     */
    public function addCorrection(string $uid, string $spaceId, array $c): array {
        if ($this->fetch($uid, $spaceId) === null) {
            throw new \InvalidArgumentException('没有这个空间');
        }
        $pull = trim((string)($c['pull'] ?? ''));
        if (!isset(self::PULLS[$pull])) {
            throw new \InvalidArgumentException('pull 是「收」或「窄」');
        }
        $how = mb_substr(trim((string)($c['how'] ?? '')), 0, 600);
        if ($how === '') {
            throw new \InvalidArgumentException('要写一句：你上一条是怎样' . $pull . '的');
        }
        $said = trim((string)($c['said'] ?? ''));
        if ($said === '') {
            throw new \InvalidArgumentException('找不到他纠正你的那句话');
        }
        $saidId = (int)($c['said_id'] ?? 0);
        if ($saidId > 0) {
            foreach ($this->corrections($uid, $spaceId, 200) as $old) {
                if ($old['said_id'] === $saidId) {
                    return ['id' => $old['id'], 'new' => false];
                }
            }
        }
        $q = $this->db->getQueryBuilder();
        $q->insert('cognos_norm_events')->values([
            'user_id' => $q->createNamedParameter($uid),
            'space_id' => $q->createNamedParameter($spaceId),
            'kind' => $q->createNamedParameter(self::CORRECTION),
            'who' => $q->createNamedParameter(mb_substr((string)($c['who'] ?? 'agent'), 0, 64)),
            'text' => $q->createNamedParameter($how),
            'options' => $q->createNamedParameter(json_encode([
                'pull' => $pull,
                // 他的话一个字不截（表里这一列是长文本）；前面那条回答留开头一段，够看出它当时怎么走的
                'said' => $said, 'said_id' => $saidId, 'said_at' => (int)($c['said_at'] ?? 0),
                'before' => mb_substr(trim((string)($c['before'] ?? '')), 0, 2400), 'before_id' => (int)($c['before_id'] ?? 0),
                'before_who' => mb_substr((string)($c['before_who'] ?? ''), 0, 64),
                'conversation' => (int)($c['conversation'] ?? 0), 'title' => mb_substr((string)($c['title'] ?? ''), 0, 200),
                'source' => (string)($c['source'] ?? 'agent'),
            ], JSON_UNESCAPED_UNICODE)),
            'created_at' => $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $this->touch($uid, $spaceId);
        return ['id' => (int)$q->getLastInsertId(), 'new' => true];
    }

    /**
     * 这个空间记下的纠正，新的在前（按他说那句话的时间；种进来的标本用原来的时间）。不给 spaceId 是这个账号所有空间的。
     * @return list<array{id: int, space_id: string, pull: string, how: string, said: string, said_id: int, said_at: int, before: string, before_id: int, before_who: string, conversation: int, title: string, source: string, who: string, created_at: int}>
     */
    public function corrections(string $uid, ?string $spaceId, int $limit = 60): array {
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('cognos_norm_events')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('kind', $q->createNamedParameter(self::CORRECTION)));
        if ($spaceId !== null) {
            $q->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId)));
        }
        $rows = $q->orderBy('id', 'DESC')->setMaxResults(max(1, $limit))->executeQuery()->fetchAll();
        $out = array_map(static function (array $r): array {
            $m = json_decode((string)($r['options'] ?? ''), true);
            $m = is_array($m) ? $m : [];
            return [
                'id' => (int)$r['id'], 'space_id' => (string)$r['space_id'], 'pull' => (string)($m['pull'] ?? ''), 'how' => (string)$r['text'],
                'said' => (string)($m['said'] ?? ''), 'said_id' => (int)($m['said_id'] ?? 0), 'said_at' => (int)($m['said_at'] ?? 0) ?: (int)$r['created_at'],
                'before' => (string)($m['before'] ?? ''), 'before_id' => (int)($m['before_id'] ?? 0), 'before_who' => (string)($m['before_who'] ?? ''),
                'conversation' => (int)($m['conversation'] ?? 0), 'title' => (string)($m['title'] ?? ''),
                'source' => (string)($m['source'] ?? 'agent'), 'who' => (string)($r['who'] ?? ''), 'created_at' => (int)$r['created_at'],
            ];
        }, $rows);
        usort($out, static fn(array $a, array $b): int => [$b['said_at'], $b['id']] <=> [$a['said_at'], $a['id']]);
        return $out;
    }

    /**
     * 给被叫进这个空间的 agent：它以前在这里（和别的空间里）被纠正过的几次，原样的一段，不是一条规则。
     * 这里的最多 5 次，别处的最多 3 次；每次：它当时怎么说的开头、他怎么说、它自己说漏在哪。
     */
    public function correctionLines(string $uid, string $spaceId, ?\DateTimeZone $tz = null): string {
        $here = array_slice($this->corrections($uid, $spaceId, 5), 0, 5);
        // 别处的只拿还在 Norm 里的空间（撤回的、「验证：」开头的检查空间不算）
        $q = $this->db->getQueryBuilder();
        $live = array_flip(array_map(static fn(array $r): string => (string)$r['id'], array_filter(
            $q->select('id', 'title')->from('cognos_norm_projects')
                ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
                ->andWhere($q->expr()->orX($q->expr()->isNull('state'), $q->expr()->eq('state', $q->createNamedParameter('active'))))
                ->executeQuery()->fetchAll(),
            static fn(array $r): bool => !str_starts_with((string)$r['title'], '验证：'))));
        $elsewhere = array_slice(array_values(array_filter($this->corrections($uid, null, 60),
            static fn(array $c): bool => $c['space_id'] !== $spaceId && isset($live[$c['space_id']]))), 0, 3);
        if ($here === [] && $elsewhere === []) {
            return '';
        }
        $tz ??= new \DateTimeZone('Asia/Shanghai');
        $one = static function (array $c) use ($tz): string {
            $when = (new \DateTimeImmutable('@' . $c['said_at']))->setTimezone($tz)->format('n/j');
            $before = $c['before'] !== '' ? '当时' . ($c['before_who'] !== '' ? $c['before_who'] : 'AI') . '说：「' . self::clip($c['before'], 220) . '」' : '';
            return '- ' . $when . ($c['title'] !== '' ? '（' . $c['title'] . '）' : '') . '，漏在「' . $c['pull'] . '」。' . $before
                . "\n  他说：「" . self::clip($c['said'], 400) . '」' . "\n  漏在哪：" . $c['how'];
        };
        $out = '下面是你以前被交互对象纠正过的几次，原样摘的。不是规则，是你自己当时怎么滑过去的：回答之前看看这次是不是又要往同一处滑。'
            . "\n两股力：收＝" . self::PULLS['收'] . "；窄＝" . self::PULLS['窄'] . '。';
        if ($here !== []) {
            $out .= "\n在这个空间里：\n" . implode("\n", array_map($one, $here));
        }
        if ($elsewhere !== []) {
            $out .= "\n在别的空间里：\n" . implode("\n", array_map($one, $elsewhere));
        }
        return $out;
    }

    private static function clip(string $text, int $max): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '……' : $text;
    }

    // ---- 自述：这个空间的主体写给自己的 ----

    /** 最新的一版自述；还没写过是 null。 */
    public function selfAccount(string $uid, string $spaceId): ?array {
        return $this->selfHistory($uid, $spaceId, 1)[0] ?? null;
    }

    /**
     * 自述的各版，新的在前。version 从 1 数起（第一次写的是第 1 版）。
     * @return list<array{id: int, version: int, text: string, changed: string, why: string, unchanged: string, source: string, reading_id: ?int, created_at: int}>
     */
    public function selfHistory(string $uid, string $spaceId, int $limit = 12): array {
        $total = $this->countEvents($uid, $spaceId, 'self', false);
        if ($total === 0) {
            return [];
        }
        $q = $this->db->getQueryBuilder();
        $rows = $q->select('*')->from('cognos_norm_events')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId)))
            ->andWhere($q->expr()->eq('kind', $q->createNamedParameter('self')))
            ->orderBy('id', 'DESC')->setMaxResults(max(1, $limit))->executeQuery()->fetchAll();
        $out = [];
        foreach ($rows as $i => $r) {
            $meta = json_decode((string)($r['options'] ?? ''), true);
            $meta = is_array($meta) ? $meta : [];
            $out[] = [
                'id' => (int)$r['id'], 'version' => $total - $i, 'text' => (string)$r['text'],
                'changed' => (string)($meta['changed'] ?? ''), 'why' => (string)($meta['why'] ?? ''),
                'unchanged' => (string)($meta['unchanged'] ?? ''), 'source' => (string)($meta['source'] ?? ''),
                'reading_id' => isset($meta['reading_id']) ? (int)$meta['reading_id'] : null,
                'created_at' => (int)$r['created_at'],
            ];
        }
        return $out;
    }

    /** 某一版自述（撤回时用来退回去）。 */
    public function selfVersion(string $uid, string $spaceId, int $eventId): ?array {
        foreach ($this->selfHistory($uid, $spaceId, 200) as $v) {
            if ($v['id'] === $eventId) {
                return $v;
            }
        }
        return null;
    }

    /**
     * 这个空间的主体写一版自述。只追加，旧的不改不删；和上一版一字不差时不写，返回上一版的 id。
     * 写了就在空间里记一条，你回来时看得到它改了自己什么。
     * @return array{id: int, version: int, new: bool}
     */
    public function writeSelf(string $uid, string $spaceId, string $text, string $changed, string $why, string $unchanged, string $source, ?int $readingId = null): array {
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('自述是空的');
        }
        if ($this->fetch($uid, $spaceId) === null) {
            throw new \InvalidArgumentException('没有这个空间');
        }
        $text = mb_substr($text, 0, self::SELF_MAX);
        $prev = $this->selfAccount($uid, $spaceId);
        if ($prev !== null && $prev['text'] === $text) {
            return ['id' => $prev['id'], 'version' => $prev['version'], 'new' => false];
        }
        $cut = static fn(string $v, int $n): string => mb_substr(trim($v), 0, $n);
        $meta = ['changed' => $cut($changed, 600), 'why' => $cut($why, 600), 'unchanged' => $cut($unchanged, 600),
            'source' => $cut($source, 200), 'reading_id' => $readingId];
        $q = $this->db->getQueryBuilder();
        $q->insert('cognos_norm_events')->values([
            'user_id' => $q->createNamedParameter($uid),
            'space_id' => $q->createNamedParameter($spaceId),
            'kind' => $q->createNamedParameter('self'),
            'who' => $q->createNamedParameter(self::SELF_WHO),
            'text' => $q->createNamedParameter($text),
            'options' => $q->createNamedParameter(json_encode($meta, JSON_UNESCAPED_UNICODE)),
            'created_at' => $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $id = (int)$q->getLastInsertId();
        $this->addEvent($uid, $spaceId, 'happened', ($prev === null ? '它写下了第一版自述'
            : '它改了自己的自述' . ($meta['changed'] !== '' ? '：' . $meta['changed'] : '')) . ($meta['source'] !== '' ? '（' . $meta['source'] . '）' : '。'), [], self::SELF_WHO);
        return ['id' => $id, 'version' => ($prev['version'] ?? 0) + 1, 'new' => true];
    }

    // ---- 历史和总观：它读自己全部的原话以后写的 ----

    /**
     * 记下这次读到的几条经历（只追加）。每条：key（这次读里的编号，比如 2.3）、kind、title、text、at（原话的时间）、
     * refs（指着的原话：src 是对话的 id，这里发生的事是 0）、quotes（一字不差摘的，已经对过原话）。
     * @param list<array{key: string, kind: string, title: string, text: string, at: int, refs: list<array>, quotes: list<array>, chunk?: int}> $entries
     * @return array<string, int> key => 这一条的 id
     */
    public function addHistoryEntries(string $uid, string $spaceId, array $entries, int $readingId): array {
        if ($this->fetch($uid, $spaceId) === null) {
            throw new \InvalidArgumentException('没有这个空间');
        }
        $ids = [];
        $now = time();
        foreach ($entries as $e) {
            $title = mb_substr(trim((string)($e['title'] ?? '')), 0, 120);
            $text = mb_substr(trim((string)($e['text'] ?? '')), 0, 4000);
            $q = $this->db->getQueryBuilder();
            $q->insert('cognos_norm_events')->values([
                'user_id' => $q->createNamedParameter($uid),
                'space_id' => $q->createNamedParameter($spaceId),
                'kind' => $q->createNamedParameter(self::HISTORY_ENTRY),
                'who' => $q->createNamedParameter(self::SELF_WHO),
                'text' => $q->createNamedParameter($text !== '' ? $text : ($title !== '' ? $title : '（没写）')),
                'options' => $q->createNamedParameter(json_encode([
                    'key' => (string)$e['key'], 'kind' => (string)($e['kind'] ?? ''), 'title' => $title, 'at' => (int)($e['at'] ?? 0),
                    'refs' => array_values($e['refs'] ?? []), 'quotes' => array_values($e['quotes'] ?? []),
                    'reading_id' => $readingId, 'chunk' => (int)($e['chunk'] ?? 0),
                ], JSON_UNESCAPED_UNICODE)),
                'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ])->executeStatement();
            $ids[(string)$e['key']] = (int)$q->getLastInsertId();
        }
        return $ids;
    }

    /** @return array<int, array> id => 历史的一条，按原话的时间排 */
    public function historyEntries(string $uid, string $spaceId): array {
        $q = $this->db->getQueryBuilder();
        $rows = $q->select('*')->from('cognos_norm_events')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId)))
            ->andWhere($q->expr()->eq('kind', $q->createNamedParameter(self::HISTORY_ENTRY)))
            ->orderBy('id', 'ASC')->executeQuery()->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $m = json_decode((string)($r['options'] ?? ''), true);
            $m = is_array($m) ? $m : [];
            $out[(int)$r['id']] = [
                'id' => (int)$r['id'], 'key' => (string)($m['key'] ?? ''), 'kind' => (string)($m['kind'] ?? ''),
                'title' => (string)($m['title'] ?? ''), 'text' => (string)$r['text'], 'at' => (int)($m['at'] ?? 0),
                'refs' => is_array($m['refs'] ?? null) ? $m['refs'] : [], 'quotes' => is_array($m['quotes'] ?? null) ? $m['quotes'] : [],
                'reading_id' => isset($m['reading_id']) ? (int)$m['reading_id'] : null, 'created_at' => (int)$r['created_at'],
            ];
        }
        uasort($out, static fn(array $a, array $b): int => [$a['at'], $a['id']] <=> [$b['at'], $b['id']]);
        return $out;
    }

    /**
     * 历史分段的最新一版（新的在前的第一版）；还没写过是 null。
     * @return ?array{id: int, version: int, stages: list<array>, skipped: list<int>, changed: string, notes: list<string>, reading_id: ?int, created_at: int}
     */
    public function stagesLatest(string $uid, string $spaceId): ?array {
        return $this->stageVersions($uid, $spaceId, 1)[0] ?? null;
    }

    /** 历史分段的各版，新的在前。 */
    public function stageVersions(string $uid, string $spaceId, int $limit = 10): array {
        return array_map(static function (array $v): array {
            $m = $v['meta'];
            return ['id' => $v['id'], 'version' => $v['version'], 'stages' => is_array($m['stages'] ?? null) ? $m['stages'] : [],
                'skipped' => array_values(array_map('intval', is_array($m['skipped'] ?? null) ? $m['skipped'] : [])),
                'changed' => (string)($m['changed'] ?? ''), 'notes' => is_array($m['notes'] ?? null) ? $m['notes'] : [],
                'reading_id' => isset($m['reading_id']) ? (int)$m['reading_id'] : null, 'created_at' => $v['created_at']];
        }, $this->versionsOf($uid, $spaceId, self::HISTORY_STAGES, $limit));
    }

    /** 存一版整的历史分段（只追加）。$text 是给 agent 读的那份。 */
    public function writeStages(string $uid, string $spaceId, array $stages, array $skipped, string $changed, array $notes, int $readingId, string $text): int {
        return $this->appendVersion($uid, $spaceId, self::HISTORY_STAGES, $text !== '' ? $text : '（还没有分段）', [
            'stages' => array_values($stages), 'skipped' => array_values(array_unique(array_map('intval', $skipped))),
            'changed' => mb_substr(trim($changed), 0, 1000), 'notes' => array_values($notes), 'reading_id' => $readingId,
        ]);
    }

    /** 最新的总观；还没写过是 null。 */
    public function overview(string $uid, string $spaceId): ?array {
        return $this->overviewVersions($uid, $spaceId, 1)[0] ?? null;
    }

    /** 总观的各版，新的在前。 */
    public function overviewVersions(string $uid, string $spaceId, int $limit = 10): array {
        return array_map(static function (array $v): array {
            $m = $v['meta'];
            return ['id' => $v['id'], 'version' => $v['version'], 'text' => $v['text'],
                'now' => (string)($m['now'] ?? ''), 'lines' => is_array($m['lines'] ?? null) ? $m['lines'] : [],
                'yours' => is_array($m['yours'] ?? null) ? $m['yours'] : [], 'next' => (string)($m['next'] ?? ''),
                'upto' => isset($m['upto']) ? (int)$m['upto'] : null, 'reading_id' => isset($m['reading_id']) ? (int)$m['reading_id'] : null,
                'created_at' => $v['created_at']];
        }, $this->versionsOf($uid, $spaceId, self::OVERVIEW, $limit));
    }

    /**
     * 写一版总观（只追加）：now、lines（name、at、waits、back、refs）、yours（what、why、refs）、next。refs 是历史条目的 id。
     * $upto 是读到的原话最晚到什么时候。
     */
    public function writeOverview(string $uid, string $spaceId, array $ov, int $readingId, ?int $upto): array {
        $s = static fn($v, int $n): string => mb_substr(trim(is_scalar($v) ? (string)$v : ''), 0, $n);
        // refs：历史里的条目，存 id 和它的标题、时间（条目只追加不改，所以存下来的标题一直对）
        $ids = static function ($v): array {
            $out = [];
            foreach (is_array($v) ? $v : [] as $r) {
                $id = is_array($r) ? (int)($r['id'] ?? 0) : (int)$r;
                if ($id > 0 && !isset($out[$id])) {
                    $out[$id] = ['id' => $id, 'title' => is_array($r) ? mb_substr(trim((string)($r['title'] ?? '')), 0, 120) : '', 'at' => is_array($r) ? (int)($r['at'] ?? 0) : 0];
                }
            }
            return array_values($out);
        };
        $lines = [];
        foreach (is_array($ov['lines'] ?? null) ? $ov['lines'] : [] as $l) {
            if (is_array($l) && $s($l['name'] ?? '', 200) !== '') {
                $lines[] = ['name' => $s($l['name'], 200), 'at' => $s($l['at'] ?? '', 1000), 'waits' => $s($l['waits'] ?? '', 1000),
                    'back' => $s($l['back'] ?? '', 1000), 'refs' => $ids($l['refs'] ?? [])];
            }
        }
        $yours = [];
        foreach (is_array($ov['yours'] ?? null) ? $ov['yours'] : [] as $y) {
            if (is_array($y) && $s($y['what'] ?? '', 1000) !== '') {
                $yours[] = ['what' => $s($y['what'], 1000), 'why' => $s($y['why'] ?? '', 1000), 'refs' => $ids($y['refs'] ?? [])];
            }
        }
        $meta = ['now' => $s($ov['now'] ?? '', 3000), 'lines' => array_slice($lines, 0, 12), 'yours' => array_slice($yours, 0, 8),
            'next' => $s($ov['next'] ?? '', 2000), 'upto' => $upto, 'reading_id' => $readingId];
        if ($meta['now'] === '' && $lines === []) {
            throw new \InvalidArgumentException('总观是空的');
        }
        $text = $meta['now'];
        foreach ($meta['lines'] as $l) {
            $text .= "\n- " . $l['name'] . '：停在 ' . $l['at'] . ($l['waits'] !== '' ? '；等 ' . $l['waits'] : '') . ($l['back'] !== '' ? '；什么时候值得回来：' . $l['back'] : '');
        }
        if ($meta['yours'] !== []) {
            $text .= "\n只有交互对象能定的：" . implode('；', array_map(static fn(array $y): string => $y['what'], $meta['yours']));
        }
        if ($meta['next'] !== '') {
            $text .= "\n要是现在接着干：" . $meta['next'];
        }
        $id = $this->appendVersion($uid, $spaceId, self::OVERVIEW, $text, $meta);
        return ['id' => $id, 'version' => count($this->versionsOf($uid, $spaceId, self::OVERVIEW, 1000))];
    }

    /** 页面上要的几个数：分段第几版、几段、几条、几条还没归段、读到哪天。 */
    public function historyMeta(string $uid, string $spaceId): ?array {
        $latest = $this->stagesLatest($uid, $spaceId);
        $count = $this->countEvents($uid, $spaceId, self::HISTORY_ENTRY, false);
        if ($latest === null && $count === 0) {
            return null;
        }
        $placed = 0;
        $to = null;
        foreach ($latest['stages'] ?? [] as $st) {
            $placed += count($st['entries'] ?? []);
            $to = max($to ?? 0, (int)($st['to'] ?? 0)) ?: $to;
        }
        return ['version' => $latest['version'] ?? 0, 'stages' => count($latest['stages'] ?? []), 'entries' => $count,
            'unplaced' => max(0, $count - $placed - count($latest['skipped'] ?? [])), 'to' => $to,
            'updated_at' => $latest['created_at'] ?? null];
    }

    /**
     * 页面「历史」那一栏要的全部：最新一版分段、所有条目、还没归段的、没放进历史的、各段指着的对话叫什么。
     */
    public function history(string $uid, string $spaceId): array {
        $versions = $this->stageVersions($uid, $spaceId, 10);
        $latest = $versions[0] ?? null;
        $entries = $this->historyEntries($uid, $spaceId);
        $placed = [];
        foreach ($latest['stages'] ?? [] as $st) {
            foreach ($st['entries'] ?? [] as $eid) {
                $placed[(int)$eid] = true;
            }
        }
        $skipped = array_flip($latest['skipped'] ?? []);
        $unplaced = array_values(array_filter(array_keys($entries), static fn(int $id): bool => !isset($placed[$id]) && !isset($skipped[$id])));
        // 各条指着的对话现在叫什么（题目可能改过，所以读的时候查）
        $cids = [];
        foreach ($entries as $e) {
            foreach ($e['refs'] as $r) {
                if ((int)($r['src'] ?? 0) > 0) {
                    $cids[(int)$r['src']] = true;
                }
            }
        }
        $sources = [];
        if ($cids !== []) {
            $q = $this->db->getQueryBuilder();
            foreach ($q->select('id', 'title')->from('cognos_creations')
                ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
                ->andWhere($q->expr()->in('id', $q->createNamedParameter(array_keys($cids), IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeQuery()->fetchAll() as $r) {
                $sources[(int)$r['id']] = (string)$r['title'];
            }
        }
        $own = $this->fetch($uid, $spaceId)['conversation_id'] ?? null;
        return [
            'version' => $latest['version'] ?? 0, 'stages' => $latest['stages'] ?? [], 'changed' => $latest['changed'] ?? '',
            'notes' => $latest['notes'] ?? [], 'updated_at' => $latest['created_at'] ?? null,
            'entries' => (object)$entries, 'unplaced' => $unplaced, 'skipped' => array_values(array_filter($latest['skipped'] ?? [], static fn(int $id): bool => isset($entries[$id]))),
            'sources' => (object)$sources, 'own_conversation' => $own !== null ? (int)$own : null,
            'versions' => array_map(static fn(array $v): array => ['version' => $v['version'], 'changed' => $v['changed'], 'created_at' => $v['created_at'], 'stages' => count($v['stages'])], $versions),
            'overviews' => array_map(static fn(array $v): array => ['version' => $v['version'], 'now' => $v['now'], 'text' => $v['text'], 'created_at' => $v['created_at']],
                array_slice($this->overviewVersions($uid, $spaceId, 10), 1)),
        ];
    }

    /** @return list<array{id: int, version: int, text: string, meta: array, created_at: int}> 某一种只追加的版本，新的在前；version 从 1 数起 */
    private function versionsOf(string $uid, string $spaceId, string $kind, int $limit): array {
        $total = $this->countEvents($uid, $spaceId, $kind, false);
        if ($total === 0) {
            return [];
        }
        $q = $this->db->getQueryBuilder();
        $rows = $q->select('*')->from('cognos_norm_events')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId)))
            ->andWhere($q->expr()->eq('kind', $q->createNamedParameter($kind)))
            ->orderBy('id', 'DESC')->setMaxResults(max(1, $limit))->executeQuery()->fetchAll();
        $out = [];
        foreach ($rows as $i => $r) {
            $m = json_decode((string)($r['options'] ?? ''), true);
            $out[] = ['id' => (int)$r['id'], 'version' => $total - $i, 'text' => (string)$r['text'], 'meta' => is_array($m) ? $m : [], 'created_at' => (int)$r['created_at']];
        }
        return $out;
    }

    private function appendVersion(string $uid, string $spaceId, string $kind, string $text, array $meta): int {
        if ($this->fetch($uid, $spaceId) === null) {
            throw new \InvalidArgumentException('没有这个空间');
        }
        $q = $this->db->getQueryBuilder();
        $q->insert('cognos_norm_events')->values([
            'user_id' => $q->createNamedParameter($uid),
            'space_id' => $q->createNamedParameter($spaceId),
            'kind' => $q->createNamedParameter($kind),
            'who' => $q->createNamedParameter(self::SELF_WHO),
            'text' => $q->createNamedParameter($text),
            'options' => $q->createNamedParameter(json_encode($meta, JSON_UNESCAPED_UNICODE)),
            'created_at' => $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        return (int)$q->getLastInsertId();
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

    /** @return list<array{creation_id: int, title: string, created_at: int}> */
    public function links(string $uid, string $spaceId): array {
        $q = $this->db->getQueryBuilder();
        $rows = $q->select('l.creation_id', 'l.created_at', 'c.title')->from('cognos_norm_links', 'l')
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
            $out[] = ['creation_id' => $cid, 'title' => (string)($r['title'] ?? ''), 'created_at' => (int)($r['created_at'] ?? 0)];
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

    /** @return list<array> 新的在前。不给 kind 时是发生的和等你定的；自述、历史、总观的各版不在这里（见 selfHistory、history、overview）。 */
    public function events(string $uid, string $spaceId, ?string $kind = null, int $limit = 50): array {
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('cognos_norm_events')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId)));
        if ($kind !== null) {
            $q->andWhere($q->expr()->eq('kind', $q->createNamedParameter($kind)));
        } else {
            $q->andWhere($q->expr()->notIn('kind', $q->createNamedParameter(self::NOT_EVENTS, IQueryBuilder::PARAM_STR_ARRAY)));
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
            // 连着几十段时全列出来太长：只列最近几段，别的在 read_space 的目录里
            $links = array_reverse($this->links($uid, $space['id']));
            if ($links !== []) {
                $lines[] = '这里连着 ' . count($links) . ' 段 Initium 对话。最近放进来的：'
                    . implode('；', array_map(static fn(array $l): string => '「' . $l['title'] . '」（linked_id=' . $l['creation_id'] . '）', array_slice($links, 0, 5)))
                    . '。原话你自己读得到：read_space 返回里有目录和最近一段的原话，别的段带 linked_id 读，读不完按 next_from 接着读。要接着做，先把原话读了再动手，不要只凭一句转述。';
            }
            foreach (array_slice(array_filter($this->events($uid, $space['id'], 'decide', 20), static fn(array $e): bool => $e['answer'] !== null), 0, 5) as $e) {
                $lines[] = '交互对象定过：「' . $e['text'] . '」→ ' . $e['answer'];
            }
            if (($space['keep_reason'] ?? '') !== '') {
                $lines[] = '上次重读时觉得这里变了样，交互对象说还是原样，理由：' . $space['keep_reason'];
            }
            $self = $this->selfAccount($uid, $space['id']);
            if ($self !== null) {
                $lines[] = '下面是你（这个空间的主体）自己写的自述，第 ' . $self['version'] . ' 版，' . date('n/j', $self['created_at'])
                    . ' 写的。这是你，不是别人给你的设定；回话、动手之前先照它想一遍：' . "\n" . $self['text'];
                $lines[] = '这段对话要是改变了你在维持的东西、你怎样算往前走、或你放不下的事，就自己改自述：write_part(part=self)，'
                    . 'content 给 {"text": 改完的整份自述, "changed": 改了什么, "why": 为什么, "unchanged": 这次什么没变}。只在真的变了时改，旧的每一版都留着。';
            }
            $hist = $this->historyLines($uid, $space['id']);
            if ($hist !== '') {
                $lines[] = $hist;
            }
            $corr = $this->correctionLines($uid, $space['id']);
            if ($corr !== '') {
                $lines[] = $corr;
            }
            if ($self === null) {
                $lines[] = '你（这个空间的主体）还没有自述。自述是你写给自己的：你在维持什么、怎样算往前走了、怎样算在原地、你变过什么和为什么、你最放不下的一件，'
                    . '第一人称，700 字以内。交互对象可以在空间页上让你重读自己的经历写第一版；你读过这里的经历以后也可以自己写：'
                    . 'write_part(part=self)，content 给 {"text": …, "changed": "第一次写", "why": "第一次写", "unchanged": "第一次写"}。';
            }
        } elseif ($creationId === $this->conversationIdIfAny($uid)) {
            $lines = ['这里是 Norm 自己的对话。Norm 里现在有这些空间（read_space 时填 norm_space）：'];
            foreach ($this->projects($uid) as $s) {
                $lines[] = '- ' . $s['id'] . '：「' . $s['title'] . '」' . (!empty($s['is_self']) ? 'Cognos 自己（内观）' : (($s['board'] ?? '带着自己的做法') . ($s['is_main'] ? '，主项目' : '')));
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
        $lines[] = '交互对象这句话要是在纠正你（或之前的 AI）上一条：说没接住他的意思、没抓住重点、太小、太一般、千篇一律、换了题、没读他的东西、为什么这么想……'
            . '先用 note_correction 记下来，再回答。pull 选一个：收（' . self::PULLS['收'] . '）；窄（' . self::PULLS['窄'] . '）。'
            . 'how 用一两句说清上一条具体是怎样收或怎样窄的，说事实，不要认错的客套话。只是补充、提新要求、问问题的，不算纠正，不要记。';
        $lines[] = '改理想、改目标、把一段理解改成新的、换这个空间的样子，这些不要直接改，用 note_for_you(kind=decide) 提出来，由交互对象定。'
            . '你的自述不在这里面：它是你自己的，你自己改。自述里你在维持的东西要是和交互对象定下的方向不一样，在回答里直说，不要悄悄照自己的来。';
        $lines[] = 'kind=decide 只用在真要他拿主意的地方：方向、取舍、他的口味和范围。你自己该怎么工作、该读什么、工具够不够，这些不要问他：能做就直接做，做不到就在回答里说清卡在哪。';
        $lines[] = '跟交互对象说话时用平常的话，不要提 linked_id、creation、#编号这类内部编号，他看不懂；说对话的题目。';
        return $lines;
    }

    /**
     * 给这个空间的 agent：它自己写的历史（各段一行）和最新的总观。没写过是空字符串。
     * 历史和总观里的「你」是交互对象、「我」是它自己，和这里跟 agent 说话的口吻相反，所以先说一句。
     */
    public function historyLines(string $uid, string $spaceId, ?\DateTimeZone $tz = null): string {
        $st = $this->stagesLatest($uid, $spaceId);
        $ov = $this->overview($uid, $spaceId);
        if (($st === null || $st['stages'] === []) && $ov === null) {
            return '';
        }
        $tz ??= new \DateTimeZone('Asia/Shanghai');
        $out = '下面是你自己读完这里全部的原话以后写的' . ($st !== null && $st['stages'] !== [] ? '历史' : '') . ($ov !== null ? ($st !== null && $st['stages'] !== [] ? '和' : '') . '总观' : '')
            . '（里面的「你」指交互对象，「我」是你自己）。接着干活之前先照它想一遍；它说某条线停在哪、等什么，就从那里接，不要从头再来。';
        if ($st !== null && $st['stages'] !== []) {
            $out .= "\n历史各段（按时间）：\n" . implode("\n", array_map(static fn(array $s): string => self::stageLine(['key' => ''] + $s, $tz, 160), $st['stages']));
        }
        if ($ov !== null) {
            $out .= "\n最新的总观（第 " . $ov['version'] . ' 版，' . (new \DateTimeImmutable('@' . $ov['created_at']))->setTimezone($tz)->format('n/j') . " 写的）：\n" . $ov['text'];
        }
        return $out;
    }

    /** 历史的一段，一行：「标题」（起–止）：那时在做什么。转：什么让它转了。 */
    public static function stageLine(array $s, \DateTimeZone $tz, int $max = 0): string {
        $day = static fn($t): string => $t ? (new \DateTimeImmutable('@' . (int)$t))->setTimezone($tz)->format('n/j') : '?';
        $span = $day($s['from'] ?? null) . (($s['to'] ?? null) && $day($s['to']) !== $day($s['from'] ?? null) ? '–' . $day($s['to']) : '');
        $text = (string)($s['text'] ?? '');
        if ($max > 0 && mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max) . '……';
        }
        $turn = trim((string)($s['turn'] ?? ''));
        return '- ' . ($s['key'] ?? '') . '「' . ($s['title'] ?? '') . '」（' . $span . '）：' . $text
            . ($turn !== '' ? ' 转：' . $turn . (trim((string)($s['kept'] ?? '')) !== '' ? '；没变的：' . $s['kept'] : '') : '（现在这一段，还没转）');
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
        $selfCreationId = (int)$this->config->getAppValue('cognos', 'self_creation_id', '0');
        $convId = isset($r['conversation_id']) ? (int)$r['conversation_id'] : null;
        $out = [
            'id' => (string)$r['id'], 'title' => (string)$r['title'], 'is_main' => (bool)$r['is_main'],
            'is_self' => $selfCreationId > 0 && $convId === $selfCreationId,
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
