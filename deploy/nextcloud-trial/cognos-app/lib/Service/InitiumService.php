<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Initium：还没有项目时就能做事的地方。它不是一种新东西，是还没成为某个项目位置讨论的那些会话，
 * 加上「这段会话促成了哪个项目」的形成关系。
 *
 * - 一句话或一个链接就能留下（建一段会话，第一句就是它）；要不要叫 AI 先试，由人决定。
 * - 确立：指向会话里的那几句，起个名字，可选写一段初步说明和理由，成为一个新项目，或成为已有项目的又一段来路。
 *   原会话不搬动、不改写，作为材料关联到项目根位置（用途写「来路」），还能接着聊；初步说明是新写的当前内容，
 *   不顶替最初的话。一段会话可以促成几个项目，一个项目也可以有几段来路。
 *
 * 设计见 docs/项目管理/Cognos项目设计/01-产品/开始与重组.md。
 */
final class InitiumService {
    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
        private ProjectService $projects,
        private CreationSpaceService $creations,
    ) {
    }

    /** 还没成为项目位置讨论的会话，最近的在前；带第一句、促成过哪些项目、AI 是否正在做。 */
    public function exploring(string $uid): array {
        $q=$this->db->getQueryBuilder();
        $rows=$q->select('id','title','root_location_id')->from('cognos_projects')
            ->where($q->expr()->eq('user_id',$q->createNamedParameter($uid)))
            ->andWhere($q->expr()->eq('state',$q->createNamedParameter('exploring')))
            ->orderBy('created_at','DESC')->executeQuery()->fetchAll();
        foreach ($rows as &$p) {
            $p['locations']=$this->projects->project($uid,$p['id'])['locations'];
            $history=$this->projects->lifecycleHistory($uid,$p['id']);
            $p['latest']=$history[0] ?? null;
        }
        unset($p);
        return $rows;
    }

    public function exploreFrom(string $uid,string $projectId,string $text,string $requestId): array {
        $text=trim($text);
        if ($text === '' || mb_strlen($text)>20000) { throw new \InvalidArgumentException('写下这次想探索的事，最多 2 万字'); }
        return $this->projects->commit($uid,$requestId,['explore_from',$projectId,$text],function()use($uid,$projectId,$text):array {
            $p=$this->projects->lockedProject($uid,$projectId);
            if ($p['state']!=='exploring') { throw new \RuntimeException('项目已离开自由探索状态，请查看当前推进安排',409); }
            $title=mb_substr(trim((string)strtok($text,"\n")),0,40);
            $chat=$this->creations->create($uid,$title,$text);
            $this->projects->newUsage($uid,$p['root_location_id'],(int)$chat['id'],'退回自由探索后另起的会话；原项目与讨论保留');
            return ['creation_id'=>(int)$chat['id'],'from_project_id'=>$projectId];
        });
    }

    public function list(string $uid): array {
        $qb = $this->db->getQueryBuilder();
        $placeChats = array_map('intval', array_column($qb->select('l.creation_id')->from('cognos_locations', 'l')
            ->innerJoin('l', 'cognos_projects', 'p', $qb->expr()->eq('p.id', 'l.project_id'))
            ->where($qb->expr()->eq('p.user_id', $qb->createNamedParameter($uid)))
            ->executeQuery()->fetchAll(), 'creation_id'));
        $items = array_values(array_filter($this->creations->list($uid), static fn(array $c): bool => !in_array($c['id'], $placeChats, true)));
        $formed = $this->formationsBySource($uid, array_column($items, 'id'));
        foreach ($items as &$c) {
            $qb = $this->db->getQueryBuilder();
            $first = $qb->select('id', 'content')->from('cognos_creation_messages')
                ->where($qb->expr()->eq('creation_id', $qb->createNamedParameter($c['id'], IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('role', $qb->createNamedParameter('user')))
                ->orderBy('id', 'ASC')->setMaxResults(1)->executeQuery()->fetch();
            $c['first'] = $first === false ? '' : mb_substr((string)$first['content'], 0, 200);
            $c['first_id'] = $first === false ? null : (int)$first['id'];
            $c['formed'] = $formed[$c['id']] ?? [];
            $q=$this->db->getQueryBuilder();
            $c['explored_from']=$q->select('p.id','p.title')->from('cognos_usages','u')
                ->innerJoin('u','cognos_projects','p',$q->expr()->eq('p.root_location_id','u.location_id'))
                ->where($q->expr()->eq('u.source_creation_id',$q->createNamedParameter($c['id'],IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('p.user_id',$q->createNamedParameter($uid)))
                ->andWhere($q->expr()->eq('u.purpose',$q->createNamedParameter('退回自由探索后另起的会话；原项目与讨论保留')))
                ->executeQuery()->fetchAll();
            $c['ai_working'] = $this->config->getAppValue('cognos', 'agent_pending_creation_' . $c['id'], '') !== '';
        }
        unset($c);
        return $items;
    }

    /** 留下一句话或一个链接：建一段会话，第一句就是它。不叫 AI。 */
    public function capture(string $uid, string $text, string $requestId): array {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 20000) {
            throw new \InvalidArgumentException('写一句话或贴一个链接，最多 2 万字');
        }
        $firstLine = trim((string)strtok($text, "\n"));
        $title = mb_strlen($firstLine) > 40 ? mb_substr($firstLine, 0, 40) . '…' : $firstLine;
        return $this->projects->commit($uid, $requestId, ['initium_capture', $text], function () use ($uid, $text, $title): array {
            $creation = $this->creations->create($uid, $title, $text);
            $first = $this->creations->getMessages((int)$creation['id'], $uid)[0] ?? null;
            return ['creation_id' => (int)$creation['id'], 'message_id' => $first === null ? null : (int)$first['id']];
        });
    }

    /**
     * 确立：从一段会话里指向的那几句，成为一个新项目（$projectId 为空），或成为已有项目的又一段来路。
     *
     * @param list<int> $messageIds
     */
    public function form(string $uid, int $creationId, array $messageIds, string $title, string $description, string $reason,
        string $projectId, string $requestId): array {
        $messageIds = array_values(array_unique(array_map('intval', $messageIds)));
        sort($messageIds);
        if ($messageIds === [] || count($messageIds) > 50) {
            throw new \InvalidArgumentException('先指向会话里促成它的那几句（1 到 50 句）');
        }
        $description = trim($description);
        $reason = trim($reason);
        if (mb_strlen($description) > 100000 || mb_strlen($reason) > 2000) {
            throw new \InvalidArgumentException('初步说明最多 10 万字，理由最多 2000 字');
        }
        return $this->projects->commit($uid, $requestId, ['initium_form', $creationId, $messageIds, $title, $description, $reason, $projectId],
            function () use ($uid, $creationId, $messageIds, $title, $description, $reason, $projectId): array {
                if ($this->creations->get($creationId, $uid) === null) {
                    throw new \RuntimeException('会话不存在或不可访问', 404);
                }
                $ids = array_map(static fn(array $m): int => (int)$m['id'], $this->creations->getMessages($creationId, $uid));
                if (array_diff($messageIds, $ids) !== []) {
                    throw new \InvalidArgumentException('指向的话不在这段会话里');
                }
                $new = $projectId === '';
                if ($new) {
                    $made = $this->projects->newProject($uid, $title);
                    $projectId = $made['project_id'];
                    $root = $made['location_id'];
                } else {
                    $root = (string)$this->projects->project($uid, $projectId)['root_location_id'];
                }
                // 原会话作为来路关联到根位置：不搬动、不改写，还能接着聊
                $already = array_column($this->projects->usages($uid, $root), 'source_creation_id');
                if (!in_array($creationId, $already, true) && $this->projects->location($uid, $root)['creation_id'] !== $creationId) {
                    $this->projects->newUsage($uid, $root, $creationId, $reason !== '' ? '来路：' . $reason : '来路：促成这个项目的会话');
                }
                if ($new && $description !== '') {
                    $this->projects->saveContent($uid, $root, 1, $description, $messageIds, $uid, null);
                }
                $id = bin2hex(random_bytes(16));
                $this->projects->insert('cognos_project_formations', [
                    'id' => $id, 'project_id' => $projectId, 'source_creation_id' => $creationId,
                    'message_ids' => json_encode($messageIds, JSON_THROW_ON_ERROR), 'reason' => $reason === '' ? null : $reason,
                    'formed_by' => $uid, 'formed_at' => time(),
                ]);
                return ['formation_id' => $id, 'project_id' => $projectId, 'location_id' => $root, 'new_project' => $new];
            });
    }

    /** 项目的来路：哪段会话、指向的那几句原话（原样）、谁、什么时候、为什么。 */
    public function forProject(string $uid, string $projectId): array {
        $this->projects->project($uid, $projectId);
        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('*')->from('cognos_project_formations')
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
            ->orderBy('formed_at', 'ASC')->addOrderBy('id', 'ASC')->executeQuery()->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $creationId = (int)$r['source_creation_id'];
            $creation = $this->creations->get($creationId, $uid);
            $wanted = json_decode((string)$r['message_ids'], true) ?: [];
            $quotes = [];
            foreach ($creation === null ? [] : $this->creations->getMessages($creationId, $uid) as $m) {
                if (in_array((int)$m['id'], $wanted, true)) {
                    $quotes[] = ['id' => (int)$m['id'], 'role' => (string)$m['role'], 'content' => (string)$m['content'], 'created_at' => (int)$m['created_at']];
                }
            }
            $out[] = ['id' => (string)$r['id'], 'creation_id' => $creationId, 'creation_title' => $creation['title'] ?? null,
                'quotes' => $quotes, 'reason' => $r['reason'], 'formed_by' => (string)$r['formed_by'], 'formed_at' => (int)$r['formed_at']];
        }
        return $out;
    }

    /** @return array<int, list<array{project_id: string, title: string}>> */
    private function formationsBySource(string $uid, array $creationIds): array {
        if ($creationIds === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('f.source_creation_id', 'p.id', 'p.title')->from('cognos_project_formations', 'f')
            ->innerJoin('f', 'cognos_projects', 'p', $qb->expr()->eq('p.id', 'f.project_id'))
            ->where($qb->expr()->eq('p.user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->in('f.source_creation_id', $qb->createNamedParameter($creationIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('f.formed_at', 'ASC')->executeQuery()->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['source_creation_id']][] = ['project_id' => (string)$r['id'], 'title' => (string)$r['title']];
        }
        return $out;
    }
}
