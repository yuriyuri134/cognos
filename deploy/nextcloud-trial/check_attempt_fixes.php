<?php
/* 尝试的两处，在容器里对真实数据库跑，不调用外部 AI：
 *   docker cp deploy/nextcloud-trial/check_attempt_fixes.php cognos-nextcloud-trial-app-1:/tmp/check_attempt_fixes.php
 *   docker exec -u www-data cognos-nextcloud-trial-app-1 php /tmp/check_attempt_fixes.php
 * 1. 来源选择里列出的运行都能存：叫回来、接续这类运行的 caller 不是账号，原来列出来了，选了却存不了。
 * 2. 来源选择不把整处的消息、记录、运行全取出来再截：这一处有 2000 条长消息时，只取要显示的那些。
 * 会在库里留一个名为「验证：尝试来源 <时间>」的项目（2000 条测试消息在它的讨论里），不删。
 */
declare(strict_types=1);
define('OC_CONSOLE', 1); $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/console.php'; require '/var/www/html/lib/base.php';
set_exception_handler(function ($e) { fwrite(STDERR, (string)$e); exit(1); });
use OCA\Cognos\Service\{ProjectService, RunService, AttemptService, CreationSpaceService};
use OCA\Cognos\Controller\AttemptController;

$failed = 0;
function check(bool $ok, string $text): void { global $failed; if (!$ok) { $failed++; echo "没通过：$text\n"; return; } echo "通过：$text\n"; }
$uid = 'cognos-admin';
\OC::$server->get(\OCP\IUserSession::class)->setUser(\OC::$server->get(\OCP\IUserManager::class)->get($uid));
$projects = \OC::$server->get(ProjectService::class); $runs = \OC::$server->get(RunService::class);
$attempts = \OC::$server->get(AttemptService::class); $creations = \OC::$server->get(CreationSpaceService::class);
$db = \OC::$server->get(\OCP\IDBConnection::class);
$p = $projects->create($uid, '验证：尝试来源 ' . date('Y-m-d H:i:s'), bin2hex(random_bytes(16)));
$loc = $projects->location($uid, $p['location_id']); $space = (string)$loc['creation_id'];

// 1. 叫回来这类运行：caller 记的是叫人的那位的名字
$hitRun = $runs->create('creation', $space, 'hit', '1', '回应反对 #1', 'Claude Code', '小林', []);
$sources = fn() => \OC::$server->get(AttemptController::class)->sources($loc['id'])->getData();
$listed = array_column(array_filter($sources()['sources'], fn($s) => $s['type'] === 'run'), 'id');
check(in_array($hitRun['id'], $listed, true), '来源选择里列出了这次「回应反对」的运行');
try {
    $saved = $attempts->create($uid, $loc['id'], ['title' => '验证：引用回应反对的运行', 'intro' => '验证', 'refs' => [['type' => 'run', 'id' => $hitRun['id']]]], bin2hex(random_bytes(16)));
    check(isset($saved['id']), '选了它的尝试能存下');
} catch (\Throwable $e) { check(false, '选了它的尝试能存下（' . $e->getMessage() . '）'); }
$other = $creations->create($uid, '验证：别的对话里的运行 ' . time());
$foreign = $runs->create('creation', (string)$other['id'], 'conversation', 'x', '别处', 'Claude Code', '小林', []);
try { $attempts->create('someone-else', $loc['id'], ['title' => 'x', 'refs' => [['type' => 'run', 'id' => $hitRun['id']]]], bin2hex(random_bytes(16))); $leak = true; }
catch (\Throwable) { $leak = false; }
check(!$leak, '别的账号仍然不能引用这次运行');

// 2. 这一处有 2000 条长消息
$q = $db->getQueryBuilder(); $now = time(); $body = str_repeat('这是一条很长的测试消息。', 200);
$db->beginTransaction();
for ($i = 0; $i < 2000; $i++) {
    $q = $db->getQueryBuilder();
    $q->insert('cognos_creation_messages')->values(['creation_id' => $q->createNamedParameter((int)$space, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        'role' => $q->createNamedParameter($i % 2 ? 'assistant' : 'user'), 'content' => $q->createNamedParameter($i . ' ' . $body),
        'created_at' => $q->createNamedParameter($now + $i, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)])->executeStatement();
}
$db->commit();
gc_collect_cycles(); memory_reset_peak_usage(); $base = memory_get_usage(); $t = microtime(true);
$data = $sources();
$peak = memory_get_peak_usage() - $base; $ms = (int)((microtime(true) - $t) * 1000);
$messages = array_filter($data['sources'], fn($s) => $s['type'] === 'message');
echo sprintf("（来源选择：用了约 %.1f MB 内存、%d 毫秒；列出 %d 条消息，省略 %d 条）\n", $peak / 1048576, $ms, count($messages), $data['omitted']);
check(count($messages) === 30 && str_starts_with(end($messages)['label'], '消息 #') && $data['omitted'] >= 1970, '只列出最近的 30 条消息，并如实说省略了多少');
check(str_contains(reset($messages)['label'], '1970 ') || str_contains(end($messages)['label'], '1999 '), '列出的是最近的那些');
check($peak < 3 * 1048576, '不会把 2000 条长消息全取出来（内存增长在 3 MB 以内）');

echo $failed === 0 ? "全部通过\n" : "有 $failed 条没通过\n";
exit($failed === 0 ? 0 : 1);
