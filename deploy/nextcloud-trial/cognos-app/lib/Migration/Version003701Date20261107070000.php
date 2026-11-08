<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 对话里的回答记下是谁回的（author：登记运行时的那位，比如 GPT、Claude Code）。
 * 一段对话里可以换着让 Claude 和 GPT 回，不记的话，给下一位的全文里分不清哪句是谁说的。以前的消息是 null。
 */
class Version003701Date20261107070000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('cognos_creation_messages')) {
            $table = $schema->getTable('cognos_creation_messages');
            if (!$table->hasColumn('author')) {
                $table->addColumn('author', Types::STRING, ['notnull' => false, 'length' => 64]);
            }
        }
        return $schema;
    }
}
