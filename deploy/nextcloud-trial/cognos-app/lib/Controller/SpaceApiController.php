<?php

declare(strict_types=1);

namespace OCA\Cognos\Controller;

use OCA\Cognos\Service\ClaudeBridgeService;
use OCA\Cognos\Service\ContinuationService;
use OCA\Cognos\Service\CreationSpaceService;
use OCA\Cognos\Service\DecisionService;
use OCA\Cognos\Service\DelegationService;
use OCA\Cognos\Service\HermesAgentService;
use OCA\Cognos\Service\ProjectService;
use OCA\Cognos\Service\PushBackService;
use OCA\Cognos\Service\RunService;
use OCA\Cognos\Service\SpaceEntryService;
use OCA\Cognos\Service\SpaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * 外面的 agent（本机的 Claude Code、Codex……）走进一片空间用的接口，和 Hermes 在里面用的是同一套动作：
 * 进来（拿到入口）、留下尝试、留下读法、在痕迹里找，写下想法、反对别人写下的想法、回应反对到自己的。
 *
 * agent 用交互对象的账号（应用密码）来调，但它写下的东西一律记为AI和它报的名字（subject），
 * 不能代替交互对象写原话。
 */
final class SpaceApiController extends OCSController {
    public function __construct(
        IRequest $request,
        private IUserSession $users,
        private CreationSpaceService $creations,
        private SpaceService $spaces,
        private SpaceEntryService $entries,
        private PushBackService $pushBack,
        private ContinuationService $continuation,
        private HermesAgentService $hermes,
        private IConfig $config,
        private RunService $runs,
        private DecisionService $decisions,
        private ProjectService $projects,
        private DelegationService $delegations,
    ) {
        parent::__construct('cognos', $request);
    }

