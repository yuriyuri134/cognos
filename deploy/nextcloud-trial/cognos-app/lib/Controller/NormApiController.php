<?php

declare(strict_types=1);

namespace OCA\Cognos\Controller;

use OCA\Cognos\Service\CreationSpaceService;
use OCA\Cognos\Service\HermesAgentService;
use OCA\Cognos\Service\NormService;
use OCA\Cognos\Service\ReaderService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * 本机桥和被叫进来的 agent 用的 Norm 接口（OCS）。
 *
 * - receiveReading：桥把读者的三份读法和比较发回来（ReaderService::receive）。放进了一个空间，就叫那个空间的 agent 动一下。
 * - readSpace / writePart / note：agent 在一段对话里被叫来，这段对话是 Norm 空间自己的那段时，它读、改这个空间的几块，
 *   给交互对象留一条（发生了什么，或只有他能定的一件）。在 Norm 自己的对话里，用 norm_space 指是哪个空间；
 *   在一段已经放进 Norm 的 Initium 对话里，指的是它连着的那个空间。
 */
final class NormApiController extends OCSController {
    public function __construct(
        IRequest $request,
        private IUserSession $users,
        private NormService $norm,
        private ReaderService $readers,
        private CreationSpaceService $creations,
        private HermesAgentService $hermes,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
        parent::__construct('cognos', $request);
    }

