<?php
declare(strict_types=1);
namespace OCA\Cognos\Service;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Explicit work commitments, distinct from exploratory open traces and actual executions. */
final class WorkItemService {
    public function __construct(private IDBConnection $db, private ProjectService $projects,private LifecycleService $lifecycle,private RunDriftService $runDrift) {}

    public function get(string $uid, string $id): array {
        $q=$this->db->getQueryBuilder();
        $w=$q->select('*')->from('cognos_work_items')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeQuery()->fetch();
        if (!$w) { throw new \RuntimeException('工作不存在或不可访问',404); }
        $loc=$this->projects->location($uid,$w['location_id']);
        foreach (['revision','execution_epoch','created_at','updated_at'] as $key) { $w[$key]=(int)$w[$key]; }
        $w['split_from_revision']=$w['split_from_revision']===null?null:(int)$w['split_from_revision'];
        return $w+['project_id'=>$loc['project_id'],'location_title'=>$loc['title']];
    }

    public function list(string $uid, string $projectId, string $locationId=''): array {
        $p=$this->projects->project($uid,$projectId);
        if ($locationId!=='') {
            $loc=$this->projects->location($uid,$locationId);
            if ($loc['project_id']!==$projectId) { throw new \RuntimeException('位置归属已变化，请刷新',409); }
        }
        $ids=$locationId!==''?[$locationId]:array_column($p['locations'],'id');
        if ($ids===[]) { return []; }
        $q=$this->db->getQueryBuilder();
        $rows=$q->select('id')->from('cognos_work_items')->where($q->expr()->in('location_id',$q->createNamedParameter($ids,IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('created_at','DESC')->addOrderBy('id','ASC')->executeQuery()->fetchAll();
        return array_map(fn(array $r):array=>$this->detail($uid,$r['id']),$rows);
    }

    public function detail(string $uid,string $id): array {
        $w=$this->get($uid,$id);
        $q=$this->db->getQueryBuilder();$w['events']=$q->select('revision','action','snapshot','note','actor','actor_subject','run_id','delegation_id','drift_token','created_at')->from('cognos_work_events')
            ->where($q->expr()->eq('work_id',$q->createNamedParameter($id)))->orderBy('revision','DESC')->executeQuery()->fetchAll();
        foreach ($w['events'] as &$event) { $event['snapshot']=json_decode($event['snapshot'],true,512,JSON_THROW_ON_ERROR); } unset($event);
        $q=$this->db->getQueryBuilder();$w['children']=$q->select('id','description','state')->from('cognos_work_items')
            ->where($q->expr()->eq('split_from_id',$q->createNamedParameter($id)))->orderBy('created_at')->addOrderBy('id')->executeQuery()->fetchAll();
        $q=$this->db->getQueryBuilder();$w['delegations']=$q->select('d.id','d.state','d.run_id','d.work_epoch','r.state AS run_state')->from('cognos_delegations','d')
            ->leftJoin('d','cognos_runs','r',$q->expr()->eq('r.id','d.run_id'))
            ->where($q->expr()->eq('d.work_item_id',$q->createNamedParameter($id)))->orderBy('d.created_at','DESC')->executeQuery()->fetchAll();
        return $w;
    }

    private function fields(array $data): array {
        $out=[];
        foreach (['description'=>4000,'owner'=>200,'deliverable'=>4000,'acceptance'=>4000] as $key=>$max) {
            if (isset($data[$key])&&!is_string($data[$key])) { throw new \InvalidArgumentException('工作字段必须是文字'); }
            $out[$key]=trim($data[$key]??'');
            if (mb_strlen($out[$key])>$max) { throw new \InvalidArgumentException('工作字段过长'); }
        }
        if ($out['description']==='') { throw new \InvalidArgumentException('请写明这份工作的范围'); }
        return $out;
    }

    public function create(string $uid,string $locationId,array $data,string $requestId): array {
        $fields=$this->fields($data);
        return $this->projects->commit($uid,$requestId,['work_create',$locationId,$fields],function()use($uid,$locationId,$fields):array {
            $this->projects->lockedLocation($uid,$locationId);
            return $this->insert($uid,$locationId,$fields,null,null);
        });
    }

    private function insert(string $uid,string $locationId,array $fields,?string $parent,?int $version,?array $actor=null): array {
        $now=time();$id=bin2hex(random_bytes(16));
        $row=['id'=>$id,'location_id'=>$locationId]+$fields+['state'=>'open','evidence'=>'','revision'=>1,'execution_epoch'=>1,
            'split_from_id'=>$parent,'split_from_revision'=>$version,'created_subject'=>$actor['subject']??null,'created_by'=>$uid,'created_at'=>$now,'updated_at'=>$now];
        $this->projects->insert('cognos_work_items',$row);$this->event($uid,$row,'created',$parent===null?'用户登记工作':'由原工作第 '.$version.' 版拆出；没有继承旧委托或执行授权',$actor);
        return ['id'=>$id,'revision'=>1];
    }

    private function locked(string $uid,string $id,int $expected): array {
        $w=$this->get($uid,$id);$loc=$this->projects->lockedLocation($uid,$w['location_id']);$w=$this->get($uid,$id);
        if ($w['project_id']!==$loc['project_id']||$w['revision']!==$expected) { throw new \RuntimeException('工作或归属已有变化，你填写的内容仍保留，请查看新版后再提交',409); }
        return $w;
    }

    public function update(string $uid,string $id,int $expected,array $data,string $state,string $evidence,string $note,string $requestId,?array $authority=null): array {
        $fields=$this->fields($data);$evidence=trim($evidence);$note=trim($note);
        if (!in_array($state,['open','in_progress','blocked','done','cancelled'],true)||mb_strlen($evidence)>8000||mb_strlen($note)>2000) { throw new \InvalidArgumentException('工作状态或说明无效'); }
        if ($state==='done'&&$evidence==='') { throw new \InvalidArgumentException('完成工作请留下实际交付或验收依据；运行返回不代表完成'); }
        if (in_array($state,['blocked','cancelled'],true)&&$note==='') { throw new \InvalidArgumentException('请写明等待或取消的原因'); }
        $signature=['work_update',$id,$expected,$fields,$state,$evidence,$note];
        if($authority!==null)$signature[]=['agent',$authority['run_id'],$authority['subject'],$authority['location_id']];
        return $this->projects->commit($uid,$requestId,$signature,function()use($uid,$id,$expected,$fields,$state,$evidence,$note,$authority):array {
            $w=$this->locked($uid,$id,$expected);
            $actor=$authority===null?null:$this->agentAuthority($uid,$w,$authority);
            if ($w['state']==='split') { throw new \RuntimeException('原工作已拆分，保留作来路，请修改各子项',409); }
            if (in_array($w['state'],['done','cancelled'],true)&&!in_array($state,['done','cancelled'],true)&&$note==='') { throw new \InvalidArgumentException('重新开展请留下原因'); }
            $epoch=$w['execution_epoch'];
            if (array_intersect_key($w,$fields)!=$fields || in_array($w['state'],['blocked','done','cancelled'],true) || in_array($state,['blocked','done','cancelled'],true)) { ++$epoch; }
            $next=array_replace($w,$fields,['state'=>$state,'evidence'=>$evidence,'revision'=>$expected+1,'execution_epoch'=>$epoch,'updated_at'=>time()]);
            $this->save($next);
            if($actor!==null&&in_array($state,['open','in_progress'],true))$this->advanceOwnAuthority($actor,$next['execution_epoch']);
            $this->event($uid,$next,'updated',$note,$actor);
            return ['id'=>$id,'revision'=>$next['revision']];
        });
    }

    private function splitPlan(string $uid,string $id,array $parts): array {
        if (count($parts)<2||count($parts)>12||!array_is_list($parts)) { throw new \InvalidArgumentException('一次拆为 2 到 12 份，每份写清范围'); }
        $parts=array_map(fn($p):array=>is_array($p)?$this->fields($p):throw new \InvalidArgumentException('子项无效'),$parts);
        $w=$this->get($uid,$id);
        if (!in_array($w['state'],['open','in_progress','blocked'],true)) { throw new \RuntimeException('已完成、取消或拆分的工作不能直接拆分',409); }
        $project=$this->projects->project($uid,$w['project_id']);
        return ['work'=>$w,'parts'=>$parts,'basis'=>hash('sha256',json_encode([$id,$w['revision'],$w['project_id'],$project['execution_epoch'],$parts],JSON_THROW_ON_ERROR))];
    }

    public function previewSplit(string $uid,string $id,array $parts): array {
        $plan=$this->splitPlan($uid,$id,$parts);$w=$this->detail($uid,$id);
        return ['basis'=>$plan['basis'],'parts'=>$plan['parts'],'consequences'=>[
            '原工作「'.$w['description'].'」第 '.$w['revision'].' 版保留为拆分来源，不标成已完成。',
            '拆出 '.count($parts).' 份独立工作，负责人、交付和验收按各自填写的保存，不自动继承。',
            '原来的 '.count($w['delegations']).' 条委托和实际结果仍留在原工作；不复制、不重新派发。拆分后原工作委托的直接改稿和采用资格失效。',
            '已经发出的外部工作不会因此被证明停止，仍需核对；子项必须各自安排，不能默认为同一承诺已经分成多份。']];
    }

    public function split(string $uid,string $id,array $parts,string $basis,string $requestId,?array $authority=null): array {
        $signature=['work_split',$id,$parts,$basis];
        if($authority!==null)$signature[]=['agent',$authority['run_id'],$authority['subject'],$authority['location_id']];
        return $this->projects->commit($uid,$requestId,$signature,function()use($uid,$id,$parts,$basis,$authority):array {
            $initial=$this->get($uid,$id);$this->locked($uid,$id,$initial['revision']);$plan=$this->splitPlan($uid,$id,$parts);$w=$plan['work'];
            $actor=$authority===null?null:$this->agentAuthority($uid,$w,$authority);
            if (!hash_equals($plan['basis'],$basis)) { throw new \RuntimeException('预览以后工作或项目有变化，没有拆分，请重新预览',409); }
            $children=[];foreach ($plan['parts'] as $part) { $children[]=$this->insert($uid,$w['location_id'],$part,$id,$w['revision'],$actor); }
            $next=array_replace($w,['state'=>'split','revision'=>$w['revision']+1,'execution_epoch'=>$w['execution_epoch']+1,'updated_at'=>time()]);
            $this->save($next);$this->event($uid,$next,'split',json_encode(['source_revision'=>$w['revision'],'children'=>array_column($children,'id')],JSON_THROW_ON_ERROR),$actor);
            return ['id'=>$id,'revision'=>$next['revision'],'children'=>$children];
        });
    }

    /** Inside delegation's project transaction. No execution or permission is copied from another work. */
    public function attachDelegation(string $uid,string $id,string $locationId,int $expected,string $delegationId): int {
        $candidate=$this->get($uid,$id);
        if ($candidate['location_id']!==$locationId) { throw new \RuntimeException('这份工作不在当前委托的位置',409); }
        $w=$this->locked($uid,$id,$expected);
        if (!in_array($w['state'],['open','in_progress'],true)) { throw new \RuntimeException('这份工作不在当前范围或不能派新工作',409); }
        $w['revision']++;$w['updated_at']=time();$this->save($w);$this->event($uid,$w,'delegated','委托 '.$delegationId.'；实际运行与工作是否完成分开记录');
        return $w['execution_epoch'];
    }

    /** Called only inside the locked work transaction, never from untrusted user-API arguments. */
    private function agentAuthority(string $uid,array $w,array $input):array {
        if(($input['location_id']??'')!==$w['location_id'])throw new \DomainException('这份工作不在本次委托的位置');
        // Project -> run: serialize maintenance with receipt, failure and lease expiry.
        $q=$this->db->getQueryBuilder();$q->update('cognos_runs')->set('id','id')->where($q->expr()->eq('id',$q->createNamedParameter($input['run_id'])))->executeStatement();
        $q=$this->db->getQueryBuilder();$run=$q->select('*')->from('cognos_runs')->where($q->expr()->eq('id',$q->createNamedParameter($input['run_id'])))->executeQuery()->fetch();
        $loc=$this->projects->location($uid,$w['location_id']);
        if(!$run||$run['subject']!==$input['subject']||$run['caller']!==$uid||$run['space_type']!=='creation'||$run['space_id']!==(string)$loc['creation_id']||$run['target_type']!=='delegation')throw new \DomainException('没有对应本次主体和位置的工作委托');
        if(!in_array($run['state'],['queued','claimed','running'],true)||($run['lease_until']!==null&&(int)$run['lease_until']<time()))throw new \RuntimeException('运行已经交回、停止或失联，不能继续修改工作',409);
        $q=$this->db->getQueryBuilder();$d=$q->select('*')->from('cognos_delegations')->where($q->expr()->eq('id',$q->createNamedParameter($run['target_id'])))->executeQuery()->fetch();
        if(!$d||(int)($d['may_manage_work']??0)!==1||$d['work_item_id']!==$w['id']||$d['location_id']!==$w['location_id']||!in_array($d['state'],['starting','sent'],true)||($d['run_id']!==null&&$d['run_id']!==$run['id']))throw new \DomainException('本次委托没有允许维护这份工作的安排');
        if(!$this->lifecycle->eligible($w['project_id'],$run)||!$this->lifecycle->delegationWorkEligible($d['id']))throw new \RuntimeException('项目或工作安排已有变化，旧委托资格失效',409);
        $drift=$this->runDrift->snapshot($uid,$run);$token=null;
        if($drift['decisions_missing']!==[]||$drift['basis_changed']!==[]){
            if(empty($input['acknowledged'])||!is_string($input['drift_token']??null)||!hash_equals($drift['drift_token'],$input['drift_token']))throw new WorkDriftException($drift);
            $token=$drift['drift_token'];
        }
        return ['subject'=>$run['subject'],'run_id'=>$run['id'],'delegation_id'=>$d['id'],'drift_token'=>$token];
    }

    /** The acting grant follows its own allowed edit on this same object; other grants stay stale. */
    private function advanceOwnAuthority(array $actor,int $epoch):void {
        $q=$this->db->getQueryBuilder();$q->update('cognos_delegations')->set('work_epoch',$q->createNamedParameter($epoch,IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('id',$q->createNamedParameter($actor['delegation_id'])))->executeStatement();
    }

    private function save(array $w): void {
        $q=$this->db->getQueryBuilder();$q->update('cognos_work_items');
        foreach (['description','owner','deliverable','acceptance','state','evidence','revision','execution_epoch','updated_at'] as $key) { $q->set($key,$q->createNamedParameter($w[$key],is_int($w[$key])?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR)); }
        $q->where($q->expr()->eq('id',$q->createNamedParameter($w['id'])))->executeStatement();
    }

    private function event(string $uid,array $w,string $action,string $note,?array $actor=null): void {
        unset($w['project_id'],$w['location_title']);
        $this->projects->insert('cognos_work_events',['work_id'=>$w['id'],'revision'=>$w['revision'],'action'=>$action,
            'snapshot'=>json_encode($w,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'actor'=>$uid,
            'actor_subject'=>$actor['subject']??null,'run_id'=>$actor['run_id']??null,'delegation_id'=>$actor['delegation_id']??null,'drift_token'=>$actor['drift_token']??null,'note'=>$note,'created_at'=>time()]);
    }
}
