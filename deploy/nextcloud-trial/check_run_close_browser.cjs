/* 0.35.1：「不知道做没做完」的运行，在项目页上核对后关掉。用 lib/live.cjs，不叫真实 AI。
 * 造法和真实的一样：一位参与者走 agent 的路自己登记一次运行，再按桥重启时的做法标成不知道。
 * 然后在登录后的项目页「谁在动什么」里写核对说明、关掉；看状态变了、表单没了、390px 不溢出、没有页面报错。
 * 报告 evidence/run-close-browser.json，截图 evidence/run-close-*.png。
 */
const assert = require('node:assert/strict');
const live = require('./lib/live.cjs');

live.run('run-close-browser', async (checks, report) => {
  const {req, api} = live.client();
  const p = await req('/projects/data', 'POST', {title: '验证：页面上关掉不知道的运行 ' + Date.now(), request_id: live.id()});
  const space = (await req('/projects/locations/' + p.location_id + '/data')).location.creation_id;
  const run = (await api('/spaces/creation/' + space + '/runs', 'POST', {subject: '验证参与者', purpose: '验证：之后失联'}, 201)).run;
  await api('/runs/' + run.id + '/unknown', 'POST', {reason: '验证：桥重启时这一件没了'});
  assert.equal((await api('/runs/' + run.id)).run.state, 'unknown');
  await live.client(live.outsider()).req('/projects/runs/' + run.id + '/close', 'POST', {note: '别人', request_id: live.id()}, 404);
  Object.assign(report, {project_id: p.project_id, run_id: run.id});
  checks.push('an unknown run made the real way (registered, then marked unknown); another account cannot close it');

  const b = await live.browser();
  try {
    await b.login();
    await b.go(live.APP + '/projects/' + p.project_id);
    await b.until('!!document.querySelector("#pw-who .pw-close-run textarea")');
    const who = await b.evaluate('document.querySelector("#pw-who").textContent');
    assert(who.includes('验证参与者') && who.includes('状态未知'), who);
    await b.shoot('run-close-before.png', '#pw-who-title');
    await b.click('#pw-who .pw-close-run button');                       // 空的说明：表单本身拦住，不发请求
    assert.equal((await api('/runs/' + run.id)).run.state, 'unknown', 'an empty note does not close it');
    await b.fill('#pw-who .pw-close-run textarea', '浏览器检查：看过桥的日志，这一件没有在做');
    await b.click('#pw-who .pw-close-run button');
    await b.until('!document.querySelector("#pw-who .pw-close-run") && document.querySelector("#pw-notice").textContent.includes("已关掉")');
    const after = (await api('/runs/' + run.id)).run;
    assert.equal(after.state, 'closed'); assert(after.error.includes('浏览器检查：看过桥的日志'));
    checks.push('on the project overview the unknown run shows a close form; an empty note is refused; with a note it closes, the note stays on the run, the form goes away');
    await b.shoot('run-close-after.png', '#pw-who-title');
    await b.phone();
    assert(await b.noOverflow(), 'no horizontal overflow at 390px');
    await b.shoot('run-close-phone.png', '#pw-who-title');
    checks.push('390px has no horizontal overflow');
    assert.deepEqual(b.errors, [], 'no uncaught page errors');
  } finally { b.close(); }
});
