<?php
/* 纠正：交互对象说 agent 上一条没走好时，agent 用 note_correction 记下，系统从对话里找出他那句话和前面那条回答。
 * 在容器里对真实数据库跑，不调用外部 AI：
 *   docker cp deploy/nextcloud-trial/check_corrections.php cognos-nextcloud-trial-app-1:/tmp/check_corrections.php
 *   docker exec -u www-data cognos-nextcloud-trial-app-1 php /tmp/check_corrections.php [账号，默认 cognos-admin]
 * 1. 记一次：找到的是他最近说的那句（一字不差）、它前面那条 AI 回答和是谁说的；同一句再记一次不重复；
 *    pull 不是收或窄、how 是空的、quote 在对话里找不到，都不记；给了 quote 就记含这几个字的那句。
 * 2. 页面和 agent 要的：project() 带 corrections；「正在发生」里没有纠正；
 *    agent 被叫进这个空间时读到这次纠正的原话和漏在哪，也读到别的空间里的（最多 3 次）。
 * 3. 种进标本（seeds/corrections-2026-11-09.json，20 个）：只在给了第二个参数 seed 和空间 id 时做，重复跑不重复记：
 *   docker cp deploy/nextcloud-trial/cognos-app/seeds/corrections-2026-11-09.json cognos-nextcloud-trial-app-1:/tmp/corrections.json
 *   docker exec -u www-data cognos-nextcloud-trial-app-1 php /tmp/check_corrections.php cognos-admin seed <空间 id> /tmp/corrections.json
 * 会在库里留一个「验证：纠正 <时间>」空间（最后撤回，不在 Norm 里显示），不删。
 */
declare(strict_types=1);
define('OC_CONSOLE', 1); $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/console.php'; require '/var/www/html/lib/base.php';
set_exception_handler(function ($e) { fwrite(STDERR, (string)$e); exit(1); });
use OCA\Cognos\Service\{NormService, CreationSpaceService};
use OCA\Cognos\Controller\NormApiController;

$failed = 0;
function check(bool $ok, string $text): void { global $failed; if (!$ok) { $failed++; echo "没通过：$text\n"; return; } echo "通过：$text\n"; }
$uid = $argv[1] ?? 'cognos-admin';
\OC::$server->get(\OCP\IUserSession::class)->setUser(\OC::$server->get(\OCP\IUserManager::class)->get($uid));
$norm = \OC::$server->get(NormService::class); $creations = \OC::$server->get(CreationSpaceService::class);
$api = \OC::$server->get(NormApiController::class);

if (($argv[2] ?? '') === 'seed') {
    $space = (string)($argv[3] ?? '');
    $file = (string)($argv[4] ?? '/tmp/corrections.json');
    if ($norm->project($uid, $space) === null) { fwrite(STDERR, "没有这个空间：$space\n"); exit(1); }
    $seed = json_decode((string)file_get_contents($file), true);
    $have = [];
    foreach ($norm->corrections($uid, $space, 500) as $c) { $have[$c['said_at'] . '|' . $c['said']] = true; }
    $added = 0;
    foreach ($seed['specimens'] as $s) {
        $at = (new \DateTimeImmutable($s['said_at']))->getTimestamp();
        if (isset($have[$at . '|' . $s['said']])) { continue; }
        $norm->addCorrection($uid, $space, ['pull' => $s['pull'], 'how' => $s['how'], 'said' => $s['said'], 'said_at' => $at,
            'before' => $s['before'], 'before_who' => $s['before_who'], 'title' => $s['title'], 'source' => 'seed', 'who' => 'seed']);
        $added++;
    }
    echo "种进 $added 个标本，原来有 " . count($have) . " 个。\n";
    exit(0);
}

$stamp = date('Y-m-d H:i:s');
$space = $norm->createSpace($uid, '验证：纠正 ' . $stamp, '项目', [], ['progress' => '他不用再说同一句纠正'], '验证纠正用的空间');
$conv = $norm->spaceConversation($uid, $space);
$u1 = $creations->addMessage($conv, $uid, 'user', '我想知道要不要给 AI 配一个环境，远端服务器好不好？');
$a1 = $creations->addMessage($conv, $uid, 'assistant', '现在不用租服务器。模型在厂商那边算，AI 在你电脑上动手……', $u1['id'], 'Claude');
$u2 = $creations->addMessage($conv, $uid, 'user', '就这么点吗？我说的是一个模糊的任务能不能有很多尝试。');
$a2 = $creations->addMessage($conv, $uid, 'assistant', '你说得对，我接着往下想……', $u2['id'], 'Claude');
$u3 = $creations->addMessage($conv, $uid, 'user', '另外再补充一句：先别管钱。');

// 1. 记一次
$bad = $api->note('creation', (string)$conv, 'correction', '只答了字面', [], '', 'Claude', '慢', '');
check($bad->getStatus() === 422, 'pull 不是收或窄：不记（' . $bad->getStatus() . '）');
$bad = $api->note('creation', (string)$conv, 'correction', '  ', [], '', 'Claude', '窄', '');
check($bad->getStatus() === 422, 'how 是空的：不记');
$bad = $api->note('creation', (string)$conv, 'correction', '答了字面', [], '', 'Claude', '窄', '他没说过的话');
check($bad->getStatus() === 422, 'quote 在对话里找不到：不记，说清要一字不差');
check($norm->corrections($uid, $space) === [], '上面三次都没有留下东西');

