/* Live checks on 8780 for the project-page tails: the bridge carries the agent's own basis back (note_basis),
 * and the project overview lists every place's state and what each returned result reported as its basis.
 * Uses the trial's own cognos-admin (password read from .secrets, never printed). Report in evidence/tails-api.json.
 */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const root = __dirname, app = 'http://127.0.0.1:8780/index.php/apps/cognos', ocs = 'http://127.0.0.1:8780/ocs/v2.php/apps/cognos/api/v1.0';
const admin = {user: 'cognos-admin', password: fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim()};
const evidence = path.join(root, 'evidence/tails-api.json');
const checks = []; const report = {at: new Date().toISOString(), checks};
const id = () => crypto.randomUUID();
const sleep = ms => new Promise(r => setTimeout(r, ms));
async function call(url, method, data, expected) {
  const r = await fetch(url, {method, redirect: 'manual', headers: {
    Authorization: 'Basic ' + Buffer.from(admin.user + ':' + admin.password).toString('base64'),
    'OCS-APIRequest': 'true', Accept: 'application/json', ...(data ? {'Content-Type': 'application/json'} : {})
  }, body: data ? JSON.stringify(data) : undefined, signal: AbortSignal.timeout(60000)});
  let body; try { body = await r.json(); } catch { throw Error(method + ' ' + url + ': non-JSON HTTP ' + r.status); }
  const payload = body.ocs ? body.ocs.data : body;
  assert.equal(r.status, expected, method + ' ' + url + ': ' + (payload.message || 'HTTP ' + r.status));
  return payload;
}
const req = (route, method = 'GET', data, expected = 200) => call(app + route, method, data, expected);
const api = (route, method = 'GET', data, expected = 200) => call(ocs + route + (route.includes('?') ? '&' : '?') + 'format=json', method, data, expected);

async function main() {
  const suffix = new Date().toISOString().replace(/[:.]/g, '-');
  const p = await req('/projects/data', 'POST', {title: '验证：项目总览和依据 ' + suffix, request_id: id()});
  const child = await req('/projects/' + p.project_id + '/locations', 'POST', {parent_id: p.location_id, title: '入口怎么排', request_id: id()});
  const loc = (await req('/projects/locations/' + child.location_id + '/data')).location;
  await req('/projects/locations/' + child.location_id + '/content', 'PUT',
    {expected_revision: 1, content: '入口里先放在生效的决定，再放这一处的当前内容。', source_message_ids: [], request_id: id()});
  const d = await req('/projects/' + p.project_id + '/decisions', 'POST',
    {content: '入口里不放固定的开头和结尾。', scope: 'project', request_id: id()});
  Object.assign(report, {project_id: p.project_id, location_id: child.location_id, creation_id: loc.creation_id, decision_id: d.decision_id});

  await api('/spaces/creation/' + loc.creation_id + '/basis', 'POST', {subject: 'Claude Code', revision: 2}, 409);
  checks.push('basis can only be noted while one is working here');

  const message = '这是一次只读检查。请用两三句话说说：按这一处的当前内容和在生效的决定，入口最前面该放什么？'
    + '如果你的回答确实依据了哪条决定或当前内容的哪一版，可以用 note_basis 记下；没有就不用。不要编辑文件，也不要挂起后续工作。';
  await req('/creations/' + loc.creation_id + '/messages', 'POST', {message});
  await api('/spaces/creation/' + loc.creation_id + '/basis', 'POST', {subject: 'Claude Code', decision_quote: '这句话不在任何决定里'}, 422);
  await api('/spaces/creation/' + loc.creation_id + '/basis', 'POST', {subject: 'Claude Code', revision: 9}, 422);
  checks.push('a quote that matches no applicable decision, or a revision that does not exist, is refused');
  let result; const deadline = Date.now() + 300000;
  do {
    result = await req('/creations/' + loc.creation_id + '/messages');
    if (result.runs.length && !['queued', 'claimed', 'running'].includes(result.runs[0].state)) break;
    await sleep(3000);
  } while (Date.now() < deadline);
  const run = result.runs[0];
  assert.equal(run.state, 'delivered');
  const deps = (await api('/runs/' + run.id)).depends_on;
  report.agent = {run: run.id, depends_on: deps, reply: (result.messages.filter(m => m.role === 'assistant').at(-1) || {}).content?.slice(0, 400)};
  const o = await req('/projects/' + p.project_id + '/overview');
  const recent = o.activity.recent.find(r => r.run_id === run.id);
  assert(recent, 'returned run listed under recent results');
  assert.deepEqual(recent.basis.map(b => b.type + ':' + b.id + ':' + b.version), deps.map(b => b.type + ':' + b.id + ':' + b.version));
  const reach = o.decisions.find(x => x.id === d.decision_id).progress.find(x => x.subject === 'Claude Code');
  assert(reach && reach.included_at, 'the decision was in its input');
  if (deps.some(b => b.type === 'decision' && b.id === d.decision_id)) {
    assert(reach.depended_at, 'decision basis shows as "回应里依据了它"');
    checks.push('real Claude Code run noted the decision as its basis through the bridge; the decision shows "进了输入" and "回应里依据了它"');
  } else {
    checks.push('real Claude Code run did not note the decision (allowed); overview shows what it did report: ' + JSON.stringify(deps));
  }
  const states = Object.fromEntries(o.locations.map(l => [l.id, l]));
  assert.equal(states[child.location_id].revision, 2); assert.equal(states[p.location_id].revision, 1);
  assert.equal(o.handoff_text, undefined, 'project overview carries no location-specific entry text');
  checks.push('project overview (no location) returns each place\'s state and the basis of recent results');
  report.result = 'pass';
}
main().catch(e => {report.result = 'fail'; report.error = e.message; process.exitCode = 1;}).finally(() => {
  fs.mkdirSync(path.dirname(evidence), {recursive: true}); fs.writeFileSync(evidence, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
});
