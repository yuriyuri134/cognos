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
        if (!$got['duplicate'] && $got['nudge'] !== null) {
            $this->nudge($uid, $got['nudge']['creation_id'], $got['nudge']['title']);
        }
        return new DataResponse(['reading' => $got['reading'], 'duplicate' => $got['duplicate']], $got['duplicate'] ? 200 : 201);
    }

    #[NoAdminRequired]
    public function readSpace(string $spaceType, string $spaceId, string $norm_space = ''): DataResponse {
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
        $out['formats'] = [
            'stages' => '[{"when": "时间", "title": "这一段叫什么", "text": "发生了什么", "current": true 只给现在这段, "proposed": true 是你提出、等人定的}]',
            'branches' => '[{"title": "分支", "parts": [{"title": "部分", "text": "说明"}]}]',
            'relations' => '{"nodes": [{"id": "…", "label": "…", "kind": "project|branch|attempt", "state": "…"}], "edges": [{"from": "…", "to": "…", "label": "可选"}]}',
            'compare' => '[{"title": "办法 A", "text": "它是什么", "result": "试下来怎样", "state": "在试|有结果|放下了"}]',
            'doc' => '一段文字（可以用 Markdown）',
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
        try {
            $this->norm->writePart($uid, $space['id'], $part, $content);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], 422);
        }
        return new DataResponse(['message' => '改好了「' . $space['title'] . '」的' . NormService::PARTS[$part] . '。']);
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
        return new DataResponse(['message' => ($kind === 'decide' ? '留给交互对象定了' : '记下了') . '（#' . $id . '）。他打开「' . $space['title'] . '」时最先看到。']);
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
            return $space;
        }
        $linked = $this->norm->linkedSpace($uid, $cid);
        return $linked === null ? null : $this->norm->project($uid, $linked['space']['id']);
    }

    /** 读者刚把一段 Initium 对话放进这个空间：叫这个空间的 agent 照这里的做法动一下。叫不来不要紧，下次你说话时它会来。 */
    private function nudge(string $uid, int $creationId, string $title): void {
        if ($this->config->getAppValue('cognos', 'creation_agent', 'hermes') !== 'claude-code') {
            return;
        }
        try {
            $context = $this->creations->getContext($creationId, $uid);
            $this->hermes->chatCreation($creationId,
                '（这句不是交互对象说的。读者刚把 Initium 里的「' . $title . '」放进这个空间。先用 read_space 看看这里，'
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
