"""Live checks on 8780 that a run's result comes back exactly once.

Uses the trial's own cognos-admin (password from .secrets/admin-password) and a throwaway creation space.
For the duration of the check, claude_bridge_url points at a stand-in bridge started here, so no real Claude Code
runs; the original value is put back afterwards (also on failure). The web side caches app settings, so a change
made with occ is not seen there right away and is not dropped either: the check therefore restarts the app container
before and after, so it starts from the real settings and leaves no stand-in address behind. Prints a JSON report.

What it checks:
- sending a message records a run before the bridge is called, and the bridge receives its run_id;
- a result signed by someone other than the registered participant is refused;
- the same result_id posted twice gives one message (second answer is the first one, marked duplicate);
- a second result_id for the same run is refused;
- depends_on is stored with the run;
- a run reported half-done (bridge restart) shows unknown, and its result is still accepted later;
- a run whose lease runs out (its lease set far in the past in the database; the Docker clock is a few seconds off the host's) shows unknown instead of looking unstarted.
"""
import json
import secrets
import subprocess
import threading
import time
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from check_tasks import ROOT, client_for, request

APP = '/index.php/apps/cognos'
OCS = '/ocs/v2.php/apps/cognos/api/v1.0'
CONTAINER = 'cognos-nextcloud-trial-app-1'
JOBS = []


class StandIn(BaseHTTPRequestHandler):
    def do_POST(self):  # noqa: N802
        body = json.loads(self.rfile.read(int(self.headers.get('Content-Length', '0'))).decode('utf-8'))
        JOBS.append(body)
        data = json.dumps({'accepted': True}).encode('utf-8')
        self.send_response(202)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, *args):
        pass


def occ(*args):
    result = subprocess.run(['docker', 'exec', '-u', 'www-data', CONTAINER, 'php', 'occ', *args],
                            capture_output=True, text=True, encoding='utf-8')
    return result.returncode, result.stdout.strip()


def restart_app():
    """Drop the web side's cached app settings: restart the app container and wait until 8780 answers again."""
    subprocess.run(['docker', 'restart', CONTAINER], capture_output=True, check=True)
    deadline = time.time() + 120
    while time.time() < deadline:
        try:
            with client_for('anonymous', '') as client:
                if client.get('/status.php').status_code == 200:
                    return
        except Exception:
            pass
        time.sleep(2)
    raise RuntimeError('8780 did not come back after restarting the app container')


def psql(sql):
    subprocess.run(['docker', 'exec', '-i', 'cognos-nextcloud-trial-db-1', 'sh', '-c',
                    'psql -v ON_ERROR_STOP=1 -q -U "$POSTGRES_USER" -d "$POSTGRES_DB"'],
                   input=sql, capture_output=True, text=True, encoding='utf-8', check=True)


def ocs(client, method, path, allowed=(200, 201), **kwargs):
    response = request(client, method, OCS + path, allowed=allowed, params={'format': 'json'}, **kwargs)
    return response.status_code, response.json()['ocs']['data']


def send(client, creation_id, text):
    before = len(JOBS)
    request(client, 'POST', f'{APP}/creations/{creation_id}/messages', json={'message': text})
    deadline = time.time() + 10
    while len(JOBS) == before and time.time() < deadline:
        time.sleep(0.1)
    assert len(JOBS) == before + 1, 'the stand-in bridge was not called'
    return JOBS[-1]


def result(client, creation_id, job, result_id, subject=None, allowed=(200, 201), **extra):
    return ocs(client, 'POST', f'/spaces/creation/{creation_id}/messages', allowed=allowed, json={
        'run_id': job['run_id'], 'result_id': result_id, 'subject': subject or job['subject'],
        'content': extra.pop('content', '检查用的回答'), 'reply_to': job.get('reply_to'), **extra})


def assistant_messages(client, creation_id):
    messages = request(client, 'GET', f'{APP}/creations/{creation_id}/messages').json()['messages']
    return [m for m in messages if m['role'] == 'assistant']


