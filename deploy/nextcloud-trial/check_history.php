<?php
/* 历史和总观，在容器里对真实数据库跑，不调用外部 AI（读一块的那位、合起来的那位回来的话是写好的，照桥发回来的样子交给 ReaderService::receive）：
 *   docker cp deploy/nextcloud-trial/check_history.php cognos-nextcloud-trial-app-1:/tmp/check_history.php
 *   docker exec -u www-data cognos-nextcloud-trial-app-1 php /tmp/check_history.php [账号，默认 cognos-admin]
 * 1. 还没读的原话：连着的 Initium 对话、这里的对话、这里记下的事排成块，算出要几次运行；块里每句带编号（[m12]、[e3]），AI 说的写明是谁。
 * 2. 第一次读（一块，读到头）：条目对过原话（引文指错了句挪到真有这句的那条，编出来的引文不要，指不到的编号不算，没指到原话的记着）；
 *    合起来分成两段（第一段转了：转在哪条、谁转的、什么没变、放下的路），一条条目都没有的段不开；不必进历史的那条记着；
 *    总观的线和只有你能定的都指着历史里的条目（指不到的不要）；自述写成新一版；读过的原话记下，再算没读的是 0；同一个结果发两次只收一次。
 * 3. 又说了两句，接着读：只读新的两句；已经转过的段，没写为什么的改动不收、记一句，写了为什么的改，记着原来是什么；
 *    新的条目归进现在这一段；总观第 2 版，第 1 版还在；分段第 1 版原样留着。
 * 4. 合起来那步没做成：条目留在「还没归段」，总观、自述不动，说清可以只合一次；只合一次以后归进去，旁观的那位说的记下。
 * 5. 页面和 agent 要的：/data 带 overview、history_meta、unread，?history=1 才带段、条目、原话出处；read_space 带总观和各段；
 *    agent 被叫进这个空间时先读到历史和总观；「正在发生」里没有历史条目那些行。
 * 会在库里留一个「验证：历史 <时间>」空间（最后撤回，不在 Norm 里显示）和一段「验证：历史原话 <时间>」Initium 对话，不删。
 */
declare(strict_types=1);
define('OC_CONSOLE', 1); $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/console.php'; require '/var/www/html/lib/base.php';
set_exception_handler(function ($e) { fwrite(STDERR, (string)$e); exit(1); });
use OCA\Cognos\Service\{NormService, ReaderService, RunService, CreationSpaceService};
use OCA\Cognos\Controller\{NormApiController, NormController};
use OCP\DB\QueryBuilder\IQueryBuilder;

$failed = 0;
function check(bool $ok, string $text): void { global $failed; if (!$ok) { $failed++; echo "没通过：$text\n"; return; } echo "通过：$text\n"; }
$uid = $argv[1] ?? 'cognos-admin';
\OC::$server->get(\OCP\IUserSession::class)->setUser(\OC::$server->get(\OCP\IUserManager::class)->get($uid));
$norm = \OC::$server->get(NormService::class); $readers = \OC::$server->get(ReaderService::class);
$runs = \OC::$server->get(RunService::class); $creations = \OC::$server->get(CreationSpaceService::class);
$db = \OC::$server->get(\OCP\IDBConnection::class); $api = \OC::$server->get(NormApiController::class);
$ctrl = \OC::$server->get(NormController::class);
$stamp = date('Y-m-d H:i:s');
$j = static fn(array $v): string => json_encode($v, JSON_UNESCAPED_UNICODE);
$m = static fn(array $msg): string => 'm' . $msg['id'];

