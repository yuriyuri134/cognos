/* Live checks on 8780 for adoption by an AI inside a delegation (V13's software part) and correction going one level further.
 * The in-scope adoption is done by the real local Claude Code behind the bridge; the out-of-scope ones by a participant that
 * registered itself through the agent path (03-系统/接入一条路.md). Uses cognos-admin (password read from .secrets, never printed).
 * Report in evidence/delegated-adoption-api.json.
 */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const root = __dirname, app = 'http://127.0.0.1:8780/index.php/apps/cognos', ocs = 'http://127.0.0.1:8780/ocs/v2.php/apps/cognos/api/v1.0';
const admin = {user: 'cognos-admin', password: fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim()};
const evidence = path.join(root, 'evidence/delegated-adoption-api.json');
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
const OTHER = '检查用的参与者';

async function main() {
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const p = await req('/projects/data', 'POST', {title: '验证：委托内采用 ' + stamp, request_id: id()});
  const L = (await req('/projects/' + p.project_id + '/locations', 'POST', {parent_id: p.location_id, title: '入口定稿', request_id: id()})).location_id;
  const M = (await req('/projects/' + p.project_id + '/locations', 'POST', {parent_id: p.location_id, title: '旁边一处', request_id: id()})).location_id;
  await req('/projects/locations/' + L + '/content', 'PUT', {expected_revision: 1, source_message_ids: [], request_id: id(),
    content: '入口：先列适用的在生效的决定，再给这一处的当前内容。没有决定时照实写一句。'});
  const chat = async loc => String((await req('/projects/locations/' + loc + '/data')).location.creation_id);
  const lChat = await chat(L), mChat = await chat(M);
  Object.assign(report, {project_id: p.project_id, L, M});

  // 1. 交出去，允许在这里采用：真实的本机 Claude Code
  const dg = await req('/projects/locations/' + L + '/delegations', 'POST', {may_adopt: true, return_minutes: 30, request_id: id(),
    ask: '看一下这一处当前稿（版本 2）。如果你觉得它可以作为这一处现在的定稿，用 adopt 在这一处采用它：object_type 是 location_content，object_id 是 ' + L + '，object_version 是 2，basis 写一句理由。不合适就说明为什么，不采用。'});
  assert.equal(dg.state, 'sent', dg.note);
  let d; const deadline = Date.now() + 420000;
  do { d = (await req('/projects/' + p.project_id + '/overview?location_id=' + L)).delegations.find(x => x.id === dg.delegation_id); if (d.state === 'returned') break; await sleep(4000); } while (Date.now() < deadline);
  assert.equal(d.state, 'returned', 'the hand-off came back');
  let l = await req('/projects/locations/' + L + '/adoptions');
  const a1 = l.adoptions.find(a => a.object_id === L && a.object_version === '2');
  report.agent_answer = (await req('/creations/' + lChat + '/messages')).messages.filter(m => m.role === 'assistant').at(-1).content.slice(0, 500);
  assert(a1, 'Claude Code adopted revision 2 inside the delegation: ' + report.agent_answer);
  assert.equal(a1.state, 'active'); assert.equal(a1.adopted_by, 'Claude Code');
  assert.equal(a1.authority, 'delegation:' + dg.delegation_id); assert.equal(a1.source_message_id, dg.message_id);
  checks.push('inside a delegation that allows adopting here, the real Claude Code adopted revision 2 itself: adopter is Claude Code, authority is the delegation, and the user\'s original words of the hand-off are linked');

  // 2. 不在范围内：没有交给它采用的事，只能提出
  const r1 = (await api('/spaces/creation/' + lChat + '/runs', 'POST', {subject: OTHER, purpose: '看看能不能定下来'}, 201)).run;
  const p1 = await api('/spaces/creation/' + lChat + '/adoptions', 'POST', {subject: OTHER, object_type: 'location_content', object_id: L, object_version: '2'}, 409);
  const answerId = String((await req('/creations/' + lChat + '/messages')).messages.find(m => m.role === 'assistant').id);
  const prop1 = await api('/spaces/creation/' + lChat + '/adoptions', 'POST', {subject: OTHER, object_type: 'message', object_id: answerId, basis: '这段回答说得对'}, 201);
  assert.equal(prop1.state, 'proposed');
  await api('/spaces/creation/' + lChat + '/messages', 'POST', {subject: OTHER, run_id: r1.id, result_id: crypto.randomUUID().replace(/-/g, ''), content: '检查用的结果：入口这样排可以。'}, 201);
  checks.push('a participant without an adopting delegation can only propose (state proposed); adopting what is already adopted here is refused');

  // 当前稿第 3 版（在采用之后写的），旁边一处的参与者提议采用它，用户接受；另一条提议不采用
  await req('/projects/locations/' + L + '/content', 'PUT', {expected_revision: 2, source_message_ids: [], request_id: id(),
    content: '入口：先列适用的在生效的决定，再给这一处的当前内容。没有决定时照实写一句。决定很多时只列一行一条。'});
  const r2 = (await api('/spaces/creation/' + mChat + '/runs', 'POST', {subject: OTHER, purpose: '旁边一处看定稿'}, 201)).run;
  const prop2 = await api('/spaces/creation/' + mChat + '/adoptions', 'POST', {subject: OTHER, object_type: 'location_content', object_id: L, object_version: '3'}, 201);
  assert.equal(prop2.state, 'proposed');
  await api('/spaces/creation/' + mChat + '/messages', 'POST', {subject: OTHER, run_id: r2.id, result_id: crypto.randomUUID().replace(/-/g, ''), content: '提议旁边一处也按入口定稿第 3 版来。'}, 201);
  await req('/projects/adoptions/' + prop2.adoption_id + '/accept', 'POST', {request_id: id()});
  await req('/projects/adoptions/' + prop1.adoption_id + '/decline', 'POST', {request_id: id()});
  const m = await req('/projects/locations/' + M + '/adoptions');
  const accepted = m.adoptions.find(a => a.id === prop2.adoption_id);
  assert.equal(accepted.state, 'active'); assert.equal(accepted.adopted_by, 'cognos-admin'); assert.equal(accepted.proposed_by, OTHER);
  l = await req('/projects/locations/' + L + '/adoptions');
  assert.equal(l.adoptions.find(a => a.id === prop1.adoption_id).state, 'declined');
  checks.push('the user accepts one proposal (adopter becomes the user, proposer kept) and declines the other (kept as declined)');

  // 3. 纠正第 2 版：第 1 层待复核，第 2 层（采用期间交回的结果、之后写的当前稿）待复核，第 3 层只提示
  const c = await req('/projects/corrections', 'POST', {object_type: 'location_content', object_id: L, object_version: '2',
    content: '「没有决定时照实写一句」不该写在入口规则里，那是渲染细节。', request_id: id()});
  report.correction = c;
  assert(c.review >= 1 && c.review_depth2 === 0 && c.hint_depth2 >= 2 && c.hint_depth3 >= 1, JSON.stringify(c));
  l = await req('/projects/locations/' + L + '/adoptions');
  assert.equal(l.adoptions.find(a => a.id === a1.id).state, 'review');
  const lv2 = l.impacts.filter(i => i.depth === 2);
  assert(lv2.every(i => i.effect === 'hint'), 'time correlation alone must not require review');
  assert(lv2.some(i => i.target_kind === 'run' && i.target_id === r1.id), 'the result handed back here while revision 2 was adopted is level 2');
  assert(lv2.some(i => i.target_kind === 'content' && i.note.includes('版本 3')), 'revision 3, written after adopting it, is level 2');
  assert(!lv2.some(i => i.target_id === d.run_id), 'the run that did the adopting started before it and is not swept in');
  const mImp = (await req('/projects/locations/' + M + '/adoptions')).impacts;
  assert(mImp.some(i => i.depth === 3 && i.effect === 'hint' && i.target_id === prop2.adoption_id), 'the place that adopted revision 3 only gets a level-3 hint');
  assert.equal((await req('/projects/locations/' + M + '/adoptions')).adoptions.find(a => a.id === prop2.adoption_id).state, 'active', 'and its adoption is unchanged');
  checks.push('correcting revision 2: level 1 (the adoption) requires review; level 2 only hints at temporal correlation; level 3 (another place that adopted revision 3) only gets a hint and nothing further is followed');
  report.result = 'pass';
}
main().catch(e => {report.result = 'fail'; report.error = e.message; process.exitCode = 1;}).finally(() => {
  fs.mkdirSync(path.dirname(evidence), {recursive: true}); fs.writeFileSync(evidence, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
});
