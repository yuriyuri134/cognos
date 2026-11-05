/* 项目列表页上一次关掉几条「不知道做没做完」的运行。用 lib/live.cjs，不叫真实 AI。
 * 只造、只勾自己造的运行：库里原有的那些「不知道」的运行一条都不碰（最后核对它们还在）。
 * 报告 evidence/close-unknown-batch.json，截图 evidence/close-unknown-*.png。
 */
const assert = require('node:assert/strict');
const live = require('./lib/live.cjs');

live.run('close-unknown-batch', async (checks, report) => {
  const {req, api} = live.client();
  const other = live.client(live.outsider());
  const before = (await req('/projects/runs/unknown')).runs.map(r => r.run_id);
  const p = await req('/projects/data', 'POST', {title: '验证：一次关掉不知道的运行 ' + Date.now(), request_id: live.id()});
  const space = (await req('/projects/locations/' + p.location_id + '/data')).location.creation_id;
  const unknown = async n => {
    const run = (await api('/spaces/creation/' + space + '/runs', 'POST', {subject: '验证参与者', purpose: '验证：之后失联 ' + n}, 201)).run;
    await api('/runs/' + run.id + '/unknown', 'POST', {reason: '验证：桥重启时这一件没了'});
    return run.id;
  };
  const [r1, r2, r3, r4] = [await unknown(1), await unknown(2), await unknown(3), await unknown(4)];
  const state = async id => (await api('/runs/' + id)).run.state;
  Object.assign(report, {project_id: p.project_id, runs: [r1, r2, r3, r4], unknown_before: before.length});

  const listed = (await req('/projects/runs/unknown')).runs;
  const mine = listed.find(r => r.run_id === r1);
  assert(mine && mine.where.project_title === (await req('/projects/' + p.project_id + '/data')).project.title && mine.purpose.includes('验证'), JSON.stringify(mine));
  assert(!(await other.req('/projects/runs/unknown')).runs.some(r => [r1, r2, r3, r4].includes(r.run_id)), 'another account does not see them');
  checks.push('the list shows every unknown run of this account with who, where (project / place), when and why; another account does not see them');

  await other.req('/projects/runs/close', 'POST', {run_ids: [r1], note: '别人', request_id: live.id()}, 404);
  await req('/projects/runs/close', 'POST', {run_ids: [r1, 'f'.repeat(32)], note: '有一条不是我的', request_id: live.id()}, 404);
  await req('/projects/runs/close', 'POST', {run_ids: [r1], note: '  ', request_id: live.id()}, 422);
  assert.equal(await state(r1), 'unknown', 'nothing was closed by the refused requests');
  checks.push('another account cannot close them; if one id in the batch is not yours, none is closed; an empty note is refused');

  await req('/projects/runs/' + r2 + '/close', 'POST', {note: '先单独关了这一条', request_id: live.id()});
  const batch = await req('/projects/runs/close', 'POST', {run_ids: [r1, r2], note: '检查：一次关两条，其中一条已经关了', request_id: live.id()});
  assert.deepEqual(batch.closed, [r1]); assert.deepEqual(batch.skipped.map(s => s.run_id), [r2]);
  assert.equal(await state(r1), 'closed'); assert((await api('/runs/' + r1)).run.error.includes('一次关两条'));
  checks.push('a batch closes what is still unknown and reports what was already closed as skipped, not as an error; the shared note stays on each closed run');

  const b = await live.browser();
  try {
    await b.login();
    await b.go(live.APP + '/projects');
    await b.until('!document.querySelector("#pw-unknown-section").hidden && !!document.querySelector(\'#pw-unknown-list input[value="' + r3 + '"]\')');
    const text = await b.evaluate('document.querySelector(\'#pw-unknown-list input[value="' + r3 + '"]\').closest("li").textContent');
    assert(text.includes('验证参与者') && text.includes('验证：之后失联 3') && text.includes('在 「验证：一次关掉不知道的运行'), text);
    await b.shoot('close-unknown-desktop.png', '#pw-unknown-title');
    for (const id of [r3, r4]) await b.evaluate('document.querySelector(\'#pw-unknown-list input[value="' + id + '"]\').checked = true');
    await b.fill('#pw-unknown-note', '浏览器检查：只关自己造的两条');
    await b.click('#pw-unknown-form button[type="submit"]');
    await b.until('document.querySelector("#pw-notice").textContent.includes("关掉了 2 条")');
    assert.equal(await state(r3), 'closed'); assert.equal(await state(r4), 'closed');
    assert(!(await b.evaluate('!!document.querySelector(\'#pw-unknown-list input[value="' + r3 + '"]\')')), 'closed runs leave the list');
    checks.push('on the project list page: tick two runs, write one note, close; both closed, they leave the list, the notice says how many');
    await b.phone();
    assert(await b.noOverflow(), 'no horizontal overflow at 390px');
    await b.shoot('close-unknown-phone.png', '#pw-unknown-title');
    checks.push('390px has no horizontal overflow');
    assert.deepEqual(b.errors, [], 'no uncaught page errors');
  } finally { b.close(); }

  const after = (await req('/projects/runs/unknown')).runs.map(r => r.run_id);
  assert(before.every(id => after.includes(id)), 'every unknown run that existed before the check is still unknown');
  report.unknown_after = after.length;
  checks.push('the ' + before.length + ' unknown runs that existed before were not touched');
});
