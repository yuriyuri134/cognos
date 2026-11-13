'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { requestText, toolsFor } = require('./cases.cjs');
const { parsePlan, run, state } = require('./controller.cjs');
const { openLedger } = require('./ledger.cjs');
const { reuse } = require('./reuse.cjs');

async function fixture(t) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-reuse-'));
  const file = path.join(dir, 'source.sqlite');
  const source = openLedger(file);
  const targetFile = path.join(dir, 'target.sqlite');
  let target = openLedger(targetFile);
  t.after(() => {
    source.close();
    target.close();
    fs.rmSync(dir, { recursive: true, force: true });
  });
  const config = { chat: 21 };
  source.add('inquiry', { caseId: 'D', config });
  const observation = source.add('observation', { ...await toolsFor('D').node_date_probe.read(), source: 'node_date_probe' });
  const protocol = 'Investigate date parsing';
  const input = JSON.stringify({ history: [source.read().at(-1)] });
  const request = source.add('model_requested', { protocol, input });
  const plan = { claim: 'Use calendar bounds instead of parse success', evidence: [observation], replaces: null };
  const raw = JSON.stringify({ revision: plan });
  const receipt = { chat: 21, sent: 31, reply: 32, author: 'GPT' };
  source.add('model_response', { request, raw, receipt });
  const attempt = source.add('attempt', { request, revision: plan });
  const revision = source.add('revision', { ...plan, attempt });

  const messages = [
    { id: 31, role: 'user', content: requestText(protocol, input) },
    { id: 32, role: 'assistant', author: 'GPT', reply_to: 31, content: raw },
  ];
  let readable = true;
  const client = { async req(route) {
    assert.equal(route, '/creations/21/messages');
    if (!readable) throw Object.assign(Error('not found'), { actual: 404 });
    return { messages };
  } };
  const settings = { chat: 22, source: { file, revision } };
  target.add('inquiry', { budget: 4, question: 'Choose a rule for a calendar export', config: settings });
  return {
    client, settings, messages, revoke() { readable = false; }, restore() { readable = true; },
    get target() { return target; },
    reopen() { target.close(); target = openLedger(targetFile); },
  };
}

function model(...plans) {
  return { name: 'scripted-test', async reply(_, input) {
    const next = plans.shift();
    assert.ok(next);
    const plan = typeof next === 'function' ? next(JSON.parse(input)) : next;
    return { raw: JSON.stringify(plan), receipt: {} };
  } };
}
const find = { decision: 'continue', reason: 'Check prior work', method: 'Search', tool: 'find_prior_method' };
const pause = { decision: 'pause', reason: 'Wait for the export sample' };

test('separate inquiry discovers a verified source and uses the cited method on new rows after reopening', async t => {
  const f = await fixture(t);
  const experiment = reuse(f.settings, f.client);
  assert.equal(await run(f.target, model(find, pause), experiment.tools, experiment), 'pause');
  const discovery = state(f.target.read()).observations[0];
  const ref = discovery.body.result.ref;
  assert.match(ref, /^creation:21\/message:32\/revision:/);
  assert.throws(() => experiment.tools.check_export.parseInput({ strategy: 'parseable', ref }, state(f.target.read())),
    /requires calendar bounds/);
  f.reopen();
  assert.equal(await run(f.target, model(
    input => {
      assert.deepEqual(input.history.find(e => e.id === discovery.id), discovery);
      return { decision: 'continue', reason: 'Check the export', method: 'Apply calendar bounds',
        tool: 'check_export', toolInput: { strategy: 'calendar_bounds', ref } };
    },
    input => {
      const applied = input.history.find(e => e.body.source === 'check_export');
      assert.equal(applied.body.result.ref, ref);
      assert.equal(applied.body.result.discovery, discovery.id);
      assert.deepEqual(applied.body.result.rows.map(row => row.accepted), [false, true, false, true, false]);
      return { decision: 'stop', reason: 'The batch was checked', revision: { claim: ref, evidence: [applied.id] } };
    },
  ), experiment.tools, { ...experiment, trigger: 'Sample supplied' }), 'stop');
  assert.equal(state(f.target.read()).remaining, 0);
});

test('control, inaccessible source, and mismatched receipts never supply a reusable method', async t => {
  const f = await fixture(t);
  const control = reuse({ chat: 22 }, f.client);
  assert.deepEqual(await control.tools.find_prior_method.read(), { status: 'no_match', result: {} });
  const experiment = reuse(f.settings, f.client);
  f.revoke();
  assert.deepEqual(await experiment.tools.find_prior_method.read(), { status: 'inaccessible', result: {} });
  f.restore();
  f.messages[1].reply_to = 999;
  await assert.rejects(experiment.tools.find_prior_method.read(), /receipt does not match/);
  await assert.rejects(reuse({ ...f.settings, chat: 21 }, f.client).tools.find_prior_method.read(), /separate D inquiry/);
  assert.throws(() => parsePlan(JSON.stringify({ ...find, tool: 'check_export',
    toolInput: { strategy: 'calendar_bounds', ref: 'invented' } }), state(f.target.read()), experiment.tools), /discovered source/);
});

test('revocation after discovery blocks reuse on resume before another model request', async t => {
  const f = await fixture(t);
  const experiment = reuse(f.settings, f.client);
  await run(f.target, model(find, pause), experiment.tools, experiment);
  f.reopen();
  const view = state(f.target.read());
  const { ref } = view.observations[0].body.result;
  const input = experiment.tools.check_export.parseInput({ strategy: 'calendar_bounds', ref }, view);
  f.revoke();
  assert.deepEqual(await experiment.tools.check_export.read(input, f.target.read()), { status: 'inaccessible', result: {} });
  await assert.rejects(run(f.target, model(), experiment.tools, { ...experiment, trigger: 'Try again' }), /dispatch paused/);
  assert.equal(state(f.target.read()).remaining, 2);
  assert.equal(state(f.target.read()).status, 'pause');
});
