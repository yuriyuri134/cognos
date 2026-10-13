"""Reproduce a partial Deck create using an invalid assignee, without SQL faults."""
import json
import secrets
import uuid
import sys
from datetime import datetime, timezone
from pathlib import Path

TRIAL = Path(r'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial')
sys.path.insert(0, str(TRIAL))
from check_tasks import ROOT, DECK, client_for, request


def run():
    fixed = '--native' not in sys.argv
    state = json.loads((ROOT / '.secrets/task-journey.json').read_text())
    report = {'at': datetime.now(timezone.utc).isoformat(),
              'scope': 'live ' + ('Cognos transactional' if fixed else 'native Deck') + ' create with invalid related assignee',
              'checks': [], 'phase': 'setup', 'full_journey': 'unverified'}
    try:
        with client_for(state['alice']['user'], state['alice']['password']) as alice:
            nonce = secrets.token_hex(6)
            board = request(alice, 'POST', DECK + '/boards', json={
                'title': 'Atomicity trial ' + nonce, 'color': '317CCC'}).json()
            bp = f"{DECK}/boards/{board['id']}"
            if fixed:
                request(alice, 'POST', bp + '/acl', json={'type': 0, 'participant': state['bob']['user'],
                    'permissionEdit': True, 'permissionShare': False, 'permissionManage': False})
                label = request(alice, 'POST', bp + '/labels', json={'title': '来源资料', 'color': '0082C9'}).json()
            stack = request(alice, 'POST', bp + '/stacks', json={
                'title': '待处理', 'order': 1}).json()
            cp = f"{bp}/stacks/{stack['id']}/cards"
            title = 'Rejected create ' + nonce
            report['trial_marker'] = nonce
            report['objects'] = {'board': board['id'], 'stack': stack['id']}
            report['phase'] = 'invalid related user'
            destination = '/ocs/v2.php/apps/cognos/api/v1.0/cards' if fixed else cp
            failed = alice.post(destination, params={'format': 'json'}, json={
                'requestId': str(uuid.uuid4()), 'title': title, 'type': 'plain', 'order': 1, 'stackId': stack['id'], 'boardId': board['id'],
                'users': ([{'id': state['bob']['user'], 'type': 0}] if fixed else [])
                    + [{'id': state['outsider']['user'], 'type': 0}]})
            report['http_status'] = failed.status_code
            assert failed.status_code == 400
            report['checks'].append('non-board assignee rejected with HTTP 400')
        # New client proves this is stored server state, not a stale request object.
        with client_for(state['alice']['user'], state['alice']['password']) as fresh:
            stacks = request(fresh, 'GET', bp + '/stacks').json()
            cards = [c for s in stacks for c in (s.get('cards') or []) if c['title'] == title]
            report['persisted_matching_cards'] = len(cards)
            report['card_ids'] = [c['id'] for c in cards]
            report['assigned_user_counts'] = [len(c.get('assignedUsers') or []) for c in cards]
            report['atomicity_result'] = 'passed' if len(cards) == 0 else 'failed'
            report['observation'] = ('request rejected; no task remains' if not cards else
                'request rejected after task creation; task remains without requested assignment')
            report['history_atomicity'] = 'unverified by this probe'
            if fixed:
                assert not cards
                valid = request(fresh, 'POST', destination, params={'format': 'json'}, json={
                    'requestId': str(uuid.uuid4()), 'title': 'Successful create ' + nonce, 'stackId': stack['id'], 'boardId': board['id'],
                    'labels': [{'id': label['id']}],
                    'users': [{'id': state['bob']['user'], 'type': 0}]})
                assert int(valid.json()['ocs']['meta']['statuscode']) in (100, 200)
                report['successful_card_id'] = valid.json()['ocs']['data']['id']
                assigned = valid.json()['ocs']['data']['assignedUsers']
                assert any(item['id'] == label['id'] for item in valid.json()['ocs']['data']['labels'])
                assert any(a['participant']['uid'] == state['bob']['user'] for a in assigned)
                with client_for(state['bob']['user'], state['bob']['password']) as bob:
                    saved = request(bob, 'GET', cp + '/' + str(report['successful_card_id'])).json()
                    assert any(a['participant']['uid'] == state['bob']['user'] for a in saved['assignedUsers'])
                report['checks'].append('successful transactional create assigns Bob; a fresh Bob client reads the same task')
                with client_for(state['outsider']['user'], state['outsider']['password']) as outsider:
                    denied = outsider.post(destination, params={'format': 'json'}, json={
                        'requestId': str(uuid.uuid4()), 'title': 'Denied create ' + nonce, 'stackId': stack['id'], 'boardId': board['id']})
                    assert denied.status_code in (403, 404)
                report['checks'].append('non-member cannot create through Cognos endpoint')
        report['probe_result'] = 'completed'
    except Exception as exc:
        report['probe_result'] = 'failed'
        report['failure_type'] = type(exc).__name__
        raise
    finally:
        output = ROOT / 'evidence'
        output.mkdir(parents=True, exist_ok=True)
        (output / ('任务创建-事务修复验收.json' if fixed else '任务创建-原子性复现.json')).write_text(
            json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
    print(json.dumps({k: report[k] for k in
        ('http_status', 'persisted_matching_cards', 'atomicity_result', 'probe_result')}, ensure_ascii=False))


if __name__ == '__main__':
    try:
        run()
    except Exception as exc:
        raise SystemExit('Atomicity probe failed: ' + type(exc).__name__)
