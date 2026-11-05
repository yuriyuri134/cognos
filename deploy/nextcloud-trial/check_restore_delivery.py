#!/usr/bin/env python3
"""Replay one archived engineering receipt against an isolated 8781 restore, never production.

No model runs, bridge server, outbox loop, or inflight-unknown reporting are started.
The pending/inflight files are copied; the backup artifacts are verified and left intact.
"""
from __future__ import annotations
import argparse, base64, contextlib, getpass, hashlib, importlib.util, io, json, os, re
import subprocess, sys, tarfile, urllib.error, urllib.parse, urllib.request, uuid
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parent
TARGET = 'http://127.0.0.1:8781'
CONTAINER_PATTERN = re.compile(r'cognos-restore-[a-z0-9]+-app\Z')
HEX = re.compile(r'[a-f0-9]{32}\Z')


class CheckFailed(RuntimeError):
    pass


def require(condition, message):
    if not condition:
        raise CheckFailed(message)


def sha(path):
    digest = hashlib.sha256()
    with path.open('rb') as file:
        for block in iter(lambda: file.read(4 * 1024 * 1024), b''):
            digest.update(block)
    return digest.hexdigest()


def docker(*args):
    result = subprocess.run(['docker', *args], capture_output=True, text=True, encoding='utf-8')
    require(result.returncode == 0, 'Restore-only Docker check failed; no container output emitted')
    return result.stdout


def backup_hashes(backup, manifest):
    hashes = {'manifest.json': sha(backup / 'manifest.json')}
    for name, artifact in manifest['artifacts'].items():
        require(Path(name).name == name, 'Backup artifact name must be a single file name')
        path = backup / name
        hashes[name] = sha(path)
        require(hashes[name] == artifact['sha256'], 'A backup artifact hash does not match its manifest')
    return hashes


def archive_member(archive, name):
    with tarfile.open(archive, 'r:gz') as tar:
        member = tar.getmember(name)
        require(member.isfile() and member.size <= 10 * 1024 * 1024, 'Unexpected archived fixture member')
        return tar.extractfile(member).read()


def private_stage():
    parent = ROOT / '.restore-drills'
    parent.mkdir(exist_ok=True)
    stage = parent / ('delivery-' + uuid.uuid4().hex[:12])
    stage.mkdir(mode=0o700)
    if os.name == 'nt':
        done = subprocess.run(['icacls', str(stage), '/inheritance:r', '/grant:r',
                               getpass.getuser() + ':(OI)(CI)F', 'SYSTEM:(OI)(CI)F'], capture_output=True)
        require(done.returncode == 0, 'Could not protect temporary restore delivery files')
    return stage


def copy_fixture_outbox(archive, destination):
    copied = []
    with tarfile.open(archive, 'r:gz') as tar:
        for member in tar.getmembers():
            parts = PurePosixPath(member.name).parts
            if not member.isfile():
                continue
            require(len(parts) == 3 and parts[0] == 'data' and parts[1] in ('pending', 'inflight')
                    and HEX.fullmatch(Path(parts[2]).stem) and parts[2].endswith('.json')
                    and member.size <= 10 * 1024 * 1024, 'Unexpected archived outbox path')
            target = destination / parts[1] / parts[2]
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(tar.extractfile(member).read())
            copied.append(target)
    return copied


READ_STATE = r'''define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';
set_exception_handler(function($e){fwrite(STDERR,'Restore delivery query failed; no row data emitted');exit(1);});
$f=json_decode(base64_decode($argv[1]),true);$db=\OC::$server->get(\OCP\IDBConnection::class);$c=\OC::$server->get(\OCP\IConfig::class);
if(!(bool)$c->getSystemValue('cognos_restore_quarantine',false))throw new RuntimeException('not quarantined');
$db->beginTransaction();try{$db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
$count=function(string $table,?string $key=null,$value=null)use($db):int{$q=$db->getQueryBuilder();$q->selectAlias($q->func()->count('*'),'n')->from($table);if($key!==null)$q->where($q->expr()->eq($key,$q->createNamedParameter($value,is_int($value)?\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT:\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR)));return (int)$q->executeQuery()->fetchOne();};
$q=$db->getQueryBuilder();$pending=$q->select('id','state')->from('cognos_runs')->where($q->expr()->eq('id',$q->createNamedParameter($f['pending_run'])))->executeQuery()->fetch();
$q=$db->getQueryBuilder();$inflight=$q->select('id','state','updated_at','error')->from('cognos_runs')->where($q->expr()->eq('id',$q->createNamedParameter($f['inflight_run'])))->executeQuery()->fetch();
$q=$db->getQueryBuilder();$receipt=$q->select('id','message_id')->from('cognos_run_results')->where($q->expr()->eq('id',$q->createNamedParameter($f['result_id'])))->executeQuery()->fetch();
$q=$db->getQueryBuilder();$followup=$q->select('state')->from('cognos_followups')->where($q->expr()->eq('result_id',$q->createNamedParameter($f['result_id'])))->executeQuery()->fetch();
$answer=['quarantined'=>true,'run_count'=>$count('cognos_runs'),'pending_state'=>$pending['state']??null,'inflight_state'=>$inflight['state']??null,'inflight_sha256'=>hash('sha256',json_encode($inflight,JSON_THROW_ON_ERROR)),'space_messages'=>$count('cognos_creation_messages','creation_id',(int)$f['space_id']),'receipts_for_run'=>$count('cognos_run_results','run_id',$f['pending_run']),'receipt'=>$receipt?:null,'receipt_message_count'=>$receipt?$count('cognos_creation_messages','id',(int)$receipt['message_id']):0,'followup_state'=>$followup['state']??null];
$db->commit();echo json_encode($answer,JSON_THROW_ON_ERROR);
}catch(Throwable $e){$db->rollBack();throw $e;}'''


