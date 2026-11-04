<?php
declare(strict_types=1);
namespace OCA\Cognos\Service;
use OCP\IDBConnection;use OCP\IConfig;use OCP\DB\QueryBuilder\IQueryBuilder;
/** Import is evidence preservation, never authorization to execute old work. */
final class LegacyQueueImportService {
 public function __construct(private IDBConnection $db,private IConfig $config,private DispatchQueueService $queue){}
 public function scan():array {
  $report=[];foreach($this->config->getAppKeys('cognos') as $key){
   if(!preg_match('/^pushback_queue_([^_]+)_(.+)$/D',$key,$m))continue;
   $raw=$this->config->getAppValue('cognos',$key,'[]');$hash=hash('sha256',$raw);
   $q=$this->db->getQueryBuilder();$old=$q->select('*')->from('cognos_queue_imports')->where($q->expr()->eq('source_key',$q->createNamedParameter($key)))->executeQuery()->fetch();
   if($old&&$old['source_hash']===$hash)continue;
   $state='imported';$note='旧值保留；待处理项须重新核对，不因导入自动派发';$count=0;
   try{
    $items=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($items)||!array_is_list($items))throw new \InvalidArgumentException('旧队列不是列表');
    if($m[1]!=='creation'){$state='unsupported';$note='该空间类型尚未接入主动导入，原记录保留';}
    else{
     $q=$this->db->getQueryBuilder();$space=$q->select('id')->from('cognos_creations')->where($q->expr()->eq('id',$q->createNamedParameter((int)$m[2],IQueryBuilder::PARAM_INT)))->executeQuery()->fetch();
     if(!ctype_digit($m[2])||!$space){$state='missing_space';$note='原会话不可定位，保留旧值供核对';}
     else{$count=count($this->queue->importHeldLegacy($m[1],$m[2],$items));}
    }
   }catch(\JsonException|\InvalidArgumentException $e){$state='invalid';$note='旧记录无法完整识别，未自动执行，原值保留';}
   $this->db->beginTransaction();try{
    $q=$this->db->getQueryBuilder();$q->delete('cognos_queue_imports')->where($q->expr()->eq('source_key',$q->createNamedParameter($key)))->executeStatement();
    $q=$this->db->getQueryBuilder();$v=['source_key'=>$key,'source_hash'=>$hash,'state'=>$state,'item_count'=>$count,'note'=>$note,'checked_at'=>time()];foreach($v as $n=>$value)$v[$n]=$q->createNamedParameter($value,is_int($value)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR);$q->insert('cognos_queue_imports')->values($v)->executeStatement();$this->db->commit();
   }catch(\Throwable $e){$this->db->rollBack();throw $e;}
   $report[]=['source_key'=>$key,'state'=>$state,'item_count'=>$count];
  }return $report;
 }
}