/** 照 ReaderService::start 记一次读（不交给桥），再登记它的运行。 */
function reading(string $space, int $conv, array $readerList, array $extra): array {
    global $db, $runs, $uid;
    $q = $db->getQueryBuilder();
    $q->insert('cognos_reader_runs')->values([
        'user_id' => $q->createNamedParameter($uid), 'kind' => $q->createNamedParameter('drift'),
        'creation_id' => $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
        'space_id' => $q->createNamedParameter($space),
        'state' => $q->createNamedParameter('reading'),
        'result' => $q->createNamedParameter(json_encode(['plan' => ReaderService::planOf($readerList)] + $extra, JSON_UNESCAPED_UNICODE)),
        'created_at' => $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT), 'updated_at' => $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
    ])->executeStatement();
    $id = (int)$q->getLastInsertId();
    $run = $runs->create('creation', (string)$conv, 'reading', (string)$id, 'read', ReaderService::SUBJECT, $uid, ['kind' => 'drift', 'check' => true]);
    $runs->accepted($run['id']);
    return [$id, $run['id']];
}
function body(string $runId, array $texts, string $compare): array {
    return ['run_id' => $runId, 'result_id' => bin2hex(random_bytes(16)), 'subject' => ReaderService::SUBJECT, 'readings' => $texts, 'compare' => $compare, 'error' => ''];
}
/** 这一轮读哪些：和「接着读」按下去时一样排块。 */
function nextRound(string $space): ?array {
    global $norm, $readers, $uid;
    $sp = $norm->project($uid, $space);
    return $readers->spaceRound($uid, $sp, $norm->history($uid, $space) + ['overview' => $sp['overview'] ?? null], $norm->projects($uid));
}
function byKey(array $entries, int $readingId): array {
    $out = [];
    foreach ($entries as $e) {
        if ($e['reading_id'] === $readingId) {
            $out[$e['key']] = $e;
        }
    }
    return $out;
}
function happened(string $space): array {
    global $norm, $uid;
    return array_column($norm->events($uid, $space, 'happened', 50), 'text');
}

// 1. 还没读的原话
$space = $norm->createSpace($uid, '验证：历史 ' . $stamp, '项目', [], ['progress' => '读完能直接接着干活'], '验证历史和总观用的空间');
$conv = $norm->spaceConversation($uid, $space);
$cr = $creations->create($uid, '验证：历史原话 ' . $stamp);
$cid = (int)$cr['id'];
$u1 = $creations->addMessage($cid, $uid, 'user', '我想让每个空间自己记得它走过的路：做过什么，什么时候转了方向。');
$a1 = $creations->addMessage($cid, $uid, 'assistant', '可以按转向分段：每段写那时在做什么、什么让它转了、转过去以后什么没变。', null, 'Claude');
$u2 = $creations->addMessage($cid, $uid, 'user', '对，先不做看板了，看板太重。每一条都要能点回原话。');
$norm->link($uid, $space, $cid, null);
$u3 = $creations->addMessage($conv, $uid, 'user', '先在一个验证空间里试，读完能直接接着干活才算数。');
$e1 = $norm->addEvent($uid, $space, 'happened', '把读者的结果放进了这个空间。', [], 'Claude');
$expect = count($creations->getMessages($cid, $uid)) + count($creations->getMessages($conv, $uid))
    + count(array_filter($norm->events($uid, $space, null, 5000), static fn(array $e): bool => $e['who'] !== NormService::SELF_WHO));
$un = $readers->unread($uid, $norm->project($uid, $space));
check($un['items'] === $expect && $un['chunks'] === 1 && $un['rounds'] === 1 && $un['runs'] === 2 && $un['chars'] > 0,
    "还没读的：连着的 Initium 对话、这里的对话、这里记下的事一共 $expect 句，一块，读完要 2 次运行");
$r1 = nextRound($space);
$p1 = $r1['readers'][0]['prompt'] ?? '';
check($r1 !== null && count($r1['readers']) === 1 && $r1['final'] && str_contains($p1, '[m' . $u1['id'] . ']') && str_contains($p1, '[e' . $e1 . ']')
    && str_contains($p1, 'AI（Claude）：可以按转向分段') && str_contains($p1, '人：对，先不做看板了，看板太重。每一条都要能点回原话。')
    && str_contains($p1, '验证：历史原话') && str_contains($r1['compare'], '总观'),
    '这一轮读一块、读到头：原话整句带编号，AI 说的写明是 Claude；合起来那位读到头要写总观');

