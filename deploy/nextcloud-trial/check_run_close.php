<?php
/* 0.35.1 的三处修正，在容器里对真实数据库跑，不调用外部 AI：
 *   docker cp deploy/nextcloud-trial/check_run_close.php cognos-nextcloud-trial-app-1:/tmp/check_run_close.php
 *   docker exec -u www-data cognos-nextcloud-trial-app-1 php /tmp/check_run_close.php
 * 会在库里留下一个名为「验证：关掉不知道的运行 <时间>」的项目，和别的检查一样，不删。
 * 1. 「不知道做没做完」的运行可以由用户核对后关掉，关掉后不再挡这段对话；迟到的结果照收。
 * 2. 连不上桥（cURL 6/7）算没发出去；连上以后超时（28）仍算不知道。
 * 3. 接受 AI 的提议时，这里已经采用了同一版就不再多出一条。
 */
declare(strict_types=1);
define('OC_CONSOLE', 1); $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/console.php'; require '/var/www/html/lib/base.php';
set_exception_handler(function ($e) { fwrite(STDERR, (string)$e); exit(1); });
use OCA\Cognos\Service\{ProjectService, RunService, AdoptionService, ClaudeBridgeService, CreationSpaceService};

function check(bool $ok, string $text): void { if (!$ok) throw new RuntimeException('没通过：' . $text); echo "通过：$text\n"; }
function rejects(callable $fn, int $code, string $text): void {
    try { $fn(); } catch (\RuntimeException $e) { check($e->getCode() === $code, $text . '（' . $e->getMessage() . '）'); return; }
    catch (\InvalidArgumentException $e) { check($code === 422, $text . '（' . $e->getMessage() . '）'); return; }
    check(false, $text . '：没有被拒绝');
}
$uid = 'cognos-admin';
$projects = \OC::$server->get(ProjectService::class); $runs = \OC::$server->get(RunService::class);
$adoptions = \OC::$server->get(AdoptionService::class); $creations = \OC::$server->get(CreationSpaceService::class);
$db = \OC::$server->get(\OCP\IDBConnection::class);
$p = $projects->create($uid, '验证：关掉不知道的运行 ' . time(), bin2hex(random_bytes(16)));
$loc = $projects->location($uid, $p['location_id']); $space = (string)$loc['creation_id'];

// 1. 关掉不知道的运行
$run = $runs->create('creation', $space, 'conversation', 'creation:' . $space, '验证', '验证参与者', $uid, []);
$runs->dispatchUnknown($run['id'], '验证：响应丢了');
check($runs->get($run['id'])['state'] === 'unknown', '先记成不知道');
$blocking = function () use ($db, $space): bool { $q = $db->getQueryBuilder(); return (bool)$q->select('id')->from('cognos_runs')->where($q->expr()->eq('space_id', $q->createNamedParameter($space)))
    ->andWhere($q->expr()->eq('state', $q->createNamedParameter('unknown')))->setMaxResults(1)->executeQuery()->fetch(); };
check($blocking(), '不知道的运行会挡住这段对话的接续（FollowupDispatcher 查的就是这个）');
rejects(fn() => $runs->closeUnknown($run['id'], $uid, '  '), 422, '不写核对说明不能关');
rejects(fn() => $runs->closeUnknown($run['id'], 'someone-else', '别人'), 404, '别的账号关不了');
$closed = $runs->closeUnknown($run['id'], $uid, '看了桥的日志，没有在做');
check($closed['run']['state'] === 'closed', '关掉后状态是 closed');
check(str_contains((string)$closed['run']['error'], '看了桥的日志'), '核对说明留在运行上');
check(!$blocking(), '关掉后不再挡');
rejects(fn() => $runs->closeUnknown($run['id'], $uid, '再关一次'), 409, '已经关掉的不能再关');
$resultId = bin2hex(random_bytes(16));
$late = $runs->receiveResult($runs->get($run['id']), $resultId, '验证参与者', [],
    fn() => (int)$creations->addMessage((int)$space, $uid, 'assistant', '验证：关掉以后才回来的结果')['id']);
check(($late['duplicate'] ?? false) === false && $runs->get($run['id'])['state'] === 'delivered', '关掉以后迟到的结果照收');
// 接续、叫回来这类运行的 caller 不是账号（记的是「Cognos」或叫人的那位的名字），对话的主人也要能关
$auto = $runs->create('creation', $space, 'open', '1', '验证：接续', 'Claude Code', 'Cognos', []);
$runs->dispatchUnknown($auto['id'], '验证：接续时响应丢了');
rejects(fn() => $runs->closeUnknown($auto['id'], 'someone-else', '别人'), 404, 'caller 是「Cognos」的运行，别的账号也关不了');
check($runs->closeUnknown($auto['id'], $uid, '接续那次，看过桥的日志没在做')['run']['state'] === 'closed', 'caller 是「Cognos」的运行，对话的主人能关');

// 2. 连不上桥 vs 连上后超时
$never = new ReflectionMethod(ClaudeBridgeService::class, 'neverConnected'); $never->setAccessible(true);
$request = new \GuzzleHttp\Psr7\Request('POST', 'http://host.docker.internal:8643/chat');
$refused = new \GuzzleHttp\Exception\ConnectException('cURL error 7: Failed to connect', $request, null, ['errno' => 7]);
$timeout = new \GuzzleHttp\Exception\ConnectException('cURL error 28: Operation timed out', $request, null, ['errno' => 28]);
check($never->invoke(null, $refused) === true, '连不上（cURL 7）算没发出去');
check($never->invoke(null, new \RuntimeException('wrapped', 0, $refused)) === true, '包了一层的连不上也认得出');
check($never->invoke(null, $timeout) === false, '连上后超时（cURL 28）仍算不知道');
check($never->invoke(null, new \RuntimeException('别的错')) === false, '别的错仍算不知道');

// 3. 接受提议时已经采用了同一版
$objectId = $loc['id']; $now = time();
$row = fn(string $id, string $state) => ['id' => $id, 'project_id' => $loc['project_id'], 'location_id' => $loc['id'],
    'object_type' => 'location_content', 'object_id' => $objectId, 'object_version' => '1', 'adopted_by' => $uid, 'basis' => '验证',
    'state' => $state, 'supersedes' => null, 'superseded_by' => null, 'response' => null, 'ended_at' => null, 'ended_by' => null,
    'created_at' => $now, 'updated_at' => $now];
$active = bin2hex(random_bytes(16)); $proposal = bin2hex(random_bytes(16));
$projects->insert('cognos_adoptions', $row($active, 'active'));
$projects->insert('cognos_adoptions', $row($proposal, 'proposed'));
rejects(fn() => $adoptions->acceptProposal($uid, $proposal, bin2hex(random_bytes(16))), 409, '已经采用了同一版，提议不能再接受');
$q = $db->getQueryBuilder();
$n = (int)$q->selectAlias($q->func()->count('*'), 'n')->from('cognos_adoptions')->where($q->expr()->eq('location_id', $q->createNamedParameter($loc['id'])))
    ->andWhere($q->expr()->eq('state', $q->createNamedParameter('active')))->executeQuery()->fetchOne();
check($n === 1, '这一处只有一条生效的采用');
$adoptions->declineProposal($uid, $proposal, bin2hex(random_bytes(16)));
check(true, '这条提议可以照常「不采用」收起来');
echo "全部通过\n";
