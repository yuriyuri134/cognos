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
   $q=$this->db->getQueryBuilder();$open=$q->select('*')->from('cognos_dispatch_queue')->where($q->expr()->eq('scope_id',$q->createNamedParameter($scope)))->andWhere($q->expr()->eq('kind',$q->createNamedParameter($kind)))->andWhere($q->expr()->eq('ref_id',$q->createNamedParameter($ref,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->in('state',$q->createNamedParameter(['queued','claimed','unknown'],IQueryBuilder::PARAM_STR_ARRAY)))->executeQuery()->fetch();
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
  $out=[];foreach($items as $item){$item=is_int($item)?'hit:'.$item:(string)$item;if(!preg_match('/^(msg|hit):([1-9][0-9]*)$/D',$item,$m))throw new \InvalidArgumentException('旧队列含无法识别的引用，保留原记录供核对');
   $key=hash('sha256',json_encode([$type,$spaceId,$item],JSON_THROW_ON_ERROR));$out[]=$this->enqueue($type,$spaceId,$m[1],(int)$m[2],false,$key);
  }return $out;
 }
 public function claim(string $type,string $spaceId,string $followupId):?array {
  $scope=$this->scope($type,$spaceId);return $this->atomic($scope,function()use($scope,$followupId):?array {
   $q=$this->db->getQueryBuilder();$blocked=$q->select('id')->from('cognos_dispatch_queue')->where($q->expr()->eq('scope_id',$q->createNamedParameter($scope)))->andWhere($q->expr()->in('state',$q->createNamedParameter(['claimed','unknown'],IQueryBuilder::PARAM_STR_ARRAY)))->setMaxResults(1)->executeQuery()->fetch();if($blocked)return null;
   $q=$this->db->getQueryBuilder();$row=$q->select('*')->from('cognos_dispatch_queue')->where($q->expr()->eq('scope_id',$q->createNamedParameter($scope)))->andWhere($q->expr()->eq('state',$q->createNamedParameter('queued')))->orderBy('position','ASC')->addOrderBy('id','ASC')->setMaxResults(1)->executeQuery()->fetch();if(!$row)return null;
   $q=$this->db->getQueryBuilder();$q->update('cognos_dispatch_queue')->set('state',$q->createNamedParameter('claimed'))->set('followup_id',$q->createNamedParameter($followupId))->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))->where($q->expr()->eq('id',$q->createNamedParameter($row['id'])))->executeStatement();return $this->get($row['id']);
  });
 }
 public function settle(string $id,string $followupId,string $state,string $note=''):bool {
  if(!in_array($state,['queued','sent','unknown','skipped'],true))throw new \InvalidArgumentException('队列状态无效');
  $q=$this->db->getQueryBuilder();return $q->update('cognos_dispatch_queue')->set('state',$q->createNamedParameter($state))->set('note',$q->createNamedParameter($note))->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))->where($q->expr()->eq('id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('followup_id',$q->createNamedParameter($followupId)))->andWhere($q->expr()->eq('state',$q->createNamedParameter('claimed')))->executeStatement()===1;
 }
}
