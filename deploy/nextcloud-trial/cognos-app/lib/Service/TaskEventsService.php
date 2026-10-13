<?php
declare(strict_types=1);
namespace OCA\Cognos\Service;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
/** Permission checks belong to the caller; this reads existing business events only. */
class TaskEventsService {
    public function __construct(private IDBConnection $db) {}
    public function forCard(int $cardId,?int $beforeId,array $readableBoardIds): array {
        if(!$readableBoardIds)return ['events'=>[],'nextBeforeId'=>null];
        return $this->page('card_id',$cardId,$beforeId,$readableBoardIds);
    }
    public function forBoard(int $boardId,?int $beforeId): array {return $this->page('board_id',$boardId,$beforeId);}
    private function page(string $field,int $id,?int $beforeId,?array $readableBoardIds=null): array {
        $q=$this->db->getQueryBuilder();
        $q->select('e.*','c.deleted_at AS card_deleted','s.deleted_at AS stack_deleted','s.board_id AS current_board')
            ->from('cognos_task_events','e')->leftJoin('e','deck_cards','c','c.id=e.card_id')->leftJoin('c','deck_stacks','s','s.id=c.stack_id')
            ->where($q->expr()->eq('e.'.$field,$q->createNamedParameter($id)));
        if($readableBoardIds!==null)$q->andWhere($q->expr()->in('e.board_id',$q->createNamedParameter($readableBoardIds,IQueryBuilder::PARAM_INT_ARRAY)));
        if($beforeId!==null)$q->andWhere($q->expr()->lt('e.id',$q->createNamedParameter($beforeId)));
        $result=$q->orderBy('e.id','DESC')->setMaxResults(51)->executeQuery();$rows=$result->fetchAllAssociative();$result->closeCursor();
        $more=count($rows)>50;if($more)array_pop($rows);
        foreach($rows as &$row){
            foreach(['id','board_id','card_id','created_at'] as $key)$row[$key]=(int)$row[$key];
            $row['task_url']=$row['current_board']!==null&&(int)$row['current_board']===$row['board_id']&&(int)$row['card_deleted']===0&&(int)$row['stack_deleted']===0
                ? '/index.php/apps/deck/board/'.$row['board_id'].'/card/'.$row['card_id']:null;
            unset($row['current_board'],$row['card_deleted'],$row['stack_deleted']);
            $row['before_state']=$row['before_state']===null?null:json_decode($row['before_state'],true,flags:JSON_THROW_ON_ERROR);
            $row['after_state']=json_decode($row['after_state'],true,flags:JSON_THROW_ON_ERROR);
        }
        return ['events'=>$rows,'nextBeforeId'=>$more?end($rows)['id']:null];
    }
}
