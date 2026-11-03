/* Live checks on 8780 for standing decisions and "who is working on what".
 * Uses the trial's own cognos-admin (password read from .secrets, never printed) and the trial outsider account.
 * Leaves uniquely named projects as inspectable evidence; report in evidence/decisions-api.json.
 * --agent: one real, read-only question to the configured bridge (local Claude Code); while it runs a second
 * decision is recorded, so the returned result must be marked "this decision did not exist when the run started".
 */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const root = __dirname, app = 'http://127.0.0.1:8780/index.php/apps/cognos', ocs = 'http://127.0.0.1:8780/ocs/v2.php/apps/cognos/api/v1.0';
const admin = {user: 'cognos-admin', password: fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim()};
const outsider = JSON.parse(fs.readFileSync(path.join(root, '.secrets/task-journey.json'), 'utf8')).outsider;
const evidence = path.join(root, 'evidence/decisions-api.json');
const checks = []; const report = {at: new Date().toISOString(), checks, agent: 'not invoked'};
const id = () => crypto.randomUUID();
const sleep = ms => new Promise(r => setTimeout(r, ms));
async function call(url, method, data, expected, actor) {
  const r = await fetch(url, {method, redirect: 'manual', headers: {
    Authorization: 'Basic ' + Buffer.from(actor.user + ':' + actor.password).toString('base64'),
    'OCS-APIRequest': 'true', Accept: 'application/json', ...(data ? {'Content-Type': 'application/json'} : {})
  }, body: data ? JSON.stringify(data) : undefined, signal: AbortSignal.timeout(45000)});
  let body; try { body = await r.json(); } catch { throw Error(method + ' ' + url + ': non-JSON HTTP ' + r.status); }
  const payload = body.ocs ? body.ocs.data : body;
  assert.equal(r.status, expected, method + ' ' + url + ': ' + (payload.message || 'HTTP ' + r.status));
  return payload;
}
const req = (route, method = 'GET', data, expected = 200, actor = admin) => call(app + route, method, data, expected, actor);
const api = (route, method = 'GET', data, expected = 200, actor = admin) => call(ocs + route + (route.includes('?') ? '&' : '?') + 'format=json', method, data, expected, actor);

