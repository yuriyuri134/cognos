<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initium 和确立：
 *
 * - cognos_project_formations：一个项目是从哪段会话里的哪几句确立的（formed_from）。原会话不搬动、不改写；
 *   一段会话可以促成几个项目，一个项目也可以有几段来路。记谁确立的、什么时候、为什么（可空）。
 *   Initium 本身不是一张表：它是还没成为项目位置讨论的那些会话，加上这些形成关系。
 */
class Version002400Date20261104000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('cognos_project_formations')) {
            $t = $schema->createTable('cognos_project_formations');
            $t->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('project_id', Types::STRING, ['notnull' => true, 'length' => 32]);
            $t->addColumn('source_creation_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            // JSON 列表：确立时指向的那几句（原会话里的消息 id）
            $t->addColumn('message_ids', Types::TEXT, ['notnull' => true]);
            $t->addColumn('reason', Types::TEXT, ['notnull' => false]);
            $t->addColumn('formed_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('formed_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['project_id'], 'cognos_form_proj_idx');
            $t->addIndex(['source_creation_id'], 'cognos_form_src_idx');
        }

        return $schema;
    }
}
