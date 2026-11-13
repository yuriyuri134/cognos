<?php
declare(strict_types=1);
namespace OCA\Cognos\Controller;
use OCA\Cognos\Service\{AttemptService,ProjectService,CreationSpaceService};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\{IRequest,IUserSession,IDBConnection};
use Psr\Log\LoggerInterface;
final class AttemptController extends Controller {
    public function __construct(IRequest $request,private IUserSession $users,private AttemptService $attempts,
        private ProjectService $projects,private CreationSpaceService $creations,private IDBConnection $db,private LoggerInterface $logger) { parent::__construct('cognos',$request); }
    private function respond(callable $fn):DataResponse {
        $user=$this->users->getUser();if(!$user)return new DataResponse(['message'=>'请先登录'],401);
        try{return new DataResponse($fn($user->getUID()));}
        catch(\InvalidArgumentException $e){return new DataResponse(['message'=>$e->getMessage()],422);}
        catch(\RuntimeException $e){if(in_array($e->getCode(),[403,404,409,422],true))return new DataResponse(['message'=>$e->getMessage()],$e->getCode());$this->logger->error('Attempt operation failed',['exception'=>$e,'app'=>'cognos']);return new DataResponse(['message'=>'尝试记录未保存，请核对后重试'],500);}
        catch(\Throwable $e){$this->logger->error('Attempt operation failed',['exception'=>$e,'app'=>'cognos']);return new DataResponse(['message'=>'尝试操作未完成，请保留输入并核对后重试'],500);}
    }
    #[NoAdminRequired]
    public function list(string $projectId,string $location_id=''):DataResponse{return $this->respond(fn($u)=>['items'=>$this->attempts->list($u,$projectId,$location_id)]);}
    #[NoAdminRequired]
    public function detail(string $attemptId):DataResponse{return $this->respond(fn($u)=>['item'=>$this->attempts->detail($u,$attemptId)]);}
    #[NoAdminRequired]
    public function create(string $locationId,string $request_id):DataResponse{return $this->respond(fn($u)=>$this->attempts->create($u,$locationId,$this->request->getParams(),$request_id));}
    #[NoAdminRequired]
    public function update(string $attemptId,int $expected_revision,string $request_id):DataResponse{return $this->respond(fn($u)=>$this->attempts->update($u,$attemptId,$expected_revision,$this->request->getParams(),$request_id));}
    #[NoAdminRequired]
    public function addSummary(string $attemptId,string $request_id):DataResponse{return $this->respond(fn($u)=>$this->attempts->addSummary($u,$attemptId,$this->request->getParams(),$request_id));}
    #[NoAdminRequired]
    public function updateSummary(string $summaryId,int $expected_revision,string $request_id):DataResponse{return $this->respond(fn($u)=>$this->attempts->updateSummary($u,$summaryId,$expected_revision,$this->request->getParams(),$request_id));}
    #[NoAdminRequired]
    public function readReference(array $ref):DataResponse{return $this->respond(fn($u)=>$this->attempts->readReference($u,$ref));}
    /** Optional picker, with bounded acquisition stated explicitly. It does not create references. */
    #[NoAdminRequired]
    public function sources(string $locationId):DataResponse {
        return $this->respond(function($uid)use($locationId):array{
            $loc=$this->projects->location($uid,$locationId);$sources=[];$omitted=0;
            $sources[]=['type'=>'location_content','id'=>$loc['id'],'version'=>$loc['revision'],'label'=>'当前稿：'.$loc['title'].' · 第 '.$loc['revision'].' 版'];
            $conversations=[(int)$loc['creation_id']];foreach($this->projects->usages($uid,$loc['id']) as $usage)$conversations[]=(int)$usage['source_creation_id'];
            // 每样只在查询里取要显示的那几条（最近的），另用计数说明省略了多少；原来是整处全取出来再截
            foreach(array_unique($conversations) as $chat){
                if($this->creations->get($chat,$uid)===null)continue;
                $where=fn($q)=>$q->expr()->andX(
                    $q->expr()->eq('creation_id',$q->createNamedParameter($chat,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)),
                    $q->expr()->eq('is_archived', $q->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
                $omitted+=max(0,$this->count('cognos_creation_messages',$where)-30);
                $q=$this->db->getQueryBuilder();$messages=array_reverse($q->select('id','role','content')->from('cognos_creation_messages')->where($where($q))->orderBy('id','DESC')->setMaxResults(30)->executeQuery()->fetchAll());
                foreach($messages as $m)$sources[]=['type'=>'message','id'=>(string)$m['id'],'version'=>null,'label'=>'消息 #'.$m['id'].' · '.($m['role']??'').' · '.mb_substr((string)$m['content'],0,100)];
            }
            $inSpace=fn($q)=>$q->expr()->andX($q->expr()->eq('space_type',$q->createNamedParameter('creation')),$q->expr()->eq('space_id',$q->createNamedParameter((string)$loc['creation_id'])));
            foreach([['cognos_space_traces','trace',['id','author','content'],'content',30],['cognos_runs','run',['id','subject','purpose','created_at'],'purpose',20]] as [$table,$type,$columns,$text,$limit]){
                $omitted+=max(0,$this->count($table,$inSpace)-$limit);
                $q=$this->db->getQueryBuilder();$rows=$q->select(...$columns)->from($table)->where($inSpace($q))->orderBy($type==='trace'?'id':'created_at','DESC')->addOrderBy('id','DESC')->setMaxResults($limit)->executeQuery()->fetchAll();
                foreach($rows as $row)$sources[]=['type'=>$type,'id'=>(string)$row['id'],'version'=>null,'label'=>($type==='trace'?'旧记录':'运行').' · '.($row['author']??$row['subject']??'').' · '.mb_substr((string)$row[$text],0,100)];
            }
            $atLocation=fn($q)=>$q->expr()->eq('location_id',$q->createNamedParameter($loc['id']));
            $omitted+=max(0,$this->count('cognos_work_items',$atLocation)-30);
            $q=$this->db->getQueryBuilder();$rows=$q->select('id','description','revision')->from('cognos_work_items')->where($atLocation($q))->orderBy('updated_at','DESC')->addOrderBy('id')->setMaxResults(30)->executeQuery()->fetchAll();
            foreach($rows as $row)$sources[]=['type'=>'work_item','id'=>$row['id'],'version'=>(int)$row['revision'],'label'=>'工作：'.$row['description'].' · 第 '.$row['revision'].' 版'];
            $omitted+=max(0,count($sources)-200);$sources=array_slice($sources,0,200);return ['sources'=>$sources,'omitted'=>$omitted,'truncated'=>$omitted>0];
        });
    }
    /** 一张表里符合条件的有多少（只数，不取）。$where 收到查询，返回条件。 */
    private function count(string $table,callable $where):int {
        $q=$this->db->getQueryBuilder();
        return (int)$q->select($q->func()->count('*','n'))->from($table)->where($where($q))->executeQuery()->fetchOne();
    }
}
