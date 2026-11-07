<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * 读者：不带上下文、不知道 Cognos 是什么，只拿一段对话的原话去读。
 *
 * 三个读者各自读，读法不一样的只是看到的东西：一个顺着读完，一个只读人写的，一个从最近往回读。
 * 读完再由一个比较的读者看三份读法（不看原文）：哪里读得不一样、接着它需要什么、放到 Norm 哪里。
 *
 * - Initium 的一段对话（kind=initium）：读完 Cognos 照着放，放进现有空间、新开一个空间，或者先留在原处。
 *   放了可以撤回（undo）。新开的空间要是现成的样子都装不下，它带着自己的做法（NormService::createSpace，board 为空）。
 * - Norm 里的一个空间（kind=drift）：读者回头读它的对话，看它还像不像进来时的样子。这一回不自动改，
 *   给出几种接着活的办法，由交互对象挑（choose）。
 *
 * 读者在本机经桥跑（claude_bridge.py 的 /read），没有工具，工作目录是临时目录。
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

    private const LABELS = ['顺着读完', '只读人写的', '从最近往回读'];

    private const READER_SYSTEM = <<<'TXT'
你会读到一段文字，是一个人和 AI 的对话记录。有时只有人写的那部分，有时顺序倒过来，读之前会说明。
你不知道它来自什么系统，也不需要知道。不要给这个人建议，不要评价这个人，不要接着对话往下说。

只回答三件事：
1. 这段到底是什么：不是它说自己是什么，而是读下来它实际在做的是什么。
2. 它在往哪里走：照现在这样下去，会走到哪。
3. 接着它需要什么才能继续：两到四条，每条一句。
再从原文里原样摘出最多三处支撑你这个读法的话。

