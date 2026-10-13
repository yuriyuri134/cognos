"""Live identity and membership checks on a new isolated Deck board."""
import json
import secrets
import httpx
from check_tasks import ROOT, BASE, HEADERS, DECK, client_for, request, ocs


def run():
    state = json.loads((ROOT / '.secrets/task-journey.json').read_text())
    admin_password = (ROOT / '.secrets/admin-password').read_text().strip()
    report = {'scope': 'live Deck only, not all Nextcloud applications', 'checks': [], 'phase': 'setup'}
    renamed = False
    original_name = None
    try:
        with client_for('cognos-admin', admin_password) as admin:
            outsider_path = '/ocs/v1.php/cloud/users/' + state['outsider']['user']
            original_name = ocs(admin, 'GET', outsider_path)['displayname']
            bob_name = ocs(admin, 'GET', '/ocs/v1.php/cloud/users/' + state['bob']['user'])['displayname']
            report['phase'] = 'same display name'
            ocs(admin, 'PUT', outsider_path, data={'key': 'displayname', 'value': bob_name})
            renamed = True
            assert ocs(admin, 'GET', outsider_path)['displayname'] == bob_name
            with client_for(state['alice']['user'], state['alice']['password']) as alice:
                board = request(alice, 'POST', DECK + '/boards', json={
                    'title': 'Permission journey ' + secrets.token_hex(4), 'color': '317CCC'}).json()
                board_path = f"{DECK}/boards/{board['id']}"
                acl = request(alice, 'POST', board_path + '/acl', json={
                    'type': 0, 'participant': state['bob']['user'],
                    'permissionEdit': True, 'permissionShare': False, 'permissionManage': False}).json()
                stack = request(alice, 'POST', board_path + '/stacks', json={'title': '待处理', 'order': 1}).json()
                card = request(alice, 'POST', f"{board_path}/stacks/{stack['id']}/cards", json={
                    'title': 'Retain history after departure', 'type': 'plain', 'order': 1}).json()
                card_path = f"{board_path}/stacks/{stack['id']}/cards/{card['id']}"
                request(alice, 'PUT', card_path + '/assignUser', json={'userId': state['bob']['user']})
                report['objects'] = {'board': board['id'], 'stack': stack['id'], 'card': card['id']}
                with httpx.Client(base_url=BASE, headers=HEADERS, trust_env=False, timeout=15) as anonymous:
                    request(anonymous, 'GET', board_path, allowed=(401,))
                report['checks'].append('anonymous private board read returns 401')
                with client_for(state['outsider']['user'], state['outsider']['password']) as outsider:
                    request(outsider, 'GET', card_path, allowed=(403, 404))
                    request(outsider, 'PUT', card_path + '/assignUser', allowed=(403, 404),
                            json={'userId': state['outsider']['user']})
                    boards = request(outsider, 'GET', DECK + '/boards').json()
                    assert not any(item['id'] == board['id'] for item in boards)
                report['checks'].append('same display name does not grant board/card discovery or write permission')
                with client_for(state['bob']['user'], state['bob']['password']) as bob:
                    request(bob, 'GET', card_path)
                    request(bob, 'POST', board_path + '/acl', allowed=(403,), json={
                        'type': 0, 'participant': state['outsider']['user'],
                        'permissionEdit': True, 'permissionShare': True, 'permissionManage': True})
                report['checks'].append('editing member cannot reshare or grant management rights')
                report['phase'] = 'membership removed'
                request(alice, 'DELETE', board_path + f"/acl/{acl['id']}")
                with client_for(state['bob']['user'], state['bob']['password']) as departed:
                    request(departed, 'GET', card_path, allowed=(403, 404))
                    request(departed, 'PUT', card_path + '/assignUser', allowed=(403, 404),
                            json={'userId': state['bob']['user']})
                report['checks'].append('removed board member cannot read or write even after assignment')
                remaining = request(alice, 'GET', card_path).json()
                assert remaining['title'] == card['title']
                report['checks'].append('owner still reads the task after member removal')
            report['result'] = 'passed'
    except Exception as exc:
        report['result'] = 'failed'
        report['failure_type'] = type(exc).__name__
        raise
    finally:
        if renamed:
            with client_for('cognos-admin', admin_password) as admin:
                ocs(admin, 'PUT', outsider_path, data={'key': 'displayname', 'value': original_name})
            report['display_name_restored'] = True
        (ROOT / 'evidence/任务权限-自动验收.json').write_text(
            json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')


if __name__ == '__main__':
    try:
        run()
    except Exception as exc:
        raise SystemExit('Permission checks failed: ' + type(exc).__name__)
    print('Live Deck identity and departure checks passed; cross-app permissions remain unverified.')
