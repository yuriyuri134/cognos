<?php
declare(strict_types=1);
namespace OCA\Cognos\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Additive lifecycle foundation; legacy runs get no renewed execution authority. */
class Version002900Date20261104200000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $s = $schemaClosure();
        $p = $s->getTable('cognos_projects');
        foreach (['state' => [Types::STRING, ['length'=>16,'notnull'=>true,'default'=>'active']],
            'lifecycle_revision' => [Types::BIGINT, ['notnull'=>true,'default'=>1]],
            'execution_epoch' => [Types::BIGINT, ['notnull'=>true,'default'=>1]]] as $name => [$type,$opts]) {
            if (!$p->hasColumn($name)) { $p->addColumn($name,$type,$opts); }
        }
        $r = $s->getTable('cognos_runs');
        if (!$r->hasColumn('project_id')) { $r->addColumn('project_id',Types::STRING,['length'=>32,'notnull'=>false]); }
        if (!$r->hasColumn('execution_epoch')) { $r->addColumn('execution_epoch',Types::BIGINT,['notnull'=>false]); }
        return $s;
    }
}
