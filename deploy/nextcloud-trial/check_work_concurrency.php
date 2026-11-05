<?php
declare(strict_types=1);
define('OC_CONSOLE', 1);
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/console.php';
require '/var/www/html/lib/base.php';
set_exception_handler(function ($e) { fwrite(STDERR, (string)$e); exit(1); });
use OCA\Cognos\Service\{ProjectService, WorkItemService};
$db = \OC::$server->get(\OCP\IDBConnection::class);
$projects = \OC::$server->get(ProjectService::class);
$work = \OC::$server->get(WorkItemService::class);
$request = fn() => bin2hex(random_bytes(16));
$uid = 'cognos-admin';
$mode = $argv[1];
$data = isset($argv[2]) ? json_decode(base64_decode($argv[2]), true) : [];
if ($mode === 'setup') {
    $p = $projects->create($uid, '验证：决定与重组交错 '.time(), $request());
    $w = $work->create($uid, $p['location_id'], ['description'=>'并发拆分原工作'], $request());
    $parts = [['description'=>'并发子项甲'], ['description'=>'并发子项乙']];
    $preview = $work->previewSplit($uid, $w['id'], $parts);
    echo json_encode($p + ['work_id'=>$w['id'],'parts'=>$parts,'basis'=>$preview['basis'],'token'=>bin2hex(random_bytes(8))]);
    exit;
}
$op = function (string $name) use ($uid, $data, $work, $request): array {
    if ($name === 'split') { return $work->split($uid, $data['work_id'], $data['parts'], $data['basis'], $request()); }
    if ($name === 'write') { return $work->update($uid, $data['work_id'], 1, ['description'=>'并发改变的工作范围'], 'open', '', '改变范围', $request()); }
    if ($name === 'attach') { return ['epoch'=>$work->attachDelegation($uid, $data['work_id'], $data['location_id'], 1, '验证中的委托登记，不调用外部 AI')]; }
    throw new RuntimeException('unknown operation');
};
if (str_starts_with($mode, 'hold-')) {
    $db->beginTransaction();
    try {
        $result = $op(substr($mode, 5));
        echo "LOCKED\n"; flush();
        $release = '/tmp/cognos-work-concurrency-'.$data['token'];
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
