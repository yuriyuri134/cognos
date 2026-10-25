<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 空间：发生（traces，只增不改）和读法（readings），以及每个人上次来的时间。
 * 设计见项目文件 空间入口/第一片空间-实现说明.md 第二节。
 */
class Version001700Date20261025000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('cognos_space_traces')) {
            $table = $schema->createTable('cognos_space_traces');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('space_type', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('space_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            // 自由文本，不是枚举：intent / fact / attempt / feedback / material / correction ……
            $table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 32]);
            // 原 / 世 / AI
            $table->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 8]);
            $table->addColumn('author', Types::STRING, ['notnull' => true, 'length' => 128]);
            $table->addColumn('content', Types::TEXT, ['notnull' => true]);
            $table->addColumn('said_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            // 这句话当时在做什么：定义、类比、举例、提问、顺口一提、陈述
            $table->addColumn('act', Types::STRING, ['notnull' => false, 'length' => 32]);
            $table->addColumn('confidence', Types::SMALLINT, ['notnull' => false, 'unsigned' => true]);
            // said / confirmed / rejected / withdrawn；correction 行的 status 是它给 ref_id 那条定的新状态
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'said']);
            $table->addColumn('about', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('stopped_at', Types::TEXT, ['notnull' => false]);
            $table->addColumn('touched_reality', Types::SMALLINT, ['notnull' => false, 'unsigned' => true]);
            $table->addColumn('ref_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['space_type', 'space_id', 'created_at'], 'cognos_trace_space_idx');
            $table->addIndex(['ref_id'], 'cognos_trace_ref_idx');
        }

        if (!$schema->hasTable('cognos_space_readings')) {
            $table = $schema->createTable('cognos_space_readings');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('space_type', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('space_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('reader', Types::STRING, ['notnull' => true, 'length' => 128]);
            $table->addColumn('content', Types::TEXT, ['notnull' => true]);
            $table->addColumn('confidence', Types::SMALLINT, ['notnull' => false, 'unsigned' => true]);
            $table->addColumn('refs', Types::TEXT, ['notnull' => false]);
            $table->addColumn('superseded_by', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['space_type', 'space_id', 'reader'], 'cognos_reading_space_idx');
        }

        if (!$schema->hasTable('cognos_space_seen')) {
            $table = $schema->createTable('cognos_space_seen');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('space_type', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('space_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('subject', Types::STRING, ['notnull' => true, 'length' => 128]);
            $table->addColumn('seen_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['space_type', 'space_id', 'subject'], 'cognos_seen_subject_uniq');
        }

        return $schema;
    }
}
