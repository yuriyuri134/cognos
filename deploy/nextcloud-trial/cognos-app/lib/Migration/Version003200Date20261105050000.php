<?php
declare(strict_types=1);
namespace OCA\Cognos\Migration;
use Closure;
use OCP\DB\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version003200Date20261105050000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $s = $schemaClosure();
        if (!$s->hasTable('cognos_work_items')) {
            $t = $s->createTable('cognos_work_items');
            foreach (['id'=>32,'location_id'=>32,'created_by'=>64,'state'=>16] as $n=>$len) { $t->addColumn($n, Types::STRING, ['length'=>$len,'notnull'=>true]); }
            foreach (['description','owner','deliverable','acceptance','evidence'] as $n) { $t->addColumn($n, Types::TEXT, ['notnull'=>true]); }
            $t->addColumn('split_from_id', Types::STRING, ['length'=>32,'notnull'=>false]);
            $t->addColumn('split_from_revision', Types::BIGINT, ['notnull'=>false]);
            foreach (['revision','execution_epoch','created_at','updated_at'] as $n) { $t->addColumn($n, Types::BIGINT, ['notnull'=>true]); }
            $t->setPrimaryKey(['id']); $t->addIndex(['location_id'],'cognos_work_location'); $t->addIndex(['split_from_id'],'cognos_work_parent');
        }
        if (!$s->hasTable('cognos_work_events')) {
            $t = $s->createTable('cognos_work_events');
            $t->addColumn('id', Types::BIGINT, ['notnull'=>true,'autoincrement'=>true]);
            foreach (['work_id'=>32,'action'=>24,'actor'=>64] as $n=>$len) { $t->addColumn($n, Types::STRING, ['length'=>$len,'notnull'=>true]); }
            foreach (['snapshot','note'] as $n) { $t->addColumn($n, Types::TEXT, ['notnull'=>true]); }
            foreach (['revision','created_at'] as $n) { $t->addColumn($n, Types::BIGINT, ['notnull'=>true]); }
            $t->setPrimaryKey(['id']); $t->addUniqueIndex(['work_id','revision'],'cognos_work_version');
        }
        $t = $s->getTable('cognos_delegations');
        if (!$t->hasColumn('work_item_id')) { $t->addColumn('work_item_id', Types::STRING, ['length'=>32,'notnull'=>false]); }
        if (!$t->hasColumn('work_epoch')) { $t->addColumn('work_epoch', Types::BIGINT, ['notnull'=>false]); }
        return $s;
    }
}
