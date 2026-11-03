/* Live checks on 8780 for adoption and correction (V03's software part). No agent call: it adopts an answer the real
 * Claude Code already gave in Initium (evidence/initium-api.json) and corrects a version a real run reported as its basis
 * (evidence/tails-api.json). Uses cognos-admin (password read from .secrets, never printed) and the trial outsider.
 * Report in evidence/adoption-api.json.
 */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const root = __dirname, app = 'http://127.0.0.1:8780/index.php/apps/cognos';
const admin = {user: 'cognos-admin', password: fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim()};
const outsider = JSON.parse(fs.readFileSync(path.join(root, '.secrets/task-journey.json'), 'utf8')).outsider;
const initium = JSON.parse(fs.readFileSync(path.join(root, 'evidence/initium-api.json'), 'utf8'));
const tails = JSON.parse(fs.readFileSync(path.join(root, 'evidence/tails-api.json'), 'utf8'));
const evidence = path.join(root, 'evidence/adoption-api.json');
const checks = []; const report = {at: new Date().toISOString(), checks};
const id = () => crypto.randomUUID();
async function req(route, method = 'GET', data, expected = 200, actor = admin) {
  const r = await fetch(app + route, {method, redirect: 'manual', headers: {
    Authorization: 'Basic ' + Buffer.from(actor.user + ':' + actor.password).toString('base64'),
    'OCS-APIRequest': 'true', Accept: 'application/json', ...(data ? {'Content-Type': 'application/json'} : {})
  }, body: data ? JSON.stringify(data) : undefined, signal: AbortSignal.timeout(60000)});
  let body; try { body = await r.json(); } catch { throw Error(method + ' ' + route + ': non-JSON HTTP ' + r.status); }
  assert.equal(r.status, expected, method + ' ' + route + ': ' + (body.message || 'HTTP ' + r.status));
  return body;
}
const list = loc => req('/projects/locations/' + loc + '/adoptions');

