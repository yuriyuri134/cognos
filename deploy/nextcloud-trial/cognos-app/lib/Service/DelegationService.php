<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * 从一个位置「交出去」一件事：一句话，加可选的要做成什么、用什么、不能碰什么、什么时候回来。
 *
 * - 交出去就记一条委托，在这个位置的讨论里留下这句话，经桥交给本机 Claude Code，运行挂在这条委托上
 *   （target_type = delegation），所以它出现在「谁在动什么」里，结果回到这个位置的讨论。
 * - 给了改这一处当前内容的权限，AI 经 write_content 交回的全文作为它署名的新版本提交，带交出去时的版本号
 *   （expected revision）；起步后有人改过、或者有了它输入里没有的新决定，就不覆盖，留作待比较的版本。
 *   没给权限，它交回的只是回答。
 * - 什么时候回来只是约定：过了时间还没回来，页面照实说，不替它停下，也不推定它失败了。
 *
 * 设计见 docs/项目管理/Cognos项目设计/02-协作/共同工作怎样发生.md 三、五节。
 */
final class DelegationService {
    private const MAX_TEXT = 2000;
    private const RETURN_MINUTES = [10, 30, 60, 120, 240, 1440];

    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
        private ProjectService $projects,
        private CreationSpaceService $creations,
        private DecisionService $decisions,
        private RunService $runs,
        private HermesAgentService $agents,
    ) {
    }

    /**
     * 交出去。先把委托和那句话存下（同一请求编号只存一次），再叫桥；桥没接下，委托留着并写明原因。
     *
     * @return array{delegation_id: string, message_id: int, state: string, note: ?string}
     */
    public function delegate(string $uid, string $locationId, string $ask, string $goal, string $means, string $forbidden,
        int $returnMinutes, bool $mayEdit, string $requestId, bool $mayAdopt = false): array {
        $ask = trim($ask);
        if ($ask === '' || mb_strlen($ask) > self::MAX_TEXT) {
            throw new \InvalidArgumentException('要交出去的事写一句话，最多 ' . self::MAX_TEXT . ' 字');
        }
        [$goal, $means, $forbidden] = array_map(fn(string $t): string => mb_substr(trim($t), 0, self::MAX_TEXT), [$goal, $means, $forbidden]);
        if (!in_array($returnMinutes, self::RETURN_MINUTES, true)) {
            $returnMinutes = 30;
        }
        if ($this->config->getAppValue('cognos', 'creation_agent', 'hermes') !== 'claude-code') {
            throw new \RuntimeException('交出去现在只接本机的 Claude Code（creation_agent 不是 claude-code）', 422);
        }
        $saved = $this->projects->commit($uid, $requestId, ['delegate', $locationId, $ask, $goal, $means, $forbidden, $returnMinutes, $mayEdit, $mayAdopt],
            function () use ($uid, $locationId, $ask, $goal, $means, $forbidden, $returnMinutes, $mayEdit, $mayAdopt): array {
                $loc = $this->projects->location($uid, $locationId);
                $project = $this->projects->lockedProject($uid,$loc['project_id']);
                if ($project['state'] !== 'active') { throw new ProjectInactiveException('项目已停止派新工作，请先调整推进安排'); }
                $now = time();
                $returnBy = $now + $returnMinutes * 60;
                $text = $this->message($ask, $goal, $means, $forbidden, $this->localTime($uid, $returnBy), $mayEdit, $loc['revision'], $mayAdopt);
                $message = $this->creations->addMessage($loc['creation_id'], $uid, 'user', $text);
                $id = bin2hex(random_bytes(16));
                $this->projects->insert('cognos_delegations', [
                    'id' => $id, 'project_id' => $loc['project_id'], 'location_id' => $locationId, 'ask' => $ask,
                    'goal' => $goal === '' ? null : $goal, 'means' => $means === '' ? null : $means,
                    'forbidden' => $forbidden === '' ? null : $forbidden, 'return_by' => $returnBy,
                    'may_edit' => $mayEdit ? 1 : 0, 'may_adopt' => $mayAdopt ? 1 : 0, 'base_revision' => $loc['revision'], 'state' => 'sending', 'note' => null,
                    'run_id' => null, 'message_id' => (int)$message['id'], 'result_message_id' => null, 'applied_revision' => null,
                    'held_content' => null, 'held_base_revision' => null, 'held_reason' => null, 'held_at' => null,
                    'created_by' => $uid, 'created_at' => $now, 'updated_at' => $now,
                ]);
                return ['delegation_id' => $id, 'message_id' => (int)$message['id']];
            });
        $this->send($uid, $saved['delegation_id']);
        $d = $this->get($saved['delegation_id']);
        $run = empty($d['run_id']) ? null : $this->runs->get((string)$d['run_id']);
        return $saved + ['run_state' => $run['state'] ?? null, 'state' => (string)$d['state'], 'note' => $d['note'] === null ? null : (string)$d['note']];
    }

    /** 这个位置交出去过的事，最近的在前，带运行状态、是否过了约定时间。 */
    public function forLocation(string $uid, string $locationId): array {
        $this->projects->location($uid, $locationId);
        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('d.*', 'r.state AS run_state', 'r.subject')->from('cognos_delegations', 'd')
            ->leftJoin('d', 'cognos_runs', 'r', $qb->expr()->eq('r.id', 'd.run_id'))
            ->where($qb->expr()->eq('d.location_id', $qb->createNamedParameter($locationId)))
            ->orderBy('d.created_at', 'DESC')->addOrderBy('d.id', 'ASC')->setMaxResults(20)
            ->executeQuery()->fetchAll();
        $now = time();
        return array_map(static function (array $r) use ($now): array {
            $int = static fn($v): ?int => $v === null ? null : (int)$v;
            return [
                'id' => (string)$r['id'], 'ask' => (string)$r['ask'], 'goal' => $r['goal'], 'means' => $r['means'],
                'forbidden' => $r['forbidden'], 'return_by' => (int)$r['return_by'], 'may_edit' => (int)$r['may_edit'] === 1,
                'may_adopt' => (int)($r['may_adopt'] ?? 0) === 1,
                'base_revision' => (int)$r['base_revision'], 'state' => (string)$r['state'], 'note' => $r['note'],
                'run_id' => $r['run_id'], 'run_state' => $r['run_state'], 'subject' => $r['subject'],
                'message_id' => (int)$r['message_id'], 'result_message_id' => $int($r['result_message_id']),
                'applied_revision' => $int($r['applied_revision']), 'held_content' => $r['held_content'],
                'held_base_revision' => $int($r['held_base_revision']), 'held_reason' => $r['held_reason'],
                'held_at' => $int($r['held_at']), 'created_at' => (int)$r['created_at'],
                'overdue' => in_array((string)$r['state'], ['sending', 'starting', 'sent'], true) && $now > (int)$r['return_by'],
            ];
        }, $rows);
    }

    /**
     * 项目里各位置的委托：还没回来的几件、留着待比较的版本几个。
     *
     * @return array<string, array{open: int, held: int}>
     */
    public function countsForProject(string $projectId): array {
        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('location_id', 'state', 'held_content')->from('cognos_delegations')
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
            ->executeQuery()->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $slot = &$out[(string)$r['location_id']];
            $slot ??= ['open' => 0, 'held' => 0];
            if (in_array((string)$r['state'], ['sending', 'starting', 'sent'], true)) { $slot['open']++; }
            if ($r['held_content'] !== null) { $slot['held']++; }
            unset($slot);
        }
        return $out;
    }

    /** 结果交回时：这条委托回来了，回答是哪条消息。 */
    public function returned(string $delegationId, int $messageId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('cognos_delegations')
            ->set('state', $qb->createNamedParameter('returned'))
            ->set('result_message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($delegationId)))
            ->executeStatement();
    }

    /** 给入口的几行：这次交给它的事能不能改这一处当前内容。 */
    public function canDoLines(string $delegationId): array {
        $d = $this->get($delegationId);
        if ($d === null) {
            return [];
        }
        $adopt = (int)($d['may_adopt'] ?? 0) === 1
            ? ['adopt：这次交给你的事允许你在这一处采用一样东西的一版（讨论或材料里的一段回答、当前内容的某一版），采用者记你，依据记这次委托。']
            : [];
        if ((int)$d['may_edit'] !== 1) {
            return array_merge(['这次交给你的事没有给改这一处当前内容的权限，你交回的只作为回答；write_content 会被拒。'], $adopt);
        }
        return array_merge($adopt, [sprintf('write_content：这次交给你的事允许改这一处的当前内容（交出去时是版本 %d）。把改好的全文交给它，'
            . '会作为你署名的新版本保存；起步后有人改过这一处，就不覆盖，你的版本留着等人比较。只回答、不调用它也可以。', (int)$d['base_revision'])]);
    }

    /** 这次运行挂着的委托（没有就是 null）。 */
    public function forRun(string $runId): ?array {
        $qb = $this->db->getQueryBuilder();
        $r = $qb->select('*')->from('cognos_delegations')->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))->executeQuery()->fetch();
        return $r === false ? null : $r;
    }

    /**
     * AI 在委托里交回这一处的当前内容（全文）。只认正在进行、允许改内容、由它本人在做的那条委托。
     * 交出去以后这一处没被别人改过、也没有它输入里没有的新决定，就提交成它署名的新版本；否则不覆盖，留作待比较的版本。
     *
     * @return array{applied: bool, revision: ?int, current_revision: int, message: string}
     * @throws \DomainException 没有允许它改内容的委托
     */
    public function writeContent(string $uid, int $creationId, string $subject, string $content): array {
        if (mb_strlen($content) > 100000) {
            throw new \InvalidArgumentException('内容超过 10 万字');
        }
        $loc = $this->projects->locationForCreation($uid, $creationId);
        if ($loc === null) {
            throw new \DomainException('这片空间不是项目里的位置，没有当前内容可改');
        }
        $qb = $this->db->getQueryBuilder();
        $d = $qb->select('*')->from('cognos_delegations')
            ->where($qb->expr()->eq('location_id', $qb->createNamedParameter($loc['id'])))
            ->andWhere($qb->expr()->in('state', $qb->createNamedParameter(['starting', 'sent'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('created_at', 'DESC')->setMaxResults(1)->executeQuery()->fetch();
        // 桥刚接下时委托可能还没记上运行（starting），按挂在它上面的运行找
        $runId = $d === false ? null : ($d['run_id'] ?? $this->runFor((string)$d['id']));
        $run = $runId === null ? null : $this->runs->get((string)$runId);
        if ($run === null || !in_array($run['state'], ['queued', 'claimed', 'running'], true) || $run['subject'] !== $subject) {
            throw new \DomainException('这里没有正交给你在做的委托；你的结果只作为回答');
        }
        if ((int)$d['may_edit'] !== 1) {
            throw new \DomainException('这次交给你的事没有给改当前内容的权限；你的结果只作为回答');
        }
        $expected = $d['applied_revision'] !== null ? (int)$d['applied_revision'] : (int)$d['base_revision'];
        $missing = $this->decisions->missingForRun($uid, $run);
        $reason = null;
        if ($missing !== []) {
            $reason = '起步以后有了新的适用决定（' . implode('；', array_map(static fn(array $m): string => '「' . $m['content'] . '」', $missing)) . '），你的输入里没有';
        } elseif ($loc['revision'] !== $expected) {
            $reason = sprintf('交出去以后这一处被改过（你起步时是版本 %d，现在是版本 %d）', $expected, $loc['revision']);
        } else {
            $requestId = 'dlg-' . substr(hash('sha256', $d['id'] . ':' . $expected . ':' . $content), 0, 60);
            try {
                $saved = $this->projects->updateContent($uid, $loc['id'], $expected, $content, [], $requestId, $subject, (string)$d['id']);
                $this->update((string)$d['id'], ['applied_revision' => $saved['revision']]);
                return ['applied' => true, 'revision' => $saved['revision'], 'current_revision' => $saved['revision'],
                    'message' => sprintf('已保存为版本 %d，署名 %s。', $saved['revision'], $subject)];
            } catch (\RuntimeException $error) {
                if ($error->getCode() !== 409) {
                    throw $error;
                }
                $loc = $this->projects->location($uid, $loc['id']);
                $reason = $error->getMessage();
            }
        }
        $this->update((string)$d['id'], ['held_content' => $content, 'held_base_revision' => $expected,
            'held_reason' => $reason, 'held_at' => time()]);
        return ['applied' => false, 'revision' => null, 'current_revision' => $loc['revision'],
            'message' => $reason . '。没有覆盖，你的版本留着等人比较。'];
    }

    private function send(string $uid, string $id): void {
        // 只让一个请求去叫桥（同一请求重放、或两次同时到）
        $qb = $this->db->getQueryBuilder();
        $claimed = $qb->update('cognos_delegations')->set('state', $qb->createNamedParameter('starting'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('sending')))
            ->executeStatement();
        if ($claimed !== 1) {
            return;
        }
        $d = $this->get($id);
        $loc = $this->projects->location($uid, (string)$d['location_id']);
        $context = $this->creations->getContext($loc['creation_id'], $uid);
        $message = '';
        foreach ($context['messages'] as $m) {
            if ((int)$m['id'] === (int)$d['message_id']) {
                $message = (string)$m['content'];
            }
        }
        try {
            $this->agents->chatCreation($loc['creation_id'], $message, $context, (int)$d['message_id'],
                ['delegation_id' => $id, 'delegation_ask' => (string)$d['ask']], $this->canDoLines($id));
        } catch (\Throwable $error) {
            $runId = $this->runFor($id);
            $run = $runId === null ? null : $this->runs->get($runId);
            $this->update($id, ['state' => $run !== null && $run['state'] !== 'failed' ? 'sent' : 'not_taken',
                'note' => mb_substr($error->getMessage(), 0, 1000), 'run_id' => $runId], 'starting');
            return;
        }
        $this->update($id, ['state' => 'sent', 'run_id' => $this->runFor($id)], 'starting');
    }

    private function runFor(string $id): ?string {
        $qb = $this->db->getQueryBuilder();
        $r = $qb->select('id')->from('cognos_runs')
            ->where($qb->expr()->eq('target_type', $qb->createNamedParameter('delegation')))
            ->andWhere($qb->expr()->eq('target_id', $qb->createNamedParameter($id)))
            ->orderBy('created_at', 'DESC')->setMaxResults(1)->executeQuery()->fetch();
        return $r === false ? null : (string)$r['id'];
    }

    /** 留在讨论里的那句话：交出去的人填了什么就写什么，没填的不写。 */
    private function message(string $ask, string $goal, string $means, string $forbidden, string $returnBy, bool $mayEdit, int $revision, bool $mayAdopt = false): string {
        $lines = [$ask];
        if ($goal !== '') { $lines[] = '要做成：' . $goal; }
        if ($means !== '') { $lines[] = '用：' . $means; }
        if ($forbidden !== '') { $lines[] = '不要碰：' . $forbidden; }
        $lines[] = $returnBy . ' 前回来。';
        $lines[] = $mayEdit
            ? sprintf('可以改这一处的当前内容（交出去时是版本 %d）。', $revision)
            : '只要回答，不改这一处的当前内容。';
        if ($mayAdopt) { $lines[] = '可以在这一处采用（讨论或材料里的一段回答、当前内容的某一版）。'; }
        return implode("\n", $lines);
    }

    private function get(string $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $r = $qb->select('*')->from('cognos_delegations')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeQuery()->fetch();
        return $r === false ? null : $r;
    }

    /** 用户自己的时区（Nextcloud 个人设置），没设就按入口用的 Asia/Shanghai；容器里的 date() 是 UTC。 */
    private function localTime(string $uid, int $ts): string {
        $tz = $this->config->getUserValue($uid, 'core', 'timezone', '') ?: 'Asia/Shanghai';
        try { $zone = new \DateTimeZone($tz); } catch (\Exception) { $zone = new \DateTimeZone('Asia/Shanghai'); }
        return (new \DateTimeImmutable('@' . $ts))->setTimezone($zone)->format('Y-m-d H:i');
    }

    private function update(string $id, array $values, ?string $expectedState = null): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('cognos_delegations');
        foreach ($values + ['updated_at' => time()] as $key => $value) {
            $qb->set($key, $qb->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : (is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)));
        }
        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        if ($expectedState !== null) { $qb->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($expectedState))); }
        $qb->executeStatement();
    }
}