只输出一个 JSON 对象，不要别的字：
{"what": "…", "where": "…", "needs": ["…"], "quotes": ["…"]}
用中文。每项一两句，说具体的，不说套话。
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
     * 读一段 Initium 对话。$force 为假时只在够长、上次读完以后又多了不少时才读。
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
        $views = $this->views($messages);
        $compare = $this->placeSystem($uid, $creationId);
        $reading = $this->start($uid, 'initium', $creationId, null, $messages, $views, $compare, (string)$creation['title']);
        return ['started' => true, 'reading' => $reading];
    }

    /** 回头读 Norm 里的一个空间。 */
    public function readSpace(string $uid, string $spaceId, bool $force): array {
        $space = $this->norm->project($uid, $spaceId);
        if ($space === null) {
            throw new \InvalidArgumentException('没有这个空间');
        }
        $messages = [];
        if ($space['conversation_id']) {
            $messages = $this->creations->getMessages((int)$space['conversation_id'], $uid);
        }
        // 空间自己还没怎么说过话：读它连着的 Initium 对话
        if (count($messages) < 4) {
            foreach ($this->norm->links($uid, $spaceId) as $link) {
                try {
                    $messages = array_merge($messages, $this->creations->getMessages($link['creation_id'], $uid));
                } catch (\InvalidArgumentException) {
                }
            }
            usort($messages, static fn(array $a, array $b): int => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);
        }
        $last = $this->latest($uid, 'drift', null, $spaceId);
        $why = $this->notNow($messages, $last, $force, self::DRIFT_MIN, self::DRIFT_MIN, self::DRIFT_GAP);
        if ($why !== null) {
            return ['started' => false, 'why' => $why];
        }
        $runSpace = $space['conversation_id'] ?: $this->norm->spaceConversation($uid, $spaceId);
        $reading = $this->start($uid, 'drift', (int)$runSpace, $spaceId, $messages, $this->views($messages), $this->driftSystem($uid, $space), (string)$space['title']);
        return ['started' => true, 'reading' => $reading];
    }

    /** 一段对话收到新回答以后：是 Initium 的就看要不要读，是 Norm 空间的就看要不要回头读。不读不报错。 */
    public function afterMessage(string $uid, int $creationId): void {
        // 对话一长就自动读，先关着：一段试验的短对话也会花 4 次运行。要读就点「现在就读」。要开：occ config:app:set cognos reader_auto --value=yes
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
     * 桥发回来的：三份读法和比较。照着放（initium），或者记下回头读的结论（drift）。
     * @return array{duplicate: bool, reading: array, nudge: ?array{creation_id: int, title: string}}
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

    /** 撤回 Cognos 照着读者放的：新开的空间撤回，连上的线撤掉。这段对话留在 Initium。 */
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
        if (($applied['action'] ?? '') === 'new' && !empty($applied['space_id'])) {
            $this->norm->setState($uid, (string)$applied['space_id'], 'withdrawn');
        } elseif (($applied['action'] ?? '') === 'existing' && !empty($applied['space_id'])) {
            $this->norm->addEvent($uid, (string)$applied['space_id'], 'happened',
                '「' . ($applied['title'] ?? '') . '」从这里撤回了，留在 Initium。', [], self::SUBJECT);
        }
        $this->set($id, ['undone_at' => time()]);
        return $this->get($uid, $id) ?? $reading;
    }

    /**
     * 回头读以后，交互对象挑了一种接着活的办法。
     * @return array{reading: array, space: ?array}
     */
    public function choose(string $uid, int $id, int $option, string $reason): array {
        $reading = $this->get($uid, $id);
        if ($reading === null || $reading['kind'] !== 'drift' || $reading['state'] !== 'done') {
            throw new \InvalidArgumentException('没有这次回头读');
        }
        if (!empty($reading['applied']['chosen'])) {
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
        $this->norm->addEvent($uid, $spaceId, 'happened', '读者回头读以后，你定了：' . $label . ($reason !== '' ? '（' . $reason . '）' : ''), [], '你');
        $this->set($id, ['applied' => json_encode(['chosen' => $option, 'label' => $label, 'reason' => $reason, 'at' => time()], JSON_UNESCAPED_UNICODE)]);
        return ['reading' => $this->get($uid, $id) ?? $reading, 'space' => $this->norm->project($uid, $spaceId)];
    }

    // ---- 内部：开始一次读 ----

    private function notNow(array $messages, ?array $last, bool $force, int $min, int $new, int $gap): ?string {
        if ($last !== null && $last['state'] === 'reading' && time() - $last['created_at'] < self::STALE) {
            return '读者正在读';
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

    /** 三个读者各自看到的：顺着的全文、只有人写的、倒过来的全文。太长时留开头一点和最近的。 */
    private function views(array $messages): array {
        $line = static fn(array $m): string => ($m['role'] === 'user' ? '人' : 'AI') . '：' . trim((string)$m['content']);
        $all = implode("\n\n", array_map($line, $messages));
        $mine = implode("\n\n", array_map(static fn(array $m): string => trim((string)$m['content']),
            array_values(array_filter($messages, static fn(array $m): bool => $m['role'] === 'user'))));
        $back = implode("\n\n", array_map($line, array_reverse($messages)));
        // 原话很长时，模型读到最后可能忘了要做什么，顺着原话往下接（试读时一位读者只回了「息。」）。所以原话后面再说一遍。
        $end = "\n\n（原话到这里为止。）\n\n读完了。不要接着原话往下写，按一开始说的，只输出那一个 JSON 对象。";
        return [
            "下面是一段对话的全文，按时间顺序。\n\n" . $this->clip($all, true) . $end,
            "下面只有这段对话里人写的那部分，按时间顺序，AI 的回答都去掉了。\n\n" . $this->clip($mine, true) . $end,
            "下面是一段对话的全文，顺序倒过来：最先是最近的一句，越往下越早。\n\n" . $this->clip($back, false) . $end,
        ];
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

    private function placeSystem(string $uid, int $creationId): string {
        $boards = [];
        foreach (NormService::BOARDS as $name => $b) {
            $boards[] = '- ' . $name . '：' . $b['about'] . '（默认几块：' . implode('、', $b['parts']) . '）';
        }
        $spaces = [];
        foreach ($this->norm->projects($uid) as $s) {
            $full = $this->norm->project($uid, $s['id']);
            $way = $full['way'] ?? [];
            $spaces[] = '- ' . $s['id'] . '：「' . $s['title'] . '」，' . ($s['board'] ?? '带着自己的做法（' . implode('、', $s['parts']) . '）')
                . ($s['summary'] !== '' ? '。它是什么：' . $s['summary'] : '')
                . (($way['progress'] ?? '') !== '' ? '。怎样算往前走：' . $way['progress'] : '');
        }
        $linked = $this->norm->linkedSpace($uid, $creationId);
        $current = $linked === null ? '这段对话还没放进任何空间。' : '这段对话现在连在「' . $linked['space']['title'] . '」（' . $linked['space']['id'] . '）上；如果还是属于它，action 填 existing 和这个 space_id。';
        return "三个读者各自读了同一段对话，读的方式不一样：读者一" . self::LABELS[0] . "，读者二" . self::LABELS[1] . "，读者三" . self::LABELS[2] . "。你拿到的是他们的读法，不是原文。\n\n"
            . "先比：他们读得不一样的地方在哪。不一样常常就是这段对话自己还没定的东西，说清楚是哪一点不一样、各自有什么依据；基本一致就说一致在哪。不要把不一样抹平成一个折中。\n\n"
            . "再把「接着它需要什么」合成两到四条。\n\n"
            . "最后决定把它放到哪。现成的样子：\n" . implode("\n", $boards) . "\n\n"
            . "现有的空间：\n" . ($spaces === [] ? '（还没有）' : implode("\n", $spaces)) . "\n\n" . $current . "\n\n"
            . "放法只有三种：\n"
            . "- existing：它其实属于一个现有的空间，填 space_id。\n"
            . "- new：现有的空间都不是它。有一种现成的样子装得下，board 填那个样子；都装不下（比如它反复把几种办法摆在一起比，既不是往做成推，也不是在记一种理解），board 填 null，让这一个空间先带上自己的做法："
            . "parts 从 stages（演变）、branches（拆解）、relations（关系）、compare（并排对照）、doc（文档）里挑；way 写 agent 在这里怎么工作：away 是人不在时 agent 做什么，progress 是怎样算往前走了，ask 是什么时候找人。title 用这段对话自己的话起一个短名字。\n"
            . "- stay：它还没成形，或者太短还读不出是什么，先留在原处。\n\n"
            . "只输出一个 JSON 对象，不要别的字：\n"
            . '{"differ": "…", "needs": ["…"], "place": {"action": "existing|new|stay", "space_id": "…", "board": "项目|笔记|学习|组织 或 null", "title": "…", "parts": ["…"], "way": {"away": "…", "progress": "…", "ask": "…"}, "summary": "一句话：它是什么", "why": "为什么放这里"}, "similar": "它的做法要是和某个带着自己做法的空间很像，说是哪个、像在哪；没有就空着"}'
            . "\n用中文，说具体的。";
    }

    private function driftSystem(string $uid, array $space): string {
        $way = $space['way'] ?? [];
        $then = '样子：' . ($space['board'] ?? '带着自己的做法（' . implode('、', $space['parts']) . '）')
            . ($space['summary'] !== '' ? '；它是什么：' . $space['summary'] : '')
            . (($way['progress'] ?? '') !== '' ? '；怎样算往前走：' . $way['progress'] : '');
        $others = [];
        foreach ($this->norm->projects($uid) as $s) {
            if ($s['id'] !== $space['id'] && $s['board'] === null) {
                $full = $this->norm->project($uid, $s['id']);
                $others[] = '- 「' . $s['title'] . '」：' . implode('、', $s['parts']) . '；' . (($full['way']['progress'] ?? '') ?: '');
            }
        }
        return "三个读者各自重新读了一个空间里的对话，读的方式不一样：读者一" . self::LABELS[0] . "，读者二" . self::LABELS[1] . "，读者三" . self::LABELS[2] . "。你拿到的是他们的读法，不是原文。\n\n"
            . "这个空间叫「" . $space['title'] . "」，进来时是这样的：" . $then . "。\n"
            . (($space['keep_reason'] ?? '') !== '' ? '上次读者觉得它变了样，人说还是原样，理由：' . $space['keep_reason'] . "\n" : '')
            . "现成的样子有：" . implode('、', array_keys(NormService::BOARDS)) . "。别的带着自己做法的空间：\n" . ($others === [] ? '（没有）' : implode("\n", $others)) . "\n\n"
            . "比一比：它现在还像进来时那样吗？像就说像，不要硬找变化。变了的话，给两三种它可以怎么接着活：换一种样子或做法（switch），退回 Initium 重新长（back），还是原样、读者读错了（keep）。lean 是你更倾向的那个选项的序号（从 0 开始）。\n\n"
            . "只输出一个 JSON 对象，不要别的字：\n"
            . '{"drifted": true, "then": "进来时它是什么，一句", "now": "现在读到的它是什么，一句", "quotes": ["读者依据的原话，最多三处"], "options": [{"kind": "switch", "label": "…", "detail": "…", "board": "项目|笔记|学习|组织 或 null", "parts": ["…"], "way": {"away": "…", "progress": "…", "ask": "…"}}, {"kind": "back", "label": "退回 Initium，重新长", "detail": "…"}, {"kind": "keep", "label": "还是原样，读者读错了", "detail": "…"}], "lean": 0, "similar": "它现在的做法要是和别的带着自己做法的空间很像，说是哪个；没有就空着"}'
            . "\ndrifted 为 false 时 options 给空列表。用中文，说具体的。";
    }

    private function start(string $uid, string $kind, int $runCreationId, ?string $spaceId, array $messages, array $views, string $compareSystem, string $title): array {
        $now = time();
        $upto = $messages === [] ? null : (int)max(array_column($messages, 'id'));
        $q = $this->db->getQueryBuilder();
        $q->insert('cognos_reader_runs')->values([
            'user_id' => $q->createNamedParameter($uid),
            'kind' => $q->createNamedParameter($kind),
            'creation_id' => $q->createNamedParameter($kind === 'initium' ? $runCreationId : null, $kind === 'initium' ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_NULL),
            'space_id' => $q->createNamedParameter($spaceId, $spaceId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
            'upto_message_id' => $q->createNamedParameter($upto, $upto === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
            'state' => $q->createNamedParameter('reading'),
            'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $id = (int)$q->getLastInsertId();

        $readers = [];
        foreach ($views as $i => $view) {
            $readers[] = ['label' => self::LABELS[$i], 'system' => self::READER_SYSTEM, 'prompt' => $view];
        }
        try {
            $run = $this->runs->create('creation', (string)$runCreationId, 'reading', (string)$id, 'read', self::SUBJECT, $uid, [
                'kind' => $kind, 'title' => $title, 'readers' => array_map(static fn(array $r): array => ['label' => $r['label'], 'chars' => mb_strlen($r['prompt'])], $readers),
                'compare_system' => $compareSystem,
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
            'readers' => $readers,
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

    /** @return ?array{creation_id: int, title: string} 放进空间以后要叫那个空间的 agent 动一下 */
    private function apply(string $uid, array $reading, array $body): ?array {
        $raw = array_map('strval', (array)($body['readings'] ?? []));
        $readings = [];
        foreach ($raw as $i => $text) {
            $parsed = self::json($text);
            $what = trim((string)($parsed['what'] ?? ''));
            $readings[] = [
                'label' => self::LABELS[$i] ?? ('读者' . ($i + 1)),
                'what' => $what, 'where' => (string)($parsed['where'] ?? ''),
                'needs' => array_values(array_map('strval', (array)($parsed['needs'] ?? []))),
                'quotes' => array_values(array_map('strval', (array)($parsed['quotes'] ?? []))),
                'raw' => $parsed === null ? mb_substr($text, 0, 4000) : null,
                // 回来的不是那个 JSON，或者没说「是什么」：这位没读出来，不能当成一份读法
                'missed' => $what === '',
            ];
        }
        $read = count(array_filter($readings, static fn(array $r): bool => !$r['missed']));
        $compare = self::json((string)($body['compare'] ?? '')) ?? [];
        $error = trim((string)($body['error'] ?? ''));
        $result = ['readings' => $readings, 'compare' => $compare, 'compare_raw' => $compare === [] ? mb_substr((string)($body['compare'] ?? ''), 0, 4000) : null];
        if ($read === 0 || ($compare === [] && $error !== '')) {
            $this->set($reading['id'], ['state' => 'failed', 'result' => json_encode($result, JSON_UNESCAPED_UNICODE), 'error' => $error !== '' ? $error : '读者都没读出东西']);
            return null;
        }
        $applied = null;
        $nudge = null;
        if ($reading['kind'] === 'initium') {
            [$applied, $nudge] = $this->place($uid, (int)$reading['creation_id'], $reading['id'], (array)($compare['place'] ?? []));
        } elseif (!empty($compare['drifted'])) {
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
                return [['action' => 'kept', 'space_id' => $spaceId, 'space_title' => $space['title'], 'why' => $why], null];
            }
            $linkId = $this->norm->link($uid, $spaceId, $creationId, $readingId);
            $this->norm->addEvent($uid, $spaceId, 'happened', '读了 Initium 里的「' . $title . '」，连到这里。' . $why, [], self::SUBJECT);
            return [['action' => 'existing', 'space_id' => $spaceId, 'space_title' => $space['title'], 'link_id' => $linkId, 'title' => $title, 'why' => $why],
                ['creation_id' => $this->norm->spaceConversation($uid, $spaceId), 'title' => $title]];
        }
        if ($action === 'new') {
            $board = isset($place['board']) && is_string($place['board']) && isset(NormService::BOARDS[$place['board']]) ? $place['board'] : null;
            $name = trim((string)($place['title'] ?? '')) ?: $title;
            $spaceId = $this->norm->createSpace($uid, mb_substr($name, 0, 60), $board, (array)($place['parts'] ?? []),
                is_array($place['way'] ?? null) ? $place['way'] : [], (string)($place['summary'] ?? ''));
            $linkId = $this->norm->link($uid, $spaceId, $creationId, $readingId);
            $this->norm->addEvent($uid, $spaceId, 'happened', '从 Initium 里的「' . $title . '」长出来，读者放进了 Norm。' . $why, [], self::SUBJECT);
            $space = $this->norm->project($uid, $spaceId);
            return [['action' => 'new', 'space_id' => $spaceId, 'space_title' => $space['title'] ?? $name, 'board' => $board, 'link_id' => $linkId, 'title' => $title, 'why' => $why],
                ['creation_id' => $this->norm->spaceConversation($uid, $spaceId), 'title' => $title]];
        }
        return [['action' => 'stay', 'why' => $why], null];
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
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($data) ? $data : null;
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
