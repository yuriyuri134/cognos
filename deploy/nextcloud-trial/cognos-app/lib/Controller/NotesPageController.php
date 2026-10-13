<?php
declare(strict_types=1);

namespace OCA\Cognos\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class NotesPageController extends Controller {
    public function __construct(
        string $AppName,
        IRequest $request
    ) {
        parent::__construct($AppName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        Util::addScript('cognos', 'notes-index');
        Util::addStyle('cognos', 'notes-page');
        return new TemplateResponse('cognos', 'notes-index');
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function view(string $noteId): TemplateResponse {
        Util::addScript('cognos', 'notes-view');
        Util::addStyle('cognos', 'notes-page');
        return new TemplateResponse('cognos', 'notes-view', ['noteId' => $noteId]);
    }
}