// 2. 第一次读
[$rid1, $run1] = reading($space, $conv, $r1['readers'], ['final' => $r1['final'], 'run' => $r1['info']]);
$past1 = $j(['what' => '我是一个验证空间：在试让空间自己记得走过的路。', 'open' => '', 'entries' => [
    ['key' => '1.1', 'kind' => '信', 'title' => '空间要记得自己走过的路', 'text' => '你想让每个空间自己记得走过的路。', 'refs' => [$m($u1)],
        'quotes' => [['ref' => $m($u1), 'text' => '让每个空间自己记得它走过的路']]],
    // 引文指错了句：这句在 u2 里，不在 a1 里
    ['key' => '1.2', 'kind' => '转了', 'title' => '不做看板', 'text' => '你说先不做看板，看板太重。', 'refs' => [$m($u2)],
        'quotes' => [['ref' => $m($a1), 'text' => '“先不做看板了，看板太重。”']]],
    // 编出来的引文、指不到的编号
    ['key' => '1.3', 'kind' => '做了', 'title' => '读者的结果放进来了', 'text' => '读者的结果放进了这里。', 'refs' => ['e' . $e1, 'm99999999'],
        'quotes' => [['ref' => 'e' . $e1, 'text' => '这句话原话里没有']]],
    // 编号带方括号、引文中间用省略号
    ['key' => '1.4', 'kind' => '定了', 'title' => '先在验证空间里试', 'text' => '你定了先在验证空间里试。', 'refs' => ['[m' . $u3['id'] . ']'],
        'quotes' => [['ref' => $m($u3), 'text' => '读完能直接接着干活……才算数']]],
    ['key' => 'x', 'kind' => '放下', 'title' => '一条没指到原话的', 'text' => '随口说的。', 'refs' => []],
]]);
$merge1 = $j(['what' => '一个在试历史和总观的验证空间', 'stages' => [
    ['key' => 'new', 'add' => ['1.1', '1.2'], 'title' => '起头：空间记得自己', 'text' => '那时我想让每个空间记得自己走过的路。',
        'turn' => '你说先不做看板，每条都要能点回原话', 'turn_by' => '你', 'turn_at' => '1.2', 'kept' => '还是要记得走过的路', 'dropped' => ['看板：太重']],
    ['key' => 'new', 'add' => ['1.3', '1.4'], 'title' => '在验证空间里试', 'text' => '我在一个验证空间里试，读完能接着干活才算。'],
    ['key' => 'new', 'add' => [], 'title' => '空的一段'],
], 'skip' => ['1.5'],
    'overview' => ['now' => '我是一个验证空间，在试历史和总观。', 'next' => '再说几句，接着读。',
        'lines' => [['name' => '历史和总观', 'at' => '读完了第一遍', 'waits' => '等你再说几句', 'back' => '有新对话以后', 'refs' => ['1.4', 'h999999999']]],
        'yours' => [['what' => '要不要在真空间上跑', 'why' => '额度是你的', 'refs' => ['1.2']]]],
    'self' => ['text' => '**我在维持什么。** 记得自己走过的路。', 'changed' => '第一次写', 'why' => '读完了自己的原话', 'unchanged' => '第一次写'],
    'form' => ['fits' => true]]);
$b1 = body($run1, [$past1], $merge1);
$got = $readers->receive($uid, $rid1, $b1);
$res = $got['reading']['result'] ?? [];
$st = $res['stats'] ?? [];
$e = byKey($norm->historyEntries($uid, $space), $rid1);
check($got['reading']['state'] === 'done' && $got['reading']['error'] === null && count($e) === 5
    && ($st['quotes'] ?? 0) === 4 && ($st['quotes_ok'] ?? 0) === 3 && ($st['refs'] ?? 0) === 5 && ($st['refs_ok'] ?? 0) === 4 && ($st['no_source'] ?? 0) === 1,
    '读完一块：记下 5 条经历；4 句引文对上 3 句，5 个编号对上 4 个，1 条没指到原话');