def db_state(container, fixture):
    encoded = base64.b64encode(json.dumps(fixture).encode()).decode()
    return json.loads(docker('exec', '-u', 'www-data', '-w', '/var/www/html', container,
                             'php', '-r', READ_STATE, encoded))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backup', type=Path, required=True)
    parser.add_argument('--container', required=True)
    args = parser.parse_args()
    require(CONTAINER_PATTERN.fullmatch(args.container), 'Only a cognos-restore-<id>-app container is allowed')
    backup = args.backup.resolve()
    require((backup / 'COMPLETE').is_file() and not (backup / 'INCOMPLETE').exists(), 'A completed backup is required')
    manifest = json.loads((backup / 'manifest.json').read_text(encoding='utf-8'))
    require('host-fixture-outbox.tar.gz' in manifest['artifacts'] and 'host-secrets.tar.gz' in manifest['artifacts'],
            'The paired fixture and credential archives are required')
    original_hashes = backup_hashes(backup, manifest)
    fixture = json.loads((ROOT / 'evidence/backup-fixture.json').read_text(encoding='utf-8'))
    for key in ('pending_run', 'inflight_run', 'result_id'):
        require(HEX.fullmatch(fixture[key]), 'Invalid engineering fixture identity')
    require(str(fixture['space_id']).isdigit(), 'Invalid fixture conversation identity')
    info = json.loads(docker('inspect', args.container))[0]
    proxy_name = args.container.removesuffix('-app') + '-ingress'
    proxy = json.loads(docker('inspect', proxy_name))[0]
    require(proxy['State']['Running'] and proxy['Config']['Labels'].get('cognos.restore.backend') == args.container,
            'The loopback proxy must target the verified restore application')
    require(proxy['Config']['Labels'].get('cognos.restore') == info['Config']['Labels'].get('cognos.restore'),
            'The loopback proxy must belong to this restore')
    binding = proxy['NetworkSettings']['Ports'].get('80/tcp', [])
    require(info['State']['Running'] and info.get('Config', {}).get('Labels', {}).get('cognos.restore'),
            'The target must be a running labeled restore container')
    require(binding == [{'HostIp': '127.0.0.1', 'HostPort': '8781'}], 'Restore HTTP must bind only 127.0.0.1:8781')
    before = db_state(args.container, fixture)
    require(before['quarantined'], 'Restore dispatch quarantine must be enabled')
    require(before['pending_state'] is not None and before['inflight_state'] in ('queued', 'claimed', 'running', 'unknown', 'result_pending'),
            'Both original restored run identities, including the unconfirmed run, must be present')
    stage = private_stage()
    outbox = stage / 'outbox'
    copied = copy_fixture_outbox(backup / 'host-fixture-outbox.tar.gz', outbox)
    pending = outbox / 'pending' / (fixture['result_id'] + '.json')
    inflight = outbox / 'inflight' / (fixture['inflight_run'] + '.json')
    require(pending in copied and inflight in copied, 'Paired pending and inflight fixture files are required')
    payload = json.loads(pending.read_text(encoding='utf-8'))
    require(payload['run_id'] == fixture['pending_run'] and payload['result_id'] == fixture['result_id']
            and str(payload['space_id']) == str(fixture['space_id']) and payload['space_type'] == 'creation',
            'Archived result must match the selected engineering fixture')
    require(json.loads(inflight.read_text(encoding='utf-8'))['run_id'] == fixture['inflight_run'], 'Copied inflight record identity does not match')
    inflight_before = sha(inflight)
    credential = archive_member(backup / 'host-secrets.tar.gz', 'data/codex-app-password').decode().strip()
    require(bool(credential), 'The backed-up application credential is missing')
    os.environ.update(COGNOS_URL=TARGET, COGNOS_USER='cognos-admin', COGNOS_APP_PASSWORD=credential,
                      COGNOS_BRIDGE_TOKEN='restore-delivery-check-not-a-server', COGNOS_BRIDGE_COMMANDS='0',
                      COGNOS_SELF_MERGE='0', COGNOS_BRIDGE_OUTBOX=str(outbox),
                      COGNOS_SPACES_DIR=str(stage / 'unused-spaces'), COGNOS_RUN_LOG_DIR=str(stage / 'unused-runs'))
    spec = importlib.util.spec_from_file_location('restore_delivery_bridge', ROOT.parent.parent / 'tools/cognos-space-mcp/claude_bridge.py')
    bridge = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = bridge
    spec.loader.exec_module(bridge)
    require(bridge.COGNOS_URL == TARGET and bridge.OUTBOX_DIR.resolve() == outbox.resolve(), 'Bridge copy has unsafe defaults')
    forbidden_calls = []
    def forbidden(*_args, **_kwargs):
        forbidden_calls.append(True)
        raise RuntimeError('Bridge startup, model execution and inflight reporting are forbidden in this check')
    bridge.main = bridge.outbox_loop = bridge.report_unknown = bridge.work = bridge.run_claude = forbidden
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            raise urllib.error.HTTPError(req.full_url, code, 'Restore check refuses redirects', headers, fp)
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    requests = []
    allowed_path = '/ocs/v2.php/apps/cognos/api/v1.0/spaces/creation/' + str(fixture['space_id']) + '/messages'
    def restore_only_urlopen(request, *unused, **kwargs):
        url = request.full_url if isinstance(request, urllib.request.Request) else str(request)
        parsed = urllib.parse.urlsplit(url)
        require(parsed.scheme == 'http' and parsed.netloc == '127.0.0.1:8781'
                and parsed.path == allowed_path and not parsed.query and not parsed.fragment,
                'HTTP replay target must be exactly the isolated 8781 fixture callback')
        require(isinstance(request, urllib.request.Request) and request.get_method() == 'POST', 'Only the fixture callback POST is allowed')
        requests.append(parsed.path)
        return opener.open(request, timeout=kwargs.get('timeout', 60))
    bridge.urllib.request.urlopen = restore_only_urlopen
    original_post_back = bridge.post_back
    def lose_first_receipt(result):
        require(bridge.COGNOS_URL == TARGET, 'Restore replay target changed')
        original_post_back(result)
        raise urllib.error.URLError('engineering check: committed HTTP receipt deliberately lost')
    bridge.post_back = lose_first_receipt
    with contextlib.redirect_stderr(io.StringIO()):
        first = bridge.deliver(pending)
    require(first is False and pending.is_file(), 'A lost receipt must retain the exact pending result file')
    after_loss = db_state(args.container, fixture)
    require(after_loss['receipts_for_run'] == 1 and after_loss['receipt_message_count'] == 1
            and after_loss['pending_state'] == 'delivered', 'The first HTTP post must commit one real receipt and message')
    bridge.post_back = original_post_back
    with contextlib.redirect_stderr(io.StringIO()):
        second = bridge.deliver(pending)
    require(second is True and not pending.exists(), 'Acknowledged retry must remove only its copied pending file')
    after_retry = db_state(args.container, fixture)
    require(after_retry == after_loss, 'Same result retry must not create another message, receipt, run or followup')
    require(after_retry['run_count'] == before['run_count'] and after_retry['followup_state'] == 'pending',
            'Receiving a restored result must not automatically dispatch another run')
    expected_messages = before['space_messages'] + (0 if before['receipt'] else 1)
    require(after_retry['space_messages'] == expected_messages, 'Exactly the original result content may be committed once')
    require(inflight.is_file() and sha(inflight) == inflight_before
            and before['inflight_sha256'] == after_retry['inflight_sha256'],
            'Unconfirmed inflight state must remain unchanged, never executed or reported unknown')
    require(len(requests) == 2 and not forbidden_calls, 'Only the two explicit isolated callback requests may run')
    require(backup_hashes(backup, manifest) == original_hashes, 'Original backup artifacts must remain unchanged')
    report = {'result':'pass','kind':'engineering fixture; no model or external work executed',
              'target':TARGET,'container':args.container,'copied_outbox':str(outbox),
              'initial_receipt_present':bool(before['receipt']),'result_id':fixture['result_id'],
              'pending_run':fixture['pending_run'],'inflight_run':fixture['inflight_run'],
              'checks':['real HTTP callback committed, simulated lost acknowledgment retained its pending file',
                        'same result id retry returned the original receipt and removed only the copied pending file',
                        'exactly one message and receipt; durable followup remains pending; no new run',
                        'copied inflight file and restored inflight run state unchanged; no bridge/model/unknown-report loop',
                        'all source backup artifact hashes unchanged'],
              'receipt_message_id':after_retry['receipt']['message_id'],'http_callbacks':len(requests),
              'quarantined':after_retry['quarantined']}
    evidence = ROOT / 'evidence/restore-delivery.json'
    evidence.write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    try:
        main()
    except CheckFailed as error:
        print('Restore delivery check failed: ' + str(error), file=sys.stderr)
        sys.exit(1)
    except Exception as error:
        # Avoid printing HTTP response bodies, credentials, archived content, or a request object.
        print('Restore delivery check failed: ' + type(error).__name__ + '; no credential or source content emitted', file=sys.stderr)
        sys.exit(1)