async function main() {
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const p = await req('/projects/data', 'POST', {title: '验证：采用和纠正 ' + stamp, request_id: id()});
  const jia = (await req('/projects/' + p.project_id + '/locations', 'POST', {parent_id: p.location_id, title: '甲：依赖它', request_id: id()})).location_id;
  const yi = (await req('/projects/' + p.project_id + '/locations', 'POST', {parent_id: p.location_id, title: '乙：只参考', request_id: id()})).location_id;
  // 同一份材料（Initium 里 Claude Code 回答过的那段会话）在甲、乙分别有用途
  const source = initium.creation_id;
  const answer = (await req('/creations/' + source + '/messages')).messages.find(m => m.role === 'assistant');
  assert(answer, 'the Initium conversation has a real Claude Code answer');
  await req('/projects/locations/' + jia + '/usages', 'POST', {source_creation_id: source, purpose: '依据：决定放在哪', request_id: id()});
  await req('/projects/locations/' + yi + '/usages', 'POST', {source_creation_id: source, purpose: '参考', request_id: id()});
  await req('/projects/locations/' + jia + '/content', 'PUT', {expected_revision: 1, content: '甲的稿：决定放在入口最前面。', source_message_ids: [], request_id: id()});
  Object.assign(report, {project_id: p.project_id, jia, yi, answer_id: answer.id});

  const adoptAnswer = {object_type: 'message', object_id: String(answer.id), basis: '按这段回答排入口', request_id: id()};
  const a1 = await req('/projects/locations/' + jia + '/adoptions', 'POST', adoptAnswer);
  assert.deepEqual(await req('/projects/locations/' + jia + '/adoptions', 'POST', adoptAnswer), a1);
  await req('/projects/locations/' + jia + '/adoptions', 'POST', {...adoptAnswer, request_id: id()}, 409);
  const a2 = await req('/projects/locations/' + jia + '/adoptions', 'POST', {object_type: 'location_content', object_id: jia, object_version: '2', request_id: id()});
  const a3 = await req('/projects/locations/' + yi + '/adoptions', 'POST', {object_type: 'location_content', object_id: jia, object_version: '2', request_id: id()});
  await req('/projects/locations/' + yi + '/adoptions', 'POST', {object_type: 'location_content', object_id: jia, object_version: '9', request_id: id()}, 422);
  await req('/projects/locations/' + yi + '/adoptions', 'POST', {object_type: 'message', object_id: '999999999', request_id: id()}, 422);
  await req('/projects/locations/' + jia + '/adoptions', 'GET', null, 404, outsider);
  checks.push('adopting pins one exact version in one place; replay is identical, adopting the same version twice is refused, a version or sentence that is not there is refused, another account cannot see it');

  // 纠正那段回答：甲（采用了它）进待复核，乙（只用作材料）只得到提示
  const c1 = await req('/projects/corrections', 'POST', {object_type: 'message', object_id: String(answer.id),
    content: '这段回答把「入口里没有决定这一栏」当成了普遍情况；那次只是不在项目里。', basis: '检查用的纠正', request_id: id()});
  assert(c1.review >= 1 && c1.hint >= 1);
  let j = await list(jia), y = await list(yi);
  const A1 = () => j.adoptions.find(a => a.id === a1.adoption_id);
  assert.equal(A1().state, 'review');
  assert(j.impacts.some(i => i.effect === 'review' && i.target_id === a1.adoption_id && i.correction.content.startsWith('这段回答')));
  assert(y.impacts.some(i => i.effect === 'hint' && i.target_kind === 'usage'), 'the place that only references it gets a hint');
  assert(y.adoptions.every(a => a.state === 'active'), 'nothing changes in the referencing place');
  checks.push('correcting the answer: the place that adopted it goes to 待复核; the place that only uses the same material gets a hint and keeps its own adoptions');

  await req('/projects/adoptions/' + a1.adoption_id + '/reaffirm', 'POST', {response: '', request_id: id()}, 422);
  await req('/projects/adoptions/' + a1.adoption_id + '/reaffirm', 'POST', {response: '纠正对，但这里要的结论不受影响：决定照样放在最前面。', request_id: id()});
  j = await list(jia);
  assert.equal(A1().state, 'active'); assert(A1().response.startsWith('纠正对'));
  assert(A1().corrections.some(c => c.id === c1.correction_id), 'the correction stays readable next to the response');
  assert(!j.impacts.some(i => i.target_id === a1.adoption_id), 'the review is resolved');
  checks.push('facing it: "still adopt" needs a response; the adoption is active again and the correction and response both stay readable');

  // 纠正甲的稿第 2 版：甲和乙都采用了这一版，都进待复核
  await req('/projects/corrections', 'POST', {object_type: 'location_content', object_id: jia, object_version: '2', content: '「最前面」不对：按需出现也要试。', request_id: id()});
  j = await list(jia); y = await list(yi);
  assert.equal(j.adoptions.find(a => a.id === a2.adoption_id).state, 'review');
  assert.equal(y.adoptions.find(a => a.id === a3.adoption_id).state, 'review');
  // 乙撤回：只改乙；甲得到提示，甲的采用不变
  await req('/projects/adoptions/' + a3.adoption_id + '/withdraw', 'POST', {request_id: id()});
  j = await list(jia); y = await list(yi);
  assert.equal(y.adoptions.find(a => a.id === a3.adoption_id).state, 'withdrawn');
  assert.equal(j.adoptions.find(a => a.id === a2.adoption_id).state, 'review', 'withdrawing in 乙 does not touch 甲');
  const hint = j.impacts.find(i => i.cause_type === 'withdrawal' && i.effect === 'hint');
  assert(hint, '甲 is told 乙 withdrew');
  checks.push('withdrawing in one place changes only that place; the other place that adopted the same version gets a hint');
  await req('/projects/impacts/' + hint.id + '/resolve', 'POST', {request_id: id()});
  j = await list(jia);
  assert(!j.impacts.some(i => i.id === hint.id));
  await req('/projects/impacts/' + j.impacts.find(i => i.target_id === a2.adoption_id && i.effect === 'review').id + '/resolve', 'POST', {request_id: id()}, 409);

  // 甲改出第 3 版，改采用它：第 2 版的采用标为被替代，旧的留着
  await req('/projects/locations/' + jia + '/content', 'PUT', {expected_revision: 2, content: '甲的稿：决定先放清单，全文按需拿。', source_message_ids: [], request_id: id()});
  const a4 = await req('/projects/locations/' + jia + '/adoptions', 'POST', {object_type: 'location_content', object_id: jia, object_version: '3', supersedes: a2.adoption_id, request_id: id()});
  j = await list(jia);
  const A2 = j.adoptions.find(a => a.id === a2.adoption_id);
  assert.equal(A2.state, 'superseded'); assert.equal(A2.superseded_by, a4.adoption_id);
  assert(!j.impacts.some(i => i.target_id === a2.adoption_id), 'its review is resolved by the new adoption');
  checks.push('adopting revision 3 in place of 2 marks the old adoption superseded and resolves its review; a hint can be marked 知道了, a review on an adoption cannot be dismissed that way');

  // 纠正一个真实运行报过的依据（tails 那次：某处当前内容第 2 版）：那次运行的结果进待复核
  const c3 = await req('/projects/corrections', 'POST', {object_type: 'location_content', object_id: tails.location_id, object_version: '2',
    content: '这一版没有说清「入口」指的是哪一种入口。', request_id: id()});
  assert(c3.review >= 1);
  const t = await list(tails.location_id);
  const runReview = t.impacts.find(i => i.target_kind === 'run' && i.target_id === tails.agent.run && i.effect === 'review');
  assert(runReview, 'the run that reported this version as its basis goes to 待复核');
  await req('/projects/impacts/' + runReview.id + '/resolve', 'POST', {resolution: '看过了，那次回答的结论不变', request_id: id()});
  checks.push('a correction reaches a real run that reported the corrected version as its basis (待复核), and it can be resolved with a note');

  const counts = (await req('/projects/' + p.project_id + '/overview')).locations;
  report.review_counts = Object.fromEntries(counts.map(l => [l.title, l.review]));
  report.result = 'pass';
}
main().catch(e => {report.result = 'fail'; report.error = e.message; process.exitCode = 1;}).finally(() => {
  fs.mkdirSync(path.dirname(evidence), {recursive: true}); fs.writeFileSync(evidence, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
});
