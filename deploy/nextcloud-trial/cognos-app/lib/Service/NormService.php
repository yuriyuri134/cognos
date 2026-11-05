<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Norm（示范空间）：一段和 agent 的对话，加上项目。
 * 项目先只有三部分：历史阶段、分支和部分拆解、和各分支与尝试的关系图。
 */
final class NormService {
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

    /** Initium 列表里不显示 Norm 那段对话。 */
    public function conversationIdIfAny(string $uid): int {
        return (int)$this->config->getUserValue($uid, 'cognos', 'norm_creation_id', '0');
    }

    /** @return list<array> 主项目在前，其余按最近更新。 */
    public function projects(string $uid): array {
        $q = $this->db->getQueryBuilder();
        $rows = $q->select('id', 'title', 'is_main', 'updated_at')->from('cognos_norm_projects')
            ->where($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->orderBy('is_main', 'DESC')->addOrderBy('updated_at', 'DESC')
            ->executeQuery()->fetchAll();
        return array_map(static fn(array $r): array => [
            'id' => (string)$r['id'], 'title' => (string)$r['title'], 'is_main' => (bool)$r['is_main'], 'updated_at' => (int)$r['updated_at'],
        ], $rows);
    }

    public function project(string $uid, string $id): ?array {
        $q = $this->db->getQueryBuilder();
        $r = $q->select('*')->from('cognos_norm_projects')
            ->where($q->expr()->eq('id', $q->createNamedParameter($id)))
            ->andWhere($q->expr()->eq('user_id', $q->createNamedParameter($uid)))
            ->executeQuery()->fetch();
        if ($r === false) {
            return null;
        }
        return [
            'id' => (string)$r['id'], 'title' => (string)$r['title'], 'is_main' => (bool)$r['is_main'],
            'stages' => json_decode((string)($r['stages'] ?? '[]'), true) ?: [],
            'branches' => json_decode((string)($r['branches'] ?? '[]'), true) ?: [],
            'relations' => json_decode((string)($r['relations'] ?? '{}'), true) ?: ['nodes' => [], 'edges' => []],
            'updated_at' => (int)$r['updated_at'],
        ];
    }

    /** 建一个项目；$main 为真时它成为主项目，原来的主项目退到下面。 */
    public function create(string $uid, string $title, bool $main, array $stages = [], array $branches = [], array $relations = []): string {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) {
            throw new \InvalidArgumentException('项目名字 1 到 200 字');
        }
        $id = bin2hex(random_bytes(16));
        $now = time();
        if ($main) {
            $u = $this->db->getQueryBuilder();
            $u->update('cognos_norm_projects')->set('is_main', $u->createNamedParameter(false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL))
                ->where($u->expr()->eq('user_id', $u->createNamedParameter($uid)))->executeStatement();
        }
        $q = $this->db->getQueryBuilder();
        $q->insert('cognos_norm_projects')->values([
            'id' => $q->createNamedParameter($id),
            'user_id' => $q->createNamedParameter($uid),
            'title' => $q->createNamedParameter($title),
            'is_main' => $q->createNamedParameter($main, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL),
            'stages' => $q->createNamedParameter(json_encode($stages, JSON_UNESCAPED_UNICODE)),
            'branches' => $q->createNamedParameter(json_encode($branches, JSON_UNESCAPED_UNICODE)),
            'relations' => $q->createNamedParameter(json_encode($relations ?: ['nodes' => [], 'edges' => []], JSON_UNESCAPED_UNICODE)),
            'created_at' => $q->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
            'updated_at' => $q->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
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
}