def run():
    password = (ROOT / '.secrets/admin-password').read_text(encoding='utf-8').strip()
    report = {'scope': 'live 8780 with a stand-in bridge; no real Claude Code run', 'checks': []}
    server = ThreadingHTTPServer(('127.0.0.1', 0), StandIn)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    port = server.server_address[1]
    code, original_url = occ('config:app:get', 'cognos', 'claude_bridge_url')
    _, agent = occ('config:app:get', 'cognos', 'creation_agent')
    assert agent == 'claude-code', 'creation_agent is not claude-code; the bridge path is not in use'
    try:
        occ('config:app:set', 'cognos', 'claude_bridge_url', '--value', f'http://host.docker.internal:{port}/chat')
        restart_app()
        with client_for('cognos-admin', password) as admin:
            nonce = secrets.token_hex(4)
            creation = request(admin, 'POST', f'{APP}/creations/data', allowed=(201,),
                               json={'title': f'检查：运行回得来 {nonce}'}).json()['creation']
            cid = creation['id']
            report['creation_id'] = cid

            job = send(admin, cid, '检查一')
            assert len(job['run_id']) == 32
            _, data = ocs(admin, 'GET', f"/runs/{job['run_id']}")
            run1 = data['run']
            assert run1['state'] == 'running' and run1['lease_until'], run1
            assert run1['target_type'] == 'conversation' and run1['target_id'] == f'creation:{cid}', run1
            report['checks'].append('message records a running run (target conversation) and the bridge gets its run_id')

            status, _ = result(admin, cid, job, uuid.uuid4().hex, subject='别的 agent', allowed=(403,))
            assert status == 403
            assert assistant_messages(admin, cid) == []
            report['checks'].append('result signed by an unregistered participant is refused (403), nothing stored')

            rid = uuid.uuid4().hex
            deps = [{'type': 'trace', 'id': '1', 'version': None, 'note': '检查用的依据'}]
            status1, first = result(admin, cid, job, rid, depends_on=deps)
            status2, second = result(admin, cid, job, rid, depends_on=deps)
            assert (status1, first['duplicate']) == (201, False), (status1, first)
            assert (status2, second['duplicate']) == (200, True), (status2, second)
            assert first['message']['id'] == second['message']['id']
            assert len(assistant_messages(admin, cid)) == 1
            _, data = ocs(admin, 'GET', f"/runs/{job['run_id']}")
            assert data['run']['state'] == 'delivered', data
            assert data['depends_on'] == [{'type': 'trace', 'id': '1', 'version': None, 'note': '检查用的依据'}], data
            report['checks'].append('same result_id twice: one message, second reply marked duplicate; run delivered; depends_on stored')

            status, _ = result(admin, cid, job, uuid.uuid4().hex, allowed=(409,))
            assert status == 409 and len(assistant_messages(admin, cid)) == 1
            report['checks'].append('a second, different result for the same run is refused (409)')

            job2 = send(admin, cid, '检查二')
            _, data = ocs(admin, 'POST', f"/runs/{job2['run_id']}/unknown", json={'reason': '检查：桥重启'})
            assert data['changed'] and data['run']['state'] == 'unknown', data
            status, late = result(admin, cid, job2, uuid.uuid4().hex, content='晚到的回答')
            assert status == 201 and late['run']['state'] == 'delivered', late
            assert len(assistant_messages(admin, cid)) == 2
            report['checks'].append('run reported half-done shows unknown; its late result is still accepted once')

            # 租约到期：把这一次检查自己的运行的租约挪到很早以前（Docker 里的钟和本机差几秒，不能用「一秒前」）
            job3 = send(admin, cid, '检查三')
            psql(f"UPDATE oc_cognos_runs SET lease_until = 1 WHERE id = '{job3['run_id']}' AND state = 'running';")
            _, data = ocs(admin, 'GET', f"/runs/{job3['run_id']}")
            assert data['run']['state'] == 'unknown', data
            _, listing = ocs(admin, 'GET', f'/spaces/creation/{cid}/runs')
            states = {r['id']: r['state'] for r in listing['runs']}
            assert states == {job['run_id']: 'delivered', job2['run_id']: 'delivered', job3['run_id']: 'unknown'}, states
            report['checks'].append('run past its lease shows unknown, not unstarted; space run list matches')
    finally:
        if code == 0:
            occ('config:app:set', 'cognos', 'claude_bridge_url', '--value', original_url)
        else:
            occ('config:app:delete', 'cognos', 'claude_bridge_url')
        restart_app()
        server.shutdown()
    _, restored = occ('config:app:get', 'cognos', 'claude_bridge_url')
    report['bridge_url_restored'] = restored == original_url if code == 0 else restored == ''
    report['passed'] = True
    return report


if __name__ == '__main__':
    print(json.dumps(run(), ensure_ascii=False, indent=2))
