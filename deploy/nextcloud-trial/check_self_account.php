<?php
/* 空间自述和认领，在容器里对真实数据库跑，不调用外部 AI（读者回来的话是写好的，照桥发回来的样子交给 ReaderService::receive）：
 *   docker cp deploy/nextcloud-trial/check_self_account.php cognos-nextcloud-trial-app-1:/tmp/check_self_account.php
 *   docker exec -u www-data cognos-nextcloud-trial-app-1 php /tmp/check_self_account.php [账号，默认 cognos-admin]
 * 1. 自述只追加：一样的不另写，改了是新一版，旧的都在；发生的列表里没有自述那几行；agent 被叫进空间时先读到它；
 *    agent 用 write_part(part=self) 改，不写「这次什么没变」不收。
 * 2. 空间读自己：桥发回的自述（字符串里直接换了行也认得）写成新一版，来源「重读自己的经历」；旁观的那位说的记下；
 *    觉得不合身时给的办法能挑；同一个结果发两次只收一次。
 * 3. Initium 一段让空间认领：放进说「是我的」的空间，它照读的时候说的改了自述；比较的那位写的「读者几」换成名字；
 *    撤回以后连线撤掉、自述退回（再追加一版）。
 *    读的这段时间自述又改过了，就不拿旧的那份去盖。
 * 会在库里留两个「验证：自述 <时间>」「验证：别的空间 <时间>」空间（最后撤回，不在 Norm 里显示）和两段「验证：认领 <时间>」Initium 对话，不删。
 */
declare(strict_types=1);
define('OC_CONSOLE', 1); $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/console.php'; require '/var/www/html/lib/base.php';
set_exception_handler(function ($e) { fwrite(STDERR, (string)$e); exit(1); });
use OCA\Cognos\Service\{NormService, ReaderService, RunService, CreationSpaceService};
use OCA\Cognos\Controller\NormApiController;
use OCP\DB\QueryBuilder\IQueryBuilder;

$failed = 0;
function check(bool $ok, string $text): void { global $failed; if (!$ok) { $failed++; echo "没通过：$text\n"; return; } echo "通过：$text\n"; }
$uid = $argv[1] ?? 'cognos-admin';
\OC::$server->get(\OCP\IUserSession::class)->setUser(\OC::$server->get(\OCP\IUserManager::class)->get($uid));
$norm = \OC::$server->get(NormService::class); $readers = \OC::$server->get(ReaderService::class);
$runs = \OC::$server->get(RunService::class); $creations = \OC::$server->get(CreationSpaceService::class);
$db = \OC::$server->get(\OCP\IDBConnection::class); $api = \OC::$server->get(NormApiController::class);
$stamp = date('Y-m-d H:i:s');

