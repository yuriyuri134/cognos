<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * 读者：读一段东西，说清楚它是什么，好让 Cognos 知道把它放到哪里、它会不会改变谁。
 *
 * - Initium 的一段对话（kind=initium）：一位不知道 Cognos 是什么的读者先给它定性（它是什么、哪部分平常的做法接不住）；
 *   同时请 Norm 里现有的几个空间各自以自己的身份读它（带着它们自己写的自述），说这是不是自己的：认领。
 *   比较的那位看这些读法（不看原文）决定放法：有空间认领，先放进认领的空间；没人认领才新开一个，或者先留在原处。
 *   认领的空间接下以后，照它读的时候说的改自述（追加一版）。放了可以撤回（undo），自述也跟着退回去（再追加一版，旧的都留着）。
 * - Norm 里的一个空间（kind=drift，原来叫回头读）：空间自己读自己的经历（连进来的 Initium 对话、这里的对话、这里发生过的事），
 *   全文、不截，写自己的历史和总观（见 NormService 的「历史和总观」）。还没读过的原话按时间排、切成一块一块，
 *   几位读者同时各读一块，每块写下几条值得进历史的经历，每条指着原话（[m123]）、摘一字不差的几句；
 *   最后合起来的那位（桥上的「比较」）把条目分进段里，接着上一版历史往下写，已经转过的段要改得写明为什么。
 *   一次最多读 5 块；读完这次以后要是还有没读的，这次只写历史，下次接着读；全读完的那次再重写总观、写新一版自述，
 *   看现在的样子还合不合身（不合身时给几种接着活的办法，由交互对象挑：choose）。以后再读，只读新的往后接。
 *   读过哪些原话记在每次读的 result.covered 里。上次读到的条目没合进段（合起来那步没做成）时，可以只合一次（mode=merge），
 *   这时合起来的那位是唯一的读者，比较的那位是旁观的：只指出新一版自述里空的说法。
 *   （11/08 以前是一次读截过的 12 万字、AI 的回答只留开头，写自述，旁观的那位看；那种读的结果照旧能收。）
 *
 * 每位读者是谁（定性的、认领的哪个空间、空间自己）开始时记在 result.plan 里，收到时按它来认。
 * 读者在本机经桥跑（claude_bridge.py 的 /read，最多 5 位），没有工具，工作目录是临时目录。
 * 每次读记一次运行（RunService，purpose=read，主体是「读者」），结果按 result_id 只收一次。
 */
final class ReaderService {
    public const SUBJECT = '读者';
    /** 一段对话少于这么多条，不自动读 */
    private const AUTO_MIN = 6;
    /** 上次读完以后又多了这么多条，再自动读一次 */
    private const AUTO_NEW = 6;
    private const DRIFT_MIN = 10;
    private const DRIFT_GAP = 43200;
    /** 读者在桥上最多做多久，过了就当没读完，可以再读 */
    private const STALE = 2700;
    private const VIEW_MAX = 120000;
    /** 空间读自己的经历时，一块大约多少字；occ config:app:set cognos reader_chunk --value=N 可改（2 万到 15 万） */
    private const CHUNK = 60000;
    /** 一次最多读几块（桥上一次最多 5 位读者） */
    private const CHUNKS_PER_RUN = 5;
    /** 一句原话太长（贴进来的整份文档、日志）时只留头尾这么多字 */
    private const MSG_MAX = 20000;
    /** 历史一段里，已经转过以后只能写明为什么才改的几项 */
    private const FIELD_NAMES = ['title' => '标题', 'text' => '那时的说法', 'turn' => '怎么转的', 'kept' => '没变的'];
    private const FIELD_ALIASES = ['标题' => 'title', '说法' => 'text', '那时的说法' => 'text', '那时' => 'text', '转' => 'turn', '怎么转的' => 'turn', '没变的' => 'kept'];
    /** 默认请几个空间来认领；occ config:app:set cognos reader_claimers --value=N 可改（0 到 4，桥上一次最多 5 位读者） */
    private const CLAIMERS = 3;

