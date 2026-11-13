<?php

declare(strict_types=1);

namespace OCA\Cognos\Controller;

use OCA\Cognos\Service\ClaudeBridgeService;
use OCA\Cognos\Service\ContinuationService;
use OCA\Cognos\Service\CreationSpaceService;
use OCA\Cognos\Service\FilesService;
use OCA\Cognos\Service\HermesAgentService;
use OCA\Cognos\Service\NormService;
use OCA\Cognos\Service\PushBackService;
use OCA\Cognos\Service\RunService;
use OCA\Cognos\Service\SpaceEntryService;
use OCA\Cognos\Service\SpaceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

final class CreationSpaceController extends Controller {
    public function __construct(
        IRequest $request,
        private IUserSession $users,
        private CreationSpaceService $creations,
        private HermesAgentService $hermes,
        private SpaceService $spaces,
        private SpaceEntryService $entries,
        private PushBackService $pushBack,
        private ContinuationService $continuation,
        private ClaudeBridgeService $bridge,
        private IConfig $config,
        private RunService $runs,
        private NormService $norm,
        private FilesService $files,
    ) {
        parent::__construct('cognos', $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        Util::addScript('cognos', 'creation-space');
        Util::addStyle('cognos', 'creation-space');
        return new TemplateResponse('cognos', 'creation-space', []);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function detail(int $id): TemplateResponse {
        // chat-box（提供 CognosMd / CognosUsage）、agent-pick、file-picker、readers 先于 creation-detail 载入
        Util::addScript('cognos', 'chat-box');
        Util::addScript('cognos', 'agent-pick');
        Util::addScript('cognos', 'file-picker');
        Util::addScript('cognos', 'readers');
        Util::addScript('cognos', 'creation-detail');
        Util::addStyle('cognos', 'creation-detail');
        Util::addStyle('cognos', 'file-picker');
        Util::addStyle('cognos', 'norm');
        return new TemplateResponse('cognos', 'creation-detail', ['creationId' => $id]);
    }

    #[NoAdminRequired]
    public function list(): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            // Norm 自己的那段对话、还在 Norm 里的空间各自的那段，不算在 Initium 里
            $hidden = array_flip($this->norm->hiddenConversationIds($user->getUID()));
            // 撤回的空间留下的空对话（一句话都没说过）也不列：它不是你在 Initium 里写的东西
            $leftover = array_flip($this->norm->inactiveConversationIds($user->getUID()));
            $list = array_values(array_filter($this->creations->list($user->getUID()), static fn(array $c): bool =>
                !isset($hidden[(int)$c['id']]) && !(isset($leftover[(int)$c['id']]) && (int)$c['message_count'] === 0)));
            return new DataResponse(['creations' => $list]);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Failed to fetch creations'], 500);
        }
    }

    #[NoAdminRequired]
    public function create(): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            $body = $this->request->getParams();
            $title = (string)($body['title'] ?? '');
            $initialMessage = (string)($body['initialMessage'] ?? '');
            $agent = (string)($body['agent'] ?? '');

            if (empty($title)) {
                return new DataResponse(['message' => 'Title is required'], 422);
            }