    #[NoAdminRequired]
    public function receiveReading(int $readingId): DataResponse {
        $uid = $this->uid();
        try {
            $got = $this->readers->receive($uid, $readingId, $this->request->getParams());
        } catch (\DomainException $e) {
            return new DataResponse(['message' => $e->getMessage()], 403);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], 409);
        }
        // 放进空间以后自动叫那个空间的 agent，先关着：第一次试时它用了 4 分钟额度，却读不到放进来的原话。
        // 要开：occ config:app:set cognos reader_nudge --value=yes
        if (!$got['duplicate'] && $got['nudge'] !== null && $this->config->getAppValue('cognos', 'reader_nudge', 'no') === 'yes') {
            $this->nudge($uid, $got['nudge']['space_id'], $got['nudge']['title']);
        }
        return new DataResponse(['reading' => $got['reading'], 'duplicate' => $got['duplicate']], $got['duplicate'] ? 200 : 201);
    }

    #[NoAdminRequired]
    public function readSpace(string $spaceType, string $spaceId, string $norm_space = '', int $linked_id = 0, int $from = 0): DataResponse {
        $uid = $this->uid();
        $space = $this->resolve($uid, $spaceType, $spaceId, $norm_space);
        if ($space === null) {
            if ((int)$spaceId === $this->norm->conversationIdIfAny($uid)) {
                return new DataResponse(['spaces' => $this->norm->projects($uid), 'hint' => '填 norm_space 读其中一个']);
            }
            return new DataResponse(['message' => '这段对话还没放进 Norm 的任何空间'], 404);
        }
        $out = ['id' => $space['id'], 'title' => $space['title'], 'board' => $space['board'] ?? '带着自己的做法',
            'summary' => $space['summary'], 'way' => $space['way'], 'parts' => []];
        foreach ($space['parts'] as $p) {
            $out['parts'][$p] = ['name' => NormService::PARTS[$p], 'content' => $space[$p] ?? null];
        }
        $out['waiting_for_you'] = array_values(array_filter($this->norm->events($uid, $space['id'], 'decide', 20), static fn(array $e): bool => $e['answer'] === null));
        $out['recent'] = array_slice($this->norm->events($uid, $space['id'], 'happened', 10), 0, 10);
        // 只读一段原话时，别的都不给，免得一次返回太长
        if ($linked_id > 0) {
            return new DataResponse(['space' => ['id' => $space['id'], 'title' => $space['title'], 'linked' => $this->linkedText($uid, $space['id'], $linked_id, $from)]]);
        }
        $out['linked'] = $this->linkedText($uid, $space['id']);
        // 你（这个空间的主体）自己写的自述，最新一版；以前几版只给改了什么，要看全文在空间页上
        $out['self'] = $space['self'] === null ? null : [
            'version' => $space['self']['version'], 'text' => $space['self']['text'], 'written' => date('n/j H:i', $space['self']['created_at']),
            'changed' => $space['self']['changed'], 'why' => $space['self']['why'], 'unchanged' => $space['self']['unchanged'],
        ];
        $out['self_before'] = array_map(static fn(array $v): array => ['version' => $v['version'], 'written' => date('n/j H:i', $v['created_at']),
            'changed' => $v['changed'], 'why' => $v['why'], 'source' => $v['source']], array_slice($space['self_history'], 1, 5));
        $out['formats'] = [
            'stages' => '[{"when": "时间", "title": "这一段叫什么", "text": "发生了什么", "current": true 只给现在这段, "proposed": true 是你提出、等人定的}]',
            'branches' => '[{"title": "分支", "parts": [{"title": "部分", "text": "说明"}]}]',
            'relations' => '{"nodes": [{"id": "…", "label": "…", "kind": "project|branch|attempt", "state": "…"}], "edges": [{"from": "…", "to": "…", "label": "可选"}]}',
            'compare' => '[{"title": "办法 A", "text": "它是什么", "result": "试下来怎样", "state": "在试|有结果|放下了"}]',
            'doc' => '一段文字（可以用 Markdown）',
            'self' => '{"text": "改完以后的整份自述，第一人称，700 字以内", "changed": "这次改了什么", "why": "为什么改", "unchanged": "这次什么没变"}',
        ];
        return new DataResponse(['space' => $out]);
    }

    #[NoAdminRequired]
    public function writePart(string $spaceType, string $spaceId, string $part = '', mixed $content = null, string $norm_space = '', string $subject = ''): DataResponse {
        $uid = $this->uid();
        $space = $this->resolve($uid, $spaceType, $spaceId, $norm_space);
        if ($space === null) {
            return new DataResponse(['message' => '这段对话还没放进 Norm 的任何空间'], 404);
        }
        if ($part === 'self') {
            return $this->writeSelf($uid, $space, $content, $subject);
        }
        try {
            $this->norm->writePart($uid, $space['id'], $part, $content, trim($subject) !== '' ? $subject : 'agent');
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], 422);
        }
        return new DataResponse(['message' => '改好了「' . $space['title'] . '」的' . NormService::PARTS[$part] . '。']);
    }

    /** 这个空间的主体在对话里改自己的自述（write_part 的 part=self）。只追加一版，旧的都留着。 */
    private function writeSelf(string $uid, array $space, mixed $content, string $subject): DataResponse {
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            $content = is_array($decoded) ? $decoded : ['text' => $content];
        }
        if (!is_array($content)) {
            return new DataResponse(['message' => '自述的 content 要是 {"text": …, "changed": …, "why": …, "unchanged": …}'], 422);
        }
        $text = trim((string)($content['text'] ?? ''));
        if ($text === '') {
            return new DataResponse(['message' => 'text 是空的：给改完以后的整份自述'], 422);
        }
        if (mb_strlen($text) > NormService::SELF_MAX) {
            return new DataResponse(['message' => '自述有 ' . mb_strlen($text) . ' 字，太长了：缩到 ' . NormService::SELF_MAX . ' 字以内（700 字左右最好），它是写给你自己看的，不是总结'], 422);
        }
        $first = $space['self'] === null;
        $unchanged = trim((string)($content['unchanged'] ?? ''));
        if (!$first && $unchanged === '') {
            return new DataResponse(['message' => '写一句 unchanged：这次什么没变。改了以后你还是你，靠的就是这一句'], 422);
        }
        try {
            $v = $this->norm->writeSelf($uid, $space['id'], $text, (string)($content['changed'] ?? ($first ? '第一次写' : '')),
                (string)($content['why'] ?? ''), $first && $unchanged === '' ? '第一次写' : $unchanged,
                '在对话里' . (trim($subject) !== '' ? '（' . trim($subject) . '）' : ''));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], 422);
        }
        return new DataResponse(['message' => $v['new'] ? '记下了你的自述，第 ' . $v['version'] . ' 版。旧的每一版都还在。' : '和上一版一字不差，没有另记一版。']);
    }

    #[NoAdminRequired]
    public function note(string $spaceType, string $spaceId, string $kind = '', string $text = '', array $options = [], string $norm_space = '', string $subject = ''): DataResponse {
        $uid = $this->uid();
        $space = $this->resolve($uid, $spaceType, $spaceId, $norm_space);
        if ($space === null) {
            return new DataResponse(['message' => '这段对话还没放进 Norm 的任何空间'], 404);
        }
        try {
            $id = $this->norm->addEvent($uid, $space['id'], $kind, $text, $options, trim($subject) !== '' ? $subject : 'agent');
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], 422);
        }
        return new DataResponse(['message' => ($kind === 'decide' ? '留给交互对象定了' : '记下了') . '。他打开「' . $space['title'] . '」时会看到。']);
    }

    /**
     * 放进这个空间的 Initium 对话。
     *
     * 原来一次把所有连着的对话原话塞进 read_space（最多 12 万字）：连着几十段时，工具返回超出 agent 一次能收的长度，
     * 被截掉或整个收不到，agent 只看到一句转述和几个编号，说「对话内容没有过来」。现在分开：
     * - 不带 linked_id：给一张目录（每段的题目、多少条、什么时候放进来），加上最近放进来的那一段的第一页原话；
     * - 带 linked_id：读那一段的原话，一页约 1.6 万字，按整条消息分页，读不完给 next_from 接着读。人说的话不截。
     */
    private const PAGE = 16000;

    private function linkedText(string $uid, string $spaceId, int $only = 0, int $from = 0): array {
        $links = array_reverse($this->norm->links($uid, $spaceId));
        if ($only > 0) {
            foreach ($links as $link) {
                if ($link['creation_id'] === $only) {
                    return ['page' => $this->page($uid, $link, $from)];
                }
            }
            return ['page' => null, 'note' => '这个空间没有连着这一段'];
        }
        $index = [];
        foreach ($links as $link) {
            $index[] = ['linked_id' => $link['creation_id'], 'title' => $link['title'],
                'messages' => count($this->creations->getMessages($link['creation_id'], $uid)),
                'linked_at' => date('Y-m-d H:i', (int)($link['created_at'] ?? 0))];
        }
        return [
            'how' => '这里连着 ' . count($links) . ' 段 Initium 对话，最近放进来的在前。要读哪一段的原话：read_space 带 linked_id；一页读不完，返回里有 next_from，带上 from 接着读。',
            'index' => $index,
            'newest' => $links === [] ? null : $this->page($uid, $links[0], 0),
        ];
    }

    /** 一段对话从第 $from 条起的一页原话。 */
    private function page(string $uid, array $link, int $from): array {
        $messages = $this->creations->getMessages($link['creation_id'], $uid);
        $from = max(0, min($from, count($messages)));
        $lines = [];
        $size = 0;
        $i = $from;
        for (; $i < count($messages); $i++) {
            $m = $messages[$i];
            $line = ($m['role'] === 'user' ? '人' : 'AI') . '：' . trim((string)$m['content']);
            $len = mb_strlen($line);
            if ($lines !== [] && $size + $len > self::PAGE) {
                break;
            }
            // 单条 AI 回答比一页还长：这一页只放它的前一页长，后面的下一页从下一条接着；人说的话整条给
            if ($m['role'] !== 'user' && $len > self::PAGE * 2) {
                $line = mb_substr($line, 0, self::PAGE * 2) . '…（这条 AI 回答还有 ' . ($len - self::PAGE * 2) . ' 字没给）';
                $len = self::PAGE * 2;
            }
            $lines[] = $line;
            $size += $len;
        }
        return ['linked_id' => $link['creation_id'], 'title' => $link['title'], 'messages' => count($messages),
            'from' => $from, 'to' => $i, 'next_from' => $i < count($messages) ? $i : null,
            'text' => implode("\n\n", $lines)];
    }

    /** 这段对话指的是哪个空间。 */
    private function resolve(string $uid, string $spaceType, string $spaceId, string $normSpace): ?array {
        if ($spaceType !== 'creation' || !ctype_digit($spaceId)) {
            return null;
        }
        $cid = (int)$spaceId;
        if ($this->creations->get($cid, $uid) === null) {
            return null;
        }
        if ($normSpace !== '' && $cid === $this->norm->conversationIdIfAny($uid)) {
            return $this->norm->project($uid, $normSpace);
        }
        $space = $this->norm->spaceByConversation($uid, $cid);
        if ($space !== null) {
            // project() 才带着自述
            return $this->norm->project($uid, $space['id']);
        }
        $linked = $this->norm->linkedSpace($uid, $cid);
        return $linked === null ? null : $this->norm->project($uid, $linked['space']['id']);
    }

    /** 读者刚把一段 Initium 对话放进这个空间：叫这个空间的 agent 照这里的做法动一下。叫不来不要紧，下次你说话时它会来。 */
    private function nudge(string $uid, string $spaceId, string $title): void {
        if ($this->config->getAppValue('cognos', 'creation_agent', 'hermes') !== 'claude-code') {
            return;
        }
        try {
            // 空间自己的对话到要叫 agent 时才建：不叫就不建，免得撤回以后 Initium 里多出一段空对话
            $creationId = $this->norm->spaceConversation($uid, $spaceId);
            $context = $this->creations->getContext($creationId, $uid);
            $this->hermes->chatCreation($creationId,
                '（这句不是交互对象说的。读者刚把 Initium 里的「' . $title . '」放进这个空间。先用 read_space 看看这里，那段对话是最近放进来的，原话在返回的 linked.newest 里，一页读不完按 next_from 接着读；'
                . '照这里的做法看接下来能做什么，能做就做一点。做了什么、发生了什么，用 note_for_you 记下；只有交互对象能定的，用 note_for_you(kind=decide) 提出来。'
                . '交互对象回来时会先看到那里。）', $context);
        } catch (\Throwable $error) {
            $this->logger->info('读者放完以后没叫来这个空间的 agent：' . $error->getMessage(), ['app' => 'cognos']);
        }
    }

    private function uid(): string {
        $uid = $this->users->getUser()?->getUID();
        if ($uid === null) {
            throw new \RuntimeException('Authentication required');
        }
        return $uid;
    }
}
