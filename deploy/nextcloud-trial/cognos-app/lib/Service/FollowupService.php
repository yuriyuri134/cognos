<?php
declare(strict_types=1);
namespace OCA\Cognos\Service;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
/** Durable scheduling intent. Claimed work is never automatically replayed. */
final class FollowupService {
 public function __construct(private IDBConnection $db){}
 /** Called inside receiveResult's transaction. */
 public function enqueue(string $resultId,array $run,string $uid):void {
  $q=$this->db->getQueryBuilder();$values=['result_id'=>$resultId,'run_id'=>$run['id'],'user_id'=>$uid,'space_type'=>$run['space_type'],'space_id'=>$run['space_id'],
   'state'=>'pending','open_id'=>$run['target_type']==='open'?(int)$run['target_id']:null,'note'=>'','created_at'=>time(),'updated_at'=>time()];
  foreach($values as $key=>$v)$values[$key]=$q->createNamedParameter($v,$v===null?IQueryBuilder::PARAM_NULL:(is_int($v)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR));
  $q->insert('cognos_followups')->values($values)->executeStatement();
 }
 public function get(string $id):?array {
  $q=$this->db->getQueryBuilder();$r=$q->select('*')->from('cognos_followups')->where($q->expr()->eq('result_id',$q->createNamedParameter($id)))->executeQuery()->fetch();return $r?:null;
 }
 private function transition(string $id,string $from,string $to,string $note=''):bool {
  $q=$this->db->getQueryBuilder();return $q->update('cognos_followups')->set('state',$q->createNamedParameter($to))
   ->set('note',$q->createNamedParameter($note))->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
   ->where($q->expr()->eq('result_id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('state',$q->createNamedParameter($from)))->executeStatement()===1;
 }
 /** false means already claimed/attempted: caller must not dispatch again. */
 public function process(string $id,string $uid,callable $dispatch):bool {
  $event=$this->get($id);
  if(!$event||$event['user_id']!==$uid||!$this->transition($id,'pending','claimed'))return false;
  try {
   $dispatch($event);
   $this->transition($id,'claimed','attempted','已执行一次接续选择；实际执行状态以关联运行为准');
  }catch(\Throwable $e){
   $this->transition($id,'claimed','unknown','接续处理中断，可能已派出；不能自动重复');
   // Result was already committed; a scheduling error must not turn its receipt into a failure.
  }
  return true;
 }
 public function expireClaims(int $before):int {
  $q=$this->db->getQueryBuilder();return $q->update('cognos_followups')->set('state',$q->createNamedParameter('unknown'))
   ->set('note',$q->createNamedParameter('领取接续后失联，可能已派出；不能自动重复'))
   ->set('updated_at',$q->createNamedParameter(time(),IQueryBuilder::PARAM_INT))
   ->where($q->expr()->eq('state',$q->createNamedParameter('claimed')))->andWhere($q->expr()->lt('updated_at',$q->createNamedParameter($before,IQueryBuilder::PARAM_INT)))->executeStatement();
 }
}
