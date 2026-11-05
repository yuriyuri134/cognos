<?php
declare(strict_types=1);
namespace OCA\Cognos\Controller;
use OCA\Cognos\Service\WorkItemService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
final class WorkItemController extends Controller {
    public function __construct(IRequest $request,private IUserSession $users,private WorkItemService $work,private LoggerInterface $logger) { parent::__construct('cognos',$request); }
    private function respond(callable $fn): DataResponse {
        $user=$this->users->getUser();if (!$user) { return new DataResponse(['message'=>'请先登录'],401); }
        try { return new DataResponse($fn($user->getUID())); }
        catch (\InvalidArgumentException $e) { return new DataResponse(['message'=>$e->getMessage()],422); }
        catch (\RuntimeException $e) {
            if (in_array($e->getCode(),[404,409,422],true)) { return new DataResponse(['message'=>$e->getMessage()],$e->getCode()); }
            $this->logger->error('Work operation failed',['exception'=>$e,'app'=>'cognos']);return new DataResponse(['message'=>'工作操作未完成，请核对后重试'],500);
        } catch (\Throwable $e) { $this->logger->error('Work operation failed',['exception'=>$e,'app'=>'cognos']);return new DataResponse(['message'=>'工作操作未完成，请核对后重试'],500); }
    }
    #[NoAdminRequired]
    public function list(string $projectId,string $location_id=''): DataResponse { return $this->respond(fn($uid)=>['items'=>$this->work->list($uid,$projectId,$location_id)]); }
    #[NoAdminRequired]
    public function detail(string $workId): DataResponse { return $this->respond(fn($uid)=>['item'=>$this->work->detail($uid,$workId)]); }
    #[NoAdminRequired]
    public function create(string $locationId,string $description,string $request_id,string $owner='',string $deliverable='',string $acceptance=''): DataResponse {
        $data=['description'=>$description,'owner'=>$owner,'deliverable'=>$deliverable,'acceptance'=>$acceptance];
        return $this->respond(fn($uid)=>$this->work->create($uid,$locationId,$data,$request_id));
    }
    #[NoAdminRequired]
    public function update(string $workId,int $expected_revision,string $description,string $state,string $request_id,string $owner='',string $deliverable='',string $acceptance='',string $evidence='',string $note=''): DataResponse {
        $data=['description'=>$description,'owner'=>$owner,'deliverable'=>$deliverable,'acceptance'=>$acceptance];
        return $this->respond(fn($uid)=>$this->work->update($uid,$workId,$expected_revision,$data,$state,$evidence,$note,$request_id));
    }
    #[NoAdminRequired]
    public function previewSplit(string $workId,array $parts): DataResponse { return $this->respond(fn($uid)=>$this->work->previewSplit($uid,$workId,$parts)); }
    #[NoAdminRequired]
    public function split(string $workId,array $parts,string $basis,string $request_id): DataResponse { return $this->respond(fn($uid)=>$this->work->split($uid,$workId,$parts,$basis,$request_id)); }
}
