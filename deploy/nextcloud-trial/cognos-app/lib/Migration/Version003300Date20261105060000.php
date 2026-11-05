<?php
declare(strict_types=1);
namespace OCA\Cognos\Migration;
use Closure;use OCP\DB\Types;use OCP\DB\ISchemaWrapper;use OCP\Migration\IOutput;use OCP\Migration\SimpleMigrationStep;
class Version003300Date20261105060000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
        $s=$schemaClosure();$d=$s->getTable('cognos_delegations');
        if(!$d->hasColumn('may_manage_work'))$d->addColumn('may_manage_work',Types::SMALLINT,['notnull'=>true,'default'=>0]);
        $w=$s->getTable('cognos_work_items');if(!$w->hasColumn('created_subject'))$w->addColumn('created_subject',Types::STRING,['length'=>128,'notnull'=>false]);
        $e=$s->getTable('cognos_work_events');
        foreach(['actor_subject'=>128,'run_id'=>32,'delegation_id'=>32,'drift_token'=>64] as $name=>$length)if(!$e->hasColumn($name))$e->addColumn($name,Types::STRING,['length'=>$length,'notnull'=>false]);
        return $s;
    }
}
