<?php
declare(strict_types=1);
namespace OCA\Cognos\Service;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Exception as DbException;
/** Persistent work references; network calls must always happen outside its transactions. */
final class DispatchQueueService {
 public function __construct(private IDBConnection $db){}
 private function scope(string $type,string $id):string{return hash('sha256',json_encode([$type,$id],JSON_THROW_ON_ERROR));}
 private function ensureScope(string $scope):void {
  $q=$this->db->getQueryBuilder();if($q->select('id')->from('cognos_queue_scopes')->where($q->expr()->eq('id',$q->createNamedParameter($scope)))->executeQuery()->fetch())return;
  $this->db->beginTransaction();try{
   $this->insert('cognos_queue_scopes',['id'=>$scope,'sequence'=>0]);$this->db->commit();
  }catch(\Throwable $e){$this->db->rollBack();if(!($e instanceof DbException)||$e->getReason()!==DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION)throw $e;}
 }
 private function atomic(string $scope,callable $work):mixed {
  $this->ensureScope($scope);$this->db->beginTransaction();try{
   $q=$this->db->getQueryBuilder();$q->update('cognos_queue_scopes')->set('sequence','sequence')->where($q->expr()->eq('id',$q->createNamedParameter($scope)))->executeStatement();
   $r=$work();$this->db->commit();return $r;
  }catch(\Throwable $e){$this->db->rollBack();throw $e;}
 }
 private function insert(string $table,array $values):void {
  $q=$this->db->getQueryBuilder();foreach($values as $k=>$v)$values[$k]=$q->createNamedParameter($v,$v===null?IQueryBuilder::PARAM_NULL:(is_int($v)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR));$q->insert($table)->values($values)->executeStatement();
 }
 public function get(string $id):?array {$q=$this->db->getQueryBuilder();$r=$q->select('*')->from('cognos_dispatch_queue')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeQuery()->fetch();return $r?:null;}
 public function enqueue(string $type,string $spaceId,string $kind,int $ref,bool $front=false,?string $legacy=null):array {
  if(!in_array($kind,['msg','hit'],true)||$ref<1)throw new \InvalidArgumentException('队列引用无效');
  $scope=$this->scope($type,$spaceId);
  return $this->atomic($scope,function()use($scope,$kind,$ref,$front,$legacy):array {
   if($legacy!==null){$q=$this->db->getQueryBuilder();$old=$q->select('*')->from('cognos_dispatch_queue')->where($q->expr()->eq('legacy_key',$q->createNamedParameter($legacy)))->executeQuery()->fetch();if($old)return $old;}
   $q=$this->db->getQueryBuilder();$open=$q->select('*')->from('cognos_dispatch_queue')->where($q->expr()->eq('scope_id',$q->createNamedParameter($scope)))->andWhere($q->expr()->eq('kind',$q->createNamedParameter($kind)))->andWhere($q->expr()->eq('ref_id',$q->createNamedParameter($ref,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->in('state',$q->createNamedParameter(['queued','claimed','unknown','held'],IQueryBuilder::PARAM_STR_ARRAY)))->executeQuery()->fetch();
   if($open){
    if($legacy!==null&&$open['legacy_key']===null){$q=$this->db->getQueryBuilder();$q->update('cognos_dispatch_queue')->set('legacy_key',$q->createNamedParameter($legacy))->where($q->expr()->eq('id',$q->createNamedParameter($open['id'])))->executeStatement();return $this->get($open['id']);}
    return $open;
   }
   $q=$this->db->getQueryBuilder();$q->update('cognos_queue_scopes')->set('sequence',$q->createFunction('sequence + 1'))->where($q->expr()->eq('id',$q->createNamedParameter($scope)))->executeStatement();
   $q=$this->db->getQueryBuilder();$seq=(int)$q->select('sequence')->from('cognos_queue_scopes')->where($q->expr()->eq('id',$q->createNamedParameter($scope)))->executeQuery()->fetchOne();
   $row=['id'=>bin2hex(random_bytes(16)),'scope_id'=>$scope,'kind'=>$kind,'ref_id'=>$ref,'state'=>'queued','position'=>$front?-$seq:$seq,'followup_id'=>null,'run_id'=>null,'legacy_key'=>$legacy,'note'=>'','created_at'=>time(),'updated_at'=>time()];$this->insert('cognos_dispatch_queue',$row);return $row;
  });
 }
 public function importLegacy(string $type,string $spaceId,array $items):array {
  $normalized=[];foreach($items as $item){$item=is_int($item)?'hit:'.$item:(string)$item;if(!preg_match('/^(msg|hit):([1-9][0-9]*)$/D',$item,$m))throw new \InvalidArgumentException('旧队列含无法识别的引用，保留原记录供核对');$normalized[]=[$item,$m[1],(int)$m[2]];}
  return $this->atomic($this->scope($type,$spaceId),function()use($normalized,$type,$spaceId):array{$out=[];foreach($normalized as [$item,$kind,$id]){$key=hash('sha256',json_encode([$type,$spaceId,$item],JSON_THROW_ON_ERROR));$out[]=$this->enqueue($type,$spaceId,$kind,$id,false,$key);}return $out;});
 }
 public function importHeldLegacy(string $type,string $spaceId,array $items):array {
  return $this->atomic($this->scope($type,$spaceId),function()use($type,$spaceId,$items):array{$rows=$this->importLegacy($type,$spaceId,$items);$this->holdLegacy($type,$spaceId);return $rows;});
 }
 public function holdLegacy(string $type,string $spaceId):void {
  $this->atomic($this->scope($type,$spaceId),function()use($type,$spaceId):void{$q=$this->db->getQueryBuilder();$q->update('cognos_dispatch_queue')->set('state',$q->createNamedParameter('held'))->set('note',$q->createNamedParameter('旧排队记录已找回；需要重新核对推进安排，未自动派发'))->where($q->expr()->eq('scope_id',$q->createNamedParameter($this->scope($type,$spaceId))))->andWhere($q->expr()->eq('state',$q->createNamedParameter('queued')))->andWhere($q->expr()->isNotNull('legacy_key'))->andWhere($q->expr()->isNull('reviewed_at'))->executeStatement();});
 }
 public function hasHeld(string $type,string $spaceId):bool {
  $q=$this->db->getQueryBuilder();return (bool)$q->select('id')->from('cognos_dispatch_queue')->where($q->expr()->eq('scope_id',$q->createNamedParameter($this->scope($type,$spaceId))))->andWhere($q->expr()->eq('state',$q->createNamedParameter('held')))->setMaxResults(1)->executeQuery()->fetch();
 }
 public function claim(string $type,string $spaceId,string $followupId, ?string $expectedItem = null, bool $exact = false):?array {
  $scope=$this->scope($type,$spaceId);return $this->atomic($scope,function()use($scope,$followupId,$expectedItem,$exact):?array {
   $q=$this->db->getQueryBuilder();$blocked=$q->select('id')->from('cognos_dispatch_queue')->where($q->expr()->eq('scope_id',$q->createNamedParameter($scope)))->andWhere($q->expr()->in('state',$q->createNamedParameter($exact?['claimed','unknown']:['claimed','unknown','held'],IQueryBuilder::PARAM_STR_ARRAY)))->setMaxResults(1)->executeQuery()->fetch();if($blocked)return null;
   $q=$this->db->getQueryBuilder();$q->select('*')->from('cognos_dispatch_queue')->where($q->expr()->eq('scope_id',$q->createNamedParameter($scope)))->andWhere($q->expr()->eq('state',$q->createNamedParameter('queued')));if($exact){$q->andWhere($q->expr()->eq('id',$q->createNamedParameter($expectedItem)));$q->andWhere($q->expr()->isNotNull('reviewed_at'));}else{$q->andWhere($q->expr()->isNull('reviewed_at'));}$row=$q->orderBy('position','ASC')->addOrderBy('id','ASC')->setMaxResults(1)->executeQuery()->fetch();if(!$row||($expectedItem!==null&&$row['id']!==$expectedItem))return null;
   $q=$this->db->getQueryBuilder();$q->update('cognos_dispatch_queue')->set('state',$q->createNamedParameter('claimed'))->set('followup_id',$q->createNamedParameter($followupId))->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))->where($q->expr()->eq('id',$q->createNamedParameter($row['id'])))->executeStatement();return $this->get($row['id']);
  });
 }
 /** Called only inside run creation's transaction, before any network dispatch. */
 public function bindRun(string $id,string $followupId,string $type,string $spaceId,string $runId,string $targetType,string $targetId,array $input):void {
  $item=$this->get($id);
  if(!$item||$item['scope_id']!==$this->scope($type,$spaceId)||$item['state']!=='claimed'||$item['followup_id']!==$followupId)throw new \RuntimeException('队列领取或空间已变化，没有创建运行',409);
  $extra=is_array($input['extra']??null)?$input['extra']:[];
  $matches=$item['kind']==='hit' ? $targetType==='hit'&&(string)$item['ref_id']===$targetId
   : $targetType==='conversation'&&$targetId===$type.':'.$spaceId&&(int)($extra['reply_to']??0)===(int)$item['ref_id'];
  if(!$matches)throw new \RuntimeException('运行目标与队列原引用不一致',409);
  if($item['run_id']!==null)throw new \RuntimeException('队列项已经关联运行，不能重复派发',409);
  $q=$this->db->getQueryBuilder();$n=$q->update('cognos_dispatch_queue')->set('run_id',$q->createNamedParameter($runId))
   ->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
   ->where($q->expr()->eq('id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('state',$q->createNamedParameter('claimed')))
   ->andWhere($q->expr()->eq('followup_id',$q->createNamedParameter($followupId)))->andWhere($q->expr()->isNull('run_id'))->executeStatement();
  if($n!==1)throw new \RuntimeException('队列已被其他派发占用，没有创建重复运行',409);
 }

 /** Same transaction as the run outcome. A late outcome can affect only its own binding. */
 public function runOutcome(string $runId,string $outcome,bool $retryable=false):void {
  $state=match($outcome){'running','delivered'=>'sent','unknown'=>'unknown','failed'=>$retryable?'queued':'skipped',default=>throw new \InvalidArgumentException('运行结果状态无效')};
  $q=$this->db->getQueryBuilder();$q->update('cognos_dispatch_queue')->set('state',$q->createNamedParameter($state))
   ->set('note',$q->createNamedParameter('运行 '.$runId.'：'.$outcome.($retryable?'；明确忙拒绝，保留原工作等待重排':'')))
   ->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT));
  if($state==='queued'){$q->set('run_id',$q->createNamedParameter(null,IQueryBuilder::PARAM_NULL));$q->set('followup_id',$q->createNamedParameter(null,IQueryBuilder::PARAM_NULL));}
  $q->where($q->expr()->eq('run_id',$q->createNamedParameter($runId)))->andWhere($q->expr()->in('state',$q->createNamedParameter(['claimed','sent','unknown'],IQueryBuilder::PARAM_STR_ARRAY)))->executeStatement();
 }