// 最近一句是补充，不是纠正：agent 用 quote 指到那句纠正
$r = $api->note('creation', (string)$conv, 'correction', '答了字面的服务器，没接住他要的是尝试能不能放开', [], '', 'Claude', '窄', '就这么点吗');
check($r->getStatus() === 200, '给 quote 记一次：成了（' . $r->getStatus() . ' ' . ($r->getData()['message'] ?? '') . '）');
$list = $norm->corrections($uid, $space);
$c = $list[0] ?? [];
check(count($list) === 1, '记下一条');
check(($c['said'] ?? '') === $u2['content'] && ($c['said_id'] ?? 0) === $u2['id'], '他那句话一字不差，是 quote 指的那句，不是最近的补充');
check(($c['before'] ?? '') === $a1['content'] && ($c['before_id'] ?? 0) === $a1['id'] && ($c['before_who'] ?? '') === 'Claude', '前面那条是它之前的回答，记着是 Claude 说的');
check(($c['pull'] ?? '') === '窄' && ($c['how'] ?? '') === '答了字面的服务器，没接住他要的是尝试能不能放开' && ($c['conversation'] ?? 0) === $conv, '漏在哪、哪段对话都记着');
$again = $api->note('creation', (string)$conv, 'correction', '再记一次', [], '', 'Claude', '收', '就这么点吗');
check($again->getStatus() === 200 && count($norm->corrections($uid, $space)) === 1, '同一句再记一次：不重复');

// 不给 quote：默认是他最近说的一句
$u4 = $creations->addMessage($conv, $uid, 'user', '你又只给了一小步，太小了。');
$r = $api->note('creation', (string)$conv, 'correction', '认下批评以后只多走了一点', [], '', 'Claude', '收', '');
$list = $norm->corrections($uid, $space);
check($r->getStatus() === 200 && count($list) === 2 && $list[0]['said_id'] === $u4['id'] && $list[0]['before_id'] === $a2['id'], '不给 quote：记的是他最近那句，前面那条是紧挨着的回答');

// 2. 页面和 agent 要的
$p = $norm->project($uid, $space);
check(count($p['corrections'] ?? []) === 2 && $p['corrections'][0]['pull'] === '收', 'project() 带着纠正，新的在前');
$kinds = array_column($norm->events($uid, $space), 'kind');
check(!in_array(NormService::CORRECTION, $kinds, true), '「正在发生」里没有纠正');
$lines = implode("\n", $norm->agentLines($uid, $conv));
check(str_contains($lines, '他说：「就这么点吗？我说的是一个模糊的任务能不能有很多尝试。」') && str_contains($lines, '漏在哪：答了字面的服务器'), 'agent 进来读到这次纠正的原话和漏在哪');
check(str_contains($lines, '当时Claude说：「现在不用租服务器。'), 'agent 读到自己当时怎么说的开头');
check(str_contains($lines, 'note_correction') && str_contains($lines, '收（') && str_contains($lines, '窄（'), 'agent 知道用 note_correction 记、收和窄是什么');

// 别的空间的也看得到（最多 3 次）
// 检查空间（「验证：」开头）的纠正不进别的空间；改个名字当成真空间，另一处就读得到
$other = $norm->createSpace($uid, '验证：纠正 另一处 ' . $stamp, '笔记', [], [], '验证纠正用的另一个空间');
$oconv = $norm->spaceConversation($uid, $other);
$ol = implode("\n", $norm->agentLines($uid, $oconv));
check(!str_contains($ol, '你又只给了一小步，太小了。'), '「验证：」空间里的纠正不给别的空间');
$db = \OC::$server->get(\OCP\IDBConnection::class);
$rename = static function (string $id, string $title) use ($db): void {
    $q = $db->getQueryBuilder();
    $q->update('cognos_norm_projects')->set('title', $q->createNamedParameter($title))->where($q->expr()->eq('id', $q->createNamedParameter($id)))->executeStatement();
};
$rename($space, '纠正检查（临时）' . $stamp);
$ol = implode("\n", $norm->agentLines($uid, $oconv));
check(str_contains($ol, '在别的空间里：') && str_contains($ol, '你又只给了一小步，太小了。'), '另一个空间的 agent 读得到别的真空间里的纠正');
check(!str_contains($ol, '在这个空间里：'), '另一个空间自己还没有纠正时，不写「在这个空间里」');
$norm->setState($uid, $space, 'withdrawn');
$ol = implode("\n", $norm->agentLines($uid, $oconv));
check(!str_contains($ol, '你又只给了一小步，太小了。'), '撤回的空间里的纠正不给别的空间');
$rename($space, '验证：纠正 ' . $stamp);

$norm->setState($uid, $space, 'withdrawn');
$norm->setState($uid, $other, 'withdrawn');
echo $failed === 0 ? "全部通过\n" : "$failed 条没通过\n";
exit($failed === 0 ? 0 : 1);
