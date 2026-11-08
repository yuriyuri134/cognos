<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 空间里每一块被改之前的样子（cognos_norm_part_versions）。
 * agent 用 write_part 改一块时是整块重写，原来不留旧的：写坏了、写偏了就找不回来。现在每次改之前先存一份，
 * 空间页上能看到谁什么时候改的，可以换回上一版。
 */
class Version003702Date20261107130000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('cognos_norm_part_versions')) {
            $t = $schema->createTable('cognos_norm_part_versions');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
            $t->addColumn('space_id', Types::STRING, ['length' => 32, 'notnull' => true]);
            $t->addColumn('part', Types::STRING, ['length' => 16, 'notnull' => true]);
            // 被换下来的那一版
            $t->addColumn('content', Types::TEXT, ['notnull' => false]);
            // 是谁换下了它（改的人）
            $t->addColumn('who', Types::STRING, ['length' => 64, 'notnull' => false]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['user_id', 'space_id', 'part'], 'cognos_npv_space');
        }
        return $schema;
    }
}