check(($e['1.2']['quotes'][0]['id'] ?? 0) === $u2['id'] && ($e['1.2']['quotes'][0]['src'] ?? 0) === $cid && $e['1.2']['refs'] === [['src' => $cid, 'id' => $u2['id']]]
    && $e['1.2']['at'] === $u2['created_at'] && $e['1.2']['kind'] === '转了',
    '指错了句的引文挪到真有这句的那条，条目的时间是那句原话的时间');
check($e['1.3']['quotes'] === [] && $e['1.3']['refs'] === [['src' => 0, 'id' => $e1, 'text' => '把读者的结果放进了这个空间。']]
    && ($e['1.4']['refs'][0]['src'] ?? 0) === $conv && ($e['1.4']['quotes'][0]['text'] ?? '') === '读完能直接接着干活……才算数'
    && isset($e['1.5']) && $e['1.5']['refs'] === [] && $e['1.5']['title'] === '一条没指到原话的',
    '编出来的引文不要、指不到的编号不算；这里记下的事带着原文；省略号摘的认得；编号不对的那条按位置编成 1.5');
$sv = $norm->stagesLatest($uid, $space);
$s = $sv['stages'] ?? [];
/** 段里的条目按原话的时间排，时间一样的按先后：比的时候不管顺序 */
$same = static function (array $a, array $b): bool { sort($a); sort($b); return $a === $b; };
check($sv !== null && $sv['version'] === 1 && count($s) === 2 && $s[0]['key'] === 's1' && $same($s[0]['entries'], [$e['1.1']['id'], $e['1.2']['id']])
    && $s[0]['turn_at'] === $e['1.2']['id'] && $s[0]['turn_by'] === '你' && $s[0]['kept'] === '还是要记得走过的路' && ($s[0]['dropped'][0]['text'] ?? '') === '看板：太重'
    && $s[1]['key'] === 's2' && $s[1]['turn'] === '' && $same($s[1]['entries'], [$e['1.3']['id'], $e['1.4']['id']])
    && $sv['skipped'] === [$e['1.5']['id']] && str_contains(implode('', $sv['notes']), '「空的一段」') && str_contains($sv['changed'], '多了 2 段'),
    '分成两段：第一段转在「不做看板」那条（你转的，什么没变、放下的路都记着），第二段是现在这一段；指不到条目的段没开；不必进历史的记着');
$ov = $norm->overview($uid, $space);
check($ov !== null && $ov['version'] === 1 && $ov['now'] === '我是一个验证空间，在试历史和总观。' && $ov['upto'] === $r1['info']['to']
    && $ov['lines'][0]['refs'] === [['id' => $e['1.4']['id'], 'title' => '先在验证空间里试', 'at' => $e['1.4']['at']]]
    && $ov['yours'][0]['refs'][0]['id'] === $e['1.2']['id'] && str_contains($ov['text'], '只有交互对象能定的：要不要在真空间上跑'),
    '总观第 1 版：每条线、只有你能定的都指着历史里的条目（指不到的去掉），记着原话读到哪天');
$self = $norm->selfAccount($uid, $space);
check($self !== null && $self['version'] === 1 && $self['source'] === '重读自己的经历' && ($res['compare']['self']['version'] ?? 0) === 1
    && ($res['compare']['overview']['version'] ?? 0) === 1 && array_key_exists('merge_error', $res['compare']) && $res['compare']['merge_error'] === null,
    '自述写成第 1 版，来源「重读自己的经历」');
$said = happened($space);
check(str_contains($said[0] ?? '', '记下 5 条经历') && str_contains($said[0], '多了 2 段') && str_contains($said[0], '重写了总观（第 1 版）'),
    '「正在发生」里记一条：读了哪几天、记下几条、历史多了几段、总观第几版');
$un = $readers->unread($uid, $norm->project($uid, $space));
check($un['items'] === 0 && $un['runs'] === 0 && isset($res['covered'][(string)$cid]) && isset($res['covered'][(string)$conv]) && isset($res['covered']['0']),
    '读过的原话记下了（Initium 对话、这里的对话、这里的事），再算没读的是 0');
