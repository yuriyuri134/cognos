/* Live checks on 8780 for "交出去" from a project location, with the real local Claude Code behind the bridge.
 * Uses the trial's own cognos-admin (password read from .secrets, never printed) and the trial outsider account.
 * Leaves a uniquely named project as inspectable evidence; report in evidence/delegate-api.json.
 *
 * 1. may edit: hand off "write pros/cons of the two entry schemes back into the draft" -> comes back, the draft
 *    gets a new revision authored by Claude Code, linked to the delegation.
 * 2. may edit, but the person edits the draft right after handing off -> nothing is overwritten; the AI's version
 *    is kept as a candidate to compare.
 * 3. answer only -> comes back as an answer; write_content is refused, the draft is unchanged.
 */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const root = __dirname, app = 'http://127.0.0.1:8780/index.php/apps/cognos', ocs = 'http://127.0.0.1:8780/ocs/v2.php/apps/cognos/api/v1.0';
const admin = {user: 'cognos-admin', password: fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim()};
const outsider = JSON.parse(fs.readFileSync(path.join(root, '.secrets/task-journey.json'), 'utf8')).outsider;
const evidence = path.join(root, 'evidence/delegate-api.json');
const checks = []; const report = {at: new Date().toISOString(), checks, runs: []};
const id = () => crypto.randomUUID();
const sleep = ms => new Promise(r => setTimeout(r, ms));
async function call(url, method, data, expected, actor) {
  const r = await fetch(url, {method, redirect: 'manual', headers: {
    Authorization: 'Basic ' + Buffer.from(actor.user + ':' + actor.password).toString('base64'),
    'OCS-APIRequest': 'true', Accept: 'application/json', ...(data ? {'Content-Type': 'application/json'} : {})
  }, body: data ? JSON.stringify(data) : undefined, signal: AbortSignal.timeout(60000)});
  let body; try { body = await r.json(); } catch { throw Error(method + ' ' + url + ': non-JSON HTTP ' + r.status); }
  const payload = body.ocs ? body.ocs.data : body;
  assert.equal(r.status, expected, method + ' ' + url + ': ' + (payload.message || 'HTTP ' + r.status));
  return payload;
}
const req = (route, method = 'GET', data, expected = 200, actor = admin) => call(app + route, method, data, expected, actor);
const api = (route, method = 'GET', data, expected = 200, actor = admin) => call(ocs + route + (route.includes('?') ? '&' : '?') + 'format=json', method, data, expected, actor);

const DRAFT = [
  '两种入口方案',
  '',
  '方案甲：进入一个位置时，先给适用的在生效的决定，再给这一处的当前内容和最近的变化。',
  '方案乙：进入一个位置时，先给这一处的当前内容，决定只在和要做的事有关时才列出。',
].join('\n');

async function overview(projectId, locationId) {
  return req('/projects/' + projectId + '/overview?location_id=' + locationId);
}
async function waitBack(projectId, locationId, delegationId) {
  const deadline = Date.now() + 420000;
  for (;;) {
    const o = await overview(projectId, locationId);
    const d = o.delegations.find(x => x.id === delegationId);
    if (d && (d.state === 'returned' || d.state === 'not_taken' || ['failed', 'unknown'].includes(d.run_state))) return {o, d};
    if (Date.now() > deadline) throw Error('delegation did not come back in 7 minutes: ' + JSON.stringify(d));
    await sleep(4000);
  }
}

