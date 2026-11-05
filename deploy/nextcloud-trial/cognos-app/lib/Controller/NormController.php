<?php

declare(strict_types=1);

namespace OCA\Cognos\Controller;

use OCA\Cognos\Service\NormService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

/** Norm（示范空间）：对话 + 项目。 */
final class NormController extends Controller {
    public function __construct(IRequest $request, private IUserSession $users, private NormService $norm) {
        parent::__construct('cognos', $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function page(): TemplateResponse {
        Util::addScript('cognos', 'chat-box');
        Util::addScript('cognos', 'norm');
        Util::addStyle('cognos', 'norm');
        return new TemplateResponse('cognos', 'norm');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function projectPage(string $projectId): TemplateResponse {
        Util::addScript('cognos', 'norm-project');
        Util::addStyle('cognos', 'norm');
        return new TemplateResponse('cognos', 'norm-project', ['projectId' => $projectId]);
    }

    #[NoAdminRequired]
    public function data(): DataResponse {
        $uid = $this->uid();
        return new DataResponse(['conversation_id' => $this->norm->conversationId($uid), 'projects' => $this->norm->projects($uid)]);
    }

    #[NoAdminRequired]
    public function project(string $projectId): DataResponse {
        $project = $this->norm->project($this->uid(), $projectId);
        return $project === null ? new DataResponse(['message' => '没有这个项目'], 404) : new DataResponse(['project' => $project]);
    }

    private function uid(): string {
        $user = $this->users->getUser();
        if ($user === null) {
            throw new \RuntimeException('Authentication required');
        }
        return $user->getUID();
    }
}
