<?php
declare(strict_types=1);
namespace OCA\Cognos\Migration;
use Closure;
use OCP\DB\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
/** Additive records; existing traces, conversations and work state are untouched. */
class Version003400Date20261105070000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $s=$schemaClosure();
        if(!$s->hasTable('cognos_attempts')) {
            $t=$s->createTable('cognos_attempts');
            $t->addColumn('id',Types::STRING,['length'=>32,'notnull'=>true]);
            $t->addColumn('user_id',Types::STRING,['length'=>64,'notnull'=>true]);
            $t->addColumn('revision',Types::INTEGER,['notnull'=>true]);
            $t->addColumn('payload',Types::TEXT,['notnull'=>true]);
            $t->addColumn('created_at',Types::BIGINT,['notnull'=>true]);
            $t->addColumn('updated_at',Types::BIGINT,['notnull'=>true]);
            $t->setPrimaryKey(['id']);$t->addIndex(['user_id','updated_at'],'cognos_attempt_owner');
        }
        if(!$s->hasTable('cognos_attempt_places')) {
            $t=$s->createTable('cognos_attempt_places');
            $t->addColumn('attempt_id',Types::STRING,['length'=>32,'notnull'=>true]);
            $t->addColumn('location_id',Types::STRING,['length'=>32,'notnull'=>true]);
            $t->setPrimaryKey(['attempt_id','location_id']);$t->addIndex(['location_id'],'cognos_attempt_location');
        }
        if(!$s->hasTable('cognos_attempt_revs')) {
            $t=$s->createTable('cognos_attempt_revs');
            $t->addColumn('attempt_id',Types::STRING,['length'=>32,'notnull'=>true]);
            $this->revisionColumns($t);$t->setPrimaryKey(['attempt_id','revision']);
        }
        if(!$s->hasTable('cognos_stage_summaries')) {
            $t=$s->createTable('cognos_stage_summaries');
            $t->addColumn('id',Types::STRING,['length'=>32,'notnull'=>true]);
            $t->addColumn('attempt_id',Types::STRING,['length'=>32,'notnull'=>true]);
            $t->addColumn('revision',Types::INTEGER,['notnull'=>true]);
            $t->addColumn('payload',Types::TEXT,['notnull'=>true]);
            $t->addColumn('created_at',Types::BIGINT,['notnull'=>true]);
            $t->addColumn('updated_at',Types::BIGINT,['notnull'=>true]);
            $t->setPrimaryKey(['id']);$t->addIndex(['attempt_id','created_at'],'cognos_stage_attempt');
        }
        if(!$s->hasTable('cognos_stage_revs')) {
            $t=$s->createTable('cognos_stage_revs');
            $t->addColumn('summary_id',Types::STRING,['length'=>32,'notnull'=>true]);
            $this->revisionColumns($t);$t->setPrimaryKey(['summary_id','revision']);
        }
        return $s;
    }
    private function revisionColumns($t): void {
        $t->addColumn('revision',Types::INTEGER,['notnull'=>true]);
        $t->addColumn('payload',Types::TEXT,['notnull'=>true]);
        $t->addColumn('author',Types::STRING,['length'=>64,'notnull'=>true]);
        $t->addColumn('created_at',Types::BIGINT,['notnull'=>true]);
    }
}