async function main() {
  const suffix = new Date().toISOString().replace(/[:.]/g, '-');
  const p = await req('/projects/data', 'POST', {title: '验证：交出去 ' + suffix, request_id: id()});
  const loc = (await req('/projects/locations/' + p.location_id + '/data')).location;
  const saved = await req('/projects/locations/' + p.location_id + '/content', 'PUT',
    {expected_revision: loc.revision, content: DRAFT, source_message_ids: [], request_id: id()});
  Object.assign(report, {project_id: p.project_id, location_id: p.location_id, creation_id: loc.creation_id});
  assert.equal(saved.revision, 2);

  await req('/projects/locations/' + p.location_id + '/delegations', 'POST', {ask: 'x', request_id: id()}, 404, outsider);
  checks.push('another trial account cannot hand off from this place');

  // 1. 允许改内容
  const ask1 = {ask: '把当前稿里两种入口方案各写三条优缺点放回当前稿', goal: '当前稿里每个方案下面有优点三条、缺点三条',
    means: '只看当前稿', forbidden: '不要删掉原来对两个方案的描述', return_minutes: 30, may_edit: true, request_id: id()};
  const d1 = await req('/projects/locations/' + p.location_id + '/delegations', 'POST', ask1);
  assert.equal(d1.state, 'sent', 'bridge took the delegation: ' + d1.note);
  assert.deepEqual(await req('/projects/locations/' + p.location_id + '/delegations', 'POST', ask1), {...d1});
  let o = await overview(p.project_id, p.location_id);
  assert.equal(o.delegations.filter(x => x.ask === ask1.ask).length, 1, 'replaying the same request does not hand off twice');
  const running = o.activity.runs.find(r => r.location && r.location.id === p.location_id);
  assert(running && running.purpose.startsWith('交出去：'), 'the delegation shows under who is working on what');
  checks.push('hand-off recorded once (replay-safe), sent to the bridge, and visible under who-is-doing-what');
  const msgs = (await req('/creations/' + loc.creation_id + '/messages')).messages;
  const said = msgs.find(m => m.id === d1.message_id);
  assert(said && said.role === 'user' && said.content.includes(ask1.ask) && said.content.includes('不要碰：' + ask1.forbidden));
  let back = await waitBack(p.project_id, p.location_id, d1.delegation_id);
  report.runs.push({delegation: d1.delegation_id, state: back.d.state, run_state: back.d.run_state, applied: back.d.applied_revision, held: back.d.held_reason});
  assert.equal(back.d.state, 'returned');
  assert(back.d.result_message_id, 'the answer came back into this place');
  assert.equal(back.d.applied_revision, 3, 'the AI version was committed as revision 3: ' + JSON.stringify(back.d));
  const after1 = (await req('/projects/locations/' + p.location_id + '/data')).location;
  assert.equal(after1.revision, 3); assert.equal(after1.editor, 'Claude Code');
  assert(after1.content.includes('方案甲') && after1.content.includes('方案乙'), 'original descriptions kept');
  const rev3 = (await req('/projects/locations/' + p.location_id + '/history')).revisions.find(r => r.revision === 3);
  assert.equal(rev3.editor, 'Claude Code'); assert.equal(rev3.delegation_id, d1.delegation_id);
  report.revision3 = after1.content.slice(0, 1500);
  checks.push('may-edit hand-off came back: answer in this place; draft revision 3 authored by Claude Code and linked to the delegation; revision 2 kept');

  // 2. 交出去以后人先改了当前稿：不覆盖，留作待比较
  const ask2 = {ask: '在当前稿末尾加一段三句话的小结', return_minutes: 10, may_edit: true, request_id: id()};
  const d2 = await req('/projects/locations/' + p.location_id + '/delegations', 'POST', ask2);
  assert.equal(d2.state, 'sent', 'bridge took the delegation: ' + d2.note);
  const human = after1.content + '\n\n（人在交出去以后加的一句：先别下结论。）';
  const edited = await req('/projects/locations/' + p.location_id + '/content', 'PUT',
    {expected_revision: 3, content: human, source_message_ids: [], request_id: id()});
  assert.equal(edited.revision, 4);
  back = await waitBack(p.project_id, p.location_id, d2.delegation_id);
  report.runs.push({delegation: d2.delegation_id, state: back.d.state, run_state: back.d.run_state, applied: back.d.applied_revision, held: back.d.held_reason});
  assert.equal(back.d.state, 'returned');
  assert.equal(back.d.applied_revision, null, 'nothing applied over the person\'s edit');
  assert(back.d.held_content && back.d.held_base_revision === 3 && back.d.held_reason.includes('被改过'), 'AI version kept to compare: ' + JSON.stringify(back.d));
  const after2 = (await req('/projects/locations/' + p.location_id + '/data')).location;
  assert.equal(after2.revision, 4); assert.equal(after2.content, human); assert.equal(after2.editor, 'cognos-admin');
  checks.push('person edited after hand-off: no overwrite; the AI version is kept as a candidate based on revision 3; draft stays at the person\'s revision 4');

  // 3. 只要回答
  const d3 = await req('/projects/locations/' + p.location_id + '/delegations', 'POST',
    {ask: '只用两三句话说说：这一稿现在还缺什么？', return_minutes: 10, may_edit: false, request_id: id()});
  assert.equal(d3.state, 'sent', 'bridge took the delegation: ' + d3.note);
  await api('/spaces/creation/' + loc.creation_id + '/content', 'POST', {subject: 'Claude Code', content: '不该写进去'}, 403);
  back = await waitBack(p.project_id, p.location_id, d3.delegation_id);
  report.runs.push({delegation: d3.delegation_id, state: back.d.state, run_state: back.d.run_state, applied: back.d.applied_revision, held: back.d.held_reason});
  assert.equal(back.d.state, 'returned'); assert(back.d.result_message_id);
  assert.equal(back.d.applied_revision, null); assert.equal(back.d.held_content, null);
  assert.equal((await req('/projects/locations/' + p.location_id + '/data')).location.revision, 4);
  await api('/spaces/creation/' + loc.creation_id + '/content', 'POST', {subject: 'Claude Code', content: '不该写进去'}, 403);
  checks.push('answer-only hand-off: comes back as an answer; write_content refused during and after; draft unchanged');
  report.result = 'pass';
}
main().catch(e => {report.result = 'fail'; report.error = e.message; process.exitCode = 1;}).finally(() => {
  fs.mkdirSync(path.dirname(evidence), {recursive: true}); fs.writeFileSync(evidence, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
});