async function main() {
  const suffix = new Date().toISOString().replace(/[:.]/g, '-');
  const p = await req('/projects/data', 'POST', {title: '验证：在生效的决定 ' + suffix, request_id: id()});
  const child = await req('/projects/' + p.project_id + '/locations', 'POST', {parent_id: p.location_id, title: '下面的一处', request_id: id()});
  const sibling = await req('/projects/' + p.project_id + '/locations', 'POST', {parent_id: p.location_id, title: '旁边的一处', request_id: id()});
  const rootChat = (await req('/projects/locations/' + p.location_id + '/data')).location.creation_id;
  const childChat = (await req('/projects/locations/' + child.location_id + '/data')).location.creation_id;
  Object.assign(report, {project_id: p.project_id, location_id: p.location_id, child_location_id: child.location_id, creation_id: rootChat});
  let o = await req('/projects/' + p.project_id + '/overview?location_id=' + p.location_id);
  assert.equal(o.decisions.length, 0); assert.equal(o.activity.runs.length, 0);
  assert(o.handoff_text.includes('这一处目前没有登记在生效的决定'));
  checks.push('new project: no decisions, nobody working, copyable entry text says so');

  const first = {content: '项目部分不再出新的总方案，在已有设计上改。', scope: 'project', source_note: '检查用：11/02 的决定', request_id: id()};
  const d1 = await req('/projects/' + p.project_id + '/decisions', 'POST', first);
  assert.deepEqual(await req('/projects/' + p.project_id + '/decisions', 'POST', first), d1);
  await req('/projects/' + p.project_id + '/decisions', 'POST', {...first, content: first.content + '（改）'}, 409);
  const d2 = await req('/projects/' + p.project_id + '/decisions', 'POST',
    {content: '这一处只比较，不下结论。', scope: 'here', location_id: child.location_id, request_id: id()});
  checks.push('recording replays identically; same request id with other content is refused');

  const textOf = async chat => (await api('/spaces/creation/' + chat + '/enter?subject=' + encodeURIComponent('检查'))).text;
  const childText = await textOf(childChat);
  assert(childText.startsWith('## 在生效的决定'), 'entry must start with the standing decisions');
  assert(childText.indexOf(first.content) > 0 && childText.indexOf('这一处只比较') > 0);
  assert(childText.indexOf('这一处只比较') < childText.indexOf('## 你在哪'));
  const rootText = await textOf(rootChat);
  assert(rootText.includes(first.content) && !rootText.includes('这一处只比较'), 'a decision scoped to one place stays out of other places');
  const siblingText = await textOf((await req('/projects/locations/' + sibling.location_id + '/data')).location.creation_id);
  assert(!siblingText.includes('这一处只比较'));
  checks.push('entry starts with the decisions that apply here (project-wide + this place), not those of other places');

  // AI 代录：只能指向用户说的那一句，原话要原样在里面
  // 原话放在另一段对话的开头（建对话时带的第一句是用户说的，不会叫 AI）
  const said = (await req('/creations/data', 'POST', {title: '验证：原话所在的对话 ' + suffix,
    initialMessage: '检查用的原话：以后这一处的结论都要附上反例。其余的随便。'}, 201)).creation;
  const userMsg = (await req('/creations/' + said.id + '/messages')).messages.find(m => m.role === 'user');
  assert(userMsg, 'a user message exists to point at');
  await api('/spaces/creation/' + childChat + '/decisions', 'POST', {subject: 'Claude Code', content: '以后这一处的结论都要附上反例。'}, 422);
  await api('/spaces/creation/' + childChat + '/decisions', 'POST', {subject: 'Claude Code', source_message_id: userMsg.id, content: '这一处的结论必须附反例'}, 422);
  const d3 = await api('/spaces/creation/' + childChat + '/decisions', 'POST',
    {subject: 'Claude Code', source_message_id: userMsg.id, content: '以后这一处的结论都要附上反例。', scope: 'here'}, 201);
  o = await req('/projects/' + p.project_id + '/overview');
  const proxied = o.decisions.find(d => d.id === d3.decision_id);
  assert.equal(proxied.original_author, 'cognos-admin'); assert.equal(proxied.recorded_by, 'Claude Code'); assert.equal(proxied.recorded_by_ai, true);
  assert.equal(proxied.source_message_id, userMsg.id);
  checks.push('an agent can record on the user\'s behalf only from the user\'s own sentence, verbatim; author stays the user, recorder is the agent');

  // 撤回、替代：旧的留着，入口里不再有
  await req('/projects/decisions/' + d2.decision_id + '/revoke', 'POST', {request_id: id()});
  const replaced = await req('/projects/' + p.project_id + '/decisions', 'POST',
    {content: '项目部分不再出新的总方案。', scope: 'project', supersedes: d1.decision_id, request_id: id()});
  o = await req('/projects/' + p.project_id + '/overview');
  const byId = Object.fromEntries(o.decisions.map(d => [d.id, d]));
  assert.equal(byId[d2.decision_id].status, 'revoked');
  assert.equal(byId[d1.decision_id].status, 'superseded'); assert.equal(byId[d1.decision_id].superseded_by, replaced.decision_id);
  assert.equal(byId[replaced.decision_id].status, 'active');
  await req('/projects/decisions/' + d2.decision_id + '/revoke', 'POST', {request_id: id()}, 409);
  const after = await textOf(childChat);
  assert(!after.includes('这一处只比较') && !after.includes(first.content) && after.includes('项目部分不再出新的总方案。'));
  checks.push('revoked and superseded decisions leave the entry but stay readable with their history');

  for (const route of ['/projects/' + p.project_id + '/overview']) await req(route, 'GET', null, 404, outsider);
  await req('/projects/' + p.project_id + '/decisions', 'POST', {content: 'x', scope: 'project', request_id: id()}, 404, outsider);
  await req('/projects/decisions/' + replaced.decision_id + '/revoke', 'POST', {request_id: id()}, 404, outsider);
  checks.push('another trial account can neither read, record nor revoke');

  if (process.argv.includes('--agent')) {
    const message = '这是一次只读检查。请用一两句话说说：这一处现在有哪些在生效的决定。不要调用工具，不要编辑文件，也不要挂起后续工作。';
    await req('/creations/' + childChat + '/messages', 'POST', {message});
    o = await req('/projects/' + p.project_id + '/overview');
    const running = o.activity.runs.find(r => r.location && r.location.id === child.location_id);
    assert(running, 'the run shows under who is working on what');
    // 运行中途再登记一条：它起步时还没有
    const late = await req('/projects/' + p.project_id + '/decisions', 'POST',
      {content: '检查中途加的决定：回答尽量短。', scope: 'project', request_id: id()});
    let result; const deadline = Date.now() + 240000;
    do {
      result = await req('/creations/' + childChat + '/messages');
      if (result.runs.some(r => r.state !== 'running' && r.state !== 'queued' && r.state !== 'claimed')) break;
      await sleep(3000);
    } while (Date.now() < deadline);
    const run = result.runs[0];
    assert.equal(run.state, 'delivered', 'run should come back');
    const reply = result.messages.filter(m => m.role === 'assistant').at(-1);
    o = await req('/projects/' + p.project_id + '/overview');
    const live = Object.fromEntries(o.decisions.map(d => [d.id, d]));
    const reach = live[replaced.decision_id].progress.find(x => x.subject === 'Claude Code');
    assert(reach && reach.included_at, 'decision present at start shows "in the input" for Claude Code');
    const lateReach = live[late.decision_id].progress.find(x => x.subject === 'Claude Code');
    assert(lateReach && !lateReach.included_at, 'decision recorded mid-run was not in that input');
    const back = o.activity.recent.find(r => r.run_id === run.id);
    assert(back && back.decisions_missing.some(d => d.id === late.decision_id), 'returned result marked: decision did not exist at start');
    report.agent = {run: run.id, state: run.state, reply: reply ? reply.content.slice(0, 400) : null};
    checks.push('real run: decision in its input shows "in the input"; decision added mid-run marked missing at return; result kept');
  }
  report.result = 'pass';
}
main().catch(e => {report.result = 'fail'; report.error = e.message; process.exitCode = 1;}).finally(() => {
  fs.mkdirSync(path.dirname(evidence), {recursive: true}); fs.writeFileSync(evidence, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
});