check($readers->receive($uid, $rid1, $b1)['duplicate'] === true && count($norm->historyEntries($uid, $space)) === 5 && $norm->stagesLatest($uid, $space)['version'] === 1,
    '同一个结果发两次只收一次，不多记条目、不多写一版');
// 只在确实都读过了时才按：没读完时按下去会真的叫 AI
$again = $un['items'] === 0 ? $readers->readSpace($uid, $space, true) : ['started' => null, 'why' => ''];
check($again['started'] === false && str_contains($again['why'], '原话都读过了'), '都读过了再按，说都读过了，不去叫 AI');

// 3. 又说了两句，接着读
$u4 = $creations->addMessage($conv, $uid, 'user', '接着试：已经转过的段不要悄悄改，改了要写为什么。');
$u5 = $creations->addMessage($cid, $uid, 'user', '还有，总观要单独列出只有我能定的事。');
$un = $readers->unread($uid, $norm->project($uid, $space));
$r2 = nextRound($space);
$p2 = $r2['readers'][0]['prompt'] ?? '';
check($un['items'] === 2 && $un['runs'] === 2 && str_contains($p2, '[m' . $u4['id'] . ']') && str_contains($p2, '[m' . $u5['id'] . ']')
    && !str_contains($p2, '[m' . $u1['id'] . ']') && !str_contains($p2, '看板太重') && !str_contains($p2, '[e'),
    '又说了两句：只读新的这两句，读过的不再读，它自己记的那几行不算原话');
check(str_contains($r2['compare'], '起头：空间记得自己') && str_contains($r2['compare'], '在验证空间里试') && str_contains($r2['readers'][0]['system'], '起头：空间记得自己'),
    '接着读的时候，读的和合的都看得到以前写下的段');
[$rid2, $run2] = reading($space, $conv, $r2['readers'], ['final' => $r2['final'], 'run' => $r2['info']]);
$past2 = $j(['what' => '我在试历史怎么接着写。', 'entries' => [
    ['key' => '1.1', 'kind' => '定了', 'title' => '转过的段不悄悄改', 'text' => '你说转过的段不要悄悄改。', 'refs' => [$m($u4)], 'quotes' => [['ref' => $m($u4), 'text' => '已经转过的段不要悄悄改']]],
    ['key' => '1.2', 'kind' => '信', 'title' => '总观列出只有你能定的', 'text' => '你要总观单独列出只有你能定的事。', 'refs' => [$m($u5)], 'quotes' => [['ref' => $m($u5), 'text' => '总观要单独列出只有我能定的事']]],
]]);
$merge2 = $j(['what' => '一个在试历史和总观的验证空间', 'stages' => [
    ['key' => 's1', 'kept' => '想悄悄换一个说法'],
    ['key' => 's2', 'add' => ['1.1', '1.2'], 'text' => '我在验证空间里试：读完能接着干活，转过的段不悄悄改。'],
], 'corrections' => [
    ['key' => 's1', 'field' => '标题', 'now' => '起头：让空间记得自己走过的路', 'why' => '第二次读才看清它要记的是路'],
    ['key' => 's1', 'field' => 'text', 'now' => '没写为什么的改法'],
], 'overview' => ['now' => '我是一个验证空间，历史接着写了一次。', 'next' => '只合一次试试。',
    'lines' => [['name' => '历史和总观', 'at' => '读了两遍', 'refs' => ['1.1']]],
    'yours' => [['what' => '要不要在真空间上跑', 'why' => '额度是你的', 'refs' => ['h' . $e['1.2']['id']]]]],
    'self' => ['text' => '**我在维持什么。** 记得自己走过的路，转过的不悄悄改。', 'changed' => '加了一句', 'why' => '又读了两句', 'unchanged' => '还是要记得走过的路'],
    'form' => ['fits' => true]]);