 public function expireClaims(int $before):void {
  $q=$this->db->getQueryBuilder();$q->update('cognos_dispatch_queue')->set('state',$q->createNamedParameter('unknown'))->set('note',$q->createNamedParameter('领取后失联，不自动重放'))->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))->where($q->expr()->eq('state',$q->createNamedParameter('claimed')))->andWhere($q->expr()->lt('updated_at',$q->createNamedParameter($before,IQueryBuilder::PARAM_INT)))->executeStatement();
 }
 public function reconcileIntent(string $followupId,string $note):void {
  $q=$this->db->getQueryBuilder();$q->update('cognos_dispatch_queue')->set('state',$q->createNamedParameter('reconciled'))->set('note',$q->createNamedParameter('用户已核清并关闭本次接续：'.$note.'；不重放，不改变运行状态'))->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))->where($q->expr()->eq('followup_id',$q->createNamedParameter($followupId)))->andWhere($q->expr()->eq('state',$q->createNamedParameter('unknown')))->executeStatement();
 }

 /** Caller must first verify ownership of the supplied conversations. */
 public function forCreations(array $ids):array {
  if($ids===[])return [];$scopes=[];foreach($ids as $id)$scopes[$this->scope('creation',(string)$id)]=(string)$id;
  $read=function(bool $active)use($scopes):array{
   $q=$this->db->getQueryBuilder();$q->select('*')->from('cognos_dispatch_queue')->where($q->expr()->in('scope_id',$q->createNamedParameter(array_keys($scopes),IQueryBuilder::PARAM_STR_ARRAY)));
   $states=$q->createNamedParameter(['queued','claimed','unknown','held'],IQueryBuilder::PARAM_STR_ARRAY);$q->andWhere($active?$q->expr()->in('state',$states):$q->expr()->notIn('state',$states))->orderBy('updated_at','DESC')->addOrderBy('id','ASC');if(!$active)$q->setMaxResults(10);return $q->executeQuery()->fetchAll();
  };
  $rows=array_merge($read(true),$read(false));foreach($rows as &$row){$row['space_id']=$scopes[$row['scope_id']];$row['run_state']=null;if($row['run_id']!==null){$q=$this->db->getQueryBuilder();$run=$q->select('state')->from('cognos_runs')->where($q->expr()->eq('id',$q->createNamedParameter($row['run_id'])))->executeQuery()->fetch();$row['run_state']=$run['state']??null;}}unset($row);return $rows;
 }

 public function suspendReviewed(string $id):void {
  $q=$this->db->getQueryBuilder();$q->update('cognos_dispatch_queue')->set('state',$q->createNamedParameter('held'))->set('reviewed_at',$q->createNamedParameter(null,IQueryBuilder::PARAM_NULL))->set('note',$q->createNamedParameter('继续许可已取消或项目安排变化，需要重新核对'))->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
   ->where($q->expr()->eq('id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('state',$q->createNamedParameter('queued')))->andWhere($q->expr()->isNull('run_id'))->andWhere($q->expr()->isNotNull('reviewed_at'))->executeStatement();
 }
 public function releaseHeld(string $id,string $spaceId,string $uid,string $note):array {
  $item=$this->get($id);if(!$item||$item['scope_id']!==$this->scope('creation',$spaceId))throw new \RuntimeException('旧队列记录不存在或不可访问',404);
  $note=trim($note);if($note===''||mb_strlen($note)>2000)throw new \InvalidArgumentException('请写下核对后继续的说明，最多 2000 字');
  $q=$this->db->getQueryBuilder();$n=$q->update('cognos_dispatch_queue')->set('state',$q->createNamedParameter('queued'))->set('reviewed_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
   ->set('note',$q->createNamedParameter($item['note']."\n明确继续（".$uid.'）：'.$note))->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
   ->where($q->expr()->eq('id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('state',$q->createNamedParameter('held')))->executeStatement();
  if($n!==1)throw new \RuntimeException('旧队列状态已变化，请重新核对',409);return $this->get($id);
 }
 public function close(string $id,string $spaceId,string $uid,string $expected,string $note):array {
  $item=$this->get($id);if(!$item||$item['scope_id']!==$this->scope('creation',$spaceId))throw new \RuntimeException('队列记录不存在或不可访问',404);
  $note=trim($note);if(!in_array($expected,['queued','unknown','held'],true)||$note===''||mb_strlen($note)>2000)throw new \InvalidArgumentException('请写下核对或取消说明，最多 2000 字');
  $next=$expected==='unknown'?'reconciled':'cancelled';$q=$this->db->getQueryBuilder();
  $n=$q->update('cognos_dispatch_queue')->set('state',$q->createNamedParameter($next))->set('note',$q->createNamedParameter($item['note']."\n核对记录（".$uid.'）：'.$note.'；不重放，不改变实际运行状态。'))->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
   ->where($q->expr()->eq('id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('state',$q->createNamedParameter($expected)))->executeStatement();
  if($n!==1)throw new \RuntimeException('队列状态已变化，请先查看最新记录',409);
  return ['item_id'=>$id,'state'=>$next,'followup_id'=>$item['followup_id']];
 }
 public function blocked(string $type,string $spaceId):bool {
  $q=$this->db->getQueryBuilder();return (bool)$q->select('id')->from('cognos_dispatch_queue')->where($q->expr()->eq('scope_id',$q->createNamedParameter($this->scope($type,$spaceId))))->andWhere($q->expr()->in('state',$q->createNamedParameter(['claimed','unknown','held'],IQueryBuilder::PARAM_STR_ARRAY)))->setMaxResults(1)->executeQuery()->fetch();
 }
 public function settle(string $id,string $followupId,string $state,string $note=''):bool {
  if(!in_array($state,['queued','sent','unknown','skipped'],true))throw new \InvalidArgumentException('队列状态无效');
  $q=$this->db->getQueryBuilder();return $q->update('cognos_dispatch_queue')->set('state',$q->createNamedParameter($state))->set('note',$q->createNamedParameter($note))->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))->where($q->expr()->eq('id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('followup_id',$q->createNamedParameter($followupId)))->andWhere($q->expr()->eq('state',$q->createNamedParameter('claimed')))->andWhere($q->expr()->isNull('run_id'))->executeStatement()===1;
 }
}