            $creation = $this->creations->create($user->getUID(), $title, $initialMessage);
            if (in_array($agent, ['claude', 'gpt', 'chatgpt'], true)) {
                $this->config->setUserValue($user->getUID(), 'cognos', 'agent_c' . $creation['id'], $agent);
            }
            return new DataResponse(['creation' => $creation], 201);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Failed to create creation'], 500);
        }
    }

    #[NoAdminRequired]
    public function get(int $id): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            $creation = $this->creations->get($id, $user->getUID());
            if (!$creation) {
                return new DataResponse(['message' => 'Creation not found'], 404);
            }
            return new DataResponse(['creation' => $creation]);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Failed to fetch creation'], 500);
        }
    }

    #[NoAdminRequired]
    public function rename(int $id): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            $title = (string)($this->request->getParams()['title'] ?? '');
            $creation = $this->creations->rename($id, $user->getUID(), $title);
            return new DataResponse(['creation' => $creation]);
        } catch (\InvalidArgumentException $error) {
            $code = str_contains($error->getMessage(), 'Creation') ? 404 : 422;
            return new DataResponse(['message' => $error->getMessage()], $code);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Failed to rename creation'], 500);
        }
    }

    #[NoAdminRequired]
    public function chatgptTabs(int $id): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        $uid = $user->getUID();
        if ($this->creations->get($id, $uid) === null) {
            return new DataResponse(['message' => 'Creation not found'], 404);
        }
        $res = $this->bridge->chatgptBrowserAction($uid, 'tabs', ['space_id' => (string)$id]);
        unset($res['_ok'], $res['_status']);
        return new DataResponse($res);
    }

    #[NoAdminRequired]
    public function bindChatgpt(int $id): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        $uid = $user->getUID();
        if ($this->creations->get($id, $uid) === null) {
            return new DataResponse(['message' => 'Creation not found'], 404);
        }
        $body = $this->request->getParams();
        $rawUrl = trim((string)($body['url'] ?? ''));
        $cleanUrl = $this->normalizeChatgptUrl($rawUrl);
        if ($rawUrl !== '' && $cleanUrl === '') {
            return new DataResponse(['message' => '只支持 https://chatgpt.com/c/... 格式的 ChatGPT 对话地址'], 422);
        }
        $messages = $this->creations->getMessages($id, $uid);
        $history = array_map(static fn(array $m): array => [
            'role' => $m['role'],
            'content' => $m['content'],
            'author' => $m['author'],
        ], $messages);
        $res = $this->bridge->chatgptBrowserAction($uid, 'bind', [
            'space_id' => (string)$id,
            'url' => $cleanUrl,
            'history' => $history,
        ]);
        if (!($res['_ok'] ?? false)) {
            return new DataResponse(['message' => $res['error'] ?? $res['message'] ?? '绑定失败'], $res['_status'] ?? 503);
        }
        unset($res['_ok'], $res['_status']);
        return new DataResponse($res);
    }

    #[NoAdminRequired]
    public function chatgptThread(int $id): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        $uid = $user->getUID();
        $creation = $this->creations->get($id, $uid);
        if ($creation === null) {
            return new DataResponse(['message' => 'Creation not found'], 404);
        }
        $body = $this->request->getParams();
        $mode = (string)($body['mode'] ?? 'import_bind');
        if (!in_array($mode, ['import_bind', 'bind_only', 'pull'], true)) {
            return new DataResponse(['message' => '无效的导入模式'], 422);
        }
        $rawUrl = trim((string)($body['url'] ?? ''));
        $cleanUrl = $this->normalizeChatgptUrl($rawUrl);
        if ($rawUrl !== '' && $cleanUrl === '') {
            return new DataResponse(['message' => '只支持 https://chatgpt.com/c/... 格式的 ChatGPT 对话地址'], 422);
        }
        $pageId = $body['pageId'] ?? null;
        if ($mode === 'bind_only') {
            $messages = $this->creations->getMessages($id, $uid);
            $history = array_map(static fn(array $m): array => [
                'role' => $m['role'],
                'content' => $m['content'],
                'author' => $m['author'],
            ], $messages);
            $res = $this->bridge->chatgptBrowserAction($uid, 'bind', [
                'space_id' => (string)$id,
                'url' => $cleanUrl,
                'history' => $history,
            ]);
            if (!($res['_ok'] ?? false)) {
                return new DataResponse(['message' => $res['error'] ?? $res['message'] ?? '绑定失败'], $res['_status'] ?? 503);
            }
            unset($res['_ok'], $res['_status']);
            return new DataResponse($res);
        }
        if ($mode === 'pull' && $cleanUrl === '' && ($pageId === null || $pageId === '')) {
            $status = $this->bridge->chatgptBrowserAction($uid, 'status', ['space_id' => (string)$id]);
            $boundUrl = (string)($status['bound']['url'] ?? '');
            $cleanUrl = $this->normalizeChatgptUrl($boundUrl);
            if ($cleanUrl === '') {
                return new DataResponse(['message' => '还没有绑定过 ChatGPT 对话'], 422);
            }
        }
        $thread = $this->bridge->chatgptBrowserAction($uid, 'thread', [
            'pageId' => $pageId,
            'url' => $cleanUrl !== '' ? $cleanUrl : null,
        ]);
        if (!($thread['_ok'] ?? false) || empty($thread['url'])) {
            return new DataResponse(
                ['message' => $thread['error'] ?? $thread['message'] ?? '没能读取这条 ChatGPT 对话'],
                ($thread['_status'] ?? 503) >= 400 ? (int)$thread['_status'] : 503
            );
        }
        $turns = is_array($thread['turns'] ?? null) ? $thread['turns'] : [];
        if ($turns === []) {
            return new DataResponse(['message' => '这条 ChatGPT 页面里还没有读到对话消息'], 422);
        }
        $imported = $this->creations->importMessages($id, $uid, $turns);
        if ($creation['title'] === '新对话' && !empty($thread['title'])) {
            $creation = $this->creations->rename($id, $uid, (string)$thread['title']);
        }
        $history = array_map(static fn(array $m): array => [
            'role' => $m['role'],
            'content' => $m['content'],
            'author' => $m['author'],
        ], $imported['all_messages']);
        $bindRes = $this->bridge->chatgptBrowserAction($uid, 'bind', [
            'space_id' => (string)$id,
            'url' => (string)$thread['url'],
            'history' => $history,
        ]);
        return new DataResponse([
            'ok' => true,
            'creation' => $creation,
            'bound' => $bindRes['bound'] ?? ['url' => (string)$thread['url']],
            'imported' => $imported['imported'],
            'skipped' => $imported['skipped'],
            'messages' => $imported['all_messages'],
            'title' => (string)($thread['title'] ?? ''),
        ]);
    }

    private function normalizeChatgptUrl(string $raw): string {
        $s = trim($raw);
        if ($s === '') {
            return '';
        }
        $parts = parse_url($s);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'chatgpt.com') {
            return '';
        }
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        if (!preg_match('#^/(?:g/[A-Za-z0-9-]+/)?c/[A-Za-z0-9-]+$#', $path)) {
            return '';
        }
        return 'https://chatgpt.com' . $path;
    }

    #[NoAdminRequired]
    public function sendMessage(int $id): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            $body = $this->request->getParams();
            $message = (string)($body['message'] ?? '');
            $recall = (int)($body['recall'] ?? 0);

            if (empty($message) && $recall === 0) {
                return new DataResponse(['message' => 'Message is required'], 422);
            }

            if ($this->creations->get($id, $user->getUID()) === null) {
                return new DataResponse(['message' => 'Creation not found'], 404);
            }

            $context = $this->creations->getContext($id, $user->getUID());
            if ($recall > 0) {
                // 再叫一次：不存新消息，拿你说过的那一句去叫，上下文只到那一句为止
                $upTo = [];
                $userMessage = null;
                foreach ($context['messages'] as $item) {
                    $upTo[] = $item;
                    if ($item['id'] === $recall) {
                        $userMessage = $item;
                        break;
                    }
                }
                if ($userMessage === null || $userMessage['role'] !== 'user') {
                    return new DataResponse(['message' => 'Message not found'], 404);
                }
                $message = (string)$userMessage['content'];
                $context['messages'] = $upTo;
                $userMessage = null;
            } else {
                $userMessage = $this->creations->addMessage($id, $user->getUID(), 'user', $message);
                $context = $this->creations->getContext($id, $user->getUID());
            }
            $saved = $userMessage === null ? [] : [$userMessage];
            $replyTo = $userMessage === null ? $recall : (int)$userMessage['id'];

            try {
                $agentResponse = $this->hermes->chatCreation($id, $message, $context, $replyTo);
            } catch (\RuntimeException $error) {
                if ($error instanceof \OCA\Cognos\Service\ProjectInactiveException) {
                    return new DataResponse(['messages'=>$saved,'pending'=>false,'message'=>$error->getMessage(),'execution_blocked'=>true]);
                }
                if (!ClaudeBridgeService::isBusy($error)) {
                    return new DataResponse(['messages' => $saved, 'message' => $error->getMessage()], 502);
                }
                // 桥正忙（比如写下想法的那位正被叫回来回应一条反对）：这句排着，它做完发回回答时接着叫
                $this->pushBack->enqueueMessage('creation', (string)$id, $replyTo);
                return new DataResponse([
                    'messages' => $saved,
                    'pending' => true,
                    'queued' => true,
                    'who' => ($this->runs->activeForSpace('creation', (string)$id)[0]['subject'] ?? $this->bridge->subjectFor('creation', (string)$id)),
                ]);
            } catch (\Throwable $error) {
                // 原话已经留下了，只是没叫来人：把它和原因一起回给页面，别让人以为没发出去再发一遍
                return new DataResponse([
                    'messages' => $saved,
                    'message' => $error->getMessage(),
                ], 502);
            }

            if ($agentResponse === null) {
                // 实际运行者接着做，完成后把回答发回原对话
                return new DataResponse(['messages' => $saved, 'pending' => true, 'who' => ($this->runs->activeForSpace('creation', (string)$id)[0]['subject'] ?? $this->bridge->subjectFor('creation', (string)$id))]);
            }

            $agentMessage = $this->creations->addMessage($id, $user->getUID(), 'assistant', $agentResponse, $replyTo);

            return new DataResponse([
                'messages' => array_merge($saved, [$agentMessage])
            ]);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Failed to send message: ' . $error->getMessage()], 500);
        }
    }

    /** 改你说过的一句（它后面的对话一起去掉），页面接着用这句再叫一次 agent。 */
    #[NoAdminRequired]
    public function editMessage(int $id, int $messageId): DataResponse {
        return $this->changeMessage($id, function (string $uid) use ($id, $messageId) {
            $content = trim((string)($this->request->getParams()['message'] ?? ''));
            if ($content === '') throw new \InvalidArgumentException('Message is required');
            return $this->creations->editMessage($id, $uid, $messageId, $content);
        });
    }

    /** 删掉一句（你说的话连同回它的回答）。 */
    #[NoAdminRequired]
    public function deleteMessage(int $id, int $messageId): DataResponse {
        return $this->changeMessage($id, fn(string $uid) => $this->creations->deleteMessage($id, $uid, $messageId));
    }

    private function changeMessage(int $id, callable $change): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            if ($this->creations->get($id, $user->getUID()) === null) {
                return new DataResponse(['message' => 'Creation not found'], 404);
            }
            if ($this->runs->activeForSpace('creation', (string)$id) !== []) {
                return new DataResponse(['message' => '还有人在回这段对话，等它回完再改。'], 409);
            }
            return new DataResponse($change($user->getUID()));
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 404);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Failed: ' . $error->getMessage()], 500);
        }
    }

    #[NoAdminRequired]
    public function getMessages(int $id): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        try {
            if ($this->creations->get($id, $user->getUID()) === null) {
                return new DataResponse(['message' => 'Creation not found'], 404);
            }
            $messages = $this->creations->getMessages($id, $user->getUID());
            $runs = $this->runs->forSpace('creation', (string)$id);
            $active = $this->runs->activeForSpace('creation', (string)$id);
            return new DataResponse(['messages' => $messages, 'pending' => $active !== [],
                'pending_who' => $active[0]['subject'] ?? null, 'runs' => $runs,
                'usage' => $this->bridge->usage('creation', (string)$id)]);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Failed to fetch messages'], 500);
        }
    }

    /**
     * 侧栏「这里」：和被叫进来的 agent（Hermes 或 Claude Code）拿到的是同一份入口。看过就记下这次来的时间。
     */
    #[NoAdminRequired]
    public function space(int $id): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        $space = $this->entries->resolve('creation', (string)$id, $user->getUID());
        if ($space === null) {
            return new DataResponse(['message' => 'Creation not found'], 404);
        }
        try {
            $entry = $this->entries->entry($space, $user->getUID());
            $this->spaces->markSeen('creation', (string)$id, $user->getUID());
            return new DataResponse([
                'entry' => $entry,
                'text' => $this->entries->renderText($entry),
                'continue_budget' => $this->continuation->budget('creation', (string)$id),
            ]);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Failed to load space: ' . $error->getMessage()], 500);
        }
    }

    /**
     * 交互对象在侧栏里留下一条：自己的原话（意图、事实），现实碰回来的，或者对某一条的确认、否定。
     * 只能写小林和现实结果；AI只能由 agent 自己写。
     */
    #[NoAdminRequired]
    public function addSpaceTrace(int $id): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        if ($this->entries->resolve('creation', (string)$id, $user->getUID()) === null) {
            return new DataResponse(['message' => 'Creation not found'], 404);
        }
        $body = $this->request->getParams();
        $source = (string)($body['source'] ?? '原');
        if (!in_array($source, ['原', '世'], true)) {
            return new DataResponse(['message' => 'source must be 原 or 世'], 422);
        }
        if (($body['kind'] ?? '') === 'answer') {
            return new DataResponse(['message' => '回应一条反对，用 /space/hits/{hitId}/answer'], 422);
        }
        try {
            $trace = $this->spaces->addTrace('creation', (string)$id, [
                'kind' => (string)($body['kind'] ?? ''),
                'source' => $source,
                'author' => $user->getUID(),
                'content' => (string)($body['content'] ?? ''),
                'about' => $body['about'] ?? null,
                'act' => $body['act'] ?? null,
                'said_at' => isset($body['said_at']) ? (int)$body['said_at'] : time(),
                'ref_id' => isset($body['ref_id']) ? (int)$body['ref_id'] : null,
                'status' => (string)($body['status'] ?? 'said'),
                'stopped_at' => $body['stopped_at'] ?? null,
                'touched_reality' => array_key_exists('touched_reality', $body) ? (bool)$body['touched_reality'] : null,
                'governs' => $body['governs'] ?? null,
            ]);
            // 反对到一条写下的想法：去找写下想法的那位
            $pushback = $trace['kind'] === 'hit' ? $this->pushBack->afterHit('creation', (string)$id, $user->getUID(), $trace) : null;
            return new DataResponse(['trace' => $trace, 'pushback' => $pushback], 201);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => 'Failed to add trace'], 500);
        }
    }

    /**
     * 交互对象回应反对到自己写下的想法的一下：还成立、放下或改成新的。
     */
    #[NoAdminRequired]
    public function answerHit(int $id, int $hitId): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        if ($this->entries->resolve('creation', (string)$id, $user->getUID()) === null) {
            return new DataResponse(['message' => 'Creation not found'], 404);
        }
        $body = $this->request->getParams();
        try {
            $result = $this->spaces->answerHit(
                'creation',
                (string)$id,
                $hitId,
                '原',
                $user->getUID(),
                (string)($body['act'] ?? ''),
                (string)($body['content'] ?? ''),
                null,
                isset($body['new_premise']) ? (string)$body['new_premise'] : null,
                isset($body['governs']) ? (string)$body['governs'] : null,
            );
            return new DataResponse($result, 201);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        }
    }

    /**
     * 挂上一件没完的：对话里交互对象说的一句（message_id），或者直接写一句（content）。挂上就开始走。
     * 同一句只挂一次（open 的 about 记着 msg:<id>）。
     */
    #[NoAdminRequired]
    public function hangOpen(int $id): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        $uid = $user->getUID();
        if ($this->entries->resolve('creation', (string)$id, $uid) === null) {
            return new DataResponse(['message' => 'Creation not found'], 404);
        }
        $body = $this->request->getParams();
        $content = trim((string)($body['content'] ?? ''));
        $saidAt = null;
        $about = null;
        $messageId = (int)($body['message_id'] ?? 0);
        if ($messageId > 0) {
            $said = null;
            foreach ($this->creations->getMessages($id, $uid) as $message) {
                if ($message['id'] === $messageId) {
                    $said = $message;
                    break;
                }
            }
            if ($said === null || $said['role'] !== 'user') {
                return new DataResponse(['message' => '只能挂你自己说的一句'], 422);
            }
            $about = 'msg:' . $messageId;
            foreach ($this->spaces->opens('creation', (string)$id) as $open) {
                if ($open['about'] === $about && !$open['closed']) {
                    return new DataResponse(['open' => $open, 'started' => ['called' => false, 'message' => sprintf('这句已经挂着了（#%d，%s）。', $open['id'], $open['state'])]]);
                }
            }
            $content = (string)$said['content'];
            $saidAt = (int)$said['created_at'];
        }
        if ($content === '') {
            return new DataResponse(['message' => 'content or message_id is required'], 422);
        }
        try {
            $result = $this->continuation->hang('creation', (string)$id, $uid, $content, '原', $uid, $saidAt, $about);
            return new DataResponse($result, 201);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        }
    }

    /** 交互对象按「接着走」：停在原地、卡住、完了的也再叫一次。 */
    #[NoAdminRequired]
    public function continueOpen(int $id, int $openId): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        if ($this->entries->resolve('creation', (string)$id, $user->getUID()) === null) {
            return new DataResponse(['message' => 'Creation not found'], 404);
        }
        return new DataResponse($this->continuation->start('creation', (string)$id, $user->getUID(), $openId, true));
    }

    /** 交互对象说这件完了：收起来，不再拉着。 */
    #[NoAdminRequired]
    public function closeOpen(int $id, int $openId): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        if ($this->entries->resolve('creation', (string)$id, $user->getUID()) === null) {
            return new DataResponse(['message' => 'Creation not found'], 404);
        }
        $open = $this->spaces->openState('creation', (string)$id, $openId);
        if ($open === null) {
            return new DataResponse(['message' => 'Open not found'], 404);
        }
        $reason = trim((string)($this->request->getParams()['content'] ?? ''));
        $trace = $this->spaces->addTrace('creation', (string)$id, [
            'kind' => 'correction', 'source' => '原', 'author' => $user->getUID(), 'status' => 'confirmed',
            'content' => $reason !== '' ? $reason : '这件完了', 'ref_id' => $openId, 'said_at' => time(),
        ]);
        return new DataResponse(['trace' => $trace], 201);
    }

    #[NoAdminRequired]
    public function listFiles(int $id): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        $uid = $user->getUID();
        if ($this->creations->get($id, $uid) === null) {
            return new DataResponse(['message' => 'Creation not found'], 404);
        }
        try {
            $dir = (string)$this->request->getParam('dir', '');
            $q = (string)$this->request->getParam('q', '');
            return new DataResponse($this->files->browse($uid, $id, $dir, $q));
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => '读取文件列表失败：' . $error->getMessage()], 500);
        }
    }

    #[NoAdminRequired]
    public function uploadFile(int $id): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        $uid = $user->getUID();
        if ($this->creations->get($id, $uid) === null) {
            return new DataResponse(['message' => 'Creation not found'], 404);
        }
        $uploaded = $this->request->getUploadedFile('file');
        if (!is_array($uploaded)) {
            return new DataResponse(['message' => '没有收到上传文件'], 422);
        }
        try {
            $meta = $this->files->upload($uid, $id, $uploaded);
            return new DataResponse(['file' => $meta], 201);
        } catch (\InvalidArgumentException $error) {
            return new DataResponse(['message' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => '上传文件失败：' . $error->getMessage()], 500);
        }
    }

    #[NoAdminRequired]
    public function readFile(int $id, int $fileId): DataResponse {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        $uid = $user->getUID();
        if ($this->creations->get($id, $uid) === null) {
            return new DataResponse(['message' => 'Creation not found'], 404);
        }
        try {
            $file = $this->files->getFileForUser($uid, $fileId);
            if ($file === null) {
                return new DataResponse(['message' => '找不到该文件'], 404);
            }
            return new DataResponse([
                'file' => $this->files->describeFile($uid, $file),
                'excerpt' => $this->files->readTextExcerpt($file),
            ]);
        } catch (\Throwable $error) {
            return new DataResponse(['message' => '读取文件失败：' . $error->getMessage()], 500);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function rawFile(int $fileId) {
        $user = $this->users->getUser();
        if (!$user) {
            return new DataResponse(['message' => 'Authentication required'], 401);
        }
        $file = $this->files->getFileForUser($user->getUID(), $fileId);
        if ($file === null) {
            return new DataResponse(['message' => 'File not found'], 404);
        }
        $stream = $file->fopen('rb');
        if (!is_resource($stream)) {
            return new DataResponse(['message' => 'Unable to read file'], 500);
        }
        $mime = (string)$file->getMimeType();
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }
        $inline = $this->files->isInlineSafeMime($mime);
        $disposition = ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($file->getName());
        return new StreamResponse($stream, 200, [
            'Content-Type' => $inline ? $mime : 'application/octet-stream',
            'Content-Disposition' => $disposition,
            'Content-Length' => (string)$file->getSize(),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
