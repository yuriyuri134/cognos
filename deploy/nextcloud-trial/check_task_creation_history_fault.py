"""Reject one nonce-tagged creation activity in the isolated trial database only."""
import json
import secrets
import uuid
import subprocess
import sys
from pathlib import Path

TRIAL = Path(r'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial')
sys.path.insert(0, str(TRIAL))
from check_tasks import ROOT, DECK, client_for, request


def sql(statement):
    result = subprocess.run(['docker', '--config', str(TRIAL / '.docker-client'),
        '--host', 'npipe:////./pipe/dockerDesktopLinuxEngine', 'exec', '-i',
        'cognos-nextcloud-trial-db-1', 'psql', '-U', 'nextcloud', '-d', 'nextcloud',
        '-v', 'ON_ERROR_STOP=1', '-tA'], input=statement, text=True,
        capture_output=True, timeout=30)
    if result.returncode:
        raise RuntimeError('Scoped trial SQL failed')
    return result.stdout.strip()


def run():
    nonce = secrets.token_hex(6)
    name = 'cognos_fault_' + nonce
    title = 'Atomic history ' + nonce
    state = json.loads((ROOT / '.secrets/task-journey.json').read_text())
    report = {'scope': 'Cognos create database transaction only; not all Deck mutations',
              'fault': 'reject activity INSERT for one unique test title only',
              'checks': [], 'full_journey': 'unverified'}
    try:
        with client_for(state['alice']['user'], state['alice']['password']) as alice:
            board = request(alice, 'POST', DECK + '/boards', json={
                'title': 'History fault ' + nonce, 'color': '317CCC'}).json()
            bp = f"{DECK}/boards/{board['id']}"
            stack = request(alice, 'POST', bp + '/stacks', json={'title': '待处理', 'order': 1}).json()
            report['objects'] = {'board': board['id'], 'stack': stack['id']}
            # Identifiers and marker are generated hex, never supplied by a user or request.
            sql(f"""CREATE FUNCTION {name}() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.app = 'deck' AND NEW.subject = 'card_create'
    AND position('{title}' in NEW.subjectparams) > 0 THEN
   RAISE EXCEPTION 'isolated Cognos activity fault';
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER {name} BEFORE INSERT ON oc_activity
FOR EACH ROW EXECUTE FUNCTION {name}();""")
            request_id = str(uuid.uuid4())
            failed = alice.post('/ocs/v2.php/apps/cognos/api/v1.0/cards', params={'format': 'json'},
                json={'requestId': request_id, 'title': title, 'stackId': stack['id'], 'boardId': board['id']})
            report['http_status'] = failed.status_code
            assert failed.status_code == 500
            report['checks'].append('forced creation-history failure is reported as failure')
        with client_for(state['alice']['user'], state['alice']['password']) as fresh:
            stacks = request(fresh, 'GET', bp + '/stacks').json()
            assert not any(c['title'] == title for s in stacks for c in (s.get('cards') or []))
            report['persisted_matching_cards'] = 0
            report['checks'].append('fresh client sees no task after history write failed')
        history_count = int(sql(f"SELECT count(*) FROM oc_activity WHERE app='deck' AND position('{title}' in subjectparams)>0;"))
        assert history_count == 0
        report['persisted_matching_activity_rows'] = history_count
        report['checks'].append('no partial creation activity remains')
        request_rows = int(sql(f"SELECT count(*) FROM oc_cognos_task_requests WHERE request_id='{request_id}';"))
        assert request_rows == 0
        report['persisted_matching_request_rows'] = request_rows
        report['checks'].append('failed history transaction also removes the recovery reservation')
        report['result'] = 'passed'
    except Exception as exc:
        report['result'] = 'failed'
        report['failure_type'] = type(exc).__name__
        raise
    finally:
        # Cleanup runs even if setup or the HTTP assertions fail.
        sql(f'DROP TRIGGER IF EXISTS {name} ON oc_activity; DROP FUNCTION IF EXISTS {name}();')
        remaining = int(sql(f"SELECT count(*) FROM pg_trigger WHERE tgname='{name}';"))
        report['fault_hook_removed'] = remaining == 0
        output = ROOT / 'evidence'
        output.mkdir(parents=True, exist_ok=True)
        (output / '任务创建-历史故障回滚.json').write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
    print(json.dumps({'result': report['result'], 'checks': len(report['checks']),
        'fault_hook_removed': report['fault_hook_removed']}, ensure_ascii=False))


if __name__ == '__main__':
    try:
        run()
    except Exception as exc:
        raise SystemExit('History-fault probe failed: ' + type(exc).__name__)