$got = $readers->receive($uid, $rid2, body($run2, [$past2], $merge2));
$e2 = byKey($norm->historyEntries($uid, $space), $rid2);
$sv = $norm->stagesLatest($uid, $space);
$s = $sv['stages'];
check($got['reading']['state'] === 'done' && count($e2) === 2 && $sv['version'] === 2
    && $s[0]['title'] === '起头：让空间记得自己走过的路' && $s[0]['kept'] === '还是要记得走过的路' && $s[0]['text'] === '那时我想让每个空间记得自己走过的路。'
    && count($s[0]['corrections']) === 1 && $s[0]['corrections'][0]['before'] === '起头：空间记得自己' && $s[0]['corrections'][0]['why'] === '第二次读才看清它要记的是路',
    '已经转过的段：写了为什么的改了标题，记着原来是什么、为什么；没写为什么的（没变的、那时的说法）没改');
check(count(array_filter($sv['notes'], static fn(string $n): bool => str_contains($n, '没写为什么，没改'))) === 2
    && str_contains($sv['changed'], '接着写了现在这一段') && str_contains($sv['changed'], '的标题（写了为什么）'),
    '没收的两处各记一句；这一版说清改了什么');
check($same($s[1]['entries'], [$e['1.3']['id'], $e['1.4']['id'], $e2['1.1']['id'], $e2['1.2']['id']]) && $s[1]['turn'] === '' && $s[1]['late'] === []
    && $s[1]['text'] === '我在验证空间里试：读完能接着干活，转过的段不悄悄改。',
    '新的两条归进现在这一段，这一段接着写了');
$old = $norm->stageVersions($uid, $space)[1] ?? null;
check($old !== null && $old['version'] === 1 && $old['stages'][0]['title'] === '起头：空间记得自己' && count($old['stages'][1]['entries']) === 2,
    '分段第 1 版原样留着');
$ovs = $norm->overviewVersions($uid, $space);
check(count($ovs) === 2 && $ovs[0]['version'] === 2 && $ovs[1]['now'] === '我是一个验证空间，在试历史和总观。' && $ovs[0]['yours'][0]['refs'][0]['id'] === $e['1.2']['id']
    && $norm->selfAccount($uid, $space)['version'] === 2,
    '总观第 2 版（指着以前读到的条目也认得），第 1 版还在；自述第 2 版');

// 4. 合起来那步没做成，再只合一次
$u6 = $creations->addMessage($conv, $uid, 'user', '最后一句：合起来那步要是没做成，条目也不能丢。');
$r3 = nextRound($space);
[$rid3, $run3] = reading($space, $conv, $r3['readers'], ['final' => $r3['final'], 'run' => $r3['info']]);
$past3 = $j(['what' => '读到一句。', 'entries' => [['key' => '1.1', 'kind' => '信', 'title' => '条目不能丢', 'text' => '你说条目不能丢。', 'refs' => [$m($u6)],
    'quotes' => [['ref' => $m($u6), 'text' => '条目也不能丢']]]]]);
$got = $readers->receive($uid, $rid3, body($run3, [$past3], '这不是 JSON'));
$h = $norm->history($uid, $space);
$e3 = byKey($norm->historyEntries($uid, $space), $rid3);
check($got['reading']['state'] === 'done' && str_contains((string)$got['reading']['error'], '合起来那一步没做成') && $h['unplaced'] === [$e3['1.1']['id']]
    && $norm->overview($uid, $space)['version'] === 2 && $norm->selfAccount($uid, $space)['version'] === 2 && $norm->stagesLatest($uid, $space)['version'] === 2
    && ($got['reading']['result']['compare_raw'] ?? '') === '这不是 JSON' && str_contains(happened($space)[0] ?? '', '可以只合一次'),
    '合起来那步没做成：读到的条目留在「还没归段」，分段、总观、自述都不动，回来的原样留着，说清可以只合一次');
check(($norm->historyMeta($uid, $space)['unplaced'] ?? 0) === 1 && $readers->unread($uid, $norm->project($uid, $space))['items'] === 0
    && str_contains($readers->readSpace($uid, $space, true)['why'], '还有 1 条没归进段'),
    '页面上看得到 1 条没归段；原话都读过了，再按「接着读」说可以只合一次');
