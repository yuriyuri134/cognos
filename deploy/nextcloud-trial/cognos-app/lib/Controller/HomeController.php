<?php

declare(strict_types=1);

namespace OCA\Cognos\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

final class HomeController extends Controller {
    public function __construct(IRequest $request) {
        parent::__construct('cognos', $request);
    }

    #[NoAdminRequired]
    public function index(): TemplateResponse {
        Util::addScript('cognos', 'home');
        Util::addStyle('cognos', 'home');
        return new TemplateResponse('cognos', 'home');
    }
}
