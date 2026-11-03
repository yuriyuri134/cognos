<?php
declare(strict_types=1);
namespace OCA\Cognos\Controller;

use OCA\Cognos\Service\ProjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

final class ProjectController extends Controller {
    public function __construct(IRequest $request, private IUserSession $users, private ProjectService $projects, private LoggerInterface $logger) {
        parent::__construct('cognos', $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function page(string $projectId = '', string $locationId = ''): TemplateResponse {
        Util::addScript('cognos', 'project-workspace');
        Util::addStyle('cognos', 'project-workspace');
        return new TemplateResponse('cognos', 'project-workspace', ['projectId' => $projectId, 'locationId' => $locationId]);
    }

    private function respond(callable $fn): DataResponse {
        $user = $this->users->getUser();
        if (!$user) { return new DataResponse(['message' => '请先登录'], 401); }
        try { return new DataResponse($fn($user->getUID())); }
        catch (\InvalidArgumentException $e) { return new DataResponse(['message' => $e->getMessage()], 422); }
        catch (\RuntimeException $e) {
            if (in_array($e->getCode(), [404, 409], true)) { return new DataResponse(['message' => $e->getMessage()], $e->getCode()); }
            $this->logger->error('Project operation failed', ['exception' => $e, 'app' => 'cognos']);
            return new DataResponse(['message' => '操作未完成，请稍后重试'], 500);
        } catch (\Throwable $e) {
            $this->logger->error('Project operation failed', ['exception' => $e, 'app' => 'cognos']);
            return new DataResponse(['message' => '操作未完成，请稍后重试'], 500);
        }
    }

    #[NoAdminRequired]
    public function list(): DataResponse { return $this->respond(fn(string $uid) => ['projects' => $this->projects->projects($uid)]); }

    #[NoAdminRequired]
    public function get(string $projectId): DataResponse { return $this->respond(fn(string $uid) => ['project' => $this->projects->project($uid, $projectId)]); }

    #[NoAdminRequired]
    public function location(string $locationId): DataResponse { return $this->respond(fn(string $uid) => $this->projects->detail($uid, $locationId)); }

    #[NoAdminRequired]
    public function create(string $title, string $request_id): DataResponse {
        return $this->respond(fn(string $uid) => $this->projects->create($uid, $title, $request_id));
    }

    #[NoAdminRequired]
    public function addLocation(string $projectId, string $parent_id, string $title, string $request_id): DataResponse {
        return $this->respond(fn(string $uid) => $this->projects->addLocation($uid, $projectId, $parent_id, $title, $request_id));
    }

    #[NoAdminRequired]
    public function updateContent(string $locationId, int $expected_revision, string $content, array $source_message_ids, string $request_id): DataResponse {
        return $this->respond(fn(string $uid) => $this->projects->updateContent($uid, $locationId, $expected_revision, $content, $source_message_ids, $request_id));
    }

    #[NoAdminRequired]
    public function addUsage(string $locationId, int $source_creation_id, string $request_id, string $purpose = ''): DataResponse {
        return $this->respond(fn(string $uid) => $this->projects->addUsage($uid, $locationId, $source_creation_id, $purpose, $request_id));
    }

    #[NoAdminRequired]
    public function history(string $locationId, int $before = 0): DataResponse {
        return $this->respond(fn(string $uid) => ['revisions' => $this->projects->history($uid, $locationId, $before)]);
    }

    #[NoAdminRequired]
    public function revision(string $locationId, int $revision): DataResponse {
        return $this->respond(fn(string $uid) => ['revision' => $this->projects->revision($uid, $locationId, $revision)]);
    }
}
