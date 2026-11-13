<?php

declare(strict_types=1);

namespace OCA\Cognos\Controller;

use OCA\Cognos\Service\NormService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

final class HomeController extends Controller {
    public function __construct(
        IRequest $request,
        private IConfig $config,
        private IUserSession $users,
        private NormService $norm,
    ) {
        parent::__construct('cognos', $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        Util::addStyle('cognos', 'norm');
        return new TemplateResponse('cognos', 'home', ['self_space' => $this->selfSpace()]);
    }

    /**
     * Cognos 自己：被认作 Cognos 自己的那段对话（self_creation_id）要是某个 Norm 空间自己的那段，门口直接到那个空间。
     * 那里同时有它的自述、历史、总观、纠正，还挂着的事，和它自己的代码。
     */
    private function selfSpace(): ?array {
        $user = $this->users->getUser();
        $selfId = (int)$this->config->getAppValue('cognos', 'self_creation_id', '0');
        if ($user === null || $selfId <= 0) {
            return null;
        }
        $space = $this->norm->spaceByConversation($user->getUID(), $selfId);
        return $space === null ? null : ['id' => (string)$space['id'], 'title' => (string)$space['title']];
    }
}
