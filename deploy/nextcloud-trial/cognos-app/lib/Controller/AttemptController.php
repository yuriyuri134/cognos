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
            foreach(array_unique($conversations) as $chat){
                $messages=$this->creations->getMessages($chat,$uid);$omitted+=max(0,count($messages)-30);
                foreach(array_slice($messages,-30) as $m)$sources[]=['type'=>'message','id'=>(string)$m['id'],'version'=>null,'label'=>'消息 #'.$m['id'].' · '.($m['role']??'').' · '.mb_substr((string)$m['content'],0,100)];
            }
            foreach([['cognos_space_traces','trace','content',30],['cognos_runs','run','purpose',20]] as [$table,$type,$text,$limit]){
                $q=$this->db->getQueryBuilder();$rows=$q->select('*')->from($table)->where($q->expr()->eq('space_type',$q->createNamedParameter('creation')))->andWhere($q->expr()->eq('space_id',$q->createNamedParameter((string)$loc['creation_id'])))->orderBy($type==='trace'?'id':'created_at','DESC')->addOrderBy('id','DESC')->executeQuery()->fetchAll();
                $omitted+=max(0,count($rows)-$limit);foreach(array_slice($rows,0,$limit) as $row)$sources[]=['type'=>$type,'id'=>(string)$row['id'],'version'=>null,'label'=>($type==='trace'?'旧记录':'运行').' · '.($row['author']??$row['subject']??'').' · '.mb_substr((string)$row[$text],0,100)];
            }
            $q=$this->db->getQueryBuilder();$rows=$q->select('id','description','revision')->from('cognos_work_items')->where($q->expr()->eq('location_id',$q->createNamedParameter($loc['id'])))->orderBy('updated_at','DESC')->addOrderBy('id')->executeQuery()->fetchAll();
            $omitted+=max(0,count($rows)-30);foreach(array_slice($rows,0,30) as $row)$sources[]=['type'=>'work_item','id'=>$row['id'],'version'=>(int)$row['revision'],'label'=>'工作：'.$row['description'].' · 第 '.$row['revision'].' 版'];
            $omitted+=max(0,count($sources)-200);$sources=array_slice($sources,0,200);return ['sources'=>$sources,'omitted'=>$omitted,'truncated'=>$omitted>0];
        });
    }
}
