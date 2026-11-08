<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 读者和带着自己做法的空间。
 *
 * - cognos_norm_projects 变成 Norm 里的「空间」：board 是现成的样子（项目、笔记……），为空就是带着自己做法的；
 *   parts 是它用哪几块，way 是 agent 在这里怎么工作（你不在时做什么、怎样算往前走、什么时候找你），
 *   compare / doc 是并排对照和文档两块的内容；state：active、withdrawn（撤回了）、back（退回 Initium）。
 * - cognos_reader_runs：读者每读一次记一行，result 是三个读者各自的读法和比较、放置（或回头读的结论），
 *   applied 是 Cognos 照着做了什么（放进哪、新建了哪个），撤回时记 undone_at。
 * - cognos_norm_links：哪段 Initium 对话连在哪个空间上。
 * - cognos_norm_events：空间里发生了什么（happened），和只有交互对象能定的（decide）。
 */
final class Version003700Date20261106080000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('cognos_norm_projects')) {
            $t = $schema->getTable('cognos_norm_projects');
            $add = [
                'board' => [Types::STRING, ['length' => 32, 'notnull' => false]],
                'parts' => [Types::TEXT, ['notnull' => false]],
                'way' => [Types::TEXT, ['notnull' => false]],
                'summary' => [Types::TEXT, ['notnull' => false]],
                'compare' => [Types::TEXT, ['notnull' => false]],
                'doc' => [Types::TEXT, ['notnull' => false]],
                'conversation_id' => [Types::BIGINT, ['notnull' => false]],
                'state' => [Types::STRING, ['length' => 16, 'notnull' => false, 'default' => 'active']],
                'last_seen_at' => [Types::BIGINT, ['notnull' => false]],
                'keep_reason' => [Types::TEXT, ['notnull' => false]],
            ];
            foreach ($add as $name => [$type, $opts]) {
                if (!$t->hasColumn($name)) {
                    $t->addColumn($name, $type, $opts);
                }
            }
        }
        if (!$schema->hasTable('cognos_reader_runs')) {
            $t = $schema->createTable('cognos_reader_runs');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
            $t->addColumn('kind', Types::STRING, ['length' => 16, 'notnull' => true]);
            $t->addColumn('creation_id', Types::BIGINT, ['notnull' => false]);
            $t->addColumn('space_id', Types::STRING, ['length' => 32, 'notnull' => false]);
            $t->addColumn('run_id', Types::STRING, ['length' => 32, 'notnull' => false]);
            $t->addColumn('upto_message_id', Types::BIGINT, ['notnull' => false]);
            $t->addColumn('state', Types::STRING, ['length' => 16, 'notnull' => true]);
            $t->addColumn('result', Types::TEXT, ['notnull' => false]);
            $t->addColumn('applied', Types::TEXT, ['notnull' => false]);
            $t->addColumn('error', Types::TEXT, ['notnull' => false]);
            $t->addColumn('undone_at', Types::BIGINT, ['notnull' => false]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $t->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['user_id', 'creation_id'], 'cognos_reader_creation');
            $t->addIndex(['user_id', 'space_id'], 'cognos_reader_space');
        }
        if (!$schema->hasTable('cognos_norm_links')) {
            $t = $schema->createTable('cognos_norm_links');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
            $t->addColumn('space_id', Types::STRING, ['length' => 32, 'notnull' => true]);
            $t->addColumn('creation_id', Types::BIGINT, ['notnull' => true]);
            $t->addColumn('reading_id', Types::BIGINT, ['notnull' => false]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $t->addColumn('undone_at', Types::BIGINT, ['notnull' => false]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['user_id', 'space_id'], 'cognos_nlink_space');
            $t->addIndex(['user_id', 'creation_id'], 'cognos_nlink_creation');
        }
        if (!$schema->hasTable('cognos_norm_events')) {
            $t = $schema->createTable('cognos_norm_events');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
            $t->addColumn('space_id', Types::STRING, ['length' => 32, 'notnull' => true]);
            $t->addColumn('kind', Types::STRING, ['length' => 16, 'notnull' => true]);
            $t->addColumn('who', Types::STRING, ['length' => 64, 'notnull' => false]);
            $t->addColumn('text', Types::TEXT, ['notnull' => true]);
            $t->addColumn('options', Types::TEXT, ['notnull' => false]);
            $t->addColumn('answer', Types::STRING, ['length' => 200, 'notnull' => false]);
            $t->addColumn('answered_at', Types::BIGINT, ['notnull' => false]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['user_id', 'space_id'], 'cognos_nevent_space');
        }
        return $schema;
    }
}
