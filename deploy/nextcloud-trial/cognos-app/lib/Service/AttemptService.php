<?php
declare(strict_types=1);
namespace OCA\Cognos\Service;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** A versioned account-owned attempt may appear at several stable locations. */
final class AttemptService {
    public function __construct(private IDBConnection $db, private ProjectService $projects, private CreationSpaceService $creations) {}

    private function row(string $uid,string $id): array {
        $q=$this->db->getQueryBuilder();$r=$q->select('*')->from('cognos_attempts')
            ->where($q->expr()->eq('id',$q->createNamedParameter($id)))
            ->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->executeQuery()->fetch();
        if(!$r)throw new \RuntimeException('尝试不存在或不可访问',404);
        return $r;
    }
    private function payload(array $r): array { return json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR); }
    private function json(array $a): string { return json_encode($a,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE); }
    private function text(array $data,string $key,int $max,string $default=''): string {
        $value=$data[$key]??$default;
        if(!is_string($value)||mb_strlen($value)>$max)throw new \InvalidArgumentException('尝试或总结的文字字段无效或过长：'.$key);
        return trim($value);
    }
    private function places(mixed $ids): array {
        if(!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>50)throw new \InvalidArgumentException('尝试需要关联 1 到 50 个位置');
        foreach($ids as $id)if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('关联位置无效');
        $ids=array_values(array_unique($ids));sort($ids,SORT_STRING);return $ids;
    }
    private function attemptData(array $data,array $prior): array {
        $title=$this->text($data,'title',200);
        if($title==='')throw new \InvalidArgumentException('请给尝试一个名字');
        $state=$this->text($data,'state',20,'open');
        if(!in_array($state,['open','paused','ended'],true))throw new \InvalidArgumentException('尝试状态无效');
        return ['title'=>$title,'introduction'=>$this->text($data,'introduction',50000),'focus'=>$this->text($data,'focus',4000),
            'state'=>$state,'next_entry'=>$this->text($data,'next_entry',4000),
            'location_ids'=>$this->places($data['location_ids']??$prior['location_ids']),
            'refs'=>$this->refs($data['refs']??$prior['refs'])];
    }
    private function summaryData(array $data,array $prior=[]): array {
        $content=$this->text($data,'content',50000);if($content==='')throw new \InvalidArgumentException('请写下这一阶段的总结');
        return ['title'=>$this->text($data,'title',200),'coverage'=>$this->text($data,'coverage',4000),'content'=>$content,
            'covered_refs'=>$this->refs($data['covered_refs']??$prior['covered_refs']??[])];
    }
    /** Only immutable identity and explicit notes are persisted; source text is never copied. */
    private function refs(mixed $refs): array {
        if(!is_array($refs)||!array_is_list($refs)||count($refs)>100)throw new \InvalidArgumentException('引用列表无效，最多 100 条');
        $out=[];$seen=[];
        foreach($refs as $ref){
            if(!is_array($ref)||!is_string($ref['type']??null)||!isset($ref['id'])||(!is_string($ref['id'])&&!is_int($ref['id'])))throw new \InvalidArgumentException('引用需要类型和原记录编号');
            $type=$ref['type'];$id=(string)$ref['id'];$version=$ref['version']??null;
            if(!in_array($type,['message','trace','run','location_content','work_item','link'],true)||$id===''||mb_strlen($id)>4096)throw new \InvalidArgumentException('引用类型或编号无效');
            if(in_array($type,['location_content','work_item'],true)){
                if((!is_int($version)&&!(is_string($version)&&ctype_digit($version)))||(int)$version<1)throw new \InvalidArgumentException('内容或工作引用必须指定实际版本');
                $version=(int)$version;
            }elseif($version!==null){throw new \InvalidArgumentException('这种引用按原记录编号定位，不接受替代版本');}
            if($type==='link'&&(!filter_var($id,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($id,PHP_URL_SCHEME)),['http','https'],true)))throw new \InvalidArgumentException('外部链接只接受 http 或 https 地址');
            $note=$this->text($ref,'note',4000);$key=$type.':'.$id.':'.($version??'');
            if(isset($seen[$key]))continue;$seen[$key]=true;
            $out[]=['type'=>$type,'id'=>$id,'version'=>$version,'note'=>$note];
        }
        return $out;
    }
    /** Recheck current access on every read; missing sources retain their original identity. */
    private function source(string $uid,array $ref): array {
        $type=$ref['type'];$id=$ref['id'];$version=$ref['version'];$q=$this->db->getQueryBuilder();
        if($type==='link')return ['status'=>'link_only','label'=>'仅链接，未取得正文'];
        if(in_array($type,['message','trace'],true)){
            if(!ctype_digit($id)||(int)$id<1)throw new \RuntimeException('来源编号无效',404);
            $table=$type==='message'?'cognos_creation_messages':'cognos_space_traces';
            $r=$q->select('*')->from($table)->where($q->expr()->eq('id',$q->createNamedParameter((int)$id,IQueryBuilder::PARAM_INT)))->executeQuery()->fetch();
            if(!$r||($type==='trace'&&$r['space_type']!=='creation'))throw new \RuntimeException('来源不存在或不可访问',404);
            $creation=$type==='message'?(int)$r['creation_id']:(int)$r['space_id'];
            if(!$this->creations->get($creation,$uid))throw new \RuntimeException('来源不存在或不可访问',404);
            return ['status'=>'available','label'=>$type==='message'?'原会话消息 #'.$id:'原探索记录 #'.$id];
        }
        if($type==='run'){
            $r=$q->select('id')->from('cognos_runs')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('caller',$q->createNamedParameter($uid)))->executeQuery()->fetch();
            if(!$r)throw new \RuntimeException('运行来源不存在或不可访问',404);
            return ['status'=>'available','label'=>'原运行 '.$id];
        }
        if($type==='location_content'){
            $loc=$this->projects->location($uid,$id);$this->projects->revision($uid,$id,$version);
            return ['status'=>'available','label'=>$loc['title'].' · 第 '.$version.' 版'];
        }
        $w=$q->select('location_id','description')->from('cognos_work_items')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeQuery()->fetch();
        if(!$w)throw new \RuntimeException('工作来源不存在或不可访问',404);$this->projects->location($uid,$w['location_id']);
        $q=$this->db->getQueryBuilder();$event=$q->select('snapshot')->from('cognos_work_events')->where($q->expr()->eq('work_id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('revision',$q->createNamedParameter($version,IQueryBuilder::PARAM_INT)))->executeQuery()->fetch();
        if(!$event)throw new \RuntimeException('工作原版本不存在',404);$original=json_decode($event['snapshot'],true,512,JSON_THROW_ON_ERROR);
        return ['status'=>'available','label'=>($original['description']??'原工作').' · 第 '.$version.' 版'];
    }
    private function validateSources(string $uid,array $refs,array $prior=[]): void {
        // An existing missing reference may be retained while editing; a new inaccessible one cannot be introduced.
        $previous=[];foreach($prior as $r)$previous[$r['type'].':'.$r['id'].':'.($r['version']??'')]=true;
        foreach($refs as $r){try{$this->source($uid,$r);}catch(\RuntimeException $e){if(!isset($previous[$r['type'].':'.$r['id'].':'.($r['version']??'')]))throw $e;}}
    }
    private function readableRefs(string $uid,array $refs): array {
        return array_map(function(array $r)use($uid):array{try{return $r+$this->source($uid,$r);}catch(\RuntimeException){return $r+['status'=>'missing','label'=>'原来源已不可访问，原编号与版本保留'];}},$refs);
    }
    /** Lock projects in the same global order as reorganization, then recheck every stable location. */
    private function lockPlaces(string $uid,array $ids): void {
        $before=[];foreach($ids as $id)$before[$id]=$this->projects->location($uid,$id)['project_id'];
        $projects=array_values(array_unique(array_values($before)));sort($projects,SORT_STRING);
        foreach($projects as $id)$this->projects->lockedProject($uid,$id);
        foreach($before as $id=>$pid)if($this->projects->location($uid,$id)['project_id']!==$pid)throw new \RuntimeException('位置归属已变化，请重新读取尝试',409);
    }
    private function lockAttempt(string $uid,string $id,array $extra=[],?int $expected=null): array {
        $before=$this->row($uid,$id);$prior=$this->payload($before);$ids=array_values(array_unique(array_merge($prior['location_ids'],$extra)));sort($ids,SORT_STRING);
        $this->lockPlaces($uid,$ids);
        $q=$this->db->getQueryBuilder();$q->update('cognos_attempts')->set('revision','revision')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->executeStatement();
        $current=$this->row($uid,$id);
        if($this->payload($current)['location_ids']!==$prior['location_ids']||($expected!==null&&(int)$current['revision']!==$expected))throw new \RuntimeException('尝试已有变化，填写的内容仍可保留，请比较新版',409);
        return $current;
    }
    private function revision(string $table,string $key,string $id,int $version,array $payload,string $uid,int $now): void {
        $this->projects->insert($table,[$key=>$id,'revision'=>$version,'payload'=>$this->json($payload),'author'=>$uid,'created_at'=>$now]);
    }
    private function history(string $table,string $key,string $id): array {
        $q=$this->db->getQueryBuilder();$rows=$q->select('revision','payload','author','created_at')->from($table)->where($q->expr()->eq($key,$q->createNamedParameter($id)))->orderBy('revision','DESC')->executeQuery()->fetchAll();
        return array_map(fn(array $r):array=>['revision'=>(int)$r['revision'],'snapshot'=>$this->payload($r),'author'=>$r['author'],'created_at'=>(int)$r['created_at']],$rows);
    }
    private function savePlaces(string $id,array $ids): void {
        $q=$this->db->getQueryBuilder();$q->delete('cognos_attempt_places')->where($q->expr()->eq('attempt_id',$q->createNamedParameter($id)))->executeStatement();
        foreach($ids as $loc)$this->projects->insert('cognos_attempt_places',['attempt_id'=>$id,'location_id'=>$loc]);
    }
    public function create(string $uid,string $locationId,array $data,string $requestId): array {
        $payload=$this->attemptData($data,['location_ids'=>[$locationId],'refs'=>[]]);
        if(!in_array($locationId,$payload['location_ids'],true))throw new \InvalidArgumentException('建立尝试的位置需要包含在关联位置里');
        return $this->projects->commit($uid,$requestId,['attempt_create',$locationId,$payload],function()use($uid,$payload):array{
            $this->lockPlaces($uid,$payload['location_ids']);$this->validateSources($uid,$payload['refs']);$id=bin2hex(random_bytes(16));$now=time();
            $this->projects->insert('cognos_attempts',['id'=>$id,'user_id'=>$uid,'revision'=>1,'payload'=>$this->json($payload),'created_at'=>$now,'updated_at'=>$now]);
            $this->savePlaces($id,$payload['location_ids']);$this->revision('cognos_attempt_revs','attempt_id',$id,1,$payload,$uid,$now);return ['id'=>$id,'revision'=>1];
        });
    }
    public function update(string $uid,string $id,int $expected,array $data,string $requestId): array {
        if($expected<1)throw new \InvalidArgumentException('尝试预期版本无效');
        // Preserve unspecified relationships without letting derived current state change a retry's request identity.
        return $this->projects->commit($uid,$requestId,['attempt_update',$id,$expected,$data],function()use($uid,$id,$expected,$data):array{
            $extra=array_key_exists('location_ids',$data)?$this->places($data['location_ids']):[];$old=$this->lockAttempt($uid,$id,$extra,$expected);$prior=$this->payload($old);$payload=$this->attemptData($data,$prior);
            $this->validateSources($uid,$payload['refs'],$prior['refs']);$now=time();$next=$expected+1;
            $q=$this->db->getQueryBuilder();$q->update('cognos_attempts')->set('payload',$q->createNamedParameter($this->json($payload)))->set('revision',$q->createNamedParameter($next,IQueryBuilder::PARAM_INT))->set('updated_at',$q->createNamedParameter($now,IQueryBuilder::PARAM_INT))->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();
            $this->savePlaces($id,$payload['location_ids']);$this->revision('cognos_attempt_revs','attempt_id',$id,$next,$payload,$uid,$now);return ['id'=>$id,'revision'=>$next];
        });
    }
    public function list(string $uid,string $projectId,string $locationId=''): array {
        $project=$this->projects->project($uid,$projectId);
        if($locationId!==''){$loc=$this->projects->location($uid,$locationId);if($loc['project_id']!==$projectId)throw new \RuntimeException('位置归属已有变化',409);$ids=[$locationId];}else{$ids=array_column($project['locations'],'id');}
        if($ids===[])return [];$q=$this->db->getQueryBuilder();$rows=$q->selectDistinct(['a.id','a.updated_at'])->from('cognos_attempts','a')->innerJoin('a','cognos_attempt_places','p',$q->expr()->eq('p.attempt_id','a.id'))
            ->where($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->in('p.location_id',$q->createNamedParameter($ids,IQueryBuilder::PARAM_STR_ARRAY)))->orderBy('a.updated_at','DESC')->addOrderBy('a.id','ASC')->executeQuery()->fetchAll();
        return array_map(fn(array $r):array=>$this->detail($uid,$r['id']),$rows);
    }
    public function detail(string $uid,string $id): array {
        $r=$this->row($uid,$id);$payload=$this->payload($r);$history=$this->history('cognos_attempt_revs','attempt_id',$id);$payload['refs']=$this->readableRefs($uid,$payload['refs']);
        $summaries=$this->stageRows($id);
        return $payload+['id'=>$id,'user_id'=>$r['user_id'],'revision'=>(int)$r['revision'],'author'=>$history[0]['author']??$uid,'created_at'=>(int)$r['created_at'],'updated_at'=>(int)$r['updated_at'],'revisions'=>$history,'summaries'=>array_map(fn(array $s):array=>$this->summaryDetail($uid,$s),$summaries)];
    }
    /** Serialized append order is independent of clock precision and later revisions. */
    private function stageRows(string $attemptId): array {
        $q=$this->db->getQueryBuilder();$rows=$q->select('*')->from('cognos_stage_summaries')->where($q->expr()->eq('attempt_id',$q->createNamedParameter($attemptId)))->executeQuery()->fetchAll();
        usort($rows,function(array $a,array $b):int{
            $ao=(int)($this->payload($a)['stage_order']??0);$bo=(int)($this->payload($b)['stage_order']??0);
            return $bo<=>$ao ?: ((int)$b['created_at']<=>(int)$a['created_at'] ?: strcmp($a['id'],$b['id']));
        });return $rows;
    }
    private function summaryRow(string $id): array {
        $q=$this->db->getQueryBuilder();$r=$q->select('*')->from('cognos_stage_summaries')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeQuery()->fetch();if(!$r)throw new \RuntimeException('阶段总结不存在或不可访问',404);return $r;
    }
    private function summaryDetail(string $uid,array $r): array {
        $p=$this->payload($r);$history=$this->history('cognos_stage_revs','summary_id',$r['id']);$p['covered_refs']=$this->readableRefs($uid,$p['covered_refs']);
        return $p+['id'=>$r['id'],'attempt_id'=>$r['attempt_id'],'revision'=>(int)$r['revision'],'author'=>$history[0]['author']??$uid,'created_at'=>(int)$r['created_at'],'updated_at'=>(int)$r['updated_at'],'revisions'=>$history];
    }
    public function addSummary(string $uid,string $attemptId,array $data,string $requestId): array {
        $payload=$this->summaryData($data);
        return $this->projects->commit($uid,$requestId,['attempt_summary_add',$attemptId,$payload],function()use($uid,$attemptId,$payload):array{
            $this->lockAttempt($uid,$attemptId);$this->validateSources($uid,$payload['covered_refs']);$existing=$this->stageRows($attemptId);$order=count($existing);foreach($existing as $stage)$order=max($order,(int)($this->payload($stage)['stage_order']??0));$payload['stage_order']=$order+1;$id=bin2hex(random_bytes(16));$now=time();
            $this->projects->insert('cognos_stage_summaries',['id'=>$id,'attempt_id'=>$attemptId,'revision'=>1,'payload'=>$this->json($payload),'created_at'=>$now,'updated_at'=>$now]);
            $this->revision('cognos_stage_revs','summary_id',$id,1,$payload,$uid,$now);return ['id'=>$id,'attempt_id'=>$attemptId,'revision'=>1];
        });
    }
    public function updateSummary(string $uid,string $summaryId,int $expected,array $data,string $requestId): array {
        if($expected<1)throw new \InvalidArgumentException('总结预期版本无效');
        return $this->projects->commit($uid,$requestId,['attempt_summary_update',$summaryId,$expected,$data],function()use($uid,$summaryId,$expected,$data):array{
            $initial=$this->summaryRow($summaryId);$this->lockAttempt($uid,$initial['attempt_id']);
            $q=$this->db->getQueryBuilder();$q->update('cognos_stage_summaries')->set('revision','revision')->where($q->expr()->eq('id',$q->createNamedParameter($summaryId)))->executeStatement();$old=$this->summaryRow($summaryId);
            if((int)$old['revision']!==$expected)throw new \RuntimeException('总结已有变化，请比较新版后提交',409);
            $prior=$this->payload($old);$payload=$this->summaryData($data,$prior);$payload['stage_order']=(int)($prior['stage_order']??0);$this->validateSources($uid,$payload['covered_refs'],$prior['covered_refs']);$now=time();$next=$expected+1;
            $q=$this->db->getQueryBuilder();$q->update('cognos_stage_summaries')->set('payload',$q->createNamedParameter($this->json($payload)))->set('revision',$q->createNamedParameter($next,IQueryBuilder::PARAM_INT))->set('updated_at',$q->createNamedParameter($now,IQueryBuilder::PARAM_INT))->where($q->expr()->eq('id',$q->createNamedParameter($summaryId)))->executeStatement();
            $this->revision('cognos_stage_revs','summary_id',$summaryId,$next,$payload,$uid,$now);return ['id'=>$summaryId,'attempt_id'=>$old['attempt_id'],'revision'=>$next];
        });
    }

    /** Fetch exactly the referenced source only on request, retaining its own authorship fields. */
    public function readReference(string $uid,array $ref): array {
        $refs=$this->refs([$ref]);$r=$refs[0];$status=$this->source($uid,$r);$id=$r['id'];$type=$r['type'];$record=null;
        if($type!=='link'){
            $q=$this->db->getQueryBuilder();
            if($type==='message'||$type==='trace'){
                $record=$q->select('*')->from($type==='message'?'cognos_creation_messages':'cognos_space_traces')->where($q->expr()->eq('id',$q->createNamedParameter((int)$id,IQueryBuilder::PARAM_INT)))->executeQuery()->fetch();
            }elseif($type==='location_content'){$record=$this->projects->revision($uid,$id,$r['version']);}
            elseif($type==='work_item'){
                $event=$q->select('snapshot','actor','actor_subject','run_id','delegation_id','created_at','revision','action','note')->from('cognos_work_events')->where($q->expr()->eq('work_id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('revision',$q->createNamedParameter($r['version'],IQueryBuilder::PARAM_INT)))->executeQuery()->fetch();
                $record=$event;$record['snapshot']=json_decode($event['snapshot'],true,512,JSON_THROW_ON_ERROR);
            }else{
                // Deliberately omit raw provider configuration/input; these are runtime facts, not acquired conversation text.
                $record=$q->select('id','space_type','space_id','target_type','target_id','purpose','subject','caller','state','created_at','updated_at')->from('cognos_runs')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeQuery()->fetch();
                $q=$this->db->getQueryBuilder();$result=$q->select('message_id','received_at')->from('cognos_run_results')->where($q->expr()->eq('run_id',$q->createNamedParameter($id)))->executeQuery()->fetch();
                $record['result_message_id']=$result['message_id']??null;$record['result_received_at']=$result['received_at']??null;
            }
            if(!$record)throw new \RuntimeException('原来源已不可访问',404);
        }
        return ['ref'=>$r]+$status+['record'=>$record];
    }
    /** Bounded entry projection, never full histories or a replacement for original records. */
    public function entrySummary(string $uid,string $locationId,int $limit=5): array {
        $this->projects->location($uid,$locationId);$limit=max(1,min(10,$limit));
        $q=$this->db->getQueryBuilder();$total=(int)$q->selectAlias($q->func()->count('*'),'n')->from('cognos_attempts','a')->innerJoin('a','cognos_attempt_places','p',$q->expr()->eq('p.attempt_id','a.id'))
            ->where($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->eq('p.location_id',$q->createNamedParameter($locationId)))->executeQuery()->fetchOne();
        $q=$this->db->getQueryBuilder();$rows=$q->select('a.*')->from('cognos_attempts','a')->innerJoin('a','cognos_attempt_places','p',$q->expr()->eq('p.attempt_id','a.id'))
            ->where($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->eq('p.location_id',$q->createNamedParameter($locationId)))->orderBy('a.updated_at','DESC')->addOrderBy('a.id','ASC')->setMaxResults($limit)->executeQuery()->fetchAll();
        $items=[];
        foreach($rows as $r){
            $p=$this->payload($r);$truncated=false;$clip=function(string $s,int $max)use(&$truncated):string{if(mb_strlen($s)>$max){$truncated=true;return mb_substr($s,0,$max);}return $s;};
            $q=$this->db->getQueryBuilder();$author=$q->select('author')->from('cognos_attempt_revs')->where($q->expr()->eq('attempt_id',$q->createNamedParameter($r['id'])))->andWhere($q->expr()->eq('revision',$q->createNamedParameter((int)$r['revision'],IQueryBuilder::PARAM_INT)))->executeQuery()->fetchOne();
            $s=$this->stageRows($r['id'])[0]??null;$latest=null;
            if($s){$sp=$this->payload($s);$q=$this->db->getQueryBuilder();$sa=$q->select('author')->from('cognos_stage_revs')->where($q->expr()->eq('summary_id',$q->createNamedParameter($s['id'])))->andWhere($q->expr()->eq('revision',$q->createNamedParameter((int)$s['revision'],IQueryBuilder::PARAM_INT)))->executeQuery()->fetchOne();
                $latest=['id'=>$s['id'],'revision'=>(int)$s['revision'],'title'=>$sp['title'],'coverage'=>$clip($sp['coverage'],300),'content'=>$clip($sp['content'],1500),'author'=>$sa,'covered_refs'=>array_map(function(array $ref)use($clip):array{$ref['label']=$clip($ref['label'],200);return array_intersect_key($ref,array_flip(['type','id','version','status','label']));},$this->readableRefs($uid,array_slice($sp['covered_refs'],0,10))),'covered_refs_total'=>count($sp['covered_refs']),'covered_refs_omitted'=>max(0,count($sp['covered_refs'])-10)];
            }
            $items[]=['id'=>$r['id'],'title'=>$p['title'],'introduction'=>$clip($p['introduction'],1200),'focus'=>$clip($p['focus'],300),'next_entry'=>$clip($p['next_entry'],300),'state'=>$p['state'],'revision'=>(int)$r['revision'],'author'=>$author,'latest_summary'=>$latest,'text_truncated'=>$truncated];
        }
        return ['items'=>$items,'total'=>$total,'omitted'=>max(0,$total-count($items))];
    }
}
