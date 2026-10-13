<?php
declare(strict_types=1);
namespace OCA\Cognos\Controller;
use OCA\Deck\AppInfo\Application as DeckApplication;
use OCA\Deck\Db\Acl;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\PermissionService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;

class PersonalTasksController extends Controller {
    public function __construct(IRequest $request,private IDBConnection $db,private IUserSession $users,private IAppManager $apps) {parent::__construct('cognos',$request);}
    #[NoAdminRequired]
    public function index(string $status='all',?int $beforeId=null): DataResponse {
        $user=$this->users->getUser();
        if(!$user)return new DataResponse(['message'=>'Authentication required'],401);
        if(!in_array($status,['all','open','in_progress','blocked','done'],true)||($beforeId!==null&&$beforeId<1))return new DataResponse(['message'=>'Invalid task filter'],400);
        try {
            $this->apps->loadApp('deck');$deck=(new DeckApplication())->getContainer();
            $permissions=$deck->get(PermissionService::class);$ids=[];
            foreach($deck->get(BoardService::class)->findAll(-1,false,false) as $board){
                if($permissions->getPermissions($board->getId())[Acl::PERMISSION_READ]??false)$ids[]=$board->getId();
            }
            if(!$ids)return new DataResponse(['tasks'=>[],'nextBeforeId'=>null]);
            $q=$this->db->getQueryBuilder();
            $phase="CASE WHEN c.done IS NOT NULL THEN 'done' ELSE COALESCE(p.progress,'open') END";
            $q->selectDistinct(['c.id','c.title','c.stack_id','c.duedate','s.board_id','b.title AS board_title'])->addSelect($q->createFunction($phase.' AS status'))
                ->from('deck_cards','c')->innerJoin('c','deck_stacks','s','s.id=c.stack_id')->innerJoin('s','deck_boards','b','b.id=s.board_id')
                ->innerJoin('c','deck_assigned_users','u','u.card_id=c.id')->leftJoin('c','cognos_task_progress','p','p.card_id=c.id')
                ->where($q->expr()->in('s.board_id',$q->createNamedParameter($ids,IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($q->expr()->eq('u.participant',$q->createNamedParameter($user->getUID())))
                ->andWhere($q->expr()->eq('u.type',$q->createNamedParameter(Acl::PERMISSION_TYPE_USER,IQueryBuilder::PARAM_INT)))
                ->andWhere($q->expr()->eq('c.archived',$q->createNamedParameter(false,IQueryBuilder::PARAM_BOOL)))
                ->andWhere($q->expr()->eq('b.archived',$q->createNamedParameter(false,IQueryBuilder::PARAM_BOOL)));
            foreach(['c.deleted_at','s.deleted_at','b.deleted_at'] as $field)$q->andWhere($q->expr()->eq($field,$q->createNamedParameter(0,IQueryBuilder::PARAM_INT)));
            if($beforeId!==null)$q->andWhere($q->expr()->lt('c.id',$q->createNamedParameter($beforeId,IQueryBuilder::PARAM_INT)));
            if($status!=='all')$q->andWhere($phase.' = '.$q->createNamedParameter($status));
            $result=$q->orderBy('c.id','DESC')->setMaxResults(101)->executeQuery();$rows=$result->fetchAllAssociative();$result->closeCursor();
            $more=count($rows)>100;if($more)array_pop($rows);
            foreach($rows as &$row){foreach(['id','stack_id','board_id'] as $field)$row[$field]=(int)$row[$field];}
            return new DataResponse(['tasks'=>$rows,'nextBeforeId'=>$more?end($rows)['id']:null]);
        }catch(\Throwable $error){return new DataResponse(['message'=>'Tasks unavailable'],500);}
    }
}

