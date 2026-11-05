<?php
/* 审查里派发相关的两处，在容器里对真实数据库跑，不调用外部 AI：
 *   docker cp deploy/nextcloud-trial/check_dispatch_fixes.php cognos-nextcloud-trial-app-1:/tmp/check_dispatch_fixes.php
 *   docker exec -u www-data cognos-nextcloud-trial-app-1 php /tmp/check_dispatch_fixes.php
 * 1. 别的 409 错误不能被当成「桥正忙」（原来所有调用方都只看异常码是不是 409）。
 * 2. 旧的「叫回来」队列记录坏了一条，这片空间的排队和叫回来不能从此一直报错；坏记录照原样留着，记成无法识别。
 * 会在库里留一段名为「验证：派发修正 <时间>」的对话，和一条它的坏旧队列记录（作为证据，不删）。
 */
declare(strict_types=1);
define('OC_CONSOLE', 1); $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/console.php'; require '/var/www/html/lib/base.php';
set_exception_handler(function ($e) { fwrite(STDERR, (string)$e); exit(1); });
use OCA\Cognos\Service\{ProjectService, ClaudeBridgeService, PushBackService, CreationSpaceService, DispatchQueueService};

$failed = 0;
function check(bool $ok, string $text): void { global $failed; if (!$ok) { $failed++; echo "没通过：$text\n"; return; } echo "通过：$text\n"; }
$uid = 'cognos-admin';
$projects = \OC::$server->get(ProjectService::class); $creations = \OC::$server->get(CreationSpaceService::class);
$config = \OC::$server->get(\OCP\IConfig::class); $db = \OC::$server->get(\OCP\IDBConnection::class);
$isBusy = method_exists(ClaudeBridgeService::class, 'isBusy')
    ? fn(\Throwable $e): bool => ClaudeBridgeService::isBusy($e)
    : fn(\Throwable $e): bool => $e instanceof \RuntimeException && $e->getCode() === ClaudeBridgeService::BUSY;   // 修之前各处的判断

// 1. 一个真实的、和桥无关的 409：同一个请求编号换了内容
$rid = bin2hex(random_bytes(16));
$projects->commit($uid, $rid, ['验证', 1], fn() => ['ok' => 1]);
try { $projects->commit($uid, $rid, ['验证', 2], fn() => ['ok' => 2]); $conflict = null; } catch (\RuntimeException $e) { $conflict = $e; }
check($conflict !== null && $conflict->getCode() === 409, '先造出一个真实的 409（同一请求编号不能用于不同内容）');
check(!$isBusy($conflict), '这种 409 不能被当成「桥正忙」');
$guard = new \RuntimeException('当前为恢复隔离副本：保留记录与结果回执，暂不启动或派发 AI 工作。', 409);   // RestoreGuardService 抛的就是这样
check(!$isBusy($guard), '恢复隔离的 409 不能被当成「桥正忙」');
if (class_exists(\OCA\Cognos\Service\BridgeBusyException::class)) {
    check($isBusy(new \OCA\Cognos\Service\BridgeBusyException('桥正忙')), '真的桥正忙仍然认得出');
}

// 2. 坏掉的旧队列记录
$creation = $creations->create($uid, '验证：派发修正 ' . date('Y-m-d H:i:s'));
$space = (string)$creation['id']; $key = 'pushback_queue_creation_' . $space;
$config->setAppValue('cognos', $key, '这不是 JSON');
$pushBack = \OC::$server->get(PushBackService::class);
$message = $creations->addMessage((int)$space, $uid, 'user', '验证：桥正忙时排着的一句');
try { $pushBack->enqueueMessage('creation', $space, (int)$message['id']); $error = null; } catch (\Throwable $e) { $error = $e; }
check($error === null, '旧队列记录坏了，排一句新消息不报错' . ($error ? '（' . $error->getMessage() . '）' : ''));
try { $pushBack->enqueueMessage('creation', $space, (int)$message['id'] + 0); $error2 = null; } catch (\Throwable $e) { $error2 = $e; }
check($error2 === null, '第二次也不报错（不会每次都卡在同一条坏记录上）');
check($config->getAppValue('cognos', $key, '') === '这不是 JSON', '坏记录照原样留着');
$q = $db->getQueryBuilder();
$row = $q->select('state')->from('cognos_queue_imports')->where($q->expr()->eq('source_key', $q->createNamedParameter($key)))->executeQuery()->fetch();
check($row !== false && $row['state'] === 'invalid', '坏记录记成「无法识别」，可以在页面上核对');
$q = $db->getQueryBuilder();
$queued = $q->select('id')->from('cognos_dispatch_queue')->where($q->expr()->eq('ref_id', $q->createNamedParameter((int)$message['id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeQuery()->fetchAll();
check(count($queued) >= 1, '新消息排进了队列');

echo $failed === 0 ? "全部通过\n" : "有 $failed 条没通过\n";
exit($failed === 0 ? 0 : 1);
