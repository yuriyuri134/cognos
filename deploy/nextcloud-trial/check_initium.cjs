/* Live checks on 8780 for Initium and 确立 (V01's software part), with the real local Claude Code behind the bridge.
 * Uses the trial's own cognos-admin (password read from .secrets, never printed) and the trial outsider account.
 * Report in evidence/initium-api.json.
 */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const root = __dirname, app = 'http://127.0.0.1:8780/index.php/apps/cognos';
const admin = {user: 'cognos-admin', password: fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim()};
const outsider = JSON.parse(fs.readFileSync(path.join(root, '.secrets/task-journey.json'), 'utf8')).outsider;
const evidence = path.join(root, 'evidence/initium-api.json');
const checks = []; const report = {at: new Date().toISOString(), checks};
const id = () => crypto.randomUUID();
const sleep = ms => new Promise(r => setTimeout(r, ms));
async function req(route, method = 'GET', data, expected = 200, actor = admin) {
  const r = await fetch(app + route, {method, redirect: 'manual', headers: {
    Authorization: 'Basic ' + Buffer.from(actor.user + ':' + actor.password).toString('base64'),
    'OCS-APIRequest': 'true', Accept: 'application/json', ...(data ? {'Content-Type': 'application/json'} : {})
  }, body: data ? JSON.stringify(data) : undefined, signal: AbortSignal.timeout(60000)});
  let body; try { body = await r.json(); } catch { throw Error(method + ' ' + route + ': non-JSON HTTP ' + r.status); }
  assert.equal(r.status, expected, method + ' ' + route + ': ' + (body.message || 'HTTP ' + r.status));
  return body;
}

async function main() {
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  // 一句话就能留下；两件事混在一句里，后面只确立其中一件
  const said = '检查 ' + stamp + '：入口里决定该放在最前面还是按需出现？另外，笔记的标签要不要按读法分？';
  const cap = await req('/initium/data', 'POST', {text: said, request_id: id()});
  let items = (await req('/initium/data')).items;
  const item = items.find(i => i.id === cap.creation_id);
  assert(item && item.first === said && item.first_id === cap.message_id && item.formed.length === 0);
  checks.push('one sentence is enough: a conversation appears in Initium with that sentence first, no project needed');

  // 让 AI 先试：拿第一句叫它，不另存一句
  const tried = await req('/creations/' + cap.creation_id + '/messages', 'POST', {recall: cap.message_id});
  assert(tried.pending || tried.queued, 'the AI was called: ' + JSON.stringify(tried));
  let msgs; const deadline = Date.now() + 300000;
  do {
    msgs = await req('/creations/' + cap.creation_id + '/messages');
    if (msgs.messages.some(m => m.role === 'assistant')) break;
    await sleep(4000);
  } while (Date.now() < deadline);
  const answer = msgs.messages.find(m => m.role === 'assistant');
  assert(answer, 'the AI tried and answered in the conversation');
  assert.equal(msgs.messages.filter(m => m.role === 'user').length, 1, 'trying did not add another user sentence');
  report.ai_try = {run: msgs.runs[0] && msgs.runs[0].id, answer: answer.content.slice(0, 400)};
  checks.push('without a project, the real local Claude Code tried the sentence and answered in the conversation');
  const before = JSON.stringify(msgs.messages.map(m => [m.id, m.role, m.content]));

  // 确立：只指向用户那一句
  const formReq = {creation_id: cap.creation_id, message_ids: [cap.message_id], title: '验证：入口里决定放哪 ' + stamp,
    description: '只做入口里决定放在哪；笔记标签那件不在这里。', reason: '两件里这一件值得单独做下去', request_id: id()};
  const formed = await req('/initium/form', 'POST', formReq);
  assert.deepEqual(await req('/initium/form', 'POST', formReq), formed, 'same request replays the same result');
  assert(formed.new_project);
  await req('/initium/form', 'POST', {...formReq, message_ids: [999999999], request_id: id()}, 422);
  await req('/initium/form', 'POST', {...formReq, request_id: id()}, 404, outsider);
  checks.push('确立 replays identically for the same request; a sentence outside the conversation is refused; another account cannot');

  const o = await req('/projects/' + formed.project_id + '/overview');
  assert.equal(o.formed_from.length, 1);
  assert.deepEqual(o.formed_from[0].quotes.map(q => q.content), [said], 'the original sentence is quoted verbatim');
  assert.equal(o.formed_from[0].reason, formReq.reason);
  const rootLoc = await req('/projects/locations/' + formed.location_id + '/data');
  assert.equal(rootLoc.location.revision, 2); assert.equal(rootLoc.location.content, formReq.description);
  assert.deepEqual(rootLoc.source_message_ids, [cap.message_id], 'the description points back to the sentence');
  assert(rootLoc.usages.some(u => u.source_creation_id === cap.creation_id && u.purpose.startsWith('来路')), 'the conversation is related as 来路, not moved');
  assert.notEqual(rootLoc.location.creation_id, cap.creation_id, 'the project got its own discussion; the source conversation is not repurposed');
  const after = await req('/creations/' + cap.creation_id + '/messages');
  assert.equal(JSON.stringify(after.messages.map(m => [m.id, m.role, m.content])), before, 'the source conversation is unchanged');
  checks.push('确立 made a project whose 来路 quotes the original sentence verbatim; the description is a new revision pointing at it; the conversation is related, not moved, and unchanged');

  // 同一段会话再促成一个项目（另一件事），以及一个项目有两段来路
  const second = await req('/initium/form', 'POST', {creation_id: cap.creation_id, message_ids: [cap.message_id, answer.id],
    title: '验证：笔记标签 ' + stamp, request_id: id()});
  const other = await req('/initium/data', 'POST', {text: '检查 ' + stamp + '：另一段也和入口有关的话。', request_id: id()});
  await req('/initium/form', 'POST', {creation_id: other.creation_id, message_ids: [other.message_id], project_id: formed.project_id,
    reason: '补一段来路', request_id: id()});
  items = (await req('/initium/data')).items;
  const again = items.find(i => i.id === cap.creation_id);
  assert.deepEqual(again.formed.map(f => f.project_id).sort(), [formed.project_id, second.project_id].sort(), 'one conversation formed two projects');
  assert(items.some(i => i.id === other.creation_id && i.formed[0].project_id === formed.project_id));
  const o2 = await req('/projects/' + formed.project_id + '/overview');
  assert.equal(o2.formed_from.length, 2, 'one project with two sources');
  assert(!items.some(i => i.id === rootLoc.location.creation_id), 'a project location\'s own discussion is not listed in Initium');
  checks.push('one conversation can form several projects and a project can have several sources; place discussions stay out of Initium');
  Object.assign(report, {creation_id: cap.creation_id, project_id: formed.project_id, second_project_id: second.project_id, other_creation_id: other.creation_id});
  report.result = 'pass';
}
main().catch(e => {report.result = 'fail'; report.error = e.message; process.exitCode = 1;}).finally(() => {
  fs.mkdirSync(path.dirname(evidence), {recursive: true}); fs.writeFileSync(evidence, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
});
