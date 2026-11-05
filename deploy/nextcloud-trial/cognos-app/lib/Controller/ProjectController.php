<?php
declare(strict_types=1);
namespace OCA\Cognos\Controller;

use OCA\Cognos\Service\AdoptionService;
use OCA\Cognos\Service\DecisionService;
use OCA\Cognos\Service\DelegationService;
use OCA\Cognos\Service\InitiumService;
use OCA\Cognos\Service\ProjectService;
use OCA\Cognos\Service\RestructureService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

final class ProjectController extends Controller {
    public function __construct(IRequest $request, private IUserSession $users, private ProjectService $projects,
        private DecisionService $decisions, private DelegationService $delegations, private InitiumService $initium,
        private AdoptionService $adoptions, private RestructureService $restructures,
        private \OCA\Cognos\Service\FollowupService $followups,
        private \OCA\Cognos\Service\DispatchQueueService $dispatchQueue,
        private IConfig $config, private LoggerInterface $logger) {
        parent::__construct('cognos', $request);
    }

    /**
     * 项目页上方：在生效的决定（每条走到每个参与者哪一步，只说有证据的）、谁在动什么。
     * 带 location_id 时，再给一段可复制的入口文字（对这一处适用的决定 + 这一处现状），给叫不醒的端用。
     */
    #[NoAdminRequired]
    public function overview(string $projectId, string $location_id = ''): DataResponse {
        return $this->respond(function (string $uid) use ($projectId, $location_id): array {
            $decisions = $this->decisions->forProject($uid, $projectId);
            $activity = $this->projects->activity($uid, $projectId);
            $progress = $this->decisions->progress(array_column($decisions, 'id'), $activity['creation_ids']);
            foreach ($decisions as &$d) { $d['progress'] = $progress[$d['id']] ?? []; }
            unset($d);
            $followups = $this->followups->forCreations($uid,$activity['creation_ids']);
            $queue=$this->dispatchQueue->forCreations($activity['creation_ids']);
            unset($activity['creation_ids']);
            $counts = $this->delegations->countsForProject($projectId);
            $reviews = $this->adoptions->reviewCounts($projectId);
            $locations = array_map(static fn(array $l): array => $l + ($counts[$l['id']] ?? ['open' => 0, 'held' => 0]) + ['review' => $reviews[$l['id']] ?? 0],
                $this->projects->locationStates($uid, $projectId));
            $out = ['dispatch_queue'=>$queue, 'followups' => $followups, 'project' => $this->projects->project($uid, $projectId), 'lifecycle_history' => $this->projects->lifecycleHistory($uid, $projectId), 'decisions' => $decisions, 'activity' => $activity, 'locations' => $locations,
                'formed_from' => $this->initium->forProject($uid, $projectId),
                'history' => $this->restructures->historyForProject($uid, $projectId), 'merged_into' => $this->mergedInto($uid, $projectId)];
            if ($location_id !== '') {
                $out['handoff_text'] = $this->handoffText($uid, $location_id);
                $out['delegations'] = $this->delegations->forLocation($uid, $location_id);
                $out['revision'] = $this->projects->location($uid, $location_id)['revision'];
                $out['location_history'] = $this->restructures->historyForLocation($uid, $location_id);
            }
            return $out;
        });
    }

    #[NoAdminRequired]
    public function recordDecision(string $projectId, string $content, string $request_id, string $scope = 'project',
        string $location_id = '', ?int $source_message_id = null, string $source_note = '', string $supersedes = ''): DataResponse {
        return $this->respond(fn(string $uid) => $this->decisions->record($uid, $projectId, $content,
            $scope === 'here' && $location_id !== '' ? [$location_id] : [], $source_message_id ?: null,
            $source_note, null, $uid, $supersedes !== '' ? $supersedes : null, $request_id));
    }