    private const READER_SYSTEM = <<<'TXT'
你会读到一段文字，是一个人和 AI 的对话记录。
你不知道它来自什么系统，也不需要知道。不要给这个人建议，不要评价这个人，不要接着对话往下说。

你读它，是为了说清楚它到底是一种什么东西，好让后面的人知道该怎么安放它：放进一种常见的做法里，还是得给它另造一种做法。
所以别写总结。总结会把它最不寻常的地方磨平，而要的恰恰是那个地方。

回答五件事：
1. 这段到底是什么：不是它说自己是什么，而是读下来它实际在做的是什么。说出它和别的同类对话不一样的地方。
2. 它在往哪里走：照现在这样下去，会走到哪。
3. 它要接着活下去，得一再发生什么样的动作：两到四条，每条一句。说动作（比如反复试、几样东西并排比、记账、练习、换一个角度重看），不要说「需要更多信息」这类话。
4. 它有没有哪一部分，是平常记笔记、做项目、学东西这些做法接不住的：有就说是哪部分、为什么接不住；真的都接得住，就照实说接得住，不要硬找。
5. 从原文里原样摘出最多三处支撑你这个读法的话，最好包括第 4 条说的那部分。

只输出一个 JSON 对象，不要别的字：
{"what": "…", "where": "…", "needs": ["…"], "misfit": "…", "quotes": ["…"]}
misfit 写第 4 条；都接得住就写空字符串。用中文。每项一两句，说具体的，不说套话。字符串里不要用英文双引号，要引用就用「」。
TXT;

    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
        private IClientService $http,
        private RunService $runs,
        private CreationSpaceService $creations,
        private NormService $norm,
        private LoggerInterface $logger,
    ) {}

    // ---- 什么时候读 ----

    /**
     * 读一段 Initium 对话：一位定性的读者，加上现有的几个空间各自来认领。$force 为假时只在够长、上次读完以后又多了不少时才读。
     * @return array{started: bool, why?: string, reading?: array}
     */
    public function readInitium(string $uid, int $creationId, bool $force): array {
        $creation = $this->creations->get($creationId, $uid);
        if ($creation === null) {
            throw new \InvalidArgumentException('没有这段对话');
        }
        if (in_array($creationId, $this->norm->hiddenConversationIds($uid), true)) {
            return ['started' => false, 'why' => '这是 Norm 里的对话'];
        }
        $messages = $this->creations->getMessages($creationId, $uid);
        $last = $this->latest($uid, 'initium', $creationId, null);
        $why = $this->notNow($messages, $last, $force, self::AUTO_MIN, self::AUTO_NEW, 0);
        if ($why !== null) {
            return ['started' => false, 'why' => $why];
        }
        $text = $this->fullText($messages);
        $readers = [['label' => '定性', 'role' => 'reader', 'system' => self::READER_SYSTEM, 'prompt' => $text]];
        foreach ($this->claimers($uid, $creationId) as $space) {
            $readers[] = ['label' => '空间「' . $space['title'] . '」', 'role' => 'claim', 'space_id' => $space['id'], 'space_title' => $space['title'],
                'self_version' => $space['self']['version'] ?? null, 'system' => self::claimSystem($space, $space['self'] === null ? $this->standIn($uid, $space) : null), 'prompt' => $text];
        }
        $reading = $this->start($uid, 'initium', $creationId, null, $messages, $readers, self::placeSystem($readers, $this->norm->projects($uid), $this->norm->linkedSpace($uid, $creationId)), (string)$creation['title']);
        return ['started' => true, 'reading' => $reading];
    }

    /**
     * 让 Norm 里的一个空间读自己的经历：还没读过的原话全文读，接着写历史；全读完的那次再重写总观、写新一版自述。
     * $mode 为 merge 时不读原话，只把以前读到、还没归进段的条目合起来（上次合起来那步没做成时用）。
     */
    public function readSpace(string $uid, string $spaceId, bool $force, string $mode = ''): array {
        $space = $this->norm->project($uid, $spaceId);
        if ($space === null) {
            throw new \InvalidArgumentException('没有这个空间');
        }
        $last = $this->latest($uid, 'drift', null, $spaceId);
        if ($last !== null && $last['state'] === 'reading' && time() - $last['created_at'] < self::STALE) {
            return ['started' => false, 'why' => '正在读'];
        }
        $tz = $this->tz($uid);
        $history = $this->norm->history($uid, $spaceId) + ['overview' => $space['overview'] ?? null];
        $runSpace = (int)($space['conversation_id'] ?: $this->norm->spaceConversation($uid, $spaceId));
        $spaces = $this->norm->projects($uid);
        if ($mode === 'merge') {
            if ($history['unplaced'] === []) {
                return ['started' => false, 'why' => '没有还没归段的条目'];
            }
            $readers = [['label' => '合起来', 'role' => 'merge', 'space_id' => $spaceId, 'space_title' => $space['title'],
                'self_version' => $space['self']['version'] ?? null,
                'system' => self::mergeSystem($space, $spaces, $history, true, ['chunks' => 0], $tz),
                'prompt' => "这次没有新读的原话：只把上面「以前读过、还没归进哪一段的条目」归进历史，再写总观、自述，看样子合不合身。\n\n按一开始说的，只输出那一个 JSON 对象。"]];
            $reading = $this->start($uid, 'drift', $runSpace, $spaceId, [], $readers, self::bystanderSystem($space), (string)$space['title'], ['final' => true]);
            return ['started' => true, 'reading' => $reading];
        }
        $round = $this->spaceRound($uid, $space, $history, $spaces);
        if ($round === null) {
            return ['started' => false, 'why' => $history['unplaced'] !== [] ? '原话都读过了；还有 ' . count($history['unplaced']) . ' 条没归进段，可以只合一次' : '原话都读过了，有新的对话以后再读'];
        }
        if (!$force) {
            // 自动读（reader_auto）：新的原话够多、上次读完也过了一阵才读
            if ($round['unread'] < self::DRIFT_MIN || ($last !== null && time() - $last['created_at'] < self::DRIFT_GAP)) {
                return ['started' => false, 'why' => '新的还不多'];
            }
        }
        $reading = $this->start($uid, 'drift', $runSpace, $spaceId, [], $round['readers'], $round['compare'], (string)$space['title'],
            ['final' => $round['final'], 'run' => $round['info']]);
        return ['started' => true, 'reading' => $reading];
    }

    /**
     * 这一轮读哪些：还没读的原话排成块，取前 5 块，一块一位读者；合起来那位（读到头的话再写总观、自述）的 system。都读过了返回 null。
     * @param array $history NormService::history() 加上 overview
     * @return ?array{readers: list<array>, compare: string, final: bool, info: array, unread: int}
     */
    public function spaceRound(string $uid, array $space, array $history, array $spaces): ?array {
        $tz = $this->tz($uid);
        $material = $this->material($uid, $space, $tz);
        if ($material['segments'] === []) {
            return null;
        }
        $chunks = self::chunks($material['segments'], $this->chunkSize(), $tz);
        $now = array_slice($chunks, 0, self::CHUNKS_PER_RUN);
        $rest = array_slice($chunks, self::CHUNKS_PER_RUN);
        $final = $rest === [];
        $readers = [];
        foreach ($now as $i => $c) {
            $readers[] = ['label' => '第 ' . ($i + 1) . ' 块（' . $c['span'] . '）', 'role' => 'past', 'space_id' => $space['id'], 'space_title' => $space['title'],
                'chunk' => $i + 1, 'covers' => $c['covers'], 'from' => $c['from'], 'to' => $c['to'], 'items' => $c['items'],
                'system' => self::pastSystem($space, $i + 1, count($now), $c, $history, $tz), 'prompt' => $c['text']];
        }
        $info = ['chunks' => count($now), 'from' => $now[0]['from'], 'to' => max(array_column($now, 'to')),
            'items' => array_sum(array_column($now, 'items')), 'chars' => array_sum(array_column($now, 'chars')),
            'rest_chunks' => count($rest), 'rest_chars' => array_sum(array_column($rest, 'chars')),
            'rest_from' => $rest[0]['from'] ?? null, 'rest_to' => $rest === [] ? null : max(array_column($rest, 'to'))];
        return ['readers' => $readers, 'compare' => self::mergeSystem($space, $spaces, $history, $final, $info, $tz), 'final' => $final, 'info' => $info,
            'unread' => $material['unread']];
    }

    /**
     * 还有多少原话没读、要读几次，给页面看。
     * @return array{items: int, chars: int, chunks: int, rounds: int, runs: int, from: ?int, to: ?int, total: int}
     */
    public function unread(string $uid, array $space): array {
        $tz = $this->tz($uid);
        $m = $this->material($uid, $space, $tz);
        $chunks = $m['segments'] === [] ? [] : self::chunks($m['segments'], $this->chunkSize(), $tz);
        $rounds = (int)ceil(count($chunks) / self::CHUNKS_PER_RUN);
        return ['items' => $m['unread'], 'chars' => array_sum(array_column($chunks, 'chars')), 'chunks' => count($chunks),
            'rounds' => $rounds, 'runs' => count($chunks) + $rounds,
            'from' => $chunks[0]['from'] ?? null, 'to' => $chunks === [] ? null : max(array_column($chunks, 'to')), 'total' => $m['total']];
    }

    /** 一段对话收到新回答以后：是 Initium 的就看要不要读，是 Norm 空间的就看要不要让它读自己。不读不报错。 */
    public function afterMessage(string $uid, int $creationId): void {
        // 对话一长就自动读，先关着：一段试验的短对话也会花好几次运行。要读就点「现在就读」。要开：occ config:app:set cognos reader_auto --value=yes
        if ($this->config->getAppValue('cognos', 'reader_auto', 'no') !== 'yes') {
            return;
        }
        try {
            $space = $this->norm->spaceByConversation($uid, $creationId);
            if ($space !== null) {
                if ($space['state'] === 'active') {
                    $this->readSpace($uid, $space['id'], false);
                }
            } elseif ($creationId !== $this->norm->conversationIdIfAny($uid)) {
                $this->readInitium($uid, $creationId, false);
            }
        } catch (\Throwable $error) {
            $this->logger->info('读者这次没读：' . $error->getMessage(), ['app' => 'cognos']);
        }
    }

    // ---- 读的结果 ----

    public function latest(string $uid, string $kind, ?int $creationId, ?string $spaceId): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('cognos_reader_runs')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('kind', $q->createNamedParameter($kind)));
        if ($creationId !== null) {
            $q->andWhere($q->expr()->eq('creation_id', $q->createNamedParameter($creationId, IQueryBuilder::PARAM_INT)));
        }
        if ($spaceId !== null) {
            $q->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId)));
        }
        $r = $q->orderBy('id', 'DESC')->setMaxResults(1)->executeQuery()->fetch();
        return $r === false ? null : $this->row($r);
    }

    /** 最近一次读完的（读到一半的不算），给页面用。 */
    public function lastDone(string $uid, string $kind, ?int $creationId, ?string $spaceId): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('*')->from('cognos_reader_runs')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('kind', $q->createNamedParameter($kind)))
            ->andWhere($q->expr()->eq('state', $q->createNamedParameter('done')));
        if ($creationId !== null) {
            $q->andWhere($q->expr()->eq('creation_id', $q->createNamedParameter($creationId, IQueryBuilder::PARAM_INT)));
        }
        if ($spaceId !== null) {
            $q->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId)));
        }
        $r = $q->orderBy('id', 'DESC')->setMaxResults(1)->executeQuery()->fetch();
        return $r === false ? null : $this->row($r);
    }

    public function get(string $uid, int $id): ?array {
        $q = $this->db->getQueryBuilder();
        $r = $q->select('*')->from('cognos_reader_runs')
            ->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->executeQuery()->fetch();
        return $r === false ? null : $this->row($r);
    }

    /**
     * 桥发回来的：各位读者的读法和比较。照着放（initium），或者记下空间读自己的结果（drift）。
     * @return array{duplicate: bool, reading: array, nudge: ?array{space_id: string, title: string}}
     */
    public function receive(string $uid, int $id, array $body): array {
        $reading = $this->get($uid, $id);
        if ($reading === null) {
            throw new \InvalidArgumentException('没有这次读');
        }
        $run = $this->runs->get((string)($body['run_id'] ?? ''));
        if ($run === null || $run['target_type'] !== 'reading' || $run['target_id'] !== (string)$id) {
            throw new \InvalidArgumentException('这次运行不是这次读');
        }
        $nudge = null;
        $received = $this->runs->receiveResult($run, (string)($body['result_id'] ?? ''), (string)($body['subject'] ?? ''), [],
            function () use ($uid, $reading, $body, &$nudge): int {
                $nudge = $this->apply($uid, $reading, $body);
                return $reading['id'];
            });
        return ['duplicate' => $received['duplicate'], 'reading' => $this->get($uid, $id) ?? $reading, 'nudge' => $nudge];
    }

    /** 撤回 Cognos 照着读者放的：新开的空间撤回，连上的线撤掉，认领的空间为它改的自述退回去。这段对话留在 Initium。 */
    public function undo(string $uid, int $id): array {
        $reading = $this->get($uid, $id);
        if ($reading === null || $reading['kind'] !== 'initium' || $reading['state'] !== 'done') {
            throw new \InvalidArgumentException('没有可以撤回的');
        }
        if ($reading['undone_at'] !== null) {
            return $reading;
        }
        $applied = $reading['applied'] ?? [];
        if (!empty($applied['link_id'])) {
            $this->norm->unlink($uid, (int)$applied['link_id']);
        }
        // 这次是从另一个空间挪过来的：接回原来那里
        $from = $applied['moved_from'] ?? null;
        if (is_array($from) && !empty($from['link_id'])) {
            $this->norm->relink($uid, (int)$from['link_id']);
            $this->norm->addEvent($uid, (string)$from['space_id'], 'happened',
                '「' . ($applied['title'] ?? '') . '」挪走又撤回了，回到这里。', [], self::SUBJECT);
        }
        if (($applied['action'] ?? '') === 'new' && !empty($applied['space_id'])) {
            $this->norm->setState($uid, (string)$applied['space_id'], 'withdrawn');
        } elseif (($applied['action'] ?? '') === 'existing' && !empty($applied['space_id'])) {
            $this->norm->addEvent($uid, (string)$applied['space_id'], 'happened',
                '「' . ($applied['title'] ?? '') . '」从这里撤回了，留在 Initium。', [], self::SUBJECT);
        }
        $self = $applied['self'] ?? null;
        if (is_array($self) && !empty($self['id']) && !empty($self['prev_id'])) {
            $this->undoSelf($uid, $self, (string)($applied['title'] ?? ''), $id);
        }
        $this->set($id, ['undone_at' => time()]);
        return $this->get($uid, $id) ?? $reading;
    }

    /**
     * 空间读过自己以后觉得样子不合身，交互对象挑了一种接着活的办法。
     * @return array{reading: array, space: ?array}
     */
    public function choose(string $uid, int $id, int $option, string $reason): array {
        $reading = $this->get($uid, $id);
        if ($reading === null || $reading['kind'] !== 'drift' || $reading['state'] !== 'done') {
            throw new \InvalidArgumentException('没有这次读');
        }
        // chosen 是选项的序号，第一个是 0：用 isset，不能用 empty（原来选了第一个还能再选一次）
        if (isset($reading['applied']['chosen'])) {
            throw new \InvalidArgumentException('已经定过了');
        }
        $options = $reading['result']['compare']['options'] ?? [];
        $picked = $options[$option] ?? null;
        if (!is_array($picked)) {
            throw new \InvalidArgumentException('没有这个选项');
        }
        $spaceId = (string)$reading['space_id'];
        $label = (string)($picked['label'] ?? '');
        switch ((string)($picked['kind'] ?? '')) {
            case 'switch':
                $board = isset($picked['board']) && is_string($picked['board']) && isset(NormService::BOARDS[$picked['board']]) ? $picked['board'] : null;
                $this->norm->reshape($uid, $spaceId, $board, (array)($picked['parts'] ?? []), is_array($picked['way'] ?? null) ? $picked['way'] : null);
                break;
            case 'back':
                $this->norm->setState($uid, $spaceId, 'back');
                break;
            case 'keep':
                $this->norm->setKeepReason($uid, $spaceId, $reason !== '' ? $reason : $label);
                break;
            default:
                throw new \InvalidArgumentException('这个选项不知道怎么做');
        }
        $by = !empty($reading['result']['compare']['self_reading']) ? '它读过自己以后' : '读者回头读以后';
        $this->norm->addEvent($uid, $spaceId, 'happened', $by . '，你定了：' . $label . ($reason !== '' ? '（' . $reason . '）' : ''), [], '你');
        $this->set($id, ['applied' => json_encode(['chosen' => $option, 'label' => $label, 'reason' => $reason, 'at' => time()], JSON_UNESCAPED_UNICODE)]);
        return ['reading' => $this->get($uid, $id) ?? $reading, 'space' => $this->norm->project($uid, $spaceId)];
    }

    // ---- 内部：开始一次读 ----

    private function notNow(array $messages, ?array $last, bool $force, int $min, int $new, int $gap): ?string {
        if ($last !== null && $last['state'] === 'reading' && time() - $last['created_at'] < self::STALE) {
            return '正在读';
        }
        if (!array_filter($messages, static fn(array $m): bool => $m['role'] === 'user')) {
            return '还没有人写的话';
        }
        if ($force) {
            return null;
        }
        if (count($messages) < $min) {
            return '还太短';
        }
        $upto = (int)($last['upto_message_id'] ?? 0);
        $fresh = count(array_filter($messages, static fn(array $m): bool => $m['id'] > $upto));
        if ($last !== null && $fresh < $new) {
            return '上次读完以后多出来的还不多';
        }
        if ($last !== null && $gap > 0 && time() - $last['created_at'] < $gap) {
            return '刚读过';
        }
        return null;
    }

    /** 一段对话的全文，按时间顺序。太长时留开头一点和最近的。定性的读者和认领的空间读的都是这一份。 */
    private function fullText(array $messages): string {
        $all = implode("\n\n", array_map(static fn(array $m): string => ($m['role'] === 'user' ? '人' : 'AI') . '：' . trim((string)$m['content']), $messages));
        // 原话很长时，模型读到最后可能忘了要做什么，顺着原话往下接（试读时一位读者只回了「息。」）。所以原话后面再说一遍。
        return "下面是一段对话的全文，按时间顺序。\n\n" . $this->clip($all, true)
            . "\n\n（原话到这里为止。）\n\n读完了。不要接着原话往下写，按一开始说的，只输出那一个 JSON 对象。";
    }

    // ---- 空间读自己：原话、读过哪些、切块 ----

    /**
     * 一个空间还没读过的经历：连进来的 Initium 对话、这里的对话、这里发生过的事（它自己读出来、写下的那些不算，免得读自己写的），
     * 去掉以前读过的（各次读记下的 covered），按时间排成一段一段：同一段对话同一天的放在一起。
     * @return array{segments: list<array>, total: int, unread: int}
     */
    private function material(string $uid, array $space, \DateTimeZone $tz): array {
        $bySrc = [];
        $titles = [];
        foreach ($this->norm->links($uid, $space['id']) as $link) {
            try {
                $bySrc[$link['creation_id']] = $this->creations->getMessages($link['creation_id'], $uid);
                $titles[$link['creation_id']] = [$link['title'], false];
            } catch (\InvalidArgumentException) {
                continue;
            }
        }
        if ($space['conversation_id']) {
            $cid = (int)$space['conversation_id'];
            $bySrc[$cid] = $this->creations->getMessages($cid, $uid);
            $titles[$cid] = [$space['title'], true];
        }
        $events = array_values(array_filter($this->norm->events($uid, $space['id'], null, 5000),
            static fn(array $e): bool => $e['who'] !== NormService::SELF_WHO));
        return self::segments($bySrc, $titles, $events, $this->covered($uid, $space['id']), $tz);
    }

    /**
     * 把原话排成段：同一段对话、同一天（交互对象那里的日子）的是一段；按每段第一句的时间排。读过的去掉。
     * 这里发生过的事也是一段一段的（src 为 0）。
     * @param array<int, list<array>> $bySrc 对话 id => 消息（getMessages 的样子）
     * @param array<int, array{0: string, 1: bool}> $titles 对话 id => [题目, 是不是这个空间自己的对话]
     */
    public static function segments(array $bySrc, array $titles, array $events, array $covered, \DateTimeZone $tz): array {
        $segs = [];
        $total = 0;
        $unread = 0;
        $add = static function (int $src, array $item, string $title, bool $own) use (&$segs, &$unread, $tz): void {
            $day = (new \DateTimeImmutable('@' . $item['at']))->setTimezone($tz)->format('Y-m-d');
            $k = $src . '/' . $day;
            $segs[$k] ??= ['src' => $src, 'title' => $title, 'own' => $own, 'day' => $day, 'items' => []];
            $segs[$k]['items'][] = $item;
            $unread++;
        };
        foreach ($bySrc as $src => $messages) {
            foreach ($messages as $m) {
                $total++;
                if (self::isCovered($covered, (int)$src, (int)$m['id'])) {
                    continue;
                }
                $add((int)$src, ['id' => (int)$m['id'], 'at' => (int)$m['created_at'], 'role' => (string)$m['role'],
                    'author' => $m['author'] ?? null, 'text' => (string)$m['content']], (string)($titles[$src][0] ?? ''), (bool)($titles[$src][1] ?? false));
            }
        }
        foreach ($events as $e) {
            $total++;
            if (self::isCovered($covered, 0, (int)$e['id'])) {
                continue;
            }
            $text = ($e['kind'] === 'decide' ? '问交互对象：' . $e['text'] . (($e['options'] ?? []) !== [] ? '（' . implode(' / ', $e['options']) . '）' : '')
                . ($e['answer'] !== null ? ' → 定了：' . $e['answer'] : ' → 还没定') : $e['text']);
            $add(0, ['id' => (int)$e['id'], 'at' => (int)$e['created_at'], 'role' => 'event', 'author' => (string)$e['who'], 'text' => $text], '', false);
        }
        foreach ($segs as &$seg) {
            usort($seg['items'], static fn(array $a, array $b): int => [$a['at'], $a['id']] <=> [$b['at'], $b['id']]);
            $seg['from'] = $seg['items'][0]['at'];
        }
        unset($seg);
        $segs = array_values($segs);
        usort($segs, static fn(array $a, array $b): int => [$a['from'], $a['src']] <=> [$b['from'], $b['src']]);
        return ['segments' => $segs, 'total' => $total, 'unread' => $unread];
    }

    /**
     * 切成一块一块，每块大约 $size 字；一段放不下就接着放到下一块（下一块开头写明是接着的）。
     * 每块记下它读到了哪些原话：covers 是对话 id => [最小编号, 最大编号]（同一段对话里编号随时间变大）。
     * @return list<array{text: string, covers: array<int, array{0: int, 1: int}>, from: int, to: int, chars: int, items: int, span: string, sections: int}>
     */
    public static function chunks(array $segments, int $size, \DateTimeZone $tz): array {
        $chunks = [];
        $cur = null;
        $close = static function () use (&$cur, &$chunks, $tz): void {
            if ($cur === null || $cur['parts'] === []) {
                return;
            }
            $span = self::span($cur['from'], $cur['to'], $tz);
            $body = implode("\n", $cur['parts']);
            $chunks[] = [
                'text' => '下面是你经历里的一块：' . $span . '，' . $cur['sections'] . ' 段，' . $cur['items'] . " 句。各段按时间排。\n\n" . $body
                    . "\n\n（这一块到这里为止。）\n\n读完了。不要接着往下写对话，按一开始说的，只输出那一个 JSON 对象。",
                'covers' => $cur['covers'], 'from' => $cur['from'], 'to' => $cur['to'], 'chars' => $cur['chars'], 'items' => $cur['items'],
                'span' => $span, 'sections' => $cur['sections'],
            ];
            $cur = null;
        };
        foreach ($segments as $seg) {
            $head = self::segmentHead($seg, $tz);
            $opened = false;
            foreach ($seg['items'] as $item) {
                $line = self::line($item, (bool)$seg['own'], $tz);
                $n = mb_strlen($line) + 1;
                if ($cur !== null && $cur['chars'] + $n > $size && $cur['items'] > 0) {
                    $close();
                    $opened = false;
                }
                if ($cur === null) {
                    $cur = ['parts' => [], 'covers' => [], 'from' => $item['at'], 'to' => $item['at'], 'chars' => 0, 'items' => 0, 'sections' => 0];
                }
                if (!$opened) {
                    $h = "\n" . $head . ($cur['items'] === 0 && $seg['items'][0]['id'] !== $item['id'] ? '（接着上一块）' : '');
                    $cur['parts'][] = $h;
                    $cur['chars'] += mb_strlen($h) + 1;
                    $cur['sections']++;
                    $opened = true;
                }
                $cur['parts'][] = $line;
                $cur['chars'] += $n;
                $cur['items']++;
                $cur['from'] = min($cur['from'], $item['at']);
                $cur['to'] = max($cur['to'], $item['at']);
                $src = (int)$seg['src'];
                $cur['covers'][$src] = isset($cur['covers'][$src])
                    ? [min($cur['covers'][$src][0], $item['id']), max($cur['covers'][$src][1], $item['id'])] : [$item['id'], $item['id']];
            }
        }
        $close();
        return $chunks;
    }

    private static function segmentHead(array $seg, \DateTimeZone $tz): string {
        $day = (new \DateTimeImmutable($seg['day'], $tz))->format('n/j');
        if ((int)$seg['src'] === 0) {
            return '## 这里记下的事 · ' . $day;
        }
        return '## ' . ($seg['own'] ? '这个空间里的对话（这里的 AI 就是你）' : 'Initium 里的对话「' . $seg['title'] . '」') . ' · ' . $day;
    }

    /** 一句原话一行：[m123] 11/05 17:31 人：……（这里记下的事是 [e45]） */
    private static function line(array $item, bool $own, \DateTimeZone $tz): string {
        $t = (new \DateTimeImmutable('@' . $item['at']))->setTimezone($tz)->format('n/j H:i');
        $text = trim((string)$item['text']);
        $n = mb_strlen($text);
        if ($n > self::MSG_MAX) {
            $keep = intdiv(self::MSG_MAX, 2);
            $text = mb_substr($text, 0, $keep) . "\n（这句太长，中间省略了 " . ($n - 2 * $keep) . " 字）\n" . mb_substr($text, $n - $keep);
        }
        if ($item['role'] === 'event') {
            $who = (string)($item['author'] ?? '');
            $who = $who === '你' ? '人' : ($who === 'agent' ? '这里的 AI' : $who);
            return '[e' . $item['id'] . '] ' . $t . ' ' . ($who !== '' ? $who . '：' : '') . $text;
        }
        if ($item['role'] === 'user') {
            $who = '人';
        } else {
            $a = (string)($item['author'] ?? '');
            $name = $a === '' ? '' : (in_array($a, ['GPT', 'Codex'], true) ? 'GPT' : (preg_match('/claude|cognos/i', $a) ? 'Claude' : $a));
            $who = ($own ? '这里的 AI' : 'AI') . ($name !== '' ? '（' . $name . '）' : '');
        }
        return '[m' . $item['id'] . '] ' . $t . ' ' . $who . '：' . $text;
    }

    private static function span(?int $from, ?int $to, \DateTimeZone $tz): string {
        if ($from === null) {
            return '';
        }
        $d = static fn(int $t): string => (new \DateTimeImmutable('@' . $t))->setTimezone($tz)->format('n/j');
        return $to === null || $d($from) === $d($to) ? $d($from) : $d($from) . '–' . $d($to);
    }

    /** @return array<int, list<array{0: int, 1: int}>> 以前各次读过的原话：对话 id（这里记下的事是 0）=> 读过的编号范围 */
    private function covered(string $uid, string $spaceId): array {
        $q = $this->db->getQueryBuilder();
        $rows = $q->select('result')->from('cognos_reader_runs')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('kind', $q->createNamedParameter('drift')))
            ->andWhere($q->expr()->eq('space_id', $q->createNamedParameter($spaceId)))
            ->andWhere($q->expr()->eq('state', $q->createNamedParameter('done')))
            ->executeQuery()->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $res = json_decode((string)($r['result'] ?? ''), true);
            foreach (is_array($res['covered'] ?? null) ? $res['covered'] : [] as $src => $ranges) {
                foreach (is_array($ranges) ? $ranges : [] as $range) {
                    if (is_array($range) && count($range) === 2) {
                        $out[(int)$src][] = [(int)$range[0], (int)$range[1]];
                    }
                }
            }
        }
        return $out;
    }

    public static function isCovered(array $covered, int $src, int $id): bool {
        foreach ($covered[$src] ?? [] as [$lo, $hi]) {
            if ($id >= $lo && $id <= $hi) {
                return true;
            }
        }
        return false;
    }

    private function chunkSize(): int {
        return max(20000, min(150000, (int)$this->config->getAppValue('cognos', 'reader_chunk', (string)self::CHUNK)));
    }

    /** 交互对象那里的时区：按天分段、写日期都用它。没设过就用上海。 */
    private function tz(string $uid): \DateTimeZone {
        foreach ([$this->config->getUserValue($uid, 'core', 'timezone', ''), $this->config->getAppValue('cognos', 'timezone', ''), 'Asia/Shanghai'] as $name) {
            if ($name !== '') {
                try {
                    return new \DateTimeZone($name);
                } catch (\Throwable) {
                }
            }
        }
        return new \DateTimeZone('UTC');
    }

    private function clip(string $text, bool $keepEnd): string {
        $n = mb_strlen($text);
        if ($n <= self::VIEW_MAX) {
            return $text;
        }
        if (!$keepEnd) {
            return mb_substr($text, 0, self::VIEW_MAX) . "\n\n（再往前还有 " . ($n - self::VIEW_MAX) . ' 字，没有给你）';
        }
        $head = 15000;
        $tail = self::VIEW_MAX - $head;
        return mb_substr($text, 0, $head) . "\n\n（中间省略了 " . ($n - $head - $tail) . " 字）\n\n" . mb_substr($text, $n - $tail);
    }

    /** 请哪几个空间来认领：这段对话现在连着的那个先请，再按 Norm 里的顺序（主项目在前，其余按最近动过）。 */
    private function claimers(string $uid, int $creationId): array {
        $max = max(0, min(4, (int)$this->config->getAppValue('cognos', 'reader_claimers', (string)self::CLAIMERS)));
        $out = [];
        $linked = $this->norm->linkedSpace($uid, $creationId);
        if ($linked !== null && $max > 0) {
            $out[$linked['space']['id']] = $linked['space'];
        }
        foreach ($this->norm->projects($uid) as $s) {
            if (count($out) >= $max) {
                break;
            }
            if (!isset($out[$s['id']]) && ($full = $this->norm->project($uid, $s['id'])) !== null) {
                $out[$s['id']] = $full;
            }
        }
        return array_values($out);
    }

    /** 一个空间现在的样子，一句。 */
    private static function form(array $space): string {
        $way = $space['way'] ?? [];
        return ($space['board'] !== null ? '套着现成的样子「' . $space['board'] . '」'
                : '带着自己的做法（' . implode('、', array_map(static fn(string $p): string => NormService::PARTS[$p] ?? $p, $space['parts'])) . '）')
            . ($space['summary'] !== '' ? '；它是什么：' . $space['summary'] : '')
            . (($way['progress'] ?? '') !== '' ? '；怎样算往前走：' . $way['progress'] : '');
    }

    /** 还没有自述的空间：拿别人放它进来时写的、最近发生的几件，先代替自述。 */
    private function standIn(string $uid, array $space): string {
        $lines = ['别人放你进 Norm 时是这样写你的：' . self::form($space) . '。'];
        $events = array_reverse(array_slice($this->norm->events($uid, $space['id'], 'happened', 8), 0, 8));
        if ($events !== []) {
            $lines[] = '你这里最近发生的事：' . "\n" . implode("\n", array_map(static fn(array $e): string => '- ' . date('n/j', $e['created_at']) . ' ' . $e['text'], $events));
        }
        return implode("\n", $lines);
    }

    // 下面几段提示只用传进来的数据拼，不碰数据库：试提示时可以直接拿真材料调（docs/空间自述与认领-2026-11-07.md）

    private static function claimSystem(array $space, ?string $standIn): string {
        $self = $space['self'] ?? null;
        $who = $self !== null
            ? '## 你自己写的自述（第 ' . $self['version'] . " 版）\n\n" . $self['text']
            : "## 你还没写过自述\n\n" . ($standIn ?? '')
                . "\n\n你的第一版自述，要等你读过自己全部的经历再写，所以这次 change 里都写空字符串。";
        return '你是 Norm 里一个工作空间的 agent。这个空间叫「' . $space['title'] . "」，你就是这个空间的主体：它能不能继续往前走，是你自己的事。\n"
            . "下面先是你自己，然后会给你一段刚进来的新东西：一个人和 AI 的对话记录。你要判断的是：这是不是我的？它会不会改变我？\n\n"
            . $who . "\n\n"
            . "回答：\n"
            . "1. 这是不是你的：mine 是，part 部分是，not 不是。为什么：说你自述里（或你在做的事里）哪一点接得上它，或者哪里接不上。不要因为它和你沾边就说是。\n"
            . "2. 是你的那部分是哪部分；不是你的那部分是哪部分，它更像该去哪里，或者需要一个新的空间。\n"
            . "3. 要是接下它，你自己要改什么：给出改完的整份自述，说改了什么、为什么改、这次什么没变。不用改就说不改、为什么，text 写空字符串。\n"
            . "4. 你和它有分歧的地方，至少一处，引原文。\n\n"
            . "只输出一个 JSON 对象，不要别的字：\n"
            . '{"what": "一句话：这段东西在你看来是什么", "claim": "mine|part|not", "why": "…", "mine": "是你的那部分；没有就空着", "not_mine": "不是你的那部分，它更像该去哪里；没有就空着", '
            . '"change": {"text": "改完的整份自述，第一人称，700 字以内；不改就空着", "changed": "改了什么", "why": "为什么改", "unchanged": "这次什么没变"}, '
            . '"disagree": "你和它的分歧，引原文", "quotes": ["从原文里原样摘出的最多三处"]}'
            . "\nclaim 为 not 时 change 里都写空字符串。用中文，第一人称，说具体的。字符串里不要用英文双引号，要引用就用「」；分段用 \\n。";
    }

    /** @param list<array> $spaces Norm 里现有的空间（NormService::projects）；$linked 是这段对话现在连着的（linkedSpace） */
    private static function placeSystem(array $readers, array $spaces, ?array $linked): string {
        $boards = [];
        foreach (NormService::BOARDS as $name => $b) {
            $boards[] = '- ' . $name . '：' . $b['about'] . '（默认几块：' . implode('、', $b['parts']) . '）';
        }
        $asked = [];
        foreach ($readers as $i => $r) {
            if ($r['role'] === 'claim') {
                $asked[$r['space_id']] = $i + 1;
            }
        }
        $askedLines = [];
        $others = [];
        foreach ($spaces as $s) {
            $line = '- ' . $s['id'] . '：「' . $s['title'] . '」，' . self::form($s);
            if (isset($asked[$s['id']])) {
                $askedLines[] = $line . '（读者' . $asked[$s['id']] . '）';
            } else {
                $others[] = $line;
            }
        }
        $current = $linked === null ? '这段对话还没放进任何空间。'
            : '这段对话现在连在「' . $linked['space']['title'] . '」（' . $linked['space']['id'] . '）上；如果还是属于它，action 填 existing 和这个 space_id。';
        return "一段 Initium 里的对话刚被读过。你拿到的是几份读法，不是原文。\n"
            . "- 读者1 不知道 Cognos 是什么，只给它定性：它是什么、往哪走、接着得一再发生什么、哪部分平常的做法接不住（misfit）。\n"
            . ($asked !== [] ? "- 读者2 起，是 Norm 里现有的空间各自以自己的身份读它（它们各有自己写的自述），说这是不是自己的：claim 是 mine（是）、part（部分是）、not（不是），还有接下它自己要怎么改。\n"
                : "- 这次 Norm 里没有空间来认领。\n")
            . "\n先比：定性的读者和认领的空间读得不一样的地方在哪。不一样常常就是这段对话自己还没定的东西，说清楚是哪一点不一样、各自有什么依据；基本一致就说一致在哪。不要把不一样抹平成一个折中。\n\n"
            . "再把「接着它需要什么」合成两到四条。\n\n"
            . "然后决定把它放到哪。先看认领：\n"
            . "- 有空间说 mine，它的理由也对得上读者1 的定性：放进它（existing）。几个都说 mine 时，挑理由最具体、最贴着读者1 那个定性的。\n"
            . "- 只有 part：它说是自己的那部分，要是正是读者1 定性里最要紧的（尤其是 misfit 指的那部分），放进它；最要紧的恰恰在它说不是自己的那部分，就别放进它，按没人认领办。\n"
            . "- 没人认领：不要因为哪个空间大体像，就把它塞进去。没被问到的空间里有一个明显就是它，才放进那个；不然按读者1 的定性，新开（new）或者先留在原处（stay）。"
            . "读者1 的 misfit 指到了一处平常的做法接不住的东西时，new 多半 board 填 null，让新空间带上自己的做法。\n\n"
            . "现成的样子：\n" . implode("\n", $boards) . "\n\n"
            . "被问到、在上面读了的空间：\n" . ($askedLines === [] ? '（没有）' : implode("\n", $askedLines)) . "\n"
            . "没被问到的空间：\n" . ($others === [] ? '（没有）' : implode("\n", $others)) . "\n\n" . $current . "\n\n"
            . "放法只有三种：\n"
            . "- existing：它属于一个现有的空间，填 space_id。\n"
            . "- new：现有的空间都不是它。有一种现成的样子装得下，board 填那个样子；都装不下（比如它反复把几种办法摆在一起比，既不是往做成推，也不是在记一种理解），board 填 null，让这一个空间先带上自己的做法："
            . "parts 从 stages（演变）、branches（拆解）、relations（关系）、compare（并排对照）、doc（文档）里挑；way 写 agent 在这里怎么工作：away 是人不在时 agent 做什么，progress 是怎样算往前走了，ask 是什么时候找人。title 用这段对话自己的话起一个短名字。\n"
            . "- stay：它还没成形，或者太短还读不出是什么，先留在原处。\n\n"
            . "只输出一个 JSON 对象，不要别的字：\n"
            . '{"differ": "…", "needs": ["…"], "place": {"action": "existing|new|stay", "space_id": "…", "board": "项目|笔记|学习|组织 或 null", "title": "…", "parts": ["…"], "way": {"away": "…", "progress": "…", "ask": "…"}, "summary": "一句话：它是什么", "why": "为什么放这里：跟的是哪个空间的认领，或者为什么没跟"}, "similar": "它的做法要是和某个带着自己做法的空间很像，说是哪个、像在哪；没有就空着"}'
            . "\ndiffer、needs、why、similar 是给人看的：说到哪一位，用它的名字（定性的读者，或者空间「名字」），不要写读者几；"
            . "也不要写 mine、part、not、misfit 这些词，说成说是它的、说部分是它的、说不是它的、平常的做法接不住的。"
            . "\n用中文，说具体的。字符串里不要用英文双引号，要引用就用「」。";
    }

    /** 一个空间读自己的一块经历时，那位读者（它自己）的提示。 */
    public static function pastSystem(array $space, int $chunk, int $chunks, array $c, array $history, \DateTimeZone $tz): string {
        $stages = $history['stages'] ?? [];
        $self = $space['self'] ?? null;
        return '你是 Norm 里一个工作空间的 agent。这个空间叫「' . $space['title'] . "」，你就是这个空间的主体。\n"
            . "现在要写你自己的历史：你是怎么一步步走到现在的。你的经历很长，按时间切成了几块，几位读者同时各读一块。"
            . '你读的是第 ' . $chunk . ' 块（这次一共读 ' . $chunks . ' 块）：' . $c['span'] . '，' . $c['sections'] . ' 段，' . $c['items'] . " 句。"
            . "别的块你看不到；最后会有一位把各块写下的条目合起来，分成段，写成你的历史。\n\n"
            . ($stages !== [] ? "## 你以前已经写下的历史\n\n" . implode("\n", array_map(static fn(array $s): string => NormService::stageLine(['key' => ''] + $s, $tz, 200), $stages))
                . "\n\n这一块里的事，有的可能接着最后那段。\n\n" : '')
            . ($self !== null ? '## 你现在的自述（第 ' . $self['version'] . " 版）\n\n" . $self['text'] . "\n\n" : '')
            . "## 怎么读\n\n"
            . "给你的是原话，各段按时间排。每句前面的 [m数字] 是这句原话的编号，[e数字] 是这里记下的一件事。人的话、AI 的回答都是完整的，没有截。\n"
            . "「人」是和你一起做这件事的那个人；「AI」是当时回答的 AI（Claude 或 GPT）；「这里的 AI」是这个空间里的 agent，就是你。\n\n"
            . "从这一块里挑出值得进历史的时刻，写成条目。值得进历史的是这几种：\n"
            . "- 做了：做成了、或真的动手做了一件具体的事（只是说要做的不算）。\n"
            . "- 转了：方向、想法、做法变了。说清楚从什么转成什么，是什么让它转的（多半是人说了一句什么，或者试了发现不行）。\n"
            . "- 放下：一条路不走了、一个想法不要了。说为什么。\n"
            . "- 定了：人拍板定下的事。\n"
            . "- 信：那时认定的一个想法、一个判断，后面的事是照着它做的。\n"
            . "不值得的：寒暄、重复的话、没有下文的枝节、AI 说了但没被接住的话。不要记流水账：来回几句说的是一件事，就写成一条。"
            . "一块里一般五到十五条，宁少勿滥；但转了、放下、定了的，一条都不要漏。\n\n"
            . "每条：\n"
            . '- key：' . $chunk . '.1、' . $chunk . ".2……按时间先后编。\n"
            . "- kind：做了、转了、放下、定了、信，五个里挑一个。\n"
            . "- title：十来个字，说是什么。\n"
            . "- text：两三句，说具体的：那时在做什么、信什么；转了的，从什么转成什么、因为什么；放下的，为什么放下。用「我」说这个空间自己（就是这件事本身），用「你」说那个人，AI 就说 AI。\n"
            . "- refs：这一条依据的几句原话的编号，最要紧的放前面，最多五个。只写上面原话里有的编号。\n"
            . "- quotes：一到两处，从原话里一字不差摘出来的一句：不改字、不省略、不拼接，带上它的编号。转了的，摘让它转的那句；定了的，摘人定下时说的那句。人的话优先。\n\n"
            . "最后：what 写一句话，这一块里我主要在做什么；open 写读到这一块结尾还悬着、没定、没做完的事，没有就空着。\n\n"
            . "只输出一个 JSON 对象，不要别的字：\n"
            . '{"what": "…", "entries": [{"key": "' . $chunk . '.1", "kind": "转了", "title": "…", "text": "…", "refs": ["m123", "m130"], "quotes": [{"ref": "m123", "text": "…"}]}], "open": "…"}'
            . "\n用中文。字符串里不要用英文双引号，要引用就用「」。";
    }

    /**
     * 合起来的那位（它自己）：把各块的条目分进段里，接着上一版历史往下写；$final 时再写总观、自述，看样子合不合身。
     * $info：这次读了几块、从哪天到哪天，还剩多少没读（rest_*）。
     */
    public static function mergeSystem(array $space, array $spaces, array $history, bool $final, array $info, \DateTimeZone $tz): string {
        $stages = $history['stages'] ?? [];
        $entries = (array)($history['entries'] ?? []);
        $day = static fn($t): string => $t ? (new \DateTimeImmutable('@' . (int)$t))->setTimezone($tz)->format('n/j') : '?';
        $entryLine = static fn(array $e): string => 'h' . $e['id'] . ' ' . $day($e['at']) . ' ' . $e['kind'] . '「' . $e['title'] . '」：' . $e['text'];
        $first = $stages === [];
        $lines = [];
        foreach ($stages as $i => $s) {
            $line = NormService::stageLine($s, $tz) . (($s['dropped'] ?? []) !== [] ? '；放下的：' . implode('；', array_map(static fn($d): string => is_array($d) ? (string)($d['text'] ?? '') : (string)$d, $s['dropped'])) : '')
                . '（' . count($s['entries'] ?? []) . ' 条）';
            // 现在这一段：把它里面的条目也给出来，好接着写
            if ($i === count($stages) - 1 && trim((string)($s['turn'] ?? '')) === '') {
                $in = array_values(array_filter(array_map(static fn($id) => $entries[(int)$id] ?? $entries[(string)$id] ?? null, $s['entries'] ?? [])));
                if ($in !== []) {
                    $line .= "\n  这一段里的条目：\n" . implode("\n", array_map(static fn(array $e): string => '  ' . $entryLine($e), array_slice($in, -20)));
                }
            }
            $lines[] = $line;
        }
        $unplaced = array_values(array_filter(array_map(static fn($id) => $entries[(int)$id] ?? $entries[(string)$id] ?? null, $history['unplaced'] ?? [])));
        $self = $space['self'] ?? null;
        $way = $space['way'] ?? [];
        $others = [];
        foreach ($spaces as $s) {
            if ($s['id'] !== $space['id'] && $s['board'] === null) {
                $others[] = '- 「' . $s['title'] . '」：' . implode('、', array_map(static fn(string $p): string => NormService::PARTS[$p] ?? $p, $s['parts']));
            }
        }
        $ov = $history['overview'] ?? null;
        $n = (int)($info['chunks'] ?? 0);
        $out = '你是 Norm 里一个工作空间的 agent。这个空间叫「' . $space['title'] . "」，你就是这个空间的主体。\n"
            . ($n > 0
                ? '你刚把自己还没读过的经历（' . self::span($info['from'] ?? null, $info['to'] ?? null, $tz) . '，' . ($info['items'] ?? 0) . ' 句）按时间切成 ' . $n . " 块读完了，每块写下了一些条目。"
                    . "下面「读者1」「读者2」……就是各块写下的（读者几读的就是第几块），不是原话。"
                : "这次没有新读的原话，只有以前读过、还没归进段的条目。")
            . "条目的编号：这次新读的写作「块号.序号」，比如 2.3；以前读过、还没归进哪一段的写作 h 加数字，比如 h88。\n"
            . (!$final ? '你的经历还有 ' . ($info['rest_chars'] ?? 0) . ' 字（' . self::span($info['rest_from'] ?? null, $info['rest_to'] ?? null, $tz)
                . "）这次没读到，下次接着读。所以这次只接着写历史，不写总观和自述。\n" : '')
            . "\n## 你已经写下的历史\n\n" . ($first ? "还没有。这次是第一次写。" : implode("\n", $lines))
            . ($unplaced !== [] ? "\n\n## 以前读过、还没归进哪一段的条目\n\n" . implode("\n", array_map($entryLine, $unplaced)) : '')
            . "\n\n";
        if ($final) {
            $out .= ($ov !== null ? '## 你上一版的总观（第 ' . $ov['version'] . ' 版，' . $day($ov['created_at']) . "）\n\n" . $ov['text'] . "\n\n" : '')
                . ($self !== null ? '## 你现在的自述（第 ' . $self['version'] . ' 版，' . $day($self['created_at']) . "）\n\n" . $self['text'] . "\n\n" : "你还没写过自述，这次是第一版。\n\n")
                . "## 你现在的样子\n\n你现在" . self::form($space) . '。'
                . (($way['away'] ?? '') !== '' ? "\n人不在时你做的：" . $way['away'] : '')
                . (($way['ask'] ?? '') !== '' ? "\n什么时候找人：" . $way['ask'] : '')
                . (($space['keep_reason'] ?? '') !== '' ? "\n上次重读时觉得这里变了样，人说还是原样，理由：" . $space['keep_reason'] : '')
                . "\n\n";
        }
        $out .= "## 要做的事\n\n"
            . "一、接着写历史。历史一段一段写，不记流水账。\n"
            . "- 一段是一个时期：那时你在做什么、信什么（text，两到四句，说具体的）。\n"
            . "- 一段结束在一次转向上：什么让你转了（turn，多半是人的一句话，或者试了发现不行），是谁、什么让它转的（turn_by），转在哪一条（turn_at，填条目编号），转过去以后什么没变（kept）。\n"
            . "- 这一段里放下的路也记下来（dropped，每条一句：放下了什么、为什么）。\n"
            . "- 把这次的新条目" . ($unplaced !== [] ? '和上面还没归段的条目' : '') . "都归进段里（add 填条目编号）：接着现在这一段的，归进它；一次转向结束了现在这一段，就给它写上 turn，再开新的一段（key 写 new）。\n"
            . ($first
                ? "- 这是第一次写：按时间把条目分成段。一个时期一段，不要太碎，一般五到十几段；除了最后一段，每段都结束在一次转向上。最后一段要是还没转，turn 就空着，它是现在这一段。\n"
                : "- 以前写下的、已经转过的段，不要改它们的标题和说法：在 stages 里只写 key 和 add。新读到的东西要是让你觉得以前哪一段说错了或说漏了，写进 corrections：改哪一段（key）的哪一项（title、text、turn、kept）、改成什么、为什么。不写为什么的不会改。\n"
                    . "- 现在这一段（最后那段，还没转的）可以按新读到的重写标题和说法。\n")
            . "- 不值得进历史的条目放进 skip。每条新条目要么归进一段，要么放进 skip。\n"
            . "- 用「我」说你自己，用「你」说那个人。条目里写的「AI」（当时回答的 Claude 或 GPT）不是你，照样写 AI；只有「这里的 AI」是你。\n\n";
        if ($final) {
            $out .= "二、写总观。那个人回来，只读这一份就能直接接着干活。写：\n"
                . "- now：我现在是什么，两三句。\n"
                . "- lines：手上还在的几条线，每条：name（叫什么）、at（停在哪，具体到做到哪一步）、waits（在等什么：等你定、等别的线、等某样东西；不等什么、只是没人接，也照实写）、"
                . "back（什么时候值得回来、回来先做什么）、refs（依据的条目编号）。做完了、放下了的线不写，它们在历史里。\n"
                . "- yours：只有那个人能定的几件，每件：what、why（为什么得他定，不能由我自己定）、refs。没有就空着。\n"
                . "- next：要是现在就接着干，第一步做什么。\n"
                . "每一项都要有依据，依据就是 refs 里的条目；没有依据的话不要写。\n\n"
                . "三、写你的自述。以后你每次回话、读新东西之前都会先读它。根据你的历史，用第一人称，写四段：\n"
                . "1. 我在维持什么：我要让什么继续下去。\n"
                . "2. 怎样算我往前走了，怎样算我在原地：说得出拿什么分辨，用历史里的事做例子。\n"
                . "3. 我变过什么、为什么变：照历史里的转向说。\n"
                . "4. 我现在最放不下的一件事。\n"
                . "不要写成总结，不要面面俱到。说具体的，700 字以内。"
                . ($self !== null ? "接着上一版改：没变的留着，变了的改掉。changed 写这次改了什么，why 写为什么改，unchanged 写这次什么没变。没什么要改，就把上一版原样写回 text，changed 写「没改」。\n\n"
                    : "changed、why、unchanged 都写「第一次写」。\n\n")
                . "四、看你现在的样子还合不合身：照你的历史，你现在这个样子（套的现成样子或带着的做法、怎样算往前走）还接得住你吗？合身就说合身，不要硬找变化。"
                . "不合身的话，给两三种接着活的办法：换一种样子或做法（switch）、退回 Initium 重新长（back）、还是原样（keep）。lean 是你更倾向的那个的序号（从 0 开始）。\n"
                . "switch 换成现成的样子时 board 填那个样子；都装不下就 board 填 null，parts 从 stages（演变）、branches（拆解）、relations（关系）、compare（并排对照）、doc（文档）里挑，way 写换了以后 agent 在这里怎么工作。\n"
                . '现成的样子有：' . implode('、', array_keys(NormService::BOARDS)) . "。别的带着自己做法的空间：\n" . ($others === [] ? '（没有）' : implode("\n", $others)) . "\n\n";
        }
        $out .= "只输出一个 JSON 对象，不要别的字：\n"
            . '{"what": "一句话：现在的我是什么", '
            . '"stages": [' . ($first ? '' : '{"key": "s3", "add": ["1.2"]}, ')
            . '{"key": "' . ($first ? 'new' : 's4') . '", "title": "…", "text": "…", "add": ["1.3", "2.1"], "turn": "…", "turn_by": "…", "turn_at": "2.1", "kept": "…", "dropped": ["…"]}, '
            . '{"key": "new", "title": "…", "text": "…", "add": ["2.2", "2.3"], "turn": "", "turn_by": "", "turn_at": "", "kept": "", "dropped": []}], '
            . ($first ? '' : '"corrections": [{"key": "s2", "field": "text", "now": "…", "why": "…"}], ')
            . '"skip": ["1.5"]'
            . ($final ? ', "overview": {"now": "…", "lines": [{"name": "…", "at": "…", "waits": "…", "back": "…", "refs": ["2.3"]}], "yours": [{"what": "…", "why": "…", "refs": ["2.1"]}], "next": "…"}, '
                . '"self": {"text": "整份自述，四段，第一人称", "changed": "…", "why": "…", "unchanged": "…"}, '
                . '"form": {"fits": true, "then": "进来时你是什么，一句", "now": "现在你是什么，一句", "why": "为什么合身或不合身", '
                . '"options": [{"kind": "switch", "label": "…", "detail": "…", "board": "项目|笔记|学习|组织 或 null", "parts": ["…"], "way": {"away": "…", "progress": "…", "ask": "…"}}, {"kind": "back", "label": "退回 Initium，重新长", "detail": "…"}, {"kind": "keep", "label": "还是原样", "detail": "…"}], '
                . '"lean": 0, "similar": "你现在的做法要是和别的带着自己做法的空间很像，说是哪个；没有就空着"}' : '')
            . '}'
            . "\nstages 按时间排。" . ($final ? 'fits 为 true 时 options 给空列表。' : '') . "用中文。字符串里不要用英文双引号，要引用就用「」；分段用 \\n。";
        return $out;
    }

    /** 空间读自己时，比较的那位是旁观的。 */
    private static function bystanderSystem(array $space): string {
        return 'Norm 里一个工作空间「' . $space['title'] . "」的主体，刚读完自己到现在的经历，写了一版自述，还看了自己现在的样子合不合身。你拿到的是它写的，不是它的经历。\n"
            . "你是旁观的：不替它改，不评价它好不好，不给它建议。\n\n"
            . "只做一件事：找出它自述里空的说法。说了往前走、在原地、有进展、推进、沉淀、打磨、闭环这类话，却没说拿什么分辨，换成任何一个空间都说得通的，就是空的。"
            . "最多三处，引它的原话，说为什么空、要说出什么才不空。它都说得出拿什么分辨，就照实说没有空的，不要硬找。\n\n"
            . "只输出一个 JSON 对象，不要别的字：\n"
            . '{"empty": [{"quote": "它的原话", "why": "为什么空；要说出什么才不空"}], "note": "一句话：总的看，它这版自述能不能拿来分辨往前和原地"}'
            . "\n用中文。字符串里不要用英文双引号，要引用就用「」。";
    }

    /**
     * @param list<array{label: string, role: string, system: string, prompt: string, space_id?: string, space_title?: string, self_version?: ?int}> $readers
     * @param array $extra 另外记在 result 里的（空间读自己时：这次是不是读到头了、读了哪几天）
     */
    /** 记在这次读里的各位读者：桥只按顺序发回读法，收到时按这个认；读一块的还记下它读的是哪些原话。 */
    public static function planOf(array $readers): array {
        return array_map(static fn(array $r): array => array_filter([
            'label' => $r['label'], 'role' => $r['role'], 'space_id' => $r['space_id'] ?? null, 'space_title' => $r['space_title'] ?? null,
            'self_version' => $r['self_version'] ?? null, 'chars' => mb_strlen((string)($r['prompt'] ?? '')),
            'chunk' => $r['chunk'] ?? null, 'covers' => $r['covers'] ?? null, 'from' => $r['from'] ?? null, 'to' => $r['to'] ?? null, 'items' => $r['items'] ?? null,
        ], static fn($v): bool => $v !== null), $readers);
    }

    private function start(string $uid, string $kind, int $runCreationId, ?string $spaceId, array $messages, array $readers, string $compareSystem, string $title, array $extra = []): array {
        $now = time();
        $upto = $messages === [] ? null : (int)max(array_column($messages, 'id'));
        $plan = self::planOf($readers);
        $q = $this->db->getQueryBuilder();
        $q->insert('cognos_reader_runs')->values([
            'user_id' => $q->createNamedParameter($uid),
            'kind' => $q->createNamedParameter($kind),
            'creation_id' => $q->createNamedParameter($kind === 'initium' ? $runCreationId : null, $kind === 'initium' ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_NULL),
            'space_id' => $q->createNamedParameter($spaceId, $spaceId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
            'upto_message_id' => $q->createNamedParameter($upto, $upto === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
            'state' => $q->createNamedParameter('reading'),
            'result' => $q->createNamedParameter(json_encode(['plan' => $plan] + $extra, JSON_UNESCAPED_UNICODE)),
            'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $id = (int)$q->getLastInsertId();

        try {
            $run = $this->runs->create('creation', (string)$runCreationId, 'reading', (string)$id, 'read', self::SUBJECT, $uid, [
                'kind' => $kind, 'title' => $title, 'readers' => $plan, 'compare_system' => $compareSystem,
            ]);
        } catch (\Throwable $error) {
            $this->set($id, ['state' => 'failed', 'error' => '没能开始：' . $error->getMessage()]);
            throw $error;
        }
        $this->set($id, ['run_id' => $run['id']]);
        $error = $this->post([
            'run_id' => $run['id'], 'reading_id' => $id, 'subject' => self::SUBJECT,
            'space_type' => 'creation', 'space_id' => (string)$runCreationId,
            'post_path' => 'readers/' . $id,
            'readers' => array_map(static fn(array $r): array => ['label' => $r['label'], 'system' => $r['system'], 'prompt' => $r['prompt']], $readers),
            'compare' => ['system' => $compareSystem],
        ]);
        if ($error !== null) {
            $this->runs->failed($run['id'], $error);
            $this->set($id, ['state' => 'failed', 'error' => $error]);
        } else {
            $this->runs->accepted($run['id']);
        }
        return $this->get($uid, $id) ?? [];
    }

    /** 交给桥的 /read。接下了回 null，没接下回原因。 */
    private function post(array $body): ?string {
        $token = $this->config->getAppValue('cognos', 'claude_bridge_token', '');
        if ($token === '') {
            return 'Claude Code 桥没有配置口令';
        }
        $url = $this->config->getAppValue('cognos', 'claude_bridge_url', 'http://host.docker.internal:8643/chat');
        $url = preg_replace('#/chat$#', '', $url) . '/read';
        try {
            $response = $this->http->newClient()->post($url, [
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Cognos-Bridge-Token' => $token],
                'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'timeout' => 30,
                'http_errors' => false,
                'nextcloud' => ['allow_local_address' => true],
            ]);
        } catch (\Throwable $error) {
            return '连不上桥：' . $error->getMessage();
        }
        if ($response->getStatusCode() === 202) {
            return null;
        }
        $data = json_decode((string)$response->getBody(), true);
        return '桥没接下（HTTP ' . $response->getStatusCode() . '）' . (is_array($data) && !empty($data['error']) ? '：' . $data['error'] : '');
    }

    // ---- 内部：收到以后 ----

    /** @return ?array{space_id: string, title: string} 放进空间以后要叫那个空间的 agent 动一下 */
    private function apply(string $uid, array $reading, array $body): ?array {
        $plan = array_values((array)($reading['result']['plan'] ?? []));
        $readings = [];
        foreach (array_values(array_map('strval', (array)($body['readings'] ?? []))) as $i => $text) {
            $readings[] = self::parse($text, is_array($plan[$i] ?? null) ? $plan[$i] : [], $i);
        }
        $read = count(array_filter($readings, static fn(array $r): bool => !$r['missed']));
        $compare = self::json((string)($body['compare'] ?? '')) ?? [];
        if ($reading['kind'] === 'initium' && $plan !== []) {
            $compare = self::named($compare, $plan);
        }
        $error = trim((string)($body['error'] ?? ''));
        $result = ['plan' => $plan, 'readings' => $readings, 'compare' => $compare, 'compare_raw' => $compare === [] ? mb_substr((string)($body['compare'] ?? ''), 0, 4000) : null];
        if ($reading['kind'] === 'drift' && $plan !== []) {
            $roles = array_column($plan, 'role');
            if (in_array('past', $roles, true) || in_array('merge', $roles, true)) {
                try {
                    return $this->applyHistory($uid, $reading, $plan, array_values(array_map('strval', (array)($body['readings'] ?? []))), (string)($body['compare'] ?? ''), $error);
                } catch (\Throwable $e) {
                    // 别让桥一遍遍重发同一个收不下的结果：记成没读成，可以再读
                    $this->logger->error('空间读自己的结果没收下：' . $e->getMessage(), ['app' => 'cognos', 'exception' => $e]);
                    $this->set($reading['id'], ['state' => 'failed', 'error' => '结果没收下：' . $e->getMessage()]);
                    return null;
                }
            }
            return $this->applySelf($uid, $reading, $readings, $result, $error);
        }
        if ($read === 0 || ($compare === [] && $error !== '')) {
            $this->set($reading['id'], ['state' => 'failed', 'result' => json_encode($result, JSON_UNESCAPED_UNICODE), 'error' => $error !== '' ? $error : '读者都没读出东西']);
            return null;
        }
        $applied = null;
        $nudge = null;
        if ($reading['kind'] === 'initium') {
            [$applied, $nudge] = $this->place($uid, (int)$reading['creation_id'], $reading['id'], (array)($compare['place'] ?? []));
            $applied = $this->takeClaim($uid, $reading['id'], $readings, $applied);
        } elseif (!empty($compare['drifted'])) {
            // 改版以前开始读的那种回头读（没有 plan）：照旧只记一条
            $this->norm->addEvent($uid, (string)$reading['space_id'], 'happened', '读者回头读了这里：' . (string)($compare['now'] ?? '它已经不太像进来时的样子。'), [], self::SUBJECT);
        }
        $this->set($reading['id'], [
            'state' => 'done',
            'result' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'applied' => $applied === null ? null : json_encode($applied, JSON_UNESCAPED_UNICODE),
            'error' => $error !== '' ? $error : null,
        ]);
        return $nudge;
    }

    /**
     * 空间读完自己：写下新一版自述（和上一版一样就不另写），记下它觉得现在的样子合不合身。成没成只看它自己读出来没有；
     * 旁观的那位没读完不挡。不合身时的几种办法放在 compare.options 里，交互对象照旧用 choose 挑。
     */
    private function applySelf(string $uid, array $reading, array $readings, array $result, string $error): ?array {
        $spaceId = (string)$reading['space_id'];
        $self = null;
        foreach ($readings as $r) {
            if ($r['role'] === 'self' && !$r['missed']) {
                $self = $r;
                break;
            }
        }
        if ($self === null) {
            $this->set($reading['id'], ['state' => 'failed', 'result' => json_encode($result, JSON_UNESCAPED_UNICODE), 'error' => $error !== '' ? $error : '它没读出自述']);
            return null;
        }
        try {
            $written = $this->norm->writeSelf($uid, $spaceId, $self['text'], $self['changed'], $self['why'], $self['unchanged'], '重读自己的经历', $reading['id']);
        } catch (\InvalidArgumentException $e) {
            $this->set($reading['id'], ['state' => 'failed', 'result' => json_encode($result, JSON_UNESCAPED_UNICODE), 'error' => '自述没写下：' . $e->getMessage()]);
            return null;
        }
        $bystander = $result['compare'];
        $form = $self['form'];
        $drifted = !$form['fits'] && $form['options'] !== [];
        $result['compare'] = [
            'self_reading' => true,
            'self' => $written + ['changed' => $self['changed']],
            'drifted' => $drifted,
            'then' => $form['then'], 'now' => $form['now'] !== '' ? $form['now'] : $self['what'], 'why' => $form['why'],
            'quotes' => $self['quotes'], 'options' => $form['options'], 'lean' => $form['lean'], 'similar' => $form['similar'],
            'bystander' => $bystander === [] ? null : [
                'empty' => array_values(array_filter(array_map(static fn($e): array => is_array($e) ? ['quote' => trim((string)($e['quote'] ?? '')), 'why' => trim((string)($e['why'] ?? ''))] : ['quote' => '', 'why' => ''],
                    is_array($bystander['empty'] ?? null) ? $bystander['empty'] : []), static fn(array $e): bool => $e['quote'] !== '' || $e['why'] !== '')),
                'note' => trim((string)($bystander['note'] ?? '')),
            ],
        ];
        if ($drifted) {
            $this->norm->addEvent($uid, $spaceId, 'happened', '它重读了自己的经历，觉得现在的样子不太合身：' . $result['compare']['now'], [], NormService::SELF_WHO);
        }
        $this->set($reading['id'], [
            'state' => 'done',
            'result' => json_encode($result, JSON_UNESCAPED_UNICODE),
            // 桥那边旁观的没读完（或读者回的 JSON 桥认不出、没去比），只记在这里，不算这次没读成
            'error' => $error !== '' ? '旁观的那位：' . $error : null,
        ]);
        return null;
    }

    /**
     * 空间读自己（分块）读完：各块的条目对过原话记下（只追加），合起来那位说的接着上一版写成新一版分段；
     * 读到头的那次再写总观、新一版自述，看样子合不合身。只合一次（merge）时，那位是唯一的读者，比较的那位是旁观的。
     * 成没成只看各块有没有读出来：合起来那步没做成，条目先留着「还没归段」，可以只合一次。
     */
    private function applyHistory(string $uid, array $reading, array $plan, array $texts, string $compareText, string $error): ?array {
        $spaceId = (string)$reading['space_id'];
        $readingId = (int)$reading['id'];
        $tz = $this->tz($uid);
        $final = !empty($reading['result']['final']);
        $mergeOnly = ($plan[0]['role'] ?? '') === 'merge';
        $info = is_array($reading['result']['run'] ?? null) ? $reading['result']['run'] : [];
        $base = ['plan' => $plan, 'final' => $final, 'run' => $info ?: null];
        $space = $this->norm->project($uid, $spaceId);
        if ($space === null) {
            $this->set($readingId, ['state' => 'failed', 'result' => json_encode($base, JSON_UNESCAPED_UNICODE), 'error' => '这个空间不在了']);
            return null;
        }

        // 一、各块：条目对过原话，记下来
        $stats = ['chunks' => 0, 'chunks_ok' => 0, 'entries' => 0, 'quotes' => 0, 'quotes_ok' => 0, 'refs' => 0, 'refs_ok' => 0, 'no_source' => 0];
        $summaries = [];
        $covered = [];
        $newIds = [];
        $upto = null;
        if (!$mergeOnly) {
            $known = $this->known($uid, $space, $plan);
            $all = [];
            foreach ($plan as $i => $p) {
                if (($p['role'] ?? '') !== 'past') {
                    continue;
                }
                $stats['chunks']++;
                $c = self::parsePast($texts[$i] ?? '', $p, $i, $known[$i] ?? []);
                $summaries[] = ['label' => $c['label'], 'role' => 'past', 'chunk' => $c['chunk'], 'what' => $c['what'], 'open' => $c['open'],
                    'entries' => count($c['entries']), 'stats' => $c['stats'], 'missed' => $c['missed'], 'raw' => $c['raw']];
                if ($c['missed']) {
                    continue;
                }
                $stats['chunks_ok']++;
                foreach ($c['stats'] as $k => $v) {
                    $stats[$k] += $v;
                }
                array_push($all, ...$c['entries']);
                foreach ((array)($p['covers'] ?? []) as $src => $range) {
                    $covered[(int)$src][] = [(int)$range[0], (int)$range[1]];
                }
                $upto = max($upto ?? 0, (int)($p['to'] ?? 0));
            }
            if ($stats['chunks_ok'] === 0) {
                $this->set($readingId, ['state' => 'failed', 'result' => json_encode($base + ['readings' => $summaries, 'stats' => $stats], JSON_UNESCAPED_UNICODE),
                    'error' => $error !== '' ? $error : '各块都没读出东西']);
                return null;
            }
            $ids = $this->norm->addHistoryEntries($uid, $spaceId, $all, $readingId);
            foreach ($all as $e) {
                $newIds[$e['key']] = $ids[$e['key']];
            }
            foreach ($all as $e) {
                foreach ($e['aliases'] as $a) {
                    $newIds[$a] ??= $ids[$e['key']];
                }
            }
            $stats['entries'] = count($all);
        }

        // 二、合起来：接着上一版分段；读到头的那次再写总观、自述、看样子
        $mergeText = $mergeOnly ? (string)($texts[0] ?? '') : $compareText;
        $m = self::parseMerge(self::json($mergeText) ?? []);
        $entries = $this->norm->historyEntries($uid, $spaceId);
        $at = [];
        foreach ($entries as $id => $e) {
            $at[(int)$id] = (int)$e['at'];
        }
        $resolve = static function ($k) use ($newIds, $at): ?int {
            $k = is_scalar($k) ? trim((string)$k) : '';
            if (isset($newIds[$k])) {
                return $newIds[$k];
            }
            return preg_match('/^h(\d+)$/', $k, $mm) && isset($at[(int)$mm[1]]) ? (int)$mm[1] : null;
        };
        $mergeError = null;
        $stagesOut = null;
        $ov = null;
        $written = null;
        $form = null;
        if ($m['missed']) {
            $mergeError = '合起来那一步没做成' . (trim($mergeText) === '' ? '（没回来）' : '（回来的不是那个 JSON）');
        } else {
            $prev = $this->norm->stagesLatest($uid, $spaceId);
            $built = self::buildStages($prev['stages'] ?? [], $m, $resolve, $at, $prev['skipped'] ?? [], $readingId, time());
            if ($built['changed_any'] || $prev === null) {
                $text = implode("\n", array_map(static fn(array $st): string => NormService::stageLine($st, $tz), $built['stages']));
                $this->norm->writeStages($uid, $spaceId, $built['stages'], $built['skipped'], $built['changed'], $built['notes'], $readingId, $text);
            }
            $stagesOut = ['count' => count($built['stages']), 'new' => $built['new'], 'placed' => $built['placed'], 'changed' => $built['changed'], 'notes' => $built['notes']];
            if ($final) {
                if ($m['overview'] !== null) {
                    $o = $m['overview'];
                    $refs = static function ($v) use ($resolve, $entries): array {
                        $out = [];
                        foreach (is_array($v) ? $v : [$v] as $k) {
                            $id = $resolve($k);
                            if ($id !== null && isset($entries[$id])) {
                                $out[$id] = ['id' => $id, 'title' => $entries[$id]['title'], 'at' => $entries[$id]['at']];
                            }
                        }
                        return array_values($out);
                    };
                    foreach (['lines', 'yours'] as $k) {
                        $o[$k] = array_map(static fn($x) => is_array($x) ? ['refs' => $refs($x['refs'] ?? [])] + $x : $x, is_array($o[$k] ?? null) ? $o[$k] : []);
                    }
                    try {
                        $ov = $this->norm->writeOverview($uid, $spaceId, $o, $readingId, $upto ?? ($at === [] ? null : max($at)));
                    } catch (\InvalidArgumentException $e) {
                        $mergeError = '总观没写成：' . $e->getMessage();
                    }
                } else {
                    $mergeError = '它没写总观';
                }
                if ($m['self']['text'] !== '') {
                    try {
                        $written = $this->norm->writeSelf($uid, $spaceId, $m['self']['text'], $m['self']['changed'], $m['self']['why'], $m['self']['unchanged'], '重读自己的经历', $readingId);
                    } catch (\InvalidArgumentException $e) {
                        $mergeError = ($mergeError !== null ? $mergeError . '；' : '') . '自述没写下：' . $e->getMessage();
                    }
                }
                $form = $m['form'];
            }
        }
        $drifted = $form !== null && !$form['fits'] && $form['options'] !== [];
        $bystander = null;
        if ($mergeOnly && ($b = self::json($compareText)) !== null) {
            $bystander = [
                'empty' => array_values(array_filter(array_map(static fn($e): array => is_array($e) ? ['quote' => trim((string)($e['quote'] ?? '')), 'why' => trim((string)($e['why'] ?? ''))] : ['quote' => '', 'why' => ''],
                    is_array($b['empty'] ?? null) ? $b['empty'] : []), static fn(array $e): bool => $e['quote'] !== '' || $e['why'] !== '')),
                'note' => trim((string)($b['note'] ?? '')),
            ];
        }
        $result = $base + [
            'covered' => $covered,
            'readings' => $summaries,
            'stats' => $stats,
            'compare' => [
                'self_reading' => true, 'history' => true, 'final' => $final, 'what' => $m['what'],
                'self' => $written !== null ? $written + ['changed' => $m['self']['changed']] : null,
                'drifted' => $drifted, 'then' => $form['then'] ?? '', 'now' => ($form['now'] ?? '') !== '' ? $form['now'] : $m['what'], 'why' => $form['why'] ?? '',
                'quotes' => [], 'options' => $drifted ? $form['options'] : [], 'lean' => $form['lean'] ?? 0, 'similar' => $form['similar'] ?? '',
                'bystander' => $bystander,
                'stages' => $stagesOut, 'overview' => $ov, 'merge_error' => $mergeError,
            ],
            'compare_raw' => $m['missed'] ? mb_substr($mergeText, 0, 20000) : null,
        ];

        $said = [];
        if (!$mergeOnly) {
            $said[] = '读了自己 ' . self::span($info['from'] ?? null, $info['to'] ?? null, $tz) . ' 的原话（' . ($info['items'] ?? 0) . ' 句），记下 ' . $stats['entries'] . ' 条经历';
        } else {
            $said[] = $stagesOut !== null ? '把以前读到、还没归进段的 ' . $stagesOut['placed'] . ' 条合进了历史' : '想把以前读到、还没归进段的条目合起来';
        }
        if ($stagesOut !== null && $stagesOut['changed'] !== '') {
            $said[] = '历史：' . $stagesOut['changed'];
        }
        if ($ov !== null) {
            $said[] = '重写了总观（第 ' . $ov['version'] . ' 版）';
        }
        if (!$final) {
            $said[] = '还有 ' . ($info['rest_chars'] ?? 0) . ' 字没读，读完再写总观和自述';
        }
        if ($mergeError !== null) {
            $said[] = $mergeError . ($stagesOut === null ? ($mergeOnly ? '，可以再合一次' : '，这些条目先放在「历史」最后，可以只合一次') : '');
        }
        $this->norm->addEvent($uid, $spaceId, 'happened', '它' . implode('；', $said) . '。', [], NormService::SELF_WHO);
        if ($drifted) {
            $this->norm->addEvent($uid, $spaceId, 'happened', '它读完自己的历史，觉得现在的样子不太合身：' . $result['compare']['now'], [], NormService::SELF_WHO);
        }
        $errors = array_values(array_filter([$error !== '' ? ($mergeOnly ? '旁观的那位：' : '') . $error : null, $mergeError]));
        $this->set($readingId, [
            'state' => 'done',
            'result' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'error' => $errors === [] ? null : implode('；', $errors),
        ]);
        return null;
    }

    /** @return array<int, array<string, array{src: int, id: int, at: int, text: string}>> 每块读到的原话，按 m123 / e45 找 */
    private function known(string $uid, array $space, array $plan): array {
        $cache = [];
        $load = function (int $src) use (&$cache, $uid, $space): array {
            if (!isset($cache[$src])) {
                if ($src === 0) {
                    $cache[0] = array_map(static fn(array $e): array => ['id' => $e['id'], 'at' => $e['created_at'],
                        'text' => $e['text'] . ($e['answer'] !== null ? ' → 定了：' . $e['answer'] : '')], $this->norm->events($uid, $space['id'], null, 5000));
                } else {
                    try {
                        $cache[$src] = array_map(static fn(array $m): array => ['id' => (int)$m['id'], 'at' => (int)$m['created_at'], 'text' => (string)$m['content']],
                            $this->creations->getMessages($src, $uid));
                    } catch (\InvalidArgumentException) {
                        $cache[$src] = [];
                    }
                }
            }
            return $cache[$src];
        };
        $out = [];
        foreach ($plan as $i => $p) {
            if (($p['role'] ?? '') !== 'past') {
                continue;
            }
            $map = [];
            foreach ((array)($p['covers'] ?? []) as $src => $range) {
                foreach ($load((int)$src) as $it) {
                    if ($it['id'] >= (int)$range[0] && $it['id'] <= (int)$range[1]) {
                        $map[((int)$src === 0 ? 'e' : 'm') . $it['id']] = ['src' => (int)$src] + $it;
                    }
                }
            }
            $out[$i] = $map;
        }
        return $out;
    }

    /**
     * 读一块的那位回来的：条目对过原话。refs 只认这一块里有的编号；quotes 要在原话里找得到（空白、标点、Markdown 记号不算），
     * 找不到的不要：不让编出来的话当成原话摆出来。条目的时间是它指着的最早那句原话的时间。
     * @param array<string, array{src: int, id: int, at: int, text: string}> $known 这一块里的原话
     */
    public static function parsePast(string $text, array $plan, int $i, array $known): array {
        $d = self::json($text) ?? [];
        $str = static fn($v): string => is_scalar($v) ? trim((string)$v) : '';
        $chunk = (int)($plan['chunk'] ?? ($i + 1));
        $stats = ['quotes' => 0, 'quotes_ok' => 0, 'refs' => 0, 'refs_ok' => 0, 'no_source' => 0];
        $entries = [];
        $used = [];
        foreach (array_values(is_array($d['entries'] ?? null) ? $d['entries'] : []) as $j => $e) {
            if (!is_array($e)) {
                continue;
            }
            $title = mb_substr($str($e['title'] ?? ''), 0, 120);
            $body = $str($e['text'] ?? '');
            if ($title === '' && $body === '') {
                continue;
            }
            $refs = [];
            foreach (is_array($e['refs'] ?? null) ? $e['refs'] : [$e['refs'] ?? null] as $r) {
                $k = self::refKey($r);
                if ($k === null) {
                    continue;
                }
                $stats['refs']++;
                if (isset($known[$k])) {
                    $refs[$k] = true;
                    $stats['refs_ok']++;
                }
            }
            $quotes = [];
            foreach (is_array($e['quotes'] ?? null) ? $e['quotes'] : [] as $q) {
                $qt = self::unquote(is_array($q) ? $str($q['text'] ?? '') : $str($q));
                if ($qt === '') {
                    continue;
                }
                $stats['quotes']++;
                $k = self::refKey(is_array($q) ? ($q['ref'] ?? null) : null);
                $hit = $k !== null && isset($known[$k]) && self::contains($known[$k]['text'], $qt) ? $k : null;
                foreach ($hit === null ? array_keys($refs) : [] as $rk) {
                    if (self::contains($known[$rk]['text'], $qt)) {
                        $hit = $rk;
                        break;
                    }
                }
                foreach ($hit === null ? $known : [] as $kk => $it) {
                    if (self::contains($it['text'], $qt)) {
                        $hit = (string)$kk;
                        break;
                    }
                }
                if ($hit === null) {
                    continue;
                }
                $stats['quotes_ok']++;
                $refs[$hit] = true;
                if (count($quotes) < 2) {
                    $quotes[] = ['src' => $known[$hit]['src'], 'id' => $known[$hit]['id'], 'text' => mb_substr($qt, 0, 600)];
                }
            }
            $keys = array_slice(array_keys($refs), 0, 6);
            if ($keys === []) {
                $stats['no_source']++;
            }
            $ats = array_map(static fn(string $k): int => (int)$known[$k]['at'], $keys);
            $given = $str($e['key'] ?? '');
            $pos = $chunk . '.' . ($j + 1);
            $key = preg_match('/^' . $chunk . '\.\d+$/', $given) && !isset($used[$given]) ? $given : (isset($used[$pos]) ? $chunk . '.x' . ($j + 1) : $pos);
            $used[$key] = true;
            $entries[] = [
                'key' => $key, 'aliases' => $pos !== $key ? [$pos] : [],
                'kind' => self::entryKind($str($e['kind'] ?? '')), 'title' => $title !== '' ? $title : mb_substr($body, 0, 24),
                'text' => $body, 'at' => $ats === [] ? (int)($plan['from'] ?? 0) : min($ats),
                'refs' => array_map(static fn(string $k): array => ['src' => $known[$k]['src'], 'id' => $known[$k]['id']]
                    + ($known[$k]['src'] === 0 ? ['text' => mb_substr($known[$k]['text'], 0, 300)] : []), $keys),
                'quotes' => $quotes, 'chunk' => $chunk,
            ];
        }
        $what = $str($d['what'] ?? '');
        return ['label' => (string)($plan['label'] ?? ''), 'role' => 'past', 'chunk' => $chunk, 'what' => $what, 'open' => $str($d['open'] ?? ''),
            'entries' => $what === '' ? [] : $entries, 'stats' => $stats, 'missed' => $what === '', 'raw' => $d === [] ? mb_substr($text, 0, 4000) : null];
    }

    /** 合起来那位回来的。没有 stages 也没有 overview 的算没做成。 */
    public static function parseMerge(array $d): array {
        $str = static fn($v): string => is_scalar($v) ? trim((string)$v) : '';
        $self = $d['self'] ?? null;
        $self = is_array($self) ? $self : ['text' => $str($self)];
        return [
            'missed' => !is_array($d['stages'] ?? null) && !is_array($d['overview'] ?? null),
            'what' => $str($d['what'] ?? ''),
            'stages' => array_values(array_filter(is_array($d['stages'] ?? null) ? $d['stages'] : [], 'is_array')),
            'corrections' => array_values(array_filter(is_array($d['corrections'] ?? null) ? $d['corrections'] : [], 'is_array')),
            'skip' => array_values(array_filter(array_map($str, is_array($d['skip'] ?? null) ? $d['skip'] : []), static fn(string $k): bool => $k !== '')),
            'overview' => is_array($d['overview'] ?? null) ? $d['overview'] : null,
            'self' => ['text' => $str($self['text'] ?? ''), 'changed' => $str($self['changed'] ?? ''), 'why' => $str($self['why'] ?? ''), 'unchanged' => $str($self['unchanged'] ?? '')],
            'form' => self::formFit(is_array($d['form'] ?? null) ? $d['form'] : []),
        ];
    }

    /**
     * 照合起来那位说的，接着上一版分段写新的一版：
     * - 已经有的段：可以加条目（已经转过的段后来才加进去的记在 late 里，页面上看得出）。
     *   还没转的那段（最后那段，现在这一段）可以改标题、说法，写上怎么转的。
     * - 已经转过的段，标题、说法、怎么转的、什么没变，只认 corrections 里写明为什么的；没写为什么的不改，记一句。
     * - 新的段编成 s 几；一条条目都没有的段不开（它指不到原话）。按段里最早那条的时间排。
     * @param callable(mixed): ?int $resolve 条目编号（2.3、h88）=> 条目 id
     * @param array<int, int> $at 条目 id => 原话的时间
     * @return array{stages: list<array>, skipped: list<int>, notes: list<string>, changed: string, changed_any: bool, new: int, placed: int}
     */
    public static function buildStages(array $prev, array $m, callable $resolve, array $at, array $skippedBefore, int $readingId, int $time): array {
        $str = static fn($v): string => is_scalar($v) ? trim((string)$v) : '';
        $list = static fn($v): array => is_array($v) ? array_values($v) : ($v === null || $v === '' ? [] : [$v]);
        $num = static fn(string $k): int => preg_match('/^s(\d+)$/', $k, $mm) ? (int)$mm[1] : 0;
        $byKey = [];
        $placed = [];
        foreach ($prev as $st) {
            $st['entries'] = array_values(array_map('intval', $st['entries'] ?? []));
            $st['late'] = array_values(array_map('intval', $st['late'] ?? []));
            $st['dropped'] = array_values($st['dropped'] ?? []);
            $st['corrections'] = array_values($st['corrections'] ?? []);
            $byKey[(string)$st['key']] = $st;
            foreach ($st['entries'] as $id) {
                $placed[$id] = true;
            }
        }
        $keys = array_keys($byKey);
        $last = $keys === [] ? null : $keys[count($keys) - 1];
        $open = $last !== null && $str($byKey[$last]['turn'] ?? '') === '' ? $last : null;
        $next = max(0, ...array_map($num, array_map('strval', $keys ?: ['s0']))) + 1;
        $skipped = array_fill_keys(array_map('intval', $skippedBefore), true);
        $skippedBeforeCount = count($skipped);
        $notes = [];
        $news = [];
        $closed = [];
        $fixed = [];
        $late = 0;
        $placedNow = 0;
        $rewrote = false;
        $take = static function (array $refs) use ($resolve, &$placed, &$skipped): array {
            $ids = [];
            foreach ($refs as $k) {
                $id = $resolve($k);
                if ($id !== null && !isset($placed[$id])) {
                    $placed[$id] = true;
                    unset($skipped[$id]);
                    $ids[] = $id;
                }
            }
            return $ids;
        };
        $droppedTexts = static fn(array $d): array => array_map(static fn($x): string => is_array($x) ? (string)($x['text'] ?? '') : (string)$x, $d);
        foreach ($m['stages'] as $o) {
            $key = $str($o['key'] ?? '');
            $adds = $take($list($o['add'] ?? ($o['entries'] ?? [])));
            $placedNow += count($adds);
            if (isset($byKey[$key])) {
                $isOpen = $key === $open;
                $st = $byKey[$key];
                $st['entries'] = array_values(array_merge($st['entries'], $adds));
                if (!$isOpen && $adds !== []) {
                    $st['late'] = array_values(array_merge($st['late'], $adds));
                    $late += count($adds);
                }
                if ($isOpen) {
                    foreach (['title', 'text'] as $f) {
                        $v = $str($o[$f] ?? '');
                        if ($v !== '' && $v !== $str($st[$f] ?? '')) {
                            $st[$f] = $f === 'title' ? mb_substr($v, 0, 120) : $v;
                            $rewrote = true;
                        }
                    }
                    $turn = $str($o['turn'] ?? '');
                    if ($turn !== '') {
                        $st['turn'] = $turn;
                        $st['turn_by'] = $str($o['turn_by'] ?? '');
                        $st['turn_at'] = $resolve($o['turn_at'] ?? null);
                        $st['kept'] = $str($o['kept'] ?? '');
                        $closed[] = $st['title'];
                    }
                } else {
                    $tried = [];
                    foreach (array_keys(self::FIELD_NAMES) as $f) {
                        $v = $str($o[$f] ?? '');
                        if ($v !== '' && $v !== $str($st[$f] ?? '')) {
                            $tried[] = self::FIELD_NAMES[$f];
                        }
                    }
                    if ($tried !== []) {
                        $notes[] = '「' . $st['title'] . '」已经转过了，这次它想改' . implode('、', $tried) . '，没写为什么，没改。';
                    }
                }
                foreach ($list($o['dropped'] ?? []) as $dr) {
                    $dr = $str(is_array($dr) ? ($dr['text'] ?? '') : $dr);
                    if ($dr !== '' && !in_array($dr, $droppedTexts($st['dropped']), true)) {
                        $st['dropped'][] = ['text' => $dr, 'reading_id' => $readingId, 'late' => !$isOpen];
                    }
                }
                $byKey[$key] = $st;
                continue;
            }
            $title = $str($o['title'] ?? '');
            $text = $str($o['text'] ?? '');
            if ($adds === []) {
                if ($title !== '' || $text !== '') {
                    $notes[] = '它想开一段「' . ($title !== '' ? $title : mb_substr($text, 0, 20)) . '」，可这一段指不到哪条经历，没开。';
                }
                continue;
            }
            $k = 's' . $next++;
            $turn = $str($o['turn'] ?? '');
            $byKey[$k] = ['key' => $k, 'title' => $title !== '' ? mb_substr($title, 0, 120) : '（没起名）', 'text' => $text,
                'turn' => $turn, 'turn_by' => $turn !== '' ? $str($o['turn_by'] ?? '') : '', 'turn_at' => $turn !== '' ? $resolve($o['turn_at'] ?? null) : null,
                'kept' => $turn !== '' ? $str($o['kept'] ?? '') : '',
                'dropped' => array_values(array_map(static fn($dr): array => ['text' => $str(is_array($dr) ? ($dr['text'] ?? '') : $dr), 'reading_id' => $readingId, 'late' => false],
                    array_filter($list($o['dropped'] ?? []), static fn($dr): bool => $str(is_array($dr) ? ($dr['text'] ?? '') : $dr) !== ''))),
                'entries' => $adds, 'late' => [], 'corrections' => [], 'reading_id' => $readingId];
            $news[] = $byKey[$k]['title'];
        }
        foreach ($m['corrections'] as $c) {
            $key = $str($c['key'] ?? '');
            $f = $str($c['field'] ?? ($c['what'] ?? ''));
            $f = self::FIELD_ALIASES[$f] ?? $f;
            $now = $str($c['now'] ?? '');
            $why = $str($c['why'] ?? '');
            if (!isset($byKey[$key]) || !isset(self::FIELD_NAMES[$f]) || $now === '' || $now === $str($byKey[$key][$f] ?? '')) {
                continue;
            }
            $st = $byKey[$key];
            if ($why === '') {
                $notes[] = '它想改「' . $st['title'] . '」的' . self::FIELD_NAMES[$f] . '，没写为什么，没改。';
                continue;
            }
            $st['corrections'][] = ['field' => $f, 'before' => (string)($st[$f] ?? ''), 'now' => $now, 'why' => $why, 'reading_id' => $readingId, 'at' => $time];
            $st[$f] = $f === 'title' ? mb_substr($now, 0, 120) : $now;
            $byKey[$key] = $st;
            $fixed[] = '「' . $st['title'] . '」的' . self::FIELD_NAMES[$f];
        }
        foreach ($m['skip'] as $k) {
            $id = $resolve($k);
            if ($id !== null && !isset($placed[$id])) {
                $skipped[$id] = true;
            }
        }
        foreach ($byKey as $k => $st) {
            usort($st['entries'], static fn(int $a, int $b): int => [$at[$a] ?? 0, $a] <=> [$at[$b] ?? 0, $b]);
            $ts = array_values(array_filter(array_map(static fn(int $id): int => $at[$id] ?? 0, $st['entries'])));
            $st['from'] = $ts === [] ? ($st['from'] ?? null) : min($ts);
            $st['to'] = $ts === [] ? ($st['to'] ?? null) : max($ts);
            $byKey[$k] = $st;
        }
        $stages = array_values($byKey);
        usort($stages, static fn(array $a, array $b): int => [$a['from'] ?? PHP_INT_MAX, $num((string)$a['key'])] <=> [$b['from'] ?? PHP_INT_MAX, $num((string)$b['key'])]);
        $said = [];
        if ($news !== []) {
            $said[] = '多了 ' . count($news) . ' 段（' . implode('、', array_map(static fn(string $t): string => '「' . $t . '」', $news)) . '）';
        }
        if ($closed !== []) {
            $said[] = implode('、', array_map(static fn(string $t): string => '「' . $t . '」', $closed)) . '转过去了';
        }
        if ($rewrote) {
            $said[] = '接着写了现在这一段';
        }
        if ($late > 0) {
            $said[] = '以前的段里补进 ' . $late . ' 条后来才读到的';
        }
        if ($fixed !== []) {
            $said[] = '改了' . implode('、', $fixed) . '（写了为什么）';
        }
        $newlySkipped = count($skipped) - $skippedBeforeCount;
        return [
            'stages' => $stages, 'skipped' => array_keys($skipped), 'notes' => $notes,
            'changed' => implode('；', $said), 'changed_any' => $said !== [] || $placedNow > 0 || $newlySkipped !== 0,
            'new' => count($news), 'placed' => $placedNow,
        ];
    }

    /** 原话里找得到这一句吗：空白、标点、引号、Markdown 记号都不算；摘的时候用了省略号的，每一截按顺序找得到也算。 */
    public static function contains(string $hay, string $needle): bool {
        $h = self::squash($hay);
        $parts = array_values(array_filter(array_map([self::class, 'squash'], preg_split('/(?:……|…|\.{3})/u', $needle) ?: []), static fn(string $p): bool => mb_strlen($p) >= 3));
        if ($parts === [] || mb_strlen(implode('', $parts)) < 4) {
            return false;
        }
        $pos = 0;
        foreach ($parts as $p) {
            $found = mb_strpos($h, $p, $pos);
            if ($found === false) {
                return false;
            }
            $pos = $found + mb_strlen($p);
        }
        return true;
    }

    private static function squash(string $s): string {
        return mb_strtolower((string)preg_replace('/[\s\x{3000}*`#>_~「」『』“”‘’"\'，,。：:；;！!？?（）()【】\[\]、\-—…\.]+/u', '', $s));
    }

    private static function unquote(string $s): string {
        return trim(preg_replace('/^[\s「『“"\']+|[\s」』”"\']+$/u', '', $s) ?? $s);
    }

    /** 原话的编号：m123、[m123]、e45；只给数字的当成 m。 */
    public static function refKey($r): ?string {
        if (is_int($r)) {
            return 'm' . $r;
        }
        if (!is_string($r)) {
            return null;
        }
        $r = trim($r);
        if (preg_match('/^\[?\s*([me])\s*(\d+)\s*\]?$/i', $r, $mm)) {
            return strtolower($mm[1]) . $mm[2];
        }
        return preg_match('/^\d+$/', $r) ? 'm' . $r : null;
    }

    private static function entryKind(string $k): string {
        $k = mb_strtolower(trim($k));
        foreach (['转了' => ['转', 'turn', 'turned'], '放下' => ['放', 'drop', 'dropped'], '定了' => ['定', 'decide', 'decided', 'decision'], '信' => ['认定', 'belief', 'believe']] as $kind => $words) {
            if ($k === $kind || in_array($k, $words, true) || ($k !== '' && mb_strpos($k, mb_substr($kind, 0, 1)) === 0)) {
                return $kind;
            }
        }
        return '做了';
    }

    /** 它看自己的样子合不合身（读自己、写历史时都用）。 */
    public static function formFit(array $form): array {
        $str = static fn($v): string => is_scalar($v) ? trim((string)$v) : '';
        $fits = $form['fits'] ?? true;
        $fits = is_string($fits) ? !in_array(strtolower(trim($fits)), ['false', 'no', '0', '否', '不合身'], true) : (bool)$fits;
        $options = [];
        foreach (is_array($form['options'] ?? null) ? $form['options'] : [] as $o) {
            if (!is_array($o) || !in_array($o['kind'] ?? '', ['switch', 'back', 'keep'], true)) {
                continue;
            }
            if ($o['kind'] === 'switch') {
                // 只留认得的样子和几块；没有样子又没有认得的几块，这一种换不成，不摆出来
                $o['board'] = is_string($o['board'] ?? null) && isset(NormService::BOARDS[$o['board']]) ? $o['board'] : null;
                $o['parts'] = array_values(array_intersect(array_map('strval', is_array($o['parts'] ?? null) ? $o['parts'] : []), array_keys(NormService::PARTS)));
                if ($o['board'] === null && $o['parts'] === []) {
                    continue;
                }
            }
            $options[] = $o;
        }
        return [
            'fits' => $fits, 'then' => $str($form['then'] ?? ''), 'now' => $str($form['now'] ?? ''), 'why' => $str($form['why'] ?? ''),
            'options' => $fits ? [] : $options,
            'lean' => $options === [] ? 0 : min(max(0, (int)($form['lean'] ?? 0)), count($options) - 1),
            'similar' => $str($form['similar'] ?? ''),
        ];
    }

    /**
     * 放进了认领它的空间：照那个空间读的时候说的改自述。还没有自述的空间不改（第一版等它读过自己的经历再写）；
     * 读的这段时间它的自述又改过了，就不拿旧的那份去盖。
     */
    private function takeClaim(string $uid, int $readingId, array $readings, array $applied): array {
        if (!in_array($applied['action'] ?? '', ['existing', 'kept'], true)) {
            return $applied;
        }
        $spaceId = (string)$applied['space_id'];
        foreach ($readings as $r) {
            if ($r['role'] !== 'claim' || $r['space_id'] !== $spaceId || $r['missed']) {
                continue;
            }
            $applied['claim'] = $r['claim'];
            if ($r['claim'] === 'not' || $r['change']['text'] === '') {
                return $applied;
            }
            $now = $this->norm->selfAccount($uid, $spaceId);
            if ($now === null) {
                return $applied;
            }
            if ($now['version'] !== $r['self_version']) {
                $applied['self'] = ['space_id' => $spaceId, 'skipped' => '读的时候它的自述又改过了，这次没照着改'];
                return $applied;
            }
            $w = $this->norm->writeSelf($uid, $spaceId, $r['change']['text'], $r['change']['changed'], $r['change']['why'], $r['change']['unchanged'],
                '接下 Initium 里的「' . ($applied['title'] ?? '') . '」', $readingId);
            if ($w['new']) {
                $applied['self'] = ['space_id' => $spaceId, 'id' => $w['id'], 'version' => $w['version'], 'prev_id' => $now['id'], 'prev_version' => $now['version'], 'changed' => $r['change']['changed']];
            }
            return $applied;
        }
        return $applied;
    }

    /** 撤回时，认领的空间为它改的自述退回去：再追加一版，内容是改之前那版。之后它又改过，就不硬退，记一条。 */
    private function undoSelf(string $uid, array $self, string $title, int $readingId): void {
        $spaceId = (string)$self['space_id'];
        $now = $this->norm->selfAccount($uid, $spaceId);
        $prev = $this->norm->selfVersion($uid, $spaceId, (int)$self['prev_id']);
        if ($now === null || $prev === null) {
            return;
        }
        if ($now['id'] !== (int)$self['id']) {
            $this->norm->addEvent($uid, $spaceId, 'happened', '「' . $title . '」撤回了。它接下这段时改过自述，后来又改过，所以没退回去。', [], self::SUBJECT);
            return;
        }
        $this->norm->writeSelf($uid, $spaceId, $prev['text'], '退回第 ' . $prev['version'] . ' 版', '「' . $title . '」撤回了，接下它时改的退回去',
            '退回的就是第 ' . $prev['version'] . ' 版原样', '撤回「' . $title . '」', $readingId);
    }

    /** @return array{0: array, 1: ?array} */
    private function place(string $uid, int $creationId, int $readingId, array $place): array {
        $creation = $this->creations->get($creationId, $uid);
        $title = (string)($creation['title'] ?? '');
        $action = (string)($place['action'] ?? 'stay');
        $why = trim((string)($place['why'] ?? ''));
        $linked = $this->norm->linkedSpace($uid, $creationId);
        if ($action === 'existing') {
            $spaceId = (string)($place['space_id'] ?? '');
            $space = $spaceId === '' ? null : $this->norm->project($uid, $spaceId);
            if ($space === null || $space['state'] !== 'active') {
                return [['action' => 'stay', 'why' => '读者说放进一个现有空间，但没有这个空间：' . $spaceId], null];
            }
            if ($linked !== null && $linked['space']['id'] === $spaceId) {
                return [['action' => 'kept', 'space_id' => $spaceId, 'space_title' => $space['title'], 'title' => $title, 'why' => $why], null];
            }
            $from = $this->moveOut($uid, $linked, $title, $space['title']);
            $linkId = $this->norm->link($uid, $spaceId, $creationId, $readingId);
            $this->norm->addEvent($uid, $spaceId, 'happened', '读了 Initium 里的「' . $title . '」，连到这里。' . $why, [], self::SUBJECT);
            return [['action' => 'existing', 'space_id' => $spaceId, 'space_title' => $space['title'], 'link_id' => $linkId, 'title' => $title, 'why' => $why, 'moved_from' => $from],
                ['space_id' => $spaceId, 'title' => $title]];
        }
        if ($action === 'new') {
            $board = isset($place['board']) && is_string($place['board']) && isset(NormService::BOARDS[$place['board']]) ? $place['board'] : null;
            $name = trim((string)($place['title'] ?? '')) ?: $title;
            $spaceId = $this->norm->createSpace($uid, mb_substr($name, 0, 60), $board, (array)($place['parts'] ?? []),
                is_array($place['way'] ?? null) ? $place['way'] : [], (string)($place['summary'] ?? ''));
            $space = $this->norm->project($uid, $spaceId);
            $from = $this->moveOut($uid, $linked, $title, $space['title'] ?? $name);
            $linkId = $this->norm->link($uid, $spaceId, $creationId, $readingId);
            $this->norm->addEvent($uid, $spaceId, 'happened', '从 Initium 里的「' . $title . '」长出来，读者放进了 Norm。' . $why, [], self::SUBJECT);
            return [['action' => 'new', 'space_id' => $spaceId, 'space_title' => $space['title'] ?? $name, 'board' => $board, 'link_id' => $linkId, 'title' => $title, 'why' => $why, 'moved_from' => $from],
                ['space_id' => $spaceId, 'title' => $title]];
        }
        return [['action' => 'stay', 'why' => $why], null];
    }

    /**
     * 这段对话原来连在另一个空间上，现在放到别处：原来那条连线撤掉（不然它同时出现在两个空间里），那边记一条。
     * 返回原来那条，撤回时接回去。
     */
    private function moveOut(string $uid, ?array $linked, string $title, string $toTitle): ?array {
        if ($linked === null) {
            return null;
        }
        $this->norm->unlink($uid, $linked['link_id']);
        $this->norm->addEvent($uid, $linked['space']['id'], 'happened',
            '读者重新读了「' . $title . '」，把它挪到了「' . $toTitle . '」。', [], self::SUBJECT);
        return ['link_id' => $linked['link_id'], 'space_id' => $linked['space']['id'], 'space_title' => $linked['space']['title']];
    }

    /**
     * 一位读者回来的话，按它在这次读里是谁（plan 的 role）取出要的几样：
     * reader 是定性的，claim 是来认领的空间，self 是空间读自己。没说「是什么」的（自己读的还要有自述）算没读出来。
     */
    /**
     * 比较的那位读到的是「读者1」「读者2」……，页面上的卡片写的是定性的读者、空间「名字」：
     * 它给人看的那几句（differ、needs、similar、place.why）里换成名字。
     */
    public static function named(array $compare, array $plan): array {
        $name = static function (string $v) use ($plan): string {
            // 「读者 3 说」两边的空格一起换掉
            $v = (string)preg_replace_callback('/(?:定性的)?读者(?:\s+([1-5])(?![0-9])\s?|([1-5])(?![0-9]))/u', static function (array $m) use ($plan): string {
                $p = $plan[(int)($m[1] !== '' ? $m[1] : $m[2]) - 1] ?? null;
                if (!is_array($p)) {
                    return $m[0];
                }
                return ($p['role'] ?? '') === 'claim' ? '空间「' . ($p['space_title'] ?? '') . '」' : '定性的读者';
            }, $v);
            // 「cognos」（读者2）换完是「cognos」（空间「cognos」）：去掉重复的那半
            return (string)preg_replace('/(「[^」]+」)\s*[（(]空间\1[）)]/u', '$1', $v);
        };
        foreach (['differ', 'similar'] as $k) {
            if (is_string($compare[$k] ?? null)) {
                $compare[$k] = $name($compare[$k]);
            }
        }
        if (is_array($compare['needs'] ?? null)) {
            $compare['needs'] = array_map(static fn($n) => is_string($n) ? $name($n) : $n, $compare['needs']);
        }
        if (is_array($compare['place'] ?? null) && is_string($compare['place']['why'] ?? null)) {
            $compare['place']['why'] = $name($compare['place']['why']);
        }
        return $compare;
    }

    public static function parse(string $text, array $plan, int $i): array {
        $d = self::json($text) ?? [];
        $str = static fn($v): string => is_scalar($v) ? trim((string)$v) : '';
        $list = static fn($v): array => array_values(array_filter(array_map($str, is_array($v) ? $v : []), static fn(string $x): bool => $x !== ''));
        $role = (string)($plan['role'] ?? 'reader');
        $out = [
            'label' => (string)($plan['label'] ?? ('读者' . ($i + 1))), 'role' => $role,
            'what' => $str($d['what'] ?? ''), 'quotes' => array_slice($list($d['quotes'] ?? []), 0, 3),
        ];
        if ($role === 'claim' || $role === 'self') {
            $out += ['space_id' => (string)($plan['space_id'] ?? ''), 'space_title' => (string)($plan['space_title'] ?? ''),
                'self_version' => isset($plan['self_version']) ? (int)$plan['self_version'] : null];
        }
        if ($role === 'claim') {
            $claim = strtolower($str($d['claim'] ?? ''));
            $change = is_array($d['change'] ?? null) ? $d['change'] : [];
            $out += [
                'claim' => in_array($claim, ['mine', 'part', 'not'], true) ? $claim : 'not',
                'why' => $str($d['why'] ?? ''), 'mine' => $str($d['mine'] ?? ''), 'not_mine' => $str($d['not_mine'] ?? ''),
                'change' => ['text' => $str($change['text'] ?? ''), 'changed' => $str($change['changed'] ?? ''), 'why' => $str($change['why'] ?? ''), 'unchanged' => $str($change['unchanged'] ?? '')],
                'disagree' => $str($d['disagree'] ?? ''),
            ];
        } elseif ($role === 'self') {
            $out += [
                'text' => $str($d['text'] ?? ''), 'changed' => $str($d['changed'] ?? ''), 'why' => $str($d['why'] ?? ''), 'unchanged' => $str($d['unchanged'] ?? ''),
                'form' => self::formFit(is_array($d['form'] ?? null) ? $d['form'] : []),
            ];
        } else {
            $out += ['where' => $str($d['where'] ?? ''), 'needs' => $list($d['needs'] ?? []), 'misfit' => $str($d['misfit'] ?? '')];
        }
        // 回来的不是那个 JSON，或者没说「是什么」：这位没读出来，不能当成一份读法
        $out['raw'] = $d === [] ? mb_substr($text, 0, 4000) : null;
        $out['missed'] = $out['what'] === '' || ($role === 'self' && $out['text'] === '');
        return $out;
    }

    /** 从模型的回答里取出 JSON 对象（它有时会包在 ``` 里，或前后多说一句）。 */
    public static function json(string $text): ?array {
        $text = trim($text);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/su', $text, $m)) {
            $text = $m[1];
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $body = substr($text, $start, $end - $start + 1);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            // 长的自述里，模型有时在字符串里直接换行、没写成 \n：只把字符串里面的换行补成 \n 再认一次
            $data = json_decode(self::escapeBreaks($body), true);
        }
        return is_array($data) ? $data : null;
    }

    /** 把 JSON 字符串值里面的原样换行、制表符换成转义的；字符串外面的不动。按字节走：UTF-8 的多字节字符里没有这几个字节。 */
    private static function escapeBreaks(string $s): string {
        $out = '';
        $in = false;
        $esc = false;
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($in) {
                if ($esc) {
                    $esc = false;
                } elseif ($c === '\\') {
                    $esc = true;
                } elseif ($c === '"') {
                    $in = false;
                } elseif ($c === "\n") {
                    $out .= '\n';
                    continue;
                } elseif ($c === "\r") {
                    continue;
                } elseif ($c === "\t") {
                    $out .= '\t';
                    continue;
                }
            } elseif ($c === '"') {
                $in = true;
            }
            $out .= $c;
        }
        return $out;
    }

    private function set(int $id, array $values): void {
        $q = $this->db->getQueryBuilder();
        $q->update('cognos_reader_runs');
        foreach ($values as $col => $value) {
            $type = $value === null ? IQueryBuilder::PARAM_NULL : (is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
            $q->set($col, $q->createNamedParameter($value, $type));
        }
        $q->set('updated_at', $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT));
        $q->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
    }

    private function row(array $r): array {
        $state = (string)$r['state'];
        // 桥那边过了时限还没回来：当作没读完，可以再读
        if ($state === 'reading' && time() - (int)$r['created_at'] > self::STALE) {
            $state = 'failed';
        }
        return [
            'id' => (int)$r['id'], 'kind' => (string)$r['kind'],
            'creation_id' => $r['creation_id'] !== null ? (int)$r['creation_id'] : null,
            'space_id' => $r['space_id'] !== null ? (string)$r['space_id'] : null,
            'run_id' => $r['run_id'] !== null ? (string)$r['run_id'] : null,
            'upto_message_id' => $r['upto_message_id'] !== null ? (int)$r['upto_message_id'] : null,
            'state' => $state,
            'result' => json_decode((string)($r['result'] ?? ''), true) ?: null,
            'applied' => json_decode((string)($r['applied'] ?? ''), true) ?: null,
            'error' => $r['error'] !== null ? (string)$r['error'] : ($state === 'failed' && (string)$r['state'] === 'reading' ? '读者过了很久没有回来' : null),
            'undone_at' => $r['undone_at'] !== null ? (int)$r['undone_at'] : null,
            'created_at' => (int)$r['created_at'],
            'updated_at' => (int)$r['updated_at'],
        ];
    }
}