    #[NoAdminRequired]
    public function list(): DataResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        $spaces = array_map(static fn(array $c): array => [
            'type' => 'creation',
            'id' => (string)$c['id'],
            'title' => (string)$c['title'],
            'updated_at' => (int)$c['updated_at'],
        ], $this->creations->list($uid));
        return new DataResponse(['spaces' => $spaces]);
    }

    #[NoAdminRequired]
    public function enter(string $spaceType, string $spaceId, string $subject = '', string $message = ''): DataResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        $space = $this->entries->resolve($spaceType, $spaceId, $uid);
        if ($space === null) {
            return new DataResponse(['message' => 'Space not found'], 404);
        }
        $subject = $this->subject($subject);
        $call = trim($message) !== '' ? ['caller' => $uid, 'message' => trim($message)] : null;
        $entry = $this->entries->entry($space, $subject, $call, [
            'search_traces：在这片空间留下的发生里找。',
            'leave_trace：把你这次做的尝试，或你推出来的东西留下，记在你报的名字下。',
            'add_reading：留下你对这里的读法。你之前的读法不会被删，只会被标为已替换。',
            '你的其他能力（读文件、跑命令、上网）照你自己那边的权限，这片空间不知道。',
        ]);
        $text = $this->entries->renderText($entry);
        if ($call !== null) {
            // 外面进来的没有对话记录，把这次说的话直接放进入口
            $text .= "\n\n## 这次叫你来时说的话\n" . $call['message'];
        }
        $this->spaces->markSeen($spaceType, $spaceId, $subject);
        return new DataResponse(['subject' => $subject, 'text' => $text, 'entry' => $entry]);
    }

    #[NoAdminRequired]
    public function searchTraces(string $spaceType, string $spaceId, string $q = '', int $limit = 20): DataResponse {
        $uid = $this->uid();
        if ($uid === null || $this->entries->resolve($spaceType, $spaceId, $uid) === null) {
            return new DataResponse(['message' => 'Space not found'], 404);
        }
        return new DataResponse(['traces' => $this->spaces->searchTraces($spaceType, $spaceId, $q, max(1, min(50, $limit)))]);
    }

    #[NoAdminRequired]
    public function addTrace(string $spaceType, string $spaceId): DataResponse {
        $uid = $this->uid();
        if ($uid === null || $this->entries->resolve($spaceType, $spaceId, $uid) === null) {
            return new DataResponse(['message' => 'Space not found'], 404);
        }
        $body = $this->body();
        if (($body['kind'] ?? '') === 'answer') {
            return new DataResponse(['message' => '回应一条反对，用 answer_hit（/hits/{hitId}/answer）'], 422);
        }
        if (($body['kind'] ?? '') === 'reality') {
            return new DataResponse(['message' => '检查结果只能由桥在跑完检查后带回来，agent 不能自己写'], 422);
        }
        try {
            $trace = $this->spaces->addTrace($spaceType, $spaceId, [
                'kind' => (string)($body['kind'] ?? 'attempt'),
                'source' => 'AI',
                'author' => $this->subject((string)($body['subject'] ?? '')),
                'content' => (string)($body['content'] ?? ''),
                'about' => $body['about'] ?? null,
                'stopped_at' => $body['stopped_at'] ?? null,
                'touched_reality' => array_key_exists('touched_reality', $body) ? (bool)$body['touched_reality'] : null,
                'act' => $body['act'] ?? null,
                'confidence' => isset($body['confidence']) ? (int)$body['confidence'] : null,
                'ref_id' => isset($body['ref_id']) ? (int)$body['ref_id'] : null,
                'status' => (string)($body['status'] ?? 'said'),
                'governs' => $body['governs'] ?? null,
            ]);
            // 反对到一条写下的想法：去找写下想法的那位
            $pushback = $trace['kind'] === 'hit' ? $this->pushBack->afterHit($spaceType, $spaceId, $uid, $trace) : null;
            return new DataResponse(['trace' => $trace, 'pushback' => $pushback], 201);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        }
    }

    #[NoAdminRequired]
    public function addReading(string $spaceType, string $spaceId): DataResponse {
        $uid = $this->uid();
        if ($uid === null || $this->entries->resolve($spaceType, $spaceId, $uid) === null) {
            return new DataResponse(['message' => 'Space not found'], 404);
        }
        $body = $this->body();
        try {
            $reading = $this->spaces->addReading(
                $spaceType,
                $spaceId,
                $this->subject((string)($body['subject'] ?? '')),
                (string)($body['content'] ?? ''),
                isset($body['confidence']) ? (int)$body['confidence'] : null,
                is_array($body['refs'] ?? null) ? $body['refs'] : [],
            );
            return new DataResponse(['reading' => $reading], 201);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        }
    }

    /**
     * 写下想法的那位回应反对到它的一下：还成立、放下或改成新的。只有写下想法的那位（subject）能回应。
     */
    #[NoAdminRequired]
    public function answerHit(string $spaceType, string $spaceId, int $hitId): DataResponse {
        $uid = $this->uid();
        if ($uid === null || $this->entries->resolve($spaceType, $spaceId, $uid) === null) {
            return new DataResponse(['message' => 'Space not found'], 404);
        }
        $body = $this->body();
        try {
            $result = $this->spaces->answerHit(
                $spaceType,
                $spaceId,
                $hitId,
                'AI',
                $this->subject((string)($body['subject'] ?? '')),
                (string)($body['act'] ?? ''),
                (string)($body['content'] ?? ''),
                isset($body['confidence']) ? (int)$body['confidence'] : null,
                isset($body['new_premise']) ? (string)$body['new_premise'] : null,
                isset($body['governs']) ? (string)$body['governs'] : null,
            );
            return new DataResponse($result, 201);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        }
    }

    /**
     * 被叫进来、在本机接着做的 agent（claude_bridge.py）做完以后，把回答发回这段对话。
     * 它是从一件没完的叫起来的（open_id），先记下检查和它停在哪（ContinuationService::afterRun）。
     * 然后接着叫下一位：排着的反对和交互对象的话先走，都没有，就从还拉着的那件没完的接着走。
     *
     * 每个回答都要带这次运行的 run_id 和桥生成的 result_id（见 RunService）：同一个结果发两次只收一次，
     * 第二次拿回第一次存下的那条消息，不再记检查、不再叫下一件；署名不是这次运行登记的参与者，不收。
     * depends_on 是做事的那位自己列的「这次真正依据的是哪几条」，可以不带。
     */
    #[NoAdminRequired]
    public function addMessage(string $spaceType, string $spaceId): DataResponse {
        $uid = $this->uid();
        if ($uid === null || $spaceType !== 'creation' || $this->creations->get((int)$spaceId, $uid) === null) {
            return new DataResponse(['message' => 'Space not found'], 404);
        }
        $body = $this->body();
        $content = trim((string)($body['content'] ?? ''));
        if ($content === '') {
            return new DataResponse(['message' => 'content is required'], 422);
        }
        $runId = trim((string)($body['run_id'] ?? ''));
        $resultId = trim((string)($body['result_id'] ?? ''));
        if ($runId === '' || $resultId === '') {
            return new DataResponse(['message' => 'run_id and result_id are required'], 422);
        }
        $run = $this->runs->get($runId);
        if ($run === null || $run['space_type'] !== $spaceType || $run['space_id'] !== $spaceId) {
            return new DataResponse(['message' => 'Run not found in this space'], 404);
        }
        $dependsOn = $body['depends_on'] ?? [];
        if (!is_array($dependsOn)) {
            return new DataResponse(['message' => 'depends_on must be a list'], 422);
        }
        // 回的是对话里哪一句；被反对叫回来的回答是 0（不回任何一句）
        $replyTo = isset($body['reply_to']) && $body['reply_to'] !== null ? (int)$body['reply_to'] : null;
        try {
            $received = $this->runs->receiveResult($run, $resultId, trim((string)($body['subject'] ?? '')), array_values($dependsOn),
                fn(): int => (int)$this->creations->addMessage((int)$spaceId, $uid, 'assistant', $content, $replyTo)['id']);
        } catch (\DomainException $error) {
            return new DataResponse(['message' => $error->getMessage()], 403);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 409);
        }
        $message = $this->message((int)$spaceId, $uid, $received['message_id']);
        if ($received['duplicate']) {
            // 已经收过：不再存、不再记检查、不再叫下一件
            return new DataResponse(['message' => $message, 'run' => $received['run'], 'duplicate' => true], 200);
        }
        $this->config->deleteAppValue('cognos', 'agent_pending_creation_' . (int)$spaceId);
        $this->config->deleteAppValue('cognos', 'agent_pending_who_creation_' . (int)$spaceId);
        // 运行起步以后才有、输入里没有的适用决定：结果照收，记下来，页面上标「起步时还没有这条决定」；不自动应用到共享内容
        $missing = $this->decisions->missingForRun($uid, $run);
        if ($missing !== []) {
            $this->runs->setDecisionsMissing($run['id'], $missing);
        }
        if ($run['target_type'] === 'delegation') {
            $this->delegations->returned($run['target_id'], (int)$received['message_id']);
        }
        // 从哪件没完的叫起来的，以登记的运行为准
        $openId = $run['target_type'] === 'open' ? (int)$run['target_id'] : null;
        if ($openId !== null) {
            $checks = is_array($body['checks'] ?? null)
                ? ['ok' => (bool)($body['checks']['ok'] ?? false), 'text' => trim((string)($body['checks']['text'] ?? ''))]
                : null;
            try {
                $this->continuation->afterRun($spaceType, $spaceId, $openId, (int)($body['run_started'] ?? 0),
                    isset($body['branch']) ? (string)$body['branch'] : null, $checks, (bool)($body['timed_out'] ?? false));
            } catch (\InvalidArgumentException $error) {
                // 那件没完的不在了：回答照样留下，只是不接着走
            }
        }
        $this->callNext($spaceType, $spaceId, $uid, $openId);
        return new DataResponse(['message' => $message, 'run' => $received['run'], 'duplicate' => false, 'decisions_missing' => $missing], 201);
    }

    /**
     * agent 代录一条在生效的决定：必须指向这段对话里用户说的那一句（source_message_id），原话要能在那一句里原样找到。
     * 原作者记成用户本人，登记者记成 agent 报的名字。撤回、替代只有用户本人在项目页上能做。
     * scope 是 here（这个位置及其下面）或 project（整个项目，默认）。
     */
    #[NoAdminRequired]
    public function recordDecision(string $spaceType, string $spaceId): DataResponse {
        $uid = $this->uid();
        if ($uid === null || $spaceType !== 'creation' || !ctype_digit($spaceId) || $this->creations->get((int)$spaceId, $uid) === null) {
            return new DataResponse(['message' => 'Space not found'], 404);
        }
        $location = $this->projects->locationForCreation($uid, (int)$spaceId);
        if ($location === null) {
            return new DataResponse(['message' => '这片空间不是项目里的位置，决定要登记在项目上'], 422);
        }
        $body = $this->body();
        $subject = $this->subject((string)($body['subject'] ?? ''));
        if ($subject === $uid) {
            return new DataResponse(['message' => 'agent 要用自己的名字登记'], 422);
        }
        $requestId = (string)($body['request_id'] ?? '');
        if ($requestId === '') {
            $requestId = 'agent-' . hash('sha256', json_encode([$spaceId, $subject, $body['source_message_id'] ?? null, $body['content'] ?? null, $body['scope'] ?? null]));
            $requestId = substr($requestId, 0, 64);
        }
        try {
            $result = $this->decisions->record($uid, $location['project_id'], (string)($body['content'] ?? ''),
                ($body['scope'] ?? 'project') === 'here' ? [$location['id']] : [],
                isset($body['source_message_id']) ? (int)$body['source_message_id'] : null,
                isset($body['source_note']) ? (string)$body['source_note'] : null, null, $subject, null, $requestId);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        } catch (\RuntimeException $error) {
            return new DataResponse(['message' => $error->getMessage()], in_array($error->getCode(), [404, 409], true) ? $error->getCode() : 500);
        }
        return new DataResponse($result, 201);
    }

    /**
     * agent 在交给它的事里交回这一处的当前内容（全文）。只有允许改内容、正由它在做的委托才收；
     * 交出去以后这一处被人改过、或有了它输入里没有的新决定，不覆盖，留作待比较的版本（照样 200，applied=false）。
     */
    #[NoAdminRequired]
    public function writeContent(string $spaceType, string $spaceId): DataResponse {
        $uid = $this->uid();
        if ($uid === null || $spaceType !== 'creation' || !ctype_digit($spaceId) || $this->creations->get((int)$spaceId, $uid) === null) {
            return new DataResponse(['message' => 'Space not found'], 404);
        }
        $body = $this->body();
        if (!isset($body['content']) || !is_string($body['content'])) {
            return new DataResponse(['message' => 'content is required'], 422);
        }
        try {
            return new DataResponse($this->delegations->writeContent($uid, (int)$spaceId, $this->subject((string)($body['subject'] ?? '')), $body['content']));
        } catch (\DomainException $error) {
            return new DataResponse(['message' => $error->getMessage()], 403);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        }
    }

    /**
     * 运行进行中，agent 报一条这次真正依据的东西：一条在生效的决定（decision_quote，照抄原话里的一段），
     * 或这一处当前内容的某一版（revision）。可以不报；没报就是「未上报依据」，不推定没有依赖。
     * 记在这片空间里正由它在做的那次运行上（cognos_run_dependencies，和交回时带的 depends_on 同一处）。
     */
    #[NoAdminRequired]
    public function addBasis(string $spaceType, string $spaceId): DataResponse {
        $uid = $this->uid();
        if ($uid === null || $spaceType !== 'creation' || !ctype_digit($spaceId) || $this->creations->get((int)$spaceId, $uid) === null) {
            return new DataResponse(['message' => 'Space not found'], 404);
        }
        $body = $this->body();
        $subject = $this->subject((string)($body['subject'] ?? ''));
        $run = null;
        foreach ($this->runs->activeForSpace($spaceType, $spaceId) as $candidate) {
            if ($candidate['subject'] === $subject && in_array($candidate['state'], ['queued', 'claimed', 'running'], true)) {
                $run = $candidate;
                break;
            }
        }
        if ($run === null) {
            return new DataResponse(['message' => '这里没有正由你在做的运行；依据要在做的时候报'], 409);
        }
        $location = $this->projects->locationForCreation($uid, (int)$spaceId);
        if ($location === null) {
            return new DataResponse(['message' => '这片空间不是项目里的位置，没有决定和当前内容可依据'], 422);
        }
        $note = isset($body['note']) ? mb_substr(trim((string)$body['note']), 0, 1000) : null;
        try {
            if (isset($body['decision_quote']) && trim((string)$body['decision_quote']) !== '') {
                $decision = $this->decisions->byQuote($uid, $location['id'], (string)$body['decision_quote']);
                $new = $this->runs->addDependency($run['id'], 'decision', $decision['id'], null, $note);
                $what = '决定「' . $decision['content'] . '」';
            } elseif (isset($body['revision'])) {
                $revision = (int)$body['revision'];
                if ($revision < 1 || $revision > $location['revision']) {
                    return new DataResponse(['message' => sprintf('这一处的当前内容只有版本 1 到 %d', $location['revision'])], 422);
                }
                $new = $this->runs->addDependency($run['id'], 'location_content', $location['id'], (string)$revision, $note);
                $what = sprintf('这一处当前内容的版本 %d', $revision);
            } else {
                return new DataResponse(['message' => '给 decision_quote（决定原话里的一段）或 revision（当前内容的版本号）'], 422);
            }
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        }
        return new DataResponse(['message' => ($new ? '记下了：这次依据' : '已经记过：') . $what . '。', 'run_id' => $run['id']], $new ? 201 : 200);
    }

    /** 这片空间最近的运行：谁在做什么、做到哪个状态。 */
    #[NoAdminRequired]
    public function runs(string $spaceType, string $spaceId): DataResponse {
        $uid = $this->uid();
        if ($uid === null || $this->entries->resolve($spaceType, $spaceId, $uid) === null) {
            return new DataResponse(['message' => 'Space not found'], 404);
        }
        return new DataResponse(['runs' => $this->runs->forSpace($spaceType, $spaceId)]);
    }

    #[NoAdminRequired]
    public function run(string $runId): DataResponse {
        $run = $this->ownRun($runId);
        if ($run === null) {
            return new DataResponse(['message' => 'Run not found'], 404);
        }
        return new DataResponse(['run' => $run, 'depends_on' => $this->runs->dependencies($runId)]);
    }

    /**
     * 桥重启时发现这一件做到一半没了（进程退出、电脑重启）：标成 unknown，不当作没做，也不自己重跑。
     * 只改还在做的；已经收到结果的不动。
     */
    #[NoAdminRequired]
    public function runUnknown(string $runId): DataResponse {
        $run = $this->ownRun($runId);
        if ($run === null) {
            return new DataResponse(['message' => 'Run not found'], 404);
        }
        $reason = trim((string)($this->body()['reason'] ?? ''));
        $changed = $this->runs->markUnknown($runId, $reason !== '' ? mb_substr($reason, 0, 1000) : '桥重启时这一件做到一半没了：不知道做没做完。');
        return new DataResponse(['run' => $this->runs->get($runId), 'changed' => $changed]);
    }

    private function ownRun(string $runId): ?array {
        $uid = $this->uid();
        if ($uid === null) {
            return null;
        }
        $run = $this->runs->get($runId);
        if ($run === null || $this->entries->resolve($run['space_type'], $run['space_id'], $uid) === null) {
            return null;
        }
        return $run;
    }

    private function message(int $creationId, string $uid, ?int $messageId): ?array {
        foreach ($this->creations->getMessages($creationId, $uid) as $message) {
            if ((int)$message['id'] === $messageId) {
                return $message;
            }
        }
        return null;
    }

    /** 这一位做完了：排着的下一件接着叫（一条反对，或者交互对象在它忙时说的一句）；都没有，就从还拉着的没完的接着走。 */
    private function callNext(string $spaceType, string $spaceId, string $uid, ?int $justRan): void {
        $next = $this->pushBack->drain($spaceType, $spaceId, $uid);
        if ($next === null) {
            $this->continuation->resume($spaceType, $spaceId, $uid, $justRan);
            return;
        }
        [$what, $messageId] = $next;
        if ($what !== 'msg') {
            return;
        }
        try {
            $answer = $this->hermes->chatCreationFromMessage((int)$spaceId, $messageId);
            if ($answer !== null) {
                // 叫来的是 Hermes：回答当场就有，没人占着桥，没完的接着走
                $this->creations->addMessage((int)$spaceId, $uid, 'assistant', $answer, $messageId);
                $this->continuation->resume($spaceType, $spaceId, $uid, $justRan);
            }
        } catch (\Throwable $error) {
            if ($error instanceof \RuntimeException && $error->getCode() === ClaudeBridgeService::BUSY) {
                $this->pushBack->enqueueMessage($spaceType, $spaceId, $messageId, true);
            }
            // 别的原因叫不上：那句还在对话里、没人回，页面上能「再叫一次」
        }
    }

    private function uid(): ?string {
        return $this->users->getUser()?->getUID();
    }

    private function subject(string $subject): string {
        $subject = trim($subject);
        return $subject === '' ? '未署名的 agent' : mb_substr($subject, 0, 128);
    }

    private function body(): array {
        // Nextcloud 已把 JSON 请求体解析进参数里（Content-Type: application/json）
        return $this->request->getParams();
    }
}