    /** 从这个位置交出去一件事，交给本机 Claude Code；结果回到这个位置。 */
    #[NoAdminRequired]
    public function delegate(string $locationId, string $ask, string $request_id, string $goal = '', string $means = '',
        string $forbidden = '', int $return_minutes = 30, bool $may_edit = false, bool $may_adopt = false, string $work_item_id = '', int $expected_work_revision = 0, bool $may_manage_work = false, string $executor = ''): DataResponse {
        return $this->respond(fn(string $uid) => $this->delegations->delegate($uid, $locationId, $ask, $goal, $means, $forbidden,
            $return_minutes, $may_edit, $request_id, $may_adopt, $work_item_id, $expected_work_revision, $may_manage_work, $executor));
    }

    #[NoAdminRequired]
    public function revokeDecision(string $decisionId, string $request_id): DataResponse {
        return $this->respond(fn(string $uid) => $this->decisions->revoke($uid, $decisionId, $request_id));
    }

    /** 给叫不醒的端（网页会话）复制过去的一段话：适用的决定原话 + 这一处现在是什么样。 */
    private function handoffText(string $uid, string $locationId): string {
        $detail = $this->projects->detail($uid, $locationId);
        $loc = $detail['location'];
        $lines = ['这是 Cognos 项目「' . $detail['project']['title'] . '」里「' . $loc['title'] . '」这一处的现状（' . $this->localTime($uid, time(), 'Y-m-d H:i') . ' 取出）。', '', '在生效的决定：'];
        $applicable = $this->decisions->applicable($uid, $locationId);
        if ($applicable === []) { $lines[] = '（这一处目前没有登记在生效的决定）'; }
        foreach ($applicable as $d) {
            $lines[] = sprintf('- %s 说（%s）：「%s」', $d['author_name'], $this->localTime($uid, (int)($d['said_at'] ?? $d['created_at']), 'Y-m-d'), $d['content']);
        }
        $lines[] = '';
        $lines[] = '当前内容（版本 ' . $loc['revision'] . '）：';
        $content = (string)$loc['content'];
        $lines[] = $content === '' ? '（还是空的）' : mb_substr($content, 0, 6000) . (mb_strlen($content) > 6000 ? "\n（以下截断）" : '');
        if ($detail['usages'] !== []) {
            $lines[] = '';
            $lines[] = '在这里使用的材料：';
            foreach ($detail['usages'] as $u) { $lines[] = '- ' . $u['title'] . ($u['purpose'] !== '' ? '：' . $u['purpose'] : ''); }
        }
        return implode("\n", $lines);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function page(string $projectId = '', string $locationId = ''): TemplateResponse {
        Util::addScript('cognos', 'project-workspace');
        Util::addScript('cognos', 'work-items');
        Util::addScript('cognos', 'attempts');
        Util::addStyle('cognos', 'project-workspace');
        return new TemplateResponse('cognos', 'project-workspace', ['projectId' => $projectId, 'locationId' => $locationId]);
    }

    /** 重组：先预览后果（带 basis），确认时带回 basis，状态变了就整批不做。 */
    #[NoAdminRequired]
    public function previewRestructure(string $action, string $location_id = '', string $parent_id = '', string $project_id = '',
        string $target_parent_id = '', string $change_id = ''): DataResponse {
        return $this->respond(fn(string $uid) => $this->restructures->preview($uid, $action,
            $this->restructureParams($action, $location_id, $parent_id, $project_id, $target_parent_id, $change_id)));
    }

    #[NoAdminRequired]
    public function restructure(string $action, string $basis, string $request_id, string $location_id = '', string $parent_id = '',
        string $project_id = '', string $target_parent_id = '', string $change_id = ''): DataResponse {
        return $this->respond(fn(string $uid) => $this->restructures->apply($uid, $action,
            $this->restructureParams($action, $location_id, $parent_id, $project_id, $target_parent_id, $change_id), $basis, $request_id));
    }

    private function restructureParams(string $action, string $locationId, string $parentId, string $projectId, string $targetParentId, string $changeId): array {
        return match ($action) {
            'undo_move', 'undo' => ['change_id' => $changeId],
            'move' => ['location_id' => $locationId, 'parent_id' => $parentId],
            'independent' => ['location_id' => $locationId],
            'merge' => ['project_id' => $projectId, 'target_parent_id' => $targetParentId],
            default => [],
        };
    }

    private function mergedInto(string $uid, string $projectId): ?array {
        $p = $this->projects->project($uid, $projectId);
        if (empty($p['merged_into'])) { return null; }
        $t = $this->projects->project($uid, (string)$p['merged_into']);
        return ['project_id' => (string)$t['id'], 'title' => (string)$t['title'], 'merged_at' => (int)$p['merged_at']];
    }

    /** 这个位置的采用、纠正经过和还没处理的影响。 */
    #[NoAdminRequired]
    public function adoptions(string $locationId): DataResponse {
        return $this->respond(fn(string $uid) => $this->adoptions->forLocation($uid, $locationId));
    }

    #[NoAdminRequired]
    public function adopt(string $locationId, string $object_type, string $object_id, string $request_id, string $object_version = '',
        string $basis = '', string $supersedes = ''): DataResponse {
        return $this->respond(fn(string $uid) => $this->adoptions->adopt($uid, $locationId, $object_type, $object_id,
            $object_version === '' ? null : $object_version, $basis, $supersedes === '' ? null : $supersedes, $request_id));
    }

    #[NoAdminRequired]
    public function acceptProposal(string $adoptionId, string $request_id): DataResponse {
        return $this->respond(fn(string $uid) => $this->adoptions->acceptProposal($uid, $adoptionId, $request_id));
    }

    #[NoAdminRequired]
    public function declineProposal(string $adoptionId, string $request_id): DataResponse {
        return $this->respond(fn(string $uid) => $this->adoptions->declineProposal($uid, $adoptionId, $request_id));
    }

    #[NoAdminRequired]
    public function withdrawAdoption(string $adoptionId, string $request_id): DataResponse {
        return $this->respond(fn(string $uid) => $this->adoptions->withdraw($uid, $adoptionId, $request_id));
    }

    #[NoAdminRequired]
    public function reaffirmAdoption(string $adoptionId, string $response, string $request_id): DataResponse {
        return $this->respond(fn(string $uid) => $this->adoptions->reaffirm($uid, $adoptionId, $response, $request_id));
    }

    #[NoAdminRequired]
    public function correct(string $object_type, string $object_id, string $content, string $request_id, string $object_version = '',
        string $basis = ''): DataResponse {
        return $this->respond(fn(string $uid) => $this->adoptions->correct($uid, $object_type, $object_id,
            $object_version === '' ? null : $object_version, $content, $basis, $request_id));
    }

    #[NoAdminRequired]
    public function resolveImpact(string $impactId, string $request_id, string $resolution = ''): DataResponse {
        return $this->respond(fn(string $uid) => $this->adoptions->resolveImpact($uid, $impactId, $resolution, $request_id));
    }

    /** Initium：还没成为项目的会话，和确立。 */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function initiumPage(): TemplateResponse {
        Util::addScript('cognos', 'initium');
        Util::addStyle('cognos', 'project-workspace');
        return new TemplateResponse('cognos', 'initium', []);
    }

    #[NoAdminRequired]
    public function initium(): DataResponse {
        return $this->respond(fn(string $uid) => ['exploring' => $this->initium->exploring($uid), 'items' => $this->initium->list($uid), 'projects' => $this->projects->projects($uid)]);
    }

    #[NoAdminRequired]
    public function exploreFrom(string $projectId, string $text, string $request_id): DataResponse {
        return $this->respond(fn(string $uid) => $this->initium->exploreFrom($uid,$projectId,$text,$request_id));
    }

    #[NoAdminRequired]
    public function capture(string $text, string $request_id): DataResponse {
        return $this->respond(fn(string $uid) => $this->initium->capture($uid, $text, $request_id));
    }

    /** 确立：$project_id 为空就是新项目，否则成为那个项目的又一段来路。 */
    #[NoAdminRequired]
    public function form(int $creation_id, array $message_ids, string $request_id, string $title = '', string $description = '',
        string $reason = '', string $project_id = ''): DataResponse {
        return $this->respond(fn(string $uid) => $this->initium->form($uid, $creation_id, $message_ids, $title, $description,
            $reason, $project_id, $request_id));
    }

    /** 用户自己的时区，没设就按入口用的 Asia/Shanghai（容器里的 date() 是 UTC）。 */
    private function localTime(string $uid, int $ts, string $format): string {
        $tz = $this->config->getUserValue($uid, 'core', 'timezone', '') ?: 'Asia/Shanghai';
        try { $zone = new \DateTimeZone($tz); } catch (\Exception) { $zone = new \DateTimeZone('Asia/Shanghai'); }
        return (new \DateTimeImmutable('@' . $ts))->setTimezone($zone)->format($format);
    }

    #[NoAdminRequired]
    public function lifecycle(string $projectId, string $action, int $expected_revision, string $request_id, string $note = '', string $reopen_when = ''): DataResponse {
        return $this->respond(fn(string $uid) => $this->projects->changeLifecycle($uid,$projectId,$action,$expected_revision,$request_id,$note,$reopen_when));
    }

    #[NoAdminRequired]
    public function closeFollowup(string $resultId,string $expected_state,string $note,string $request_id):DataResponse {
        return $this->respond(fn(string $uid) => $this->projects->commit($uid,$request_id,['close_followup',$resultId,$expected_state,$note],
            fn()=>$this->followups->close($resultId,$uid,$expected_state,$note)));
    }

    #[NoAdminRequired]
    public function closeQueue(string $itemId,int $creation_id,string $expected_state,string $note,string $request_id):DataResponse {
        return $this->respond(fn(string $uid)=>$this->projects->commit($uid,$request_id,['close_queue',$itemId,$creation_id,$expected_state,$note],function()use($uid,$itemId,$creation_id,$expected_state,$note):array {
            $loc=$this->projects->locationForCreation($uid,$creation_id);
            if($loc===null)throw new \RuntimeException('项目讨论不存在或不可访问',404);
            $this->projects->lockedLocation($uid,$loc['id']);
            // Both review entry points lock intent before queue to avoid opposite lock order.
            $peek=$this->dispatchQueue->get($itemId);
            if($peek!==null&&$peek['followup_id']!==null)$this->followups->lockOwned($peek['followup_id'],$uid);
            $closed=$this->dispatchQueue->close($itemId,(string)$creation_id,$uid,$expected_state,$note);
            if($expected_state==='unknown'&&$closed['followup_id']!==null){
                $intent=$this->followups->get($closed['followup_id']);
                if($intent!==null&&$intent['state']==='unknown'&&$intent['user_id']===$uid)$this->followups->close($intent['result_id'],$uid,'unknown',$note);
            }
            return $closed;
        }));
    }

    #[NoAdminRequired]
    public function resumeQueue(string $itemId,int $creation_id,string $note,string $request_id):DataResponse {
        return $this->respond(fn(string $uid)=>$this->projects->commit($uid,$request_id,['resume_queue',$itemId,$creation_id,$note],function()use($uid,$itemId,$creation_id,$note):array {
            $loc=$this->projects->locationForCreation($uid,$creation_id);if($loc===null)throw new \RuntimeException('项目讨论不存在或不可访问',404);
            $loc=$this->projects->lockedLocation($uid,$loc['id']);$project=$this->projects->lockedProject($uid,$loc['project_id']);
            if($project['state']!=='active')throw new \RuntimeException('请先明确恢复项目推进，再继续旧排队',409);
            $item=$this->dispatchQueue->releaseHeld($itemId,(string)$creation_id,$uid,$note);$intent=bin2hex(random_bytes(16));
            $this->followups->enqueueReviewed($intent,$uid,(string)$creation_id,$itemId,$project,trim($note));
            return ['item_id'=>$itemId,'state'=>'queued','followup_id'=>$intent];
        }));
    }

    private function respond(callable $fn): DataResponse {
        $user = $this->users->getUser();
        if (!$user) { return new DataResponse(['message' => '请先登录'], 401); }
        try { return new DataResponse($fn($user->getUID())); }
        catch (\InvalidArgumentException $e) { return new DataResponse(['message' => $e->getMessage()], 422); }
        catch (\RuntimeException $e) {
            if (in_array($e->getCode(), [404, 409, 422], true)) { return new DataResponse(['message' => $e->getMessage()], $e->getCode()); }
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
