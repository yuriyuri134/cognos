<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 笔记的旧版本：每次保存前把上一版原样存下来，编辑不覆盖历史。
 * 见 docs/问题清单-10-27.md 第 7 条。
 */
class Version001701Date20261027000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('cognos_note_revisions')) {
            $table = $schema->createTable('cognos_note_revisions');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('note_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('content', Types::TEXT, ['notnull' => true]);
            $table->addColumn('content_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
            // 这一版当时的 updated_at
            $table->addColumn('saved_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['note_id', 'revision'], 'cognos_note_rev_uniq');
        }

        return $schema;
    }
}
