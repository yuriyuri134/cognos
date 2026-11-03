/* Live loopback trial checks. Test accounts only; no global bridge setting changes.
 * Leaves uniquely named projects and conversations as inspectable evidence.
 * Optional --agent sends one bounded, read-only request to the configured AI.
 */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const root = __dirname, base = 'http://127.0.0.1:8780/index.php/apps/cognos';
const admin = {user: 'cognos-admin', password: fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim()};
const outsider = JSON.parse(fs.readFileSync(path.join(root, '.secrets/task-journey.json'), 'utf8')).outsider;
const evidence = path.join(root, 'evidence/project-workspace-api.json');
const checks = []; const report = {at: new Date().toISOString(), checks, browser: 'not checked here', agent: 'not invoked'};
const id = () => crypto.randomUUID();
async function req(route, method = 'GET', data, expected = 200, actor = admin) {
  const r = await fetch(base + route, {method, redirect: 'manual', headers: {
    Authorization: 'Basic ' + Buffer.from(actor.user + ':' + actor.password).toString('base64'),
    'OCS-APIRequest': 'true', Accept: 'application/json', ...(data ? {'Content-Type': 'application/json'} : {})
  }, body: data ? JSON.stringify(data) : undefined, signal: AbortSignal.timeout(45000)});
  let body; try { body = await r.json(); } catch { throw Error(method + ' ' + route + ': non-JSON HTTP ' + r.status); }
  assert.equal(r.status, expected, method + ' ' + route + ': ' + (body.message || 'HTTP '+r.status));
  return body;
}
async function main() {
  const suffix = new Date().toISOString().replace(/[:.]/g, '-');
  const create = {title: '验证：共同工作 ' + suffix, request_id: id()};
  const p = await req('/projects/data', 'POST', create);
  report.project_id = p.project_id; report.location_id = p.location_id;
  assert.deepEqual(await req('/projects/data', 'POST', create), p);
  await req('/projects/data', 'POST', {...create, title: create.title + ' changed'}, 409);
  checks.push('create replay is identical; same request with different content rejected');
  const d = await req('/projects/locations/' + p.location_id + '/data');
  report.creation_id = d.location.creation_id;
  const initial = await req('/creations/' + d.location.creation_id + '/messages');
  assert.equal(initial.messages.length, 0); assert.equal(initial.runs.length, 0);
  checks.push('creating a project does not start an AI run');
  const childRequest = {parent_id: p.location_id, title: '从另一个问题进入', request_id: id()};
  const child = await req('/projects/' + p.project_id + '/locations', 'POST', childRequest);
  assert.deepEqual(await req('/projects/' + p.project_id + '/locations', 'POST', childRequest), child);
  const childDetail = await req('/projects/locations/' + child.location_id + '/data');
  assert.notEqual(childDetail.location.creation_id, d.location.creation_id);
  report.child_location_id = child.location_id;
  checks.push('branches retain distinct dedicated conversations and stable locations');
  for (const route of ['/projects/' + p.project_id + '/data', '/projects/locations/' + p.location_id + '/data',
      '/projects/locations/' + p.location_id + '/history', '/projects/locations/' + p.location_id + '/history/1',
      '/creations/' + d.location.creation_id + '/messages']) {
    await req(route, 'GET', null, 404, outsider);
  }
  await req('/projects/locations/' + p.location_id + '/content', 'PUT',
    {expected_revision: 1, content: 'outsider', source_message_ids: [], request_id: id()}, 404, outsider);
  checks.push('other trial account cannot read content/history/runs or write this project');
  const source = (await req('/creations/data', 'POST',
    {title: '验证：来源 ' + suffix, initialMessage: '同一个原始讨论，可以服务不同位置。'}, 201)).creation;
  const sourceMessages = (await req('/creations/' + source.id + '/messages')).messages;
  const use = {source_creation_id: source.id, purpose: '确立入口依据', request_id: id()};
  const usage = await req('/projects/locations/' + p.location_id + '/usages', 'POST', use);
  assert.deepEqual(await req('/projects/locations/' + p.location_id + '/usages', 'POST', use), usage);
  await req('/projects/locations/' + child.location_id + '/usages', 'POST',
    {source_creation_id: source.id, purpose: '比较另一种解释', request_id: id()});
  const rootAfter = await req('/projects/locations/' + p.location_id + '/data');
  const childAfter = await req('/projects/locations/' + child.location_id + '/data');
  assert.equal(rootAfter.usages[0].source_creation_id, childAfter.usages[0].source_creation_id);
  assert.notEqual(rootAfter.usages[0].purpose, childAfter.usages[0].purpose);
  assert.deepEqual((await req('/creations/' + source.id + '/messages')).messages, sourceMessages);
  checks.push('one unchanged source, independent usages and purposes at two locations');
  const privateSource = (await req('/creations/data', 'POST',
    {title: '验证：私有来源 ' + suffix, initialMessage: 'only the other test account'}, 201, outsider)).creation;
  const failedRequest = id();
  await req('/projects/locations/' + p.location_id + '/usages', 'POST',
    {source_creation_id: privateSource.id, purpose: 'must not leak', request_id: failedRequest}, 404);
  const failedUpdate = id();
  await req('/projects/locations/' + p.location_id + '/content', 'PUT',
    {expected_revision: 1, content: 'invalid source', source_message_ids: [2147483647], request_id: failedUpdate}, 422);
  assert.equal((await req('/projects/locations/' + p.location_id + '/history')).revisions.length, 1);
  const content = '比较两种项目入口；保留原始会话。';
  const save = {expected_revision: 1, content, source_message_ids: [sourceMessages[0].id], request_id: failedUpdate};
  assert.equal((await req('/projects/locations/' + p.location_id + '/content', 'PUT', save)).revision, 2);
  checks.push('unreadable source rejected; failed content transaction leaves no revision or successful receipt');
  const endpoint = '/projects/locations/' + p.location_id + '/content';
  const a = {expected_revision: 2, content: content + '\n并发 A', source_message_ids: [sourceMessages[0].id], request_id: id()};
  const b = {...a, content: content + '\n并发 B', request_id: id()};
  // Response statuses are inspected explicitly; neither branch silently wins in the client.
  const raw = async data => fetch(base + endpoint, {method: 'PUT', headers: {
    Authorization: 'Basic ' + Buffer.from(admin.user + ':' + admin.password).toString('base64'),
    'OCS-APIRequest': 'true', 'Content-Type': 'application/json', Accept: 'application/json'
  }, body: JSON.stringify(data), signal: AbortSignal.timeout(45000)});
  const responses = await Promise.all([raw(a), raw(b)]);
  assert.deepEqual(responses.map(r => r.status).sort(), [200, 409]);
  assert.equal((await req('/projects/locations/' + p.location_id + '/data')).location.revision, 3);
  assert.equal((await req(endpoint, 'PUT', save)).revision, 2);
  assert.equal((await req('/projects/locations/' + p.location_id + '/history')).revisions.length, 3);
  assert.equal((await req('/projects/locations/' + p.location_id + '/history/2')).revision.content, content);
  checks.push('parallel same-version writes: one commit and one conflict; retry adds no revision; old text remains');
  const other = await req('/projects/data', 'POST', {title: '验证：归属边界 ' + suffix, request_id: id()});
  await req('/projects/' + other.project_id + '/locations', 'POST',
    {parent_id: p.location_id, title: 'cannot cross projects', request_id: id()}, 422);
  assert.equal((await req('/projects/' + other.project_id + '/data')).project.locations.length, 1);
  checks.push('invalid cross-project parent rejected without partial location or discussion');
  const entry = (await req('/creations/' + d.location.creation_id + '/space')).entry;
  const context = entry.space.project_context;
  assert.equal(context.location_id, p.location_id); assert.equal(context.revision, 3);
  assert.equal(context.sources[0].conversation_id, source.id);
  assert.equal(context.sources[0].purpose_here, '确立入口依据');
  assert.equal(context.sources[0].truncated, false);
  assert(context.sources[0].excerpt.includes('同一个原始讨论'));
  assert.equal((await req('/creations/' + source.id + '/space')).entry.space.project_context, null);
  checks.push('entry includes current revision and source range; shared source is not rebound to a project');
  // No authentication or tokens are included in the report.
  if (process.argv.includes('--agent')) {
    const message = '这是一次只读的项目入口验证。请只用两句话说明当前项目内容在比较什么，以及关联材料在这里的用途。不要调用工具，不要编辑文件，也不要挂起后续工作。';
    await req('/creations/' + d.location.creation_id + '/messages', 'POST', {message});
    let result;
    const deadline = Date.now() + 180000;
    do {
      result = await req('/creations/' + d.location.creation_id + '/messages');
      if (result.messages.some(m => m.role === 'assistant') || result.runs.some(r => ['failed', 'unknown'].includes(r.state))) break;
      await new Promise(r => setTimeout(r, 3000));
    } while (Date.now() < deadline);
    report.agent = {runs: result.runs.map(r => ({id: r.id, state: r.state})),
      replies: result.messages.filter(m => m.role === 'assistant').length};
    assert.equal(report.agent.replies, 1, 'real configured agent should return one reply');
    assert.equal(result.runs[0].state, 'delivered');
    checks.push('configured agent returned one result to the project discussion');
  }
  report.result = 'pass';
}
main().catch(e => {report.result = 'fail'; report.error = e.message; process.exitCode = 1;}).finally(() => {
  fs.mkdirSync(path.dirname(evidence), {recursive: true}); fs.writeFileSync(evidence, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
});
