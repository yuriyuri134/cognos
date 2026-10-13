"""Live concurrent creation/replay checks against the isolated Cognos OCS endpoint."""
import argparse
import concurrent.futures
import json
import secrets
import threading
import uuid
from check_tasks import ROOT, DECK, client_for, request

ENDPOINT = '/ocs/v2.php/apps/cognos/api/v1.0/cards'


def post(client, payload, status=200):
    response = request(client, 'POST', ENDPOINT, allowed=(status,), params={'format': 'json'}, json=payload)
    if status == 200:
        assert int(response.json()['ocs']['meta']['statuscode']) in (100, 200)
    return response


def run(verify=False):
    state = json.loads((ROOT / '.secrets/task-journey.json').read_text())
    report = {'scope': 'live Cognos create requests; not all mutations or independent intents',
              'checks': [], 'full_journey': 'unverified'}
    try:
        if verify:
            saved = json.loads((ROOT / 'evidence/idempotency-restart-case.json').read_text())
            with client_for(**{'user': state['alice']['user'], 'password': state['alice']['password']}) as alice:
                response = post(alice, saved['payload'])
                assert response.json()['ocs']['data']['id'] == saved['card_id']
                assert response.headers.get('X-Cognos-Replayed') == 'true'
            report['checks'].append('saved request returns the original card and replay header in a fresh process')
            report['card_id'] = saved['card_id']
        else:
            a, b = state['alice'], state['bob']
            nonce = secrets.token_hex(5)
            with client_for(a['user'], a['password']) as alice:
                board = request(alice, 'POST', DECK + '/boards', json={'title': 'Idempotency ' + nonce, 'color': '317CCC'}).json()
                bp = f"{DECK}/boards/{board['id']}"
                acl = request(alice, 'POST', bp + '/acl', json={'type': 0, 'participant': b['user'],
                    'permissionEdit': True, 'permissionShare': False, 'permissionManage': False}).json()
                stack = request(alice, 'POST', bp + '/stacks', json={'title': '待处理', 'order': 1}).json()
            payload = {'requestId': str(uuid.uuid4()), 'title': 'Concurrent task ' + nonce,
                'stackId': stack['id'], 'boardId': board['id'], 'description': 'Original input',
                'users': [{'id': b['user'], 'type': 0}]}
            barrier = threading.Barrier(6)
            def submit():
                with client_for(a['user'], a['password']) as client:
                    barrier.wait(timeout=15)
                    response = post(client, payload)
                    return response.json()['ocs']['data']['id'], response.headers.get('X-Cognos-Replayed')
            with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
                responses = list(pool.map(lambda _: submit(), range(6)))
            ids = {item[0] for item in responses}
            assert len(ids) == 1
            card_id = next(iter(ids))
            assert sum(item[1] == 'true' for item in responses) == 5
            report['checks'].append('six simultaneous clients receive one card ID; five are marked replayed')
            cp = f"{bp}/stacks/{stack['id']}/cards/{card_id}"
            with client_for(a['user'], a['password']) as alice:
                all_cards = [c for s in request(alice, 'GET', bp + '/stacks').json() for c in (s.get('cards') or [])]
                assert sum(c['title'] == payload['title'] for c in all_cards) == 1
                report['checks'].append('server stores exactly one task for the concurrent operation')
                post(alice, {**payload, 'title': 'Different input ' + nonce}, 409)
                original = request(alice, 'GET', cp).json()
                assert original['title'] == payload['title']
                report['checks'].append('reusing the request ID with changed content returns 409 and leaves the task unchanged')
                updated = {**original, 'description': 'Changed after creation', 'owner': a['user']}
                request(alice, 'PUT', cp, json=updated)
                replayed = post(alice, payload).json()['ocs']['data']
                assert replayed['id'] == card_id and replayed['description'] == updated['description']
                report['checks'].append('replay returns current task state and does not overwrite later editing')
                failed_payload = {**payload, 'requestId': str(uuid.uuid4()), 'title': 'Retry after rejection ' + nonce,
                    'users': [{'id': state['outsider']['user'], 'type': 0}]}
                post(alice, failed_payload, 400)
                recovered = post(alice, {**failed_payload, 'users': []})
                assert recovered.json()['ocs']['data']['id'] != card_id
                report['checks'].append('rolled-back rejection does not reserve the key; corrected input can succeed')
                post(alice, {k: v for k, v in payload.items() if k != 'requestId'}, 400)
                report['checks'].append('missing operation ID is rejected rather than silently creating without recovery')
            with client_for(b['user'], b['password']) as bob:
                other = post(bob, {**payload, 'title': 'Independent Bob task ' + nonce})
                bob_card = other.json()['ocs']['data']['id']
                assert bob_card != card_id
            report['checks'].append('the same request ID belongs independently to each authenticated account')
            with client_for(a['user'], a['password']) as alice:
                request(alice, 'DELETE', bp + f"/acl/{acl['id']}")
            with client_for(b['user'], b['password']) as bob:
                post(bob, {**payload, 'title': 'Independent Bob task ' + nonce}, 403)
            report['checks'].append('removed member cannot recover an old response or create via replay')
            (ROOT / 'evidence/idempotency-restart-case.json').write_text(
                json.dumps({'payload': payload, 'card_id': card_id}, ensure_ascii=False, indent=2), encoding='utf-8')
            report['objects'] = {'board': board['id'], 'stack': stack['id'], 'card': card_id, 'bob_card': bob_card}
            report['concurrent_http_200'] = len(responses)
            report['replay_responses'] = 5
        report['result'] = 'passed'
    except Exception as exc:
        report['result'] = 'failed'
        report['failure_type'] = type(exc).__name__
        raise
    finally:
        filename = '任务幂等-重启验收.json' if verify else '任务幂等-并发验收.json'
        (ROOT / 'evidence' / filename).write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
    print(json.dumps({'result': report['result'], 'checks': len(report['checks'])}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--verify', action='store_true')
    args = parser.parse_args()
    try:
        run(args.verify)
    except Exception as exc:
        raise SystemExit('Idempotency check failed: ' + type(exc).__name__)
