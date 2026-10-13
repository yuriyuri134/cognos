<?php
declare(strict_types=1);

namespace OCA\Cognos\Controller;

use OCA\Cognos\Service\ActivitySourceService;
use OCA\Cognos\Service\NotesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class NotesController extends Controller {
    public function __construct(
        IRequest $request,
        private NotesService $notes,
        private ActivitySourceService $activities,
        private IUserSession $users,
    ) {
        parent::__construct('cognos', $request);
    }

    private function requireLogin(): ?JSONResponse {
        return $this->users->getUser() === null
            ? new JSONResponse(['error' => 'Authentication required'], 401)
            : null;
    }

    private function payload(): array {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) return [];
        try {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('Invalid JSON body');
        }
        return is_array($value) ? $value : [];
    }

    private function param(string $name, string $default = '', bool $trim = true): string {
        $payload = $this->payload();
        $value = (string)($payload[$name] ?? $this->request->getParam($name, $default));
        return $trim ? trim($value) : $value;
    }

    #[NoAdminRequired]
    public function createSpace(): JSONResponse {
        if ($error = $this->requireLogin()) return $error;
        try {
            $title = $this->param('title');
            if ($title === '') return new JSONResponse(['error' => 'Title is required'], 422);
            return new JSONResponse(['space' => $this->notes->createSpace($title, $this->param('description'))], 201);
        } catch (\InvalidArgumentException $error) {
            return new JSONResponse(['error' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            \OC::$server->get(\Psr\Log\LoggerInterface::class)->error('Notes createSpace failed: ' . $error->getMessage(), ['exception' => $error]);
            return new JSONResponse(['error' => 'Space could not be created', 'details' => $error->getMessage()], 500);
        }
    }

    #[NoAdminRequired]
    public function createNote(string $spaceId): JSONResponse {
        if ($error = $this->requireLogin()) return $error;
        try {
            $title = $this->param('title');
            if ($title === '') return new JSONResponse(['error' => 'Title is required'], 422);
            $note = $this->notes->createNote($spaceId, $title, $this->param('content', '', false));
            return new JSONResponse(['note' => $note], 201);
        } catch (\RuntimeException $error) {
            return new JSONResponse(['error' => 'Space not found'], 404);
        } catch (\Throwable $error) {
            return new JSONResponse(['error' => 'Note could not be created'], 500);
        }
    }

    #[NoAdminRequired]
    public function updateNote(string $noteId): JSONResponse {
        if ($error = $this->requireLogin()) return $error;
        try {
            $title = $this->param('title');
            if ($title === '') return new JSONResponse(['error' => 'Title is required'], 422);
            return new JSONResponse(['note' => $this->notes->updateNote($noteId, $title, $this->param('content', '', false))]);
        } catch (\RuntimeException $error) {
            return new JSONResponse(['error' => 'Note not found'], 404);
        } catch (\Throwable $error) {
            return new JSONResponse(['error' => 'Note could not be updated'], 500);
        }
    }

    #[NoAdminRequired]
    public function listSpaces(): JSONResponse {
        if ($error = $this->requireLogin()) return $error;
        try {
            return new JSONResponse(['spaces' => $this->notes->listSpaces()]);
        } catch (\Throwable $error) {
            return new JSONResponse(['error' => 'Notes unavailable'], 500);
        }
    }

    #[NoAdminRequired]
    public function listNotes(string $spaceId): JSONResponse {
        if ($error = $this->requireLogin()) return $error;
        try {
            return new JSONResponse(['notes' => $this->notes->listNotes($spaceId)]);
        } catch (\RuntimeException $error) {
            return new JSONResponse(['error' => 'Space not found'], 404);
        } catch (\Throwable $error) {
            return new JSONResponse(['error' => 'Notes unavailable'], 500);
        }
    }

    #[NoAdminRequired]
    public function getNote(string $noteId): JSONResponse {
        if ($error = $this->requireLogin()) return $error;
        try {
            return new JSONResponse(['note' => $this->notes->getNote($noteId)]);
        } catch (\RuntimeException $error) {
            return new JSONResponse(['error' => 'Note not found'], 404);
        } catch (\Throwable $error) {
            return new JSONResponse(['error' => 'Note unavailable'], 500);
        }
    }

    #[NoAdminRequired]
    public function search(string $query = '', ?string $spaceId = null): JSONResponse {
        if ($error = $this->requireLogin()) return $error;
        if (trim($query) === '') return new JSONResponse(['results' => []]);
        try {
            return new JSONResponse(['results' => $this->notes->search($query, $spaceId)]);
        } catch (\RuntimeException $error) {
            return new JSONResponse(['error' => 'Space not found'], 404);
        } catch (\Throwable $error) {
            return new JSONResponse(['error' => 'Search unavailable'], 500);
        }
    }

    #[NoAdminRequired]
    public function getGraph(string $spaceId): JSONResponse {
        if ($error = $this->requireLogin()) return $error;
        try {
            $this->notes->assertSpaceReadable($spaceId);
            return new JSONResponse(['nodes' => [], 'links' => []]);
        } catch (\RuntimeException $error) {
            return new JSONResponse(['error' => 'Space not found'], 404);
        }
    }

    #[NoAdminRequired]
    public function startDiscussion(string $noteId): JSONResponse {
        if ($error = $this->requireLogin()) return $error;
        try {
            $result = $this->notes->createActivity($noteId, $this->activities, $this->param('participant_id'));
            return new JSONResponse([
                'success' => true,
                'activity_id' => $result['activity']['id'],
                'activity' => $result['activity'],
                'reused' => $result['reused'],
            ], $result['reused'] ? 200 : 201);
        } catch (\InvalidArgumentException $error) {
            return new JSONResponse(['error' => $error->getMessage()], 422);
        } catch (\RuntimeException $error) {
            return new JSONResponse(['error' => 'Note not found'], 404);
        } catch (\Throwable $error) {
            return new JSONResponse(['error' => 'Activity could not be created'], 500);
        }
    }

    #[NoAdminRequired]
    public function getReferences(string $noteId): JSONResponse {
        if ($error = $this->requireLogin()) return $error;
        try {
            return new JSONResponse(['references' => $this->activities->referencesForNote($noteId)]);
        } catch (\Throwable $error) {
            return new JSONResponse(['error' => 'References unavailable'], 500);
        }
    }
}
