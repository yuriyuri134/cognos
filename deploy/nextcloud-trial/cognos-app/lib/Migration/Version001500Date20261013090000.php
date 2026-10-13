<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version001500Date20261013090000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('cognos_activity_messages')) {
            $table = $schema->getTable('cognos_activity_messages');
            if (!$table->hasIndex('cognos_activity_msg_req_unique')) {
                $table->addUniqueIndex(
                    ['activity_id', 'user_id', 'request_id'],
                    'cognos_activity_msg_req_unique',
                );
            }
        }
        return $schema;
    }
}
