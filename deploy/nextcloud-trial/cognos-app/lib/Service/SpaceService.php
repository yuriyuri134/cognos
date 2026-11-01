<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * 一片空间里的发生（traces）和读法（readings）。
 *
 * 发生只增不改：要改一条的状态，就新写一条 kind=correction，用 ref_id 指过去，
 * 它的 status 就是那一条的新状态。读的时候按最新的更正来算。
 *
 * 押着的、顶、面对也是发生：
 * - premise：谁押着的一条前提（押的那位就是 author），governs 写它管着的代码或做法；
 *   ref_id 指向旧前提时，它是那一条被重新切出来的。
 * - hit：顶到某条前提（ref_id 指过去）。谁都能顶：交互对象的原话、现实碰回来的、别的 agent。
 * - answer：押的那位面对某一下（ref_id 指向 hit），act 是 站住 / 碎了 / 重新切。
 *   「站住」不是到头了：hit 的 ref_id 也可以指向一条站住的 answer，那就是顶这个站住，
 *   算作又顶了一下它站住的那条前提，押的那位还会被叫回来。
 * - unanswered：押的那位被叫回来面对某一下（ref_id 指向 hit），做完走了却没面对。
 *   这不是谁的读法，是现实碰回来的（source=世），由 PushBackService 记下；躲开的留着。
 * 顶完要找回押的那位，见 PushBackService。
 *
 * 没完的、停在哪、检查也是发生（让思考自己接着走，见 ContinuationService）：
 * - open：挂在这片空间里还没完的一件（交互对象的一句话，或者一个 AI 自己的尝试站起来）。
 *   交互对象确认它完了（correction confirmed）或撤回，它才收。
 * - stop：一次运行走完，停在哪（ref_id 指向 open）。act 是 接着 / 卡住 / 完了，content 是这次往前走了什么，
 *   stopped_at 是口子：接着时是下一个自己够得着的口子，卡住时是只有交互对象、钱或现实能给的那一件。
 *   Cognos 自己看到的也记成 stop，来源〔世〕：原地（口子和以前走过的几乎一样）、没说（走完没说停在哪）、时限（做到一半被停下）。
 * - reality：现实碰回来的检查结果（ref_id 指向 open），act 是 过了 / 没过。
 *
 * 判断也是发生，而且要能被现实对上（交互对象 10/28：「所以在于判断，没有自主判断」）：
 * - judgment：一条发生上自己下的判断。尝试上是预判：这一步做下去，现实会碰回什么；
 *   卡住（AI 的 stop act=卡住）必须带：要是我来定，我选哪个、凭什么。不许只把选择交回去。
 * - checked：拿现实对一条判断（ref_id 指向带 judgment 的那条），act 是 对上 / 没对上 / 半对，
 *   content 写现实碰回了什么。对不上的留着；每位的判断对上几次、没对上几次，入口里看得见。
 *   下判断的这一位自己对自己的那一下留着，但不进对上几次的账：它能自己让判断成立，证明不了判断力。
 *   要进账，得是别的一位（交互对象、别的 agent、Cognos 自己观察到的〔世〕）来对。
 */