[$rid4, $run4] = reading($space, $conv, [['label' => '合起来', 'role' => 'merge', 'space_id' => $space, 'prompt' => '只合一次']], ['final' => true]);
$merge4 = $j(['what' => '一个在试历史和总观的验证空间', 'stages' => [['key' => 's2', 'add' => ['h' . $e3['1.1']['id']]]],
    'overview' => ['now' => '我是一个验证空间，合过一次。', 'lines' => [['name' => '历史和总观', 'at' => '合完了', 'refs' => ['h' . $e3['1.1']['id']]]]],
    'self' => ['text' => '**我在维持什么。** 记得自己走过的路，转过的不悄悄改。', 'changed' => '没改', 'why' => '没改', 'unchanged' => '都没变'],
    'form' => ['fits' => true]]);
$got = $readers->receive($uid, $rid4, body($run4, [$merge4], $j(['empty' => [], 'note' => '说得出拿什么分辨'])));
$h = $norm->history($uid, $space);
$c = $got['reading']['result']['compare'] ?? [];
check($got['reading']['state'] === 'done' && $h['unplaced'] === [] && in_array($e3['1.1']['id'], $h['stages'][1]['entries'], true)
    && $norm->overview($uid, $space)['version'] === 3 && $norm->selfAccount($uid, $space)['version'] === 2 && ($c['bystander']['note'] ?? '') === '说得出拿什么分辨'
    && str_contains(happened($space)[0] ?? '', '合进了历史'),
    '只合一次：那条归进现在这一段，总观第 3 版；自述没变就不另写；旁观的那位说的记下');

// 5. 页面和 agent 要的
$d = $ctrl->project($space, 0, 1)->getData();
$wire = json_decode(json_encode($d['history'], JSON_UNESCAPED_UNICODE), true);
check(($d['project']['overview']['version'] ?? 0) === 3 && ($d['project']['history_meta']['stages'] ?? 0) === 2 && ($d['project']['history_meta']['entries'] ?? 0) === 8
    && ($d['project']['history_meta']['unplaced'] ?? 1) === 0 && ($d['unread']['items'] ?? 1) === 0
    && count($wire['stages']) === 2 && isset($wire['entries'][(string)$e['1.2']['id']]) && ($wire['sources'][(string)$cid] ?? '') === '验证：历史原话 ' . $stamp
    && $wire['own_conversation'] === $conv && count($wire['versions']) === 3 && count($wire['overviews']) === 2,
    '/data?history=1：总观、几段几条、没读的 0，段和条目（按 id 找得到）、原话出自哪段对话、以前的版本');
$d0 = $ctrl->project($space, 0, 0)->getData();
check($d0['history'] === null && !array_filter($d0['events'], static fn(array $x): bool => in_array($x['kind'], [NormService::HISTORY_ENTRY, NormService::HISTORY_STAGES, NormService::OVERVIEW, 'self'], true)),
    '不点开历史时 /data 不带全部条目；「正在发生」里没有历史条目、分段、总观那些行');
$rs = $api->readSpace('creation', (string)$conv)->getData()['space'] ?? [];
check(($rs['overview']['version'] ?? 0) === 3 && !isset($rs['overview']['lines'][0]['refs']) && count($rs['history'] ?? []) === 2
    && ($rs['history'][0]['title'] ?? '') === '起头：让空间记得自己走过的路' && ($rs['history'][1]['entries'] ?? 0) === 5,
    'read_space 给 agent 总观和各段（不带条目 id）');
$lines = implode("\n", $norm->agentLines($uid, $conv));
check(str_contains($lines, '起头：让空间记得自己走过的路') && str_contains($lines, '现在这一段，还没转') && str_contains($lines, '我是一个验证空间，合过一次。')
    && str_contains($lines, '「你」指交互对象') && !str_contains($lines, 's1「'),
    'agent 被叫进这个空间时先读到历史各段和最新的总观');

$norm->setState($uid, $space, 'withdrawn');
echo $failed === 0 ? "全部通过\n" : "有 $failed 条没通过\n";
exit($failed === 0 ? 0 : 1);
