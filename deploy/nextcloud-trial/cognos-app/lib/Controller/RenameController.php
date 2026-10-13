<?php
declare(strict_types=1);
namespace OCA\Cognos\Controller;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
class RenameController extends Controller {
    public function __construct(IRequest $request, private EditController $edits) { parent::__construct('cognos',$request); }
    #[NoAdminRequired]
    public function rename(int $cardId, string $title, ?string $requestId=null, ?string $expectedTitle=null, ?int $boardId=null): DataResponse {
        return $this->edits->edit($cardId,['title'=>$title],$requestId,$expectedTitle===null?[]:['title'=>$expectedTitle],$boardId);
    }
}
