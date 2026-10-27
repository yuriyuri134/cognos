<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 对话里的回答记下它回的是哪一句（reply_to）。被顶叫回来的那位发回的回答不回任何一句，记 0；
 * 以前的消息是 null，照旧按「紧跟着的就是回答」算。不记的话，被顶叫回来的回答会让排在它前面、
 * 其实没人回的那句看起来像被回过了。
 */
class Version001801Date20261027130000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('cognos_creation_messages')) {
            $table = $schema->getTable('cognos_creation_messages');
            if (!$table->hasColumn('reply_to')) {
                $table->addColumn('reply_to', Types::BIGINT, ['notnull' => false]);
            }
        }

        return $schema;
    }
}
