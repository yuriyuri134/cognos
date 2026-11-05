/* 「AI 正在做」的标记：结果交回以后要清掉，不能等后续顺利派发才清（后续被推迟、取消时它会一直挂着）。
 * 用 lib/live.cjs，不叫真实 AI。造法：在 Initium 留一段会话，像桥接下工作时那样挂上标记，
 * 一位参与者走 agent 的路登记运行；同一段会话里另有一次「不知道」的运行，所以后续一定会被推迟。然后交回结果。
 * 报告 evidence/ai-working-flag.json。
 */
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const live = require('./lib/live.cjs');
const occ = (...args) => execFileSync('docker', ['exec', '-u', 'www-data', 'cognos-nextcloud-trial-app-1', 'php', 'occ', ...args], {encoding: 'utf8'});

live.run('ai-working-flag', async (checks, report) => {
  const {req, api} = live.client();
  const cap = await req('/initium/data', 'POST', {text: '验证：「AI 正在做」的标记 ' + Date.now(), request_id: live.id()});
  const space = cap.creation_id;
  const working = async () => (await req('/initium/data')).items.find(i => i.id === space).ai_working;
  const stuck = (await api('/spaces/creation/' + space + '/runs', 'POST', {subject: '验证参与者', purpose: '验证：失联的那次'}, 201)).run;
  await api('/runs/' + stuck.id + '/unknown', 'POST', {reason: '验证：桥重启时这一件没了'});
  const run = (await api('/spaces/creation/' + space + '/runs', 'POST', {subject: '验证参与者', purpose: '验证：会交回的这次'}, 201)).run;
  occ('config:app:set', 'cognos', 'agent_pending_creation_' + space, '--value=' + Math.floor(Date.now() / 1000));
  occ('config:app:set', 'cognos', 'agent_pending_who_creation_' + space, '--value=验证参与者');
  assert.equal(await working(), true, 'the flag is up while work is going on');
  checks.push('Initium shows 「AI 正在做」 while the flag is up');
  Object.assign(report, {creation_id: space, run_id: run.id, unknown_run_id: stuck.id});

  await api('/spaces/creation/' + space + '/messages', 'POST', {subject: '验证参与者', run_id: run.id,
    result_id: crypto.randomUUID().replace(/-/g, ''), content: '验证：交回的结果'}, 201);
  await live.sleep(1500);
  assert.equal(await working(), false, 'after the result is handed back, Initium no longer says the AI is working');
  checks.push('after the result comes back, 「AI 正在做」 is gone even though the followup is deferred (another run in this conversation is unknown)');
});
