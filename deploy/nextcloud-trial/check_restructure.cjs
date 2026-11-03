/* Live checks on 8780 for restructuring (V05's software part): move a place, make one independent, merge a project.
 * Uses cognos-admin (password read from .secrets, never printed) and the trial outsider. Report in evidence/restructure-api.json.
 */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const root = __dirname, app = 'http://127.0.0.1:8780/index.php/apps/cognos', ocs = 'http://127.0.0.1:8780/ocs/v2.php/apps/cognos/api/v1.0';
const admin = {user: 'cognos-admin', password: fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim()};
const outsider = JSON.parse(fs.readFileSync(path.join(root, '.secrets/task-journey.json'), 'utf8')).outsider;
const evidence = path.join(root, 'evidence/restructure-api.json');
const checks = []; const report = {at: new Date().toISOString(), checks, previews: {}};
const id = () => crypto.randomUUID();
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
const entry = async loc => (await call(ocs + '/spaces/creation/' + (await req('/projects/locations/' + loc + '/data')).location.creation_id + '/enter?format=json&subject=check', 'GET', null, 200, admin)).text;
const preview = (params, expected = 200, actor = admin) => req('/projects/restructure/preview', 'POST', params, expected, actor);
const apply = (params, basis, expected = 200) => req('/projects/restructure', 'POST', {...params, basis, request_id: id()}, expected);