final class SpaceService {
    public const SOURCES = ['原', '世', 'AI'];
    public const STATUSES = ['said', 'confirmed', 'rejected', 'withdrawn'];
    public const ANSWER_ACTS = ['站住', '碎了', '重新切'];
    public const STOP_ACTS = ['接着', '卡住', '完了'];
    /** Cognos 自己看到的停：原地、没说、时限（做到一半被停下，还拉着） */
    public const OBSERVED_STOPS = ['原地', '没说', '时限'];
    public const REALITY_ACTS = ['过了', '没过'];
    public const CHECK_ACTS = ['对上', '没对上', '半对'];

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /**
     * @param array{kind: string, source: string, author: string, content: string, said_at?: ?int, act?: ?string, confidence?: ?int, status?: ?string, about?: ?string, stopped_at?: ?string, touched_reality?: ?bool, ref_id?: ?int, governs?: ?string, created_at?: ?int} $trace
     */
    public function addTrace(string $spaceType, string $spaceId, array $trace): array {
        $kind = trim((string)($trace['kind'] ?? ''));
        $source = (string)($trace['source'] ?? '');
        $content = trim((string)($trace['content'] ?? ''));
        $status = (string)($trace['status'] ?? 'said');
        if ($kind === '' || mb_strlen($kind) > 32) {
            throw new \InvalidArgumentException('kind is required (≤32 chars)');
        }
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('source must be 原, 世 or AI');
        }
        if ($content === '') {
            throw new \InvalidArgumentException('content is required');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('status must be one of ' . implode(', ', self::STATUSES));
        }
        $refId = isset($trace['ref_id']) ? (int)$trace['ref_id'] : null;
        if ($kind === 'correction') {
            if ($refId === null || $this->getTrace($spaceType, $spaceId, $refId) === null) {
                throw new \InvalidArgumentException('a correction needs ref_id pointing to a trace in this space');
            }
        }
        if ($kind === 'premise' && $refId !== null && ($this->getTrace($spaceType, $spaceId, $refId)['kind'] ?? null) !== 'premise') {
            throw new \InvalidArgumentException('a re-cut premise needs ref_id pointing to the premise it replaces');
        }
        if ($kind === 'hit') {
            $target = $refId === null ? null : $this->getTrace($spaceType, $spaceId, $refId);
            $ok = $target !== null && ($target['kind'] === 'premise' || ($target['kind'] === 'answer' && $target['act'] === '站住'));
            if (!$ok) {
                throw new \InvalidArgumentException('a hit needs ref_id pointing to a premise, or to an answer that stood (站住), in this space');
            }
        }
        if ($kind === 'unanswered' && ($refId === null || ($this->getTrace($spaceType, $spaceId, $refId)['kind'] ?? null) !== 'hit')) {
            throw new \InvalidArgumentException('unanswered needs ref_id pointing to a hit in this space');
        }
        if ($kind === 'unchanged' && ($refId === null || ($this->getTrace($spaceType, $spaceId, $refId)['kind'] ?? null) !== 'premise')) {
            throw new \InvalidArgumentException('unchanged needs ref_id pointing to the re-cut premise in this space');
        }
        if ($kind === 'answer') {
            if ($refId === null || ($this->getTrace($spaceType, $spaceId, $refId)['kind'] ?? null) !== 'hit') {
                throw new \InvalidArgumentException('an answer needs ref_id pointing to a hit in this space');
            }
            if (!in_array($trace['act'] ?? null, self::ANSWER_ACTS, true)) {
                throw new \InvalidArgumentException('an answer needs act: ' . implode(' / ', self::ANSWER_ACTS));
            }
        }
        if ($kind === 'stop' || $kind === 'reality') {
            if ($refId === null || ($this->getTrace($spaceType, $spaceId, $refId)['kind'] ?? null) !== 'open') {
                throw new \InvalidArgumentException("a $kind needs ref_id pointing to an open (没完的) in this space");
            }
            $acts = $kind === 'reality' ? self::REALITY_ACTS : ($source === '世' ? self::OBSERVED_STOPS : self::STOP_ACTS);
            if (!in_array($trace['act'] ?? null, $acts, true)) {
                throw new \InvalidArgumentException("a $kind needs act: " . implode(' / ', $acts));
            }
        }
        $judgment = $this->nullableText($trace['judgment'] ?? null, null);
        if ($kind === 'stop' && $source === 'AI' && ($trace['act'] ?? null) === '卡住' && $judgment === null) {
            throw new \InvalidArgumentException('卡住 needs judgment：要是你来定，你选哪个、凭什么（不能只把选择交回去）');
        }
        $newGround = $this->nullableText($trace['new_ground'] ?? null, null);
        $nextOpen = isset($trace['next_open']) && $trace['next_open'] !== '' ? (int)$trace['next_open'] : null;
        if ($nextOpen !== null && ($kind !== 'stop' || ($trace['act'] ?? null) !== '接着'
                || ($this->getTrace($spaceType, $spaceId, $nextOpen)['kind'] ?? null) !== 'open')) {
            throw new \InvalidArgumentException('next_open only goes with a 接着 stop, and must point to an open in this space');
        }
        if ($nextOpen !== null && $nextOpen !== $refId && $source === 'AI') {
            // 转向别的一件：这是一次方向判断，要有执行效力，也就要留下理由；转向一件停着的，还要说清什么变了
            $target = $this->openState($spaceType, $spaceId, $nextOpen);
            if ($target === null || $target['closed']) {
                throw new \InvalidArgumentException('next_open points to an open that is already closed (收了，交互对象定的)');
            }
            if ($judgment === null) {
                throw new \InvalidArgumentException('next_open to another open needs judgment：为什么现在做它比这一件更值得，你还不确定的前提是什么');
            }
            if (!$target['pulls'] && $newGround === null) {
                throw new \InvalidArgumentException(sprintf('next_open #%d is stopped at 「%s」: needs new_ground，相比它停下那时，什么变了', $nextOpen, $target['state']));
            }
        }
        if ($kind === 'checked') {
            $target = $refId === null ? null : $this->getTrace($spaceType, $spaceId, $refId);
            if ($target === null || $target['judgment'] === null) {
                throw new \InvalidArgumentException('checked needs ref_id pointing to a trace that carries a judgment, in this space');
            }
            if (!in_array($trace['act'] ?? null, self::CHECK_ACTS, true)) {
                throw new \InvalidArgumentException('checked needs act: ' . implode(' / ', self::CHECK_ACTS));
            }
        }
        $confidence = isset($trace['confidence']) ? max(0, min(100, (int)$trace['confidence'])) : null;
        if ($source !== 'AI') {
            $confidence = null;
        }
        $touched = array_key_exists('touched_reality', $trace) && $trace['touched_reality'] !== null
            ? ((bool)$trace['touched_reality'] ? 1 : 0)
            : null;

        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('cognos_space_traces')->values([
            'space_type' => $qb->createNamedParameter($spaceType),
            'space_id' => $qb->createNamedParameter($spaceId),
            'kind' => $qb->createNamedParameter($kind),
            'source' => $qb->createNamedParameter($source),
            'author' => $qb->createNamedParameter(mb_substr(trim((string)($trace['author'] ?? '')) ?: '未署名', 0, 128)),
            'content' => $qb->createNamedParameter($content),
            'said_at' => $qb->createNamedParameter($trace['said_at'] ?? null, IQueryBuilder::PARAM_INT),
            'act' => $qb->createNamedParameter($this->nullableText($trace['act'] ?? null, 32)),
            'confidence' => $qb->createNamedParameter($confidence, IQueryBuilder::PARAM_INT),
            'status' => $qb->createNamedParameter($status),
            'about' => $qb->createNamedParameter($this->nullableText($trace['about'] ?? null, 255)),
            'stopped_at' => $qb->createNamedParameter($this->nullableText($trace['stopped_at'] ?? null, null)),
            'touched_reality' => $qb->createNamedParameter($touched, IQueryBuilder::PARAM_INT),
            'ref_id' => $qb->createNamedParameter($refId, IQueryBuilder::PARAM_INT),
            'governs' => $qb->createNamedParameter($this->nullableText($trace['governs'] ?? null, null)),
            'judgment' => $qb->createNamedParameter($judgment),
            'new_ground' => $qb->createNamedParameter($newGround),
            'next_open' => $qb->createNamedParameter($nextOpen, IQueryBuilder::PARAM_INT),
            'created_at' => $qb->createNamedParameter((int)($trace['created_at'] ?? $now), IQueryBuilder::PARAM_INT),
        ])->executeStatement();

