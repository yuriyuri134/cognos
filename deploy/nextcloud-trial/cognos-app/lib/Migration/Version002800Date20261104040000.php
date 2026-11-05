<?php

declare(strict_types=1);

namespace OCA\Cognos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * AI 在委托范围内采用（V13）和纠正多传一层：
 *
 * - cognos_delegations.may_adopt：这次交出去的事允许它在那一处采用。
 * - cognos_adoptions.authority：凭什么采用（delegation:<id>；用户自己采用时为空）；
 *   proposed_by：AI 没有采用的委托时只能提出，记提出者，用户接受后采用者才是用户；
 *   source_message_id：委托时用户那句话（原话），AI 依据委托采用时记下。
 * - cognos_impacts.depth：纠正传到第几层（1 直接依赖那一版，2 依赖了采用它的位置在采用期间交回的结果 / 写的当前稿，3 只提示，再往下不追）。
 */
class Version002800Date20261104040000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('cognos_delegations')) {
            $t = $schema->getTable('cognos_delegations');
            if (!$t->hasColumn('may_adopt')) {
                $t->addColumn('may_adopt', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            }
        }
        if ($schema->hasTable('cognos_adoptions')) {
            $t = $schema->getTable('cognos_adoptions');
            if (!$t->hasColumn('authority')) {
                $t->addColumn('authority', Types::STRING, ['notnull' => false, 'length' => 64]);
            }
            if (!$t->hasColumn('proposed_by')) {
                $t->addColumn('proposed_by', Types::STRING, ['notnull' => false, 'length' => 128]);
            }
            if (!$t->hasColumn('source_message_id')) {
                $t->addColumn('source_message_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            }
        }
        if ($schema->hasTable('cognos_impacts')) {
            $t = $schema->getTable('cognos_impacts');
            if (!$t->hasColumn('depth')) {
                $t->addColumn('depth', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            }
        }
        return $schema;
    }
}