async function main() {
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const A = await req('/projects/data', 'POST', {title: '验证：重组 A ' + stamp, request_id: id()});
  const add = async (pid, parent, title) => (await req('/projects/' + pid + '/locations', 'POST', {parent_id: parent, title, request_id: id()})).location_id;
  const jia = await add(A.project_id, A.location_id, '甲'), jia1 = await add(A.project_id, jia, '甲一'), yi = await add(A.project_id, A.location_id, '乙');
  const decide = (pid, content, loc) => req('/projects/' + pid + '/decisions', 'POST', {content, scope: loc ? 'here' : 'project', location_id: loc || '', request_id: id()});
  await decide(A.project_id, 'A 整个项目：先比较再下结论。');
  await decide(A.project_id, '只管甲：甲里不写实现细节。', jia);
  await decide(A.project_id, '只管乙：乙里每条都附反例。', yi);
  await req('/projects/locations/' + yi + '/content', 'PUT', {expected_revision: 1, content: '乙的稿', source_message_ids: [], request_id: id()});
  await req('/projects/locations/' + jia1 + '/adoptions', 'POST', {object_type: 'location_content', object_id: yi, object_version: '2', request_id: id()});
  Object.assign(report, {project_a: A.project_id, jia, jia1, yi});

  await preview({action: 'move', location_id: A.location_id, parent_id: jia}, 422);
  await preview({action: 'move', location_id: jia, parent_id: jia1}, 422);
  await preview({action: 'move', location_id: jia1, parent_id: yi}, 404, outsider);
  checks.push('the root cannot be moved, a place cannot go under its own descendant, another account cannot even preview');

  // 1. 调整：甲一从甲移到乙下面
  let p = await preview({action: 'move', location_id: jia1, parent_id: yi});
  report.previews.move = p.consequences;
  assert(p.consequences[0].includes('从「') && p.consequences.some(c => c.includes('开始管到它') && c.includes('只管乙')) && p.consequences.some(c => c.includes('不再管到它') && c.includes('只管甲')));
  const before = await entry(jia1);
  assert(before.includes('只管甲') && !before.includes('只管乙'));
  await apply({action: 'move', location_id: jia1, parent_id: yi}, p.basis);
  const moved = (await req('/projects/locations/' + jia1 + '/data')).location;
  assert.equal(moved.parent_id, yi);
  const after = await entry(jia1);
  assert(after.includes('只管乙') && !after.includes('只管甲') && after.includes('A 整个项目'), 'entry follows the new place');
  checks.push('move: the preview names the path and which scoped decisions start/stop applying; after applying, the same id sits under the new parent and its entry changes exactly that way');

  // 预览以后有了变化：整批不做
  p = await preview({action: 'move', location_id: yi, parent_id: jia});
  await decide(A.project_id, '预览之后才登记的决定。', jia);
  await apply({action: 'move', location_id: yi, parent_id: jia}, p.basis, 409);
  assert.equal((await req('/projects/locations/' + yi + '/data')).location.parent_id, A.location_id, 'nothing changed');
  checks.push('a stale preview (a decision was recorded after it) is refused as a whole and nothing changes');

  // 2. 独立：乙（连同甲一）成为新项目
  p = await preview({action: 'independent', location_id: yi});
  report.previews.independent = p.consequences;
  assert(p.consequences.some(c => c.includes('跟着走') && c.includes('只管乙')) && p.consequences.some(c => c.includes('留在原项目') && c.includes('A 整个项目')));
  const B = await apply({action: 'independent', location_id: yi}, p.basis);
  const bData = (await req('/projects/' + B.project_id + '/data')).project;
  assert.equal(bData.root_location_id, yi); assert.deepEqual(bData.locations.map(l => l.id).sort(), [yi, jia1].sort());
  const aData = (await req('/projects/' + A.project_id + '/data')).project;
  assert(!aData.locations.some(l => l.id === yi || l.id === jia1));
  const yiEntry = await entry(yi);
  assert(yiEntry.includes('只管乙') && !yiEntry.includes('A 整个项目'), 'only the decisions that followed apply');
  const yiPage = await req('/projects/locations/' + jia1 + '/data');
  assert.equal(yiPage.project.id, B.project_id, 'the old link of 甲一 opens and shows its current project');
  const aOverview = await req('/projects/' + A.project_id + '/overview');
  assert(aOverview.history.some(h => h.action === 'independent' && h.location_id === yi && h.to_project_id === B.project_id), 'A remembers where 乙 went');
  const hist = (await req('/projects/' + B.project_id + '/overview?location_id=' + jia1)).location_history;
  assert.deepEqual(hist.map(h => h.action), ['independent', 'move'], '甲一 shows when it belonged where');
  const adoptions = await req('/projects/locations/' + jia1 + '/adoptions');
  assert(adoptions.adoptions.some(a => a.object_id === yi && a.state === 'active'), 'the adoption followed and still resolves');
  checks.push('independent: 乙 with 甲一 becomes project B with the same ids; only 乙\'s own decision follows, A-wide ones stay; A\'s overview and 甲一\'s history both show the move; the adoption keeps working');

  // 3. 合并：C 并到 A 的甲下面
  const C = await req('/projects/data', 'POST', {title: '验证：重组 C ' + stamp, request_id: id()});
  const cChild = await add(C.project_id, C.location_id, 'C 的一处');
  await decide(C.project_id, 'C 整个项目：只写给自己看。');
  await preview({action: 'merge', project_id: C.project_id, target_parent_id: C.location_id}, 422);
  p = await preview({action: 'merge', project_id: C.project_id, target_parent_id: jia});
  report.previews.merge = p.consequences;
  assert(p.consequences.some(c => c.includes('只管原来那部分') && c.includes('C 整个项目')) && p.consequences.some(c => c.includes('也出现在这部分') && c.includes('A 整个项目')));
  await apply({action: 'merge', project_id: C.project_id, target_parent_id: jia}, p.basis);
  const cOverview = await req('/projects/' + C.project_id + '/overview');
  assert.equal(cOverview.merged_into.project_id, A.project_id, 'C keeps its identity, marked as merged into A');
  const a2 = (await req('/projects/' + A.project_id + '/data')).project;
  assert(a2.locations.some(l => l.id === C.location_id && l.parent_id === jia) && a2.locations.some(l => l.id === cChild));
  const cEntry = await entry(cChild);
  assert(cEntry.includes('C 整个项目') && cEntry.includes('A 整个项目'), 'merged part gets both');
  assert(!(await entry(jia)).includes('C 整个项目'), 'C\'s project-wide decision does not spread to the rest of A');
  await preview({action: 'merge', project_id: C.project_id, target_parent_id: jia}, 409);
  const list = (await req('/projects/data')).projects.find(x => x.id === C.project_id);
  assert.equal(list.merged_into, A.project_id);
  checks.push('merge: C\'s places move under 甲 with the same ids; C stays as a merged identity; its project-wide decision now only covers its old part while A-wide decisions also reach it; a merged project cannot be merged again');
  Object.assign(report, {project_b: B.project_id, project_c: C.project_id, c_root: C.location_id});
  report.result = 'pass';
}
main().catch(e => {report.result = 'fail'; report.error = e.message; process.exitCode = 1;}).finally(() => {
  fs.mkdirSync(path.dirname(evidence), {recursive: true}); fs.writeFileSync(evidence, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
});
