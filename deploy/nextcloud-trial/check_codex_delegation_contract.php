<?php

declare(strict_types=1);

use OCA\Cognos\Service\CreationSpaceService;
use OCA\Cognos\Service\DelegationService;
use OCA\Cognos\Service\ProjectService;
use OCA\Cognos\Service\RunService;
use OCP\IDBConnection;

define('OC_CONSOLE', 1);
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/console.php';
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, (string)$e); exit(1); });

function verify(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
    echo $message . PHP_EOL;
}

function expectDenied(callable $call, string $message): void {
    try { $call(); } catch (DomainException) { verify(true, $message); return; }
    throw new RuntimeException('Expected rejection: ' . $message);
}

$db = \OC::$server->get(IDBConnection::class);
$projects = \OC::$server->get(ProjectService::class);
$creations = \OC::$server->get(CreationSpaceService::class);
$delegations = \OC::$server->get(DelegationService::class);
$runs = \OC::$server->get(RunService::class);
$uid = 'cognos-admin';
$id = static fn(): string => bin2hex(random_bytes(16));
$db->beginTransaction();
try {
    $project = $projects->create($uid, 'Codex dispatch contract fixture', $id());
    $location = $projects->location($uid, $project['location_id']);
    $space = (string)$location['creation_id'];
    $delegation = $id();
    $message = $creations->addMessage((int)$space, $uid, 'user', 'Verify bound delegation.');
    $projects->insert('cognos_delegations', [
        'id' => $delegation, 'project_id' => $project['project_id'], 'location_id' => $location['id'],
        'ask' => 'Verify bound delegation.', 'return_by' => time()+600, 'may_edit' => 1, 'base_revision' => 1,
        'state' => 'starting', 'executor' => 'codex', 'message_id' => (int)$message['id'],
        'created_by' => $uid, 'created_at' => time(), 'updated_at' => time(),
    ]);
    verify(str_contains(implode(' ', $delegations->canDoLines($delegation)), 'write_content'),
        'The entry can describe granted tools before the run exists.');
    $run = $runs->create('creation', $space, 'delegation', $delegation, 'Verify binding', 'Codex', $uid,
        ['extra' => ['delegation_id' => $delegation, 'executor' => 'codex']]);
    verify($delegations->forRun($run['id'])['id'] === $delegation, 'Run admission binds delegation before bridge acknowledgement.');
    $other = $runs->create('creation', $space, 'conversation', 'creation:'.$space, 'Separate same-name run', 'Codex', $uid, []);
    expectDenied(fn() => $delegations->writeContent($uid, (int)$space, 'Codex', 'wrong', $other['id']),
        'Independent same-name run cannot use the delegation to write.');
    expectDenied(fn() => $delegations->writeContent($uid, (int)$space, 'Claude Code', 'wrong', $run['id']),
        'Another participant cannot use the bound run to write.');
    $applied = $delegations->writeContent($uid, (int)$space, 'Codex', 'Bound Codex draft', $run['id']);
    verify($applied['applied'] && (int)$applied['revision'] === 2, 'Authorized bound Codex run can write the intended revision.');
    $resultId = $id();
    $store = fn(): int => (int)$creations->addMessage((int)$space, $uid, 'assistant', 'Bound Codex result', (int)$message['id'])['id'];
    $first = $runs->receiveResult($run, $resultId, 'Codex', [], $store,
        fn(int $messageId) => $delegations->returned($delegation, $messageId));
    $runs->accepted($run['id']);
    verify($runs->get($run['id'])['state'] === 'delivered' && $delegations->forRun($run['id'])['state'] === 'returned',
        'Result can arrive before acknowledgement without losing the run association.');
    expectDenied(fn() => $runs->receiveResult($run, $resultId, 'Claude Code', [], $store),
        'Wrong participant cannot obtain even an existing result receipt.');
    $replay = $runs->receiveResult($run, $resultId, 'Codex', [], $store);
    verify($replay['duplicate'] && $first['message_id'] === $replay['message_id'], 'Same participant receives the original receipt.');
} finally {
    $db->rollBack();
}
