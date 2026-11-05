<?php
declare(strict_types=1);
define('OC_CONSOLE', 1);
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/console.php';
require '/var/www/html/lib/base.php';
set_exception_handler(function ($e) { fwrite(STDERR, (string)$e); exit(1); });
use OCA\Cognos\Service\{ProjectService, DecisionService, RestructureService};
$db = \OC::$server->get(\OCP\IDBConnection::class);
$projects = \OC::$server->get(ProjectService::class);
$decisions = \OC::$server->get(DecisionService::class);
$restructures = \OC::$server->get(RestructureService::class);
$request = fn() => bin2hex(random_bytes(16));
$uid = 'cognos-admin';
$mode = $argv[1];
$data = isset($argv[2]) ? json_decode(base64_decode($argv[2]), true) : [];
if ($mode === 'setup') {
    $p = $projects->create($uid, '验证：决定与重组交错 '.time(), $request());
    $target = $projects->create($uid, '验证：决定交错目标 '.time(), $request());
    $d = $decisions->record($uid, $p['project_id'], '并发决定', [$p['location_id']], null, '', null, $uid, null, $request());
    echo json_encode($p + ['decision_id' => $d['decision_id'], 'target_location_id' => $target['location_id'], 'token' => bin2hex(random_bytes(8))]);
    exit;
}
$op = function (string $name) use ($uid, $data, $decisions, $restructures, $request): array {
    if ($name === 'merge') {
        $params = ['project_id' => $data['project_id'], 'target_parent_id' => $data['target_location_id']];
        return $restructures->apply($uid, 'merge', $params, $restructures->preview($uid, 'merge', $params)['basis'], $request());
    }
    if ($name === 'decision') {
        return $decisions->record($uid, $data['project_id'], '并发新决定', [$data['location_id']], null, '', null, $uid, null, $request());
    }
    if ($name === 'revoke') { return $decisions->revoke($uid, $data['decision_id'], $request()); }
    throw new RuntimeException('unknown operation');
};
if (str_starts_with($mode, 'hold-')) {
    $db->beginTransaction();
    try {
        $result = $op(substr($mode, 5));
        echo "LOCKED\n"; flush();
        $release = '/tmp/cognos-decision-concurrency-'.$data['token'];
        $deadline = microtime(true) + 20;
        while (!file_exists($release)) {
            if (microtime(true) > $deadline) { throw new RuntimeException('release timeout'); }
            usleep(20000); clearstatcache(true, $release);
        }
        $db->commit(); echo json_encode(['ok' => true, 'result' => $result]);
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
} else {
    echo "ATTEMPT\n"; flush();
    try { echo json_encode(['ok' => true, 'result' => $op($mode)]); }
    catch (RuntimeException $e) { echo json_encode(['ok' => false, 'code' => $e->getCode(), 'error' => $e->getMessage()]); }
}