        return $this->getTrace($spaceType, $spaceId, (int)$qb->getLastInsertId()) ?? [];
    }

    public function getTrace(string $spaceType, string $spaceId, int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('cognos_space_traces')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('space_type', $qb->createNamedParameter($spaceType)))
            ->andWhere($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId)));
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->normalizeTrace($row) : null;
    }

    /**
     * 这片空间的全部发生，按时间排；每条带上 effective_status（按最新的更正算）和 corrections。
     *
     * @return list<array>
     */
    public function listTraces(string $spaceType, string $spaceId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('cognos_space_traces')
            ->where($qb->expr()->eq('space_type', $qb->createNamedParameter($spaceType)))
            ->andWhere($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId)))
            ->orderBy('id', 'ASC');
        $rows = array_map(fn(array $row): array => $this->normalizeTrace($row), $qb->executeQuery()->fetchAllAssociative());

        $byId = [];
        foreach ($rows as $row) {
            $row['effective_status'] = $row['status'];
            $row['corrections'] = [];
            $byId[$row['id']] = $row;
        }
        foreach ($rows as $row) {
            if ($row['kind'] === 'correction' && $row['ref_id'] !== null && isset($byId[$row['ref_id']])) {
                // 行按 id 升序，后来的更正覆盖先前的
                $byId[$row['ref_id']]['effective_status'] = $row['status'];
                $byId[$row['ref_id']]['corrections'][] = ['id' => $row['id'], 'status' => $row['status'], 'content' => $row['content'], 'author' => $row['author'], 'source' => $row['source'], 'created_at' => $row['created_at']];
            }
        }
        return array_values($byId);
    }

    public function searchTraces(string $spaceType, string $spaceId, string $query, int $limit = 20): array {
        $query = trim($query);
        $all = $this->listTraces($spaceType, $spaceId);
        if ($query === '') {
            return array_slice(array_reverse($all), 0, $limit);
        }
        $hits = array_filter($all, static function (array $t) use ($query): bool {
            foreach (['content', 'about', 'stopped_at', 'kind', 'author'] as $field) {
                if ($t[$field] !== null && mb_stripos((string)$t[$field], $query) !== false) {
                    return true;
                }
            }
            return false;
        });
        return array_slice(array_values($hits), 0, $limit);
    }

    /**
     * 押着的那位面对顶到它的一下。只有押的那位能面对。
     * 碎了：前提被撤回。重新切：写一条新前提（ref_id 指向旧的），旧的被撤回。都是新写的发生，旧的不改。
     *
     * @return array{answer: array, premise: ?array}
     */
    public function answerHit(string $spaceType, string $spaceId, int $hitId, string $source, string $author, string $act, string $content, ?int $confidence, ?string $newPremise = null, ?string $governs = null): array {
        $hit = $this->getTrace($spaceType, $spaceId, $hitId);
        if ($hit === null || $hit['kind'] !== 'hit') {
            throw new \InvalidArgumentException('hit not found in this space');
        }
        $premiseId = $this->premiseIdOfHit($spaceType, $spaceId, $hit);
        $premise = $premiseId === null ? null : $this->premiseState($spaceType, $spaceId, $premiseId);
        if ($premise === null) {
            throw new \InvalidArgumentException('the premise this hit points to is gone');
        }
        if ($premise['author'] !== $author) {
            throw new \InvalidArgumentException(sprintf('只有押着 #%d 的 %s 能面对这一下', $premise['id'], $premise['author']));
        }
        if (!$premise['standing']) {
            throw new \InvalidArgumentException(sprintf('#%d 已经不押了（%s）', $premise['id'], $premise['effective_status']));
        }
        if (!in_array($act, self::ANSWER_ACTS, true)) {
            throw new \InvalidArgumentException('act must be one of ' . implode(' / ', self::ANSWER_ACTS));
        }
        $newPremise = trim((string)$newPremise);
        if ($act === '重新切' && $newPremise === '') {
            throw new \InvalidArgumentException('重新切 needs the new premise');
        }

        $this->db->beginTransaction();
        try {
            $answer = $this->addTrace($spaceType, $spaceId, [
                'kind' => 'answer',
                'source' => $source,
                'author' => $author,
                'content' => $content,
                'act' => $act,
                'confidence' => $confidence,
                'ref_id' => $hitId,
                'said_at' => $source === 'AI' ? null : time(),
            ]);
            $recut = null;
            if ($act === '碎了') {
                $this->addTrace($spaceType, $spaceId, [
                    'kind' => 'correction',
                    'source' => $source,
                    'author' => $author,
                    'content' => sprintf('被 #%d 顶碎了，见 #%d', $hitId, $answer['id']),
                    'ref_id' => $premise['id'],
                    'status' => 'withdrawn',
                ]);
            } elseif ($act === '重新切') {
                $recut = $this->addTrace($spaceType, $spaceId, [
                    'kind' => 'premise',
                    'source' => $source,
                    'author' => $author,
                    'content' => $newPremise,
                    'confidence' => $confidence,
                    'governs' => trim((string)$governs) !== '' ? $governs : $premise['governs'],
                    'ref_id' => $premise['id'],
                    'said_at' => $source === 'AI' ? null : time(),
                ]);
                $this->addTrace($spaceType, $spaceId, [
                    'kind' => 'correction',
                    'source' => $source,
                    'author' => $author,
                    'content' => sprintf('被 #%d 顶了以后重新切成 #%d', $hitId, $recut['id']),
                    'ref_id' => $premise['id'],
                    'status' => 'withdrawn',
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        return ['answer' => $answer, 'premise' => $recut];
    }

    /**
     * 这片空间里押着的每一条，带上顶到它的每一下和押的那位怎么面对的。
     * standing：还押着（没被撤回、没被否）；open_hits：顶了还没面对的几下。
     *
     * @param ?list<array> $traces 已经取过的 listTraces 结果，省一次查询
     * @return list<array>
     */
    public function premises(string $spaceType, string $spaceId, ?array $traces = null): array {
        $traces ??= $this->listTraces($spaceType, $spaceId);
        $premises = [];
        $hits = [];
        $answers = [];
        $answerById = [];
        $hitById = [];
        $evaded = [];
        $unchanged = [];
        foreach ($traces as $t) {
            if ($t['kind'] === 'answer') {
                $answerById[$t['id']] = $t;
            } elseif ($t['kind'] === 'hit') {
                $hitById[$t['id']] = $t;
            } elseif ($t['kind'] === 'unanswered' && $t['ref_id'] !== null) {
                $evaded[$t['ref_id']][] = $t;
            } elseif ($t['kind'] === 'unchanged' && $t['ref_id'] !== null) {
                $unchanged[$t['ref_id']] = $t;
            }
        }
        foreach ($traces as $t) {
            if ($t['kind'] === 'premise') {
                $t['holder'] = $t['author'];
                $t['standing'] = !in_array($t['effective_status'], ['rejected', 'withdrawn'], true);
                $t['recut_from'] = $t['ref_id'];
                $t['recut_to'] = null;
                $t['hits'] = [];
                $t['open_hits'] = 0;
                // 重新切出来，governs 写到的文件一处都没改：只改了说法（〔世〕，见 PushBackService::afterReturn）
                $t['unchanged'] = $unchanged[$t['id']] ?? null;
                $premises[$t['id']] = $t;
            } elseif ($t['kind'] === 'hit') {
                $hits[] = $t;
            } elseif ($t['kind'] === 'answer' && $t['ref_id'] !== null) {
                // 按 id 升序，同一下后来的回答算数
                $answers[$t['ref_id']] = $t;
            }
        }
        foreach ($premises as $p) {
            if ($p['recut_from'] !== null && isset($premises[$p['recut_from']])) {
                $premises[$p['recut_from']]['recut_to'] = $p['id'];
            }
        }
        foreach ($hits as $h) {
            $pid = $this->resolveHitTarget($h, $answerById, $hitById);
            if ($pid === null || !isset($premises[$pid])) {
                continue;
            }
            $h['premise_id'] = $pid;
            // 顶的是一次「站住」，不是前提本身
            $h['against_answer'] = $pid !== $h['ref_id'] ? $h['ref_id'] : null;
            $h['answer'] = $answers[$h['id']] ?? null;
            // 叫回来过、做完走了却没面对的次数
            $h['evaded'] = count($evaded[$h['id']] ?? []);
            $premises[$pid]['hits'][] = $h;
            $premises[$pid]['evaded'] = ($premises[$pid]['evaded'] ?? 0) + $h['evaded'];
            if ($h['answer'] === null && $premises[$pid]['standing']) {
                $premises[$pid]['open_hits']++;
            }
        }
        foreach ($premises as &$p) {
            $p['evaded'] ??= 0;
        }
        unset($p);
        return array_values($premises);
    }

    /**
     * 这片空间里挂着的每一件没完的，带上它走过的每一次（停在哪、检查）。
     * state：还没走 / 接着 / 卡住 / 完了 / 原地 / 没说 / 时限 / 没过（检查没过）/ 收了（交互对象确认完了或撤回）。
     * pulls：它自己还拉着下一次（还没走、接着、时限、检查没过），Cognos 会从它接着叫。
     *
     * @param ?list<array> $traces 已经取过的 listTraces 结果，省一次查询
     * @return list<array>
     */
    public function opens(string $spaceType, string $spaceId, ?array $traces = null): array {
        $traces ??= $this->listTraces($spaceType, $spaceId);
        $opens = [];
        foreach ($traces as $t) {
            if ($t['kind'] === 'open') {
                $t['closed'] = $t['effective_status'] !== 'said';
                $t['steps'] = [];
                $opens[$t['id']] = $t;
            } elseif (in_array($t['kind'], ['stop', 'reality'], true) && isset($opens[$t['ref_id']])) {
                if ($t['kind'] === 'stop' && $t['effective_status'] === 'withdrawn') {
                    // 它自己撤回的停（选错了，改向）：留在痕迹里，连同撤回的理由，但不再算数
                    continue;
                }
                $opens[$t['ref_id']]['steps'][] = $t;
            }
        }
        foreach ($opens as &$o) {
            $last = $o['steps'] === [] ? null : $o['steps'][count($o['steps']) - 1];
            $o['last'] = $last;
            $o['edges'] = array_values(array_map(
                static fn(array $s): array => ['id' => $s['id'], 'edge' => (string)$s['stopped_at']],
                array_filter($o['steps'], static fn(array $s): bool => $s['kind'] === 'stop' && $s['act'] === '接着' && $s['stopped_at'] !== null),
            ));
            $stops = array_values(array_filter($o['steps'], static fn(array $s): bool => $s['kind'] === 'stop'));
            $lastStop = $stops === [] ? null : $stops[count($stops) - 1];
            if ($o['closed']) {
                $o['state'] = '收了';
            } elseif ($last !== null && $last['kind'] === 'reality' && $last['act'] === '没过') {
                // 检查没过：不管它自己说停在哪，都还拉着下一次
                $o['state'] = '没过';
            } else {
                $o['state'] = $lastStop === null ? '还没走' : (string)$lastStop['act'];
            }
            $o['runs'] = count(array_filter($stops, static fn(array $s): bool => $s['source'] === 'AI'));
            $o['last_stop'] = $lastStop;
            $o['pulls'] = in_array($o['state'], ['还没走', '接着', '时限', '没过'], true);
        }
        unset($o);
        return array_values($opens);
    }

    public function openState(string $spaceType, string $spaceId, int $id): ?array {
        foreach ($this->opens($spaceType, $spaceId) as $o) {
            if ($o['id'] === $id) {
                return $o;
            }
        }
        return null;
    }

    /**
     * 押的那位面对 #hitId 时重新切出来的那条新前提；没重新切就是 null。
     *
     * @param ?list<array> $traces
     */
    public function recutOf(string $spaceType, string $spaceId, int $hitId, string $author, ?array $traces = null): ?array {
        $traces ??= $this->listTraces($spaceType, $spaceId);
        $answer = null;
        foreach ($traces as $t) {
            if ($t['kind'] === 'answer' && (int)$t['ref_id'] === $hitId && $t['author'] === $author) {
                $answer = $t; // 同一下后来的回答算数
            }
        }
        if ($answer === null || $answer['act'] !== '重新切') {
            return null;
        }
        foreach ($traces as $t) {
            // answerHit 在同一个事务里先写 answer、再写新前提
            if ($t['kind'] === 'premise' && $t['id'] > $answer['id'] && $t['author'] === $author && $t['ref_id'] !== null) {
                return $t;
            }
        }
        return null;
    }

    /**
     * governs 里写到的文件（lib/Service/X.php::method 取 lib/Service/X.php）。
     * 只写了做法、没写文件的，返回空：那种查不了改没改。
     *
     * @return list<string>
     */
    public static function governedFiles(?string $governs): array {
        if ($governs === null) {
            return [];
        }
        preg_match_all('#[A-Za-z0-9_\-./]+\.(?:php|py|js|ts|vue|json|css|md|xml|sh)\b#u', $governs, $m);
        return array_values(array_unique(array_map(static fn(string $f): string => ltrim($f, './'), $m[0])));
    }

    /**
     * governs 写到的文件里，这次改了哪几个。$changed 是仓库里改了的路径（相对仓库根），
     * governs 里多半写的是相对应用目录的路径，所以按结尾对。
     *
     * @param list<string> $changed
     * @return array{governed: list<string>, touched: list<string>}
     */
    public static function touchedGoverned(?string $governs, array $changed): array {
        $governed = self::governedFiles($governs);
        $touched = [];
        foreach ($governed as $g) {
            foreach ($changed as $c) {
                $c = str_replace('\\', '/', (string)$c);
                if ($c === $g || str_ends_with($c, '/' . $g)) {
                    $touched[] = $g;
                    break;
                }
            }
        }
        return ['governed' => $governed, 'touched' => $touched];
    }

    /** 一下顶最终顶到的是哪条前提：直接指前提，或指一次站住（顺着它面对的那一下找回去）。 */
    public function premiseIdOfHit(string $spaceType, string $spaceId, array $hit): ?int {
        $answerById = [];
        $hitById = [];
        foreach ($this->listTraces($spaceType, $spaceId) as $t) {
            if ($t['kind'] === 'answer') {
                $answerById[$t['id']] = $t;
            } elseif ($t['kind'] === 'hit') {
                $hitById[$t['id']] = $t;
            }
        }
        return $this->resolveHitTarget($hit, $answerById, $hitById);
    }

    private function resolveHitTarget(array $hit, array $answerById, array $hitById): ?int {
        $ref = $hit['ref_id'] === null ? null : (int)$hit['ref_id'];
        // 顶站住 → 站住面对的那一下 → 那一下顶的；最多顺 16 层，防环
        for ($depth = 0; $ref !== null && $depth < 16; $depth++) {
            if (!isset($answerById[$ref])) {
                return $ref;
            }
            $answeredHit = $hitById[$answerById[$ref]['ref_id']] ?? null;
            $ref = $answeredHit === null || $answeredHit['ref_id'] === null ? null : (int)$answeredHit['ref_id'];
        }
        return null;
    }

    /** 一条前提现在的样子（带 standing、hits）；不是前提就返回 null。 */
    public function premiseState(string $spaceType, string $spaceId, int $id): ?array {
        foreach ($this->premises($spaceType, $spaceId) as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }

    /**
     * @param list<int> $refs
     */
    public function addReading(string $spaceType, string $spaceId, string $reader, string $content, ?int $confidence, array $refs = []): array {
        $reader = mb_substr(trim($reader) ?: '未署名', 0, 128);
        $content = trim($content);
        if ($content === '') {
            throw new \InvalidArgumentException('content is required');
        }
        $refs = array_values(array_filter(array_map('intval', $refs), fn(int $id): bool => $this->getTrace($spaceType, $spaceId, $id) !== null));
        $previous = $this->latestReadingOf($spaceType, $spaceId, $reader);

        $this->db->beginTransaction();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('cognos_space_readings')->values([
                'space_type' => $qb->createNamedParameter($spaceType),
                'space_id' => $qb->createNamedParameter($spaceId),
                'reader' => $qb->createNamedParameter($reader),
                'content' => $qb->createNamedParameter($content),
                'confidence' => $qb->createNamedParameter($confidence === null ? null : max(0, min(100, $confidence)), IQueryBuilder::PARAM_INT),
                'refs' => $qb->createNamedParameter(json_encode($refs)),
                'superseded_by' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT),
                'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            ])->executeStatement();
            $id = (int)$qb->getLastInsertId();
            if ($previous !== null) {
                // 旧读法不删，只标上被哪一条替换
                $up = $this->db->getQueryBuilder();
                $up->update('cognos_space_readings')
                    ->set('superseded_by', $up->createNamedParameter($id, IQueryBuilder::PARAM_INT))
                    ->where($up->expr()->eq('id', $up->createNamedParameter($previous['id'], IQueryBuilder::PARAM_INT)))
                    ->executeStatement();
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        return $this->latestReadingOf($spaceType, $spaceId, $reader) ?? [];
    }

    /** 每个读者最新的一条读法。 */
    public function currentReadings(string $spaceType, string $spaceId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('cognos_space_readings')
            ->where($qb->expr()->eq('space_type', $qb->createNamedParameter($spaceType)))
            ->andWhere($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId)))
            ->andWhere($qb->expr()->isNull('superseded_by'))
            ->orderBy('created_at', 'ASC');
        return array_map(fn(array $row): array => $this->normalizeReading($row), $qb->executeQuery()->fetchAllAssociative());
    }

    public function readingsSince(string $spaceType, string $spaceId, int $since): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('cognos_space_readings')
            ->where($qb->expr()->eq('space_type', $qb->createNamedParameter($spaceType)))
            ->andWhere($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId)))
            ->andWhere($qb->expr()->gt('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC');
        return array_map(fn(array $row): array => $this->normalizeReading($row), $qb->executeQuery()->fetchAllAssociative());
    }

    /** 上次来的时间；没来过返回 null。 */
    public function lastSeen(string $spaceType, string $spaceId, string $subject): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('seen_at')->from('cognos_space_seen')
            ->where($qb->expr()->eq('space_type', $qb->createNamedParameter($spaceType)))
            ->andWhere($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId)))
            ->andWhere($qb->expr()->eq('subject', $qb->createNamedParameter($subject)));
        $value = $qb->executeQuery()->fetchOne();
        return $value === false ? null : (int)$value;
    }

    public function markSeen(string $spaceType, string $spaceId, string $subject): void {
        $now = time();
        $up = $this->db->getQueryBuilder();
        $changed = $up->update('cognos_space_seen')
            ->set('seen_at', $up->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($up->expr()->eq('space_type', $up->createNamedParameter($spaceType)))
            ->andWhere($up->expr()->eq('space_id', $up->createNamedParameter($spaceId)))
            ->andWhere($up->expr()->eq('subject', $up->createNamedParameter($subject)))
            ->executeStatement();
        if ($changed === 0) {
            $in = $this->db->getQueryBuilder();
            $in->insert('cognos_space_seen')->values([
                'space_type' => $in->createNamedParameter($spaceType),
                'space_id' => $in->createNamedParameter($spaceId),
                'subject' => $in->createNamedParameter(mb_substr($subject, 0, 128)),
                'seen_at' => $in->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ])->executeStatement();
        }
    }

    private function latestReadingOf(string $spaceType, string $spaceId, string $reader): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('cognos_space_readings')
            ->where($qb->expr()->eq('space_type', $qb->createNamedParameter($spaceType)))
            ->andWhere($qb->expr()->eq('space_id', $qb->createNamedParameter($spaceId)))
            ->andWhere($qb->expr()->eq('reader', $qb->createNamedParameter($reader)))
            ->andWhere($qb->expr()->isNull('superseded_by'))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->normalizeReading($row) : null;
    }

    /**
     * 自己下过的判断，和现实对它的每一次对照。
     * 返回 ['open' => 还没被别人或现实对过的判断, 'record' => [author => ['对上' => n, '没对上' => n, '半对' => n]]]。
     * 只算〔AI〕下的判断。对它的那一下如果是下判断的这一位自己做的，不算数：
     * 它能自己让一条判断成立（比如预判自己接下来会不会再走），自己对自己证明不了判断力。
     * 这种自对自的留在 open 里，带 self_checks，入口里照实写出来。
     */
    public function judgments(string $spaceType, string $spaceId): array {
        $traces = $this->listTraces($spaceType, $spaceId);
        $judged = [];
        foreach ($traces as $t) {
            if ($t['judgment'] !== null && $t['source'] === 'AI' && $t['kind'] !== 'checked') {
                $judged[$t['id']] = $t + ['checks' => [], 'self_checks' => 0];
            }
        }
        foreach ($traces as $t) {
            if ($t['kind'] === 'checked' && isset($judged[$t['ref_id']])) {
                $mine = $t['source'] === 'AI' && $t['author'] === $judged[$t['ref_id']]['author'];
                if ($mine) {
                    $judged[$t['ref_id']]['self_checks']++;
                } else {
                    $judged[$t['ref_id']]['checks'][] = $t;
                }
            }
        }
        $open = [];
        $record = [];
        foreach ($judged as $j) {
            if ($j['checks'] === []) {
                $open[] = $j;
                continue;
            }
            $last = $j['checks'][count($j['checks']) - 1];
            $record[$j['author']][$last['act']] = ($record[$j['author']][$last['act']] ?? 0) + 1;
        }
        return ['open' => $open, 'record' => $record];
    }

    private function normalizeTrace(array $row): array {
        return [
            'id' => (int)$row['id'],
            'kind' => (string)$row['kind'],
            'source' => (string)$row['source'],
            'author' => (string)$row['author'],
            'content' => (string)$row['content'],
            'said_at' => $row['said_at'] === null ? null : (int)$row['said_at'],
            'act' => $row['act'] === null ? null : (string)$row['act'],
            'confidence' => $row['confidence'] === null ? null : (int)$row['confidence'],
            'status' => (string)$row['status'],
            'about' => $row['about'] === null ? null : (string)$row['about'],
            'stopped_at' => $row['stopped_at'] === null ? null : (string)$row['stopped_at'],
            'touched_reality' => $row['touched_reality'] === null ? null : ((int)$row['touched_reality'] === 1),
            'ref_id' => $row['ref_id'] === null ? null : (int)$row['ref_id'],
            'governs' => ($row['governs'] ?? null) === null ? null : (string)$row['governs'],
            'judgment' => ($row['judgment'] ?? null) === null ? null : (string)$row['judgment'],
            'new_ground' => ($row['new_ground'] ?? null) === null ? null : (string)$row['new_ground'],
            'next_open' => ($row['next_open'] ?? null) === null ? null : (int)$row['next_open'],
            'created_at' => (int)$row['created_at'],
        ];
    }

    private function normalizeReading(array $row): array {
        $refs = json_decode((string)($row['refs'] ?? '[]'), true);
        return [
            'id' => (int)$row['id'],
            'reader' => (string)$row['reader'],
            'content' => (string)$row['content'],
            'confidence' => $row['confidence'] === null ? null : (int)$row['confidence'],
            'refs' => is_array($refs) ? array_map('intval', $refs) : [],
            'superseded_by' => $row['superseded_by'] === null ? null : (int)$row['superseded_by'],
            'created_at' => (int)$row['created_at'],
        ];
    }

    private function nullableText(mixed $value, ?int $max): ?string {
        if ($value === null) {
            return null;
        }
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        return $max === null ? $text : mb_substr($text, 0, $max);
    }
}
