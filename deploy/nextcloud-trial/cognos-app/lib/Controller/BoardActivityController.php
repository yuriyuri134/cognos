<?php
declare(strict_types=1);
namespace OCA\Cognos\Controller;
use OCA\Cognos\Service\TaskEventsService;
use OCA\Deck\AppInfo\Application as DeckApplication;
use OCA\Deck\Service\BoardService;
use OCA\Deck\StatusException;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
class BoardActivityController extends Controller {
    public function __construct(IRequest $request,private IUserSession $users,private IAppManager $apps,private TaskEventsService $events) {parent::__construct('cognos',$request);}
    #[NoAdminRequired]
    public function index(int $boardId,?int $beforeId=null): DataResponse {
        if(!$this->users->getUser())return new DataResponse(['message'=>'Authentication required'],401);
        if($beforeId!==null&&$beforeId<1)return new DataResponse(['message'=>'Invalid event cursor'],400);
        try{
            $this->apps->loadApp('deck');$deck=(new DeckApplication())->getContainer();
            $board=$deck->get(BoardService::class)->find($boardId,false);
            return new DataResponse(['board'=>['id'=>$board->getId(),'title'=>$board->getTitle()]]+$this->events->forBoard($boardId,$beforeId));
        }catch(\Throwable $error){
            if($error instanceof StatusException||$error instanceof DoesNotExistException)return new DataResponse(['message'=>'Permission denied'],403);
            return new DataResponse(['message'=>'Project activity unavailable'],500);
        }
    }
}