/** 照 ReaderService::start 记一次读（不交给桥），再登记它的运行。 */
function reading(string $kind, ?int $creationId, ?string $spaceId, int $runSpace, array $plan): array {
    global $db, $runs, $uid;
    $q = $db->getQueryBuilder();
    $q->insert('cognos_reader_runs')->values([
        'user_id' => $q->createNamedParameter($uid), 'kind' => $q->createNamedParameter($kind),
        'creation_id' => $q->createNamedParameter($creationId, $creationId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
        'space_id' => $q->createNamedParameter($spaceId, $spaceId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
        'state' => $q->createNamedParameter('reading'), 'result' => $q->createNamedParameter(json_encode(['plan' => $plan], JSON_UNESCAPED_UNICODE)),
        'created_at' => $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT), 'updated_at' => $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
    ])->executeStatement();
    $id = (int)$q->getLastInsertId();
    $run = $runs->create('creation', (string)$runSpace, 'reading', (string)$id, 'read', ReaderService::SUBJECT, $uid, ['kind' => $kind, 'check' => true]);
    $runs->accepted($run['id']);
    return [$id, $run['id']];
}
function body(string $runId, array $readings, string $compare): array {
    return ['run_id' => $runId, 'result_id' => bin2hex(random_bytes(16)), 'subject' => ReaderService::SUBJECT, 'readings' => $readings, 'compare' => $compare, 'error' => ''];
}

// 1. 自述只追加
$a = $norm->createSpace($uid, '验证：自述 ' . $stamp, '项目', [], ['progress' => '留下能检验的东西'], '验证自述用的空间');
$conv = $norm->spaceConversation($uid, $a);
check($norm->selfAccount($uid, $a) === null && str_contains(implode("\n", $norm->agentLines($uid, $conv)), '还没有自述'), '新空间还没有自述，agent 被叫来时知道可以写第一版');
$w1 = $norm->writeSelf($uid, $a, '**我在维持什么。** 第一版', '第一次写', '第一次写', '第一次写', '验证');
$same = $norm->writeSelf($uid, $a, '**我在维持什么。** 第一版', 'x', 'x', 'x', '验证');
$w2 = $norm->writeSelf($uid, $a, '**我在维持什么。** 第二版', '改了一句', '验证', '别的都没变', '验证');
$hist = $norm->selfHistory($uid, $a);
check($w1['version'] === 1 && !$same['new'] && $same['id'] === $w1['id'] && $w2['version'] === 2 && count($hist) === 2 && $hist[1]['text'] === '**我在维持什么。** 第一版',
    '自述只追加：一样的不另写，改了是第 2 版，第 1 版还在');
check(!array_filter($norm->events($uid, $a), fn($e) => $e['kind'] === 'self')
    && count(array_filter($norm->events($uid, $a, 'happened'), fn($e) => str_contains($e['text'], '自述'))) === 2, '发生的列表里没有自述那几行，只有「写下了第一版」「改了自述」两条');
$lines = implode("\n", $norm->agentLines($uid, $conv));
check(str_contains($lines, '第二版') && str_contains($lines, '第 2 版') && str_contains($lines, 'write_part(part=self)'), '被叫进这个空间的 agent 先读到它自己的自述（第 2 版），知道怎么改');
$r = $api->writePart('creation', (string)$conv, 'self', ['text' => '**我在维持什么。** 第三版', 'changed' => '又改了', 'why' => '验证'], '', 'Claude');
check($r->getStatus() === 422 && str_contains($r->getData()['message'], 'unchanged'), 'agent 改自述不写「这次什么没变」不收');
$r = $api->writePart('creation', (string)$conv, 'self', json_encode(['text' => '**我在维持什么。** 第三版', 'changed' => '又改了', 'why' => '验证', 'unchanged' => '维持的还是那件'], JSON_UNESCAPED_UNICODE), '', 'Claude');
$read = $api->readSpace('creation', (string)$conv)->getData()['space'];
check($r->getStatus() === 200 && str_contains($r->getData()['message'], '第 3 版') && $read['self']['version'] === 3 && $read['self_before'][0]['version'] === 2
    && $norm->selfAccount($uid, $a)['source'] === '在对话里（Claude）', 'agent 用 write_part(part=self) 记下第 3 版；read_space 给它现在这版和以前改了什么');

// 2. 空间读自己（自述里直接换行，没写成 \n：桥那边认不出，这边要认得）
[$rid, $runId] = reading('drift', null, $a, $conv, [['label' => '它自己', 'role' => 'self', 'space_id' => $a, 'space_title' => '验证', 'self_version' => 3]]);
$selfText = "**我在维持什么。** 第四版：读过自己的经历。\n\n**怎样算往前。** 页面上多了能点开的东西。";
$selfRaw = '{"what": "一个验证用的空间", "text": "' . $selfText . '", "changed": "改成读过经历以后的样子", "why": "读了自己的经历", "unchanged": "维持的还是那件",'
    . ' "form": {"fits": false, "then": "进来时是项目", "now": "现在更像笔记", "why": "只在记", "options": [{"kind": "switch", "label": "换成笔记", "board": "笔记", "parts": ["doc"]}, {"kind": "keep", "label": "还是原样"}], "lean": 0},'
    . ' "quotes": ["一处经历"]}';
$b1 = body($runId, [$selfRaw], json_encode(['empty' => [['quote' => '往前', 'why' => '没说拿什么分辨']], 'note' => '一半说得出'], JSON_UNESCAPED_UNICODE));
$got = $readers->receive($uid, $rid, $b1);
$c = $got['reading']['result']['compare'] ?? [];
$now = $norm->selfAccount($uid, $a);
check($got['reading']['state'] === 'done' && !empty($c['self_reading']) && $c['self']['version'] === 4 && $now['version'] === 4 && $now['text'] === $selfText && $now['source'] === '重读自己的经历',
    '空间读自己：写成第 4 版自述（换行原样留着），来源「重读自己的经历」');
check(($c['bystander']['empty'][0]['quote'] ?? '') === '往前' && $c['drifted'] === true && count($c['options']) === 2, '旁观的那位说的记下了；它觉得不合身，给了两种办法');
check($readers->receive($uid, $rid, $b1)['duplicate'] === true && $norm->selfAccount($uid, $a)['version'] === 4, '同一个结果发两次只收一次，不多写一版');
$chosen = $readers->choose($uid, $rid, 0, '');
check($chosen['space']['board'] === '笔记' && $chosen['space']['parts'] === ['doc'], '挑了「换成笔记」以后空间换了样子');

// 3. Initium 一段让空间认领
$bSpace = $norm->createSpace($uid, '验证：别的空间 ' . $stamp, '项目', [], [], '放别的事');
$cr = $creations->create($uid, '验证：认领 ' . $stamp);
$cid = (int)$cr['id'];
$creations->addMessage($cid, $uid, 'user', '我想弄清楚一个空间凭什么在改了以后还是它自己。');
$creations->addMessage($cid, $uid, 'assistant', '可以从它每次改自述时写下的「什么没变」看。');
$plan = [['label' => '定性', 'role' => 'reader'],
    ['label' => '空间「自述」', 'role' => 'claim', 'space_id' => $a, 'space_title' => '验证：自述', 'self_version' => 4],
    ['label' => '空间「别的」', 'role' => 'claim', 'space_id' => $bSpace, 'space_title' => '验证：别的空间']];
[$rid2, $runId2] = reading('initium', $cid, null, $cid, $plan);
$claimText = '**我在维持什么。** 第五版：接下了一段讲连续性的对话。';
$got2 = $readers->receive($uid, $rid2, body($runId2, [
    json_encode(['what' => '在问一个会改自己的东西凭什么还是它', 'where' => '往研究走', 'needs' => ['拿真例子试'], 'misfit' => '', 'quotes' => []], JSON_UNESCAPED_UNICODE),
    json_encode(['what' => '讲连续性的对话', 'claim' => 'mine', 'why' => '正是我放不下的', 'mine' => '全部', 'not_mine' => '',
        'change' => ['text' => $claimText, 'changed' => '放不下的事加了连续性', 'why' => '这段对话', 'unchanged' => '维持的还是那件'], 'disagree' => '它太抽象', 'quotes' => []], JSON_UNESCAPED_UNICODE),
    json_encode(['what' => '和我无关', 'claim' => 'not', 'why' => '我只放别的事', 'change' => ['text' => '']], JSON_UNESCAPED_UNICODE),
], json_encode(['differ' => '读者1和读者2一致，读者 3 说不是它的', 'needs' => ['拿真例子试'], 'place' => ['action' => 'existing', 'space_id' => $a, 'why' => '跟读者2的认领']], JSON_UNESCAPED_UNICODE)));
$ap = $got2['reading']['applied'] ?? [];
$now = $norm->selfAccount($uid, $a);
check(($ap['action'] ?? '') === 'existing' && $ap['space_id'] === $a && $ap['claim'] === 'mine' && ($ap['self']['version'] ?? 0) === 5 && $now['text'] === $claimText
    && str_contains($now['source'], '验证：认领'), '放进说「是我的」的空间，它照读的时候说的改了自述（第 5 版，来源写着接下了哪段）');
check(($norm->linkedSpace($uid, $cid)['space']['id'] ?? '') === $a && $norm->selfAccount($uid, $bSpace) === null, '这段连到了认领它的空间；说「不是」的空间没被动');
check(($got2['reading']['result']['compare']['differ'] ?? '') === '定性的读者和空间「验证：自述」一致，空间「验证：别的空间」说不是它的' && $ap['why'] === '跟空间「验证：自述」的认领',
    '比较的那位写的「读者几」换成了名字（页面上的卡片写的是名字）');
$u = $readers->undo($uid, $rid2);
$now = $norm->selfAccount($uid, $a);
check($u['undone_at'] !== null && $norm->linkedSpace($uid, $cid) === null && $now['version'] === 6 && $now['text'] === $selfText && count($norm->selfHistory($uid, $a, 50)) === 6,
    '撤回以后连线撤掉，自述退回去：追加第 6 版，内容是第 4 版，旧的六版都在');

// 读的这段时间自述又改过：不拿旧的那份去盖
$cr2 = $creations->create($uid, '验证：认领 ' . $stamp . ' 之二');
$creations->addMessage((int)$cr2['id'], $uid, 'user', '再来一段。');
[$rid3, $runId3] = reading('initium', (int)$cr2['id'], null, (int)$cr2['id'],
    [['label' => '定性', 'role' => 'reader'], ['label' => '空间「自述」', 'role' => 'claim', 'space_id' => $a, 'space_title' => '验证：自述', 'self_version' => 6]]);
$norm->writeSelf($uid, $a, '**我在维持什么。** 第七版：读的时候自己又改了。', '又改了', '验证', '那件没变', '验证');
$got3 = $readers->receive($uid, $rid3, body($runId3, [
    json_encode(['what' => '又一段', 'misfit' => ''], JSON_UNESCAPED_UNICODE),
    json_encode(['what' => '又一段', 'claim' => 'mine', 'why' => '是我的', 'change' => ['text' => '照第六版改的', 'changed' => 'x', 'why' => 'x', 'unchanged' => 'x']], JSON_UNESCAPED_UNICODE),
], json_encode(['place' => ['action' => 'existing', 'space_id' => $a, 'why' => '跟认领']], JSON_UNESCAPED_UNICODE)));
check(!empty($got3['reading']['applied']['self']['skipped']) && $norm->selfAccount($uid, $a)['version'] === 7, '读的这段时间自述又改过（第 7 版），就不拿第 6 版上改的那份去盖');
$readers->undo($uid, $rid3);

$norm->setState($uid, $a, 'withdrawn'); $norm->setState($uid, $bSpace, 'withdrawn');
echo $failed === 0 ? "全部通过\n" : "有 $failed 条没通过\n";
exit($failed === 0 ? 0 : 1);
