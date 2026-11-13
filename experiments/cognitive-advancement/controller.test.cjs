'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');
const { run, state } = require('./controller.cjs');
const { openLedger, lockRun } = require('./ledger.cjs');
const { toolsFor } = require('./cases.cjs');

function create(t, budget = 5) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-advance-'));
  const file = path.join(dir, 'test.sqlite');
  const ledger = openLedger(file);
  ledger.add('inquiry', { question: 'Experiment', budget });
  t.after(() => { ledger.close(); fs.rmSync(dir, { recursive: true }); });
  return { ledger, file };
}

function scripted(...steps) {
  return { name: 'scripted-test', async reply(_protocol, input) {
    assert(steps.length, 'unexpected model call');
    const next = steps.shift();
    return { raw: JSON.stringify(typeof next === 'function' ? next(JSON.parse(input)) : next) };
  } };
}

const terminal = (decision, reason = 'finished') => ({ decision, reason });
const attempt = (tool, prediction) => ({ decision: 'continue', reason: 'test the next possibility', method: 'inspect', tool, prediction });

test('A: a search miss and access denial stay distinct and go into the next prompt', async t => {
  const { ledger } = create(t);
  const tools = {
    inbox: { read: async () => ({ status: 'no_match', result: [] }) },
    restricted: { read: async () => ({ status: 'inaccessible', result: { http: 404 } }) },
  };
  const model = scripted(attempt('inbox'), attempt('restricted'), ({ history }) => {
    assert.deepEqual(history.filter(e => e.type === 'observation').map(e => e.body.status), ['no_match', 'inaccessible']);
    return terminal('ask', 'Need read access from the records owner');
  });
  assert.equal(await run(ledger, model, tools), 'ask');
  assert.equal(await run(ledger, scripted(), tools), 'ask');
  assert.equal(await run(ledger, scripted(({ history }) => {
    assert.equal(history.at(-1).type, 'trigger');
    return terminal('stop');
  }), tools, { trigger: 'Records owner provided the missing limit' }), 'stop');
});

test('B: prediction is durable before the tool runs; corrected judgment changes later input and action', async t => {
  const { ledger, file } = create(t);
  const original = ledger.add('revision', { claim: 'not installed', source: 'case_hypothesis' });
  const tools = {
    status: { async read() {
      const independent = openLedger(file);
      try { assert.equal(independent.read().at(-1).body.prediction, 'installed false'); }
      finally { independent.close(); }
      return { status: 'found', result: { installed: true } };
    } },
    readings: { read: async () => ({ status: 'found', result: { http: 200 } }) },
  };
  const model = scripted(attempt('status', 'installed false'), ({ history }) => {
    const observation = history.find(e => e.type === 'observation');
    assert.equal(observation.body.result.installed, true);
    return { ...attempt('readings'), revision: { claim: 'installed', replaces: original, evidence: [observation.id] } };
  }, ({ activeJudgments }) => {
    assert.deepEqual(activeJudgments.map(e => e.body.claim), ['installed']);
    return terminal('stop');
  });
  assert.equal(await run(ledger, model, tools), 'stop');
  assert.deepEqual(state(ledger.read()).observations.map(e => e.body.source), ['status', 'readings']);
  assert.equal(state(ledger.read()).revisions.length, 2);
});

test('C: stops identical actions despite changed justification, then respects budget on explicit resume', async t => {
  const { ledger } = create(t, 3);
  const first = { ...attempt('empty'), method: 'Search!' };
  const model = scripted(first, { ...first, method: 'search', reason: 'new wording' });
  const tools = { empty: { read: async () => ({ status: 'no_match', result: [] }) } };
  assert.equal(await run(ledger, model, tools), 'pause');
  assert.equal(ledger.read().at(-1).body.reason, 'repeated_attempt');
  assert.equal(await run(ledger, scripted({ decision: 'branch', reason: 'check another hypothesis', method: 'deduce' }), tools,
    { trigger: 'a new hypothesis' }), 'pause');
  assert.equal(ledger.read().at(-1).body.reason, 'budget_exhausted');
  assert.equal(await run(ledger, scripted(), tools, { trigger: 'no budget' }), 'pause');
});

test('D: local experiment runs on real Node and observes rollover', async () => {
  const probe = toolsFor('D', {}, {}).node_date_probe;
  const { status, result } = await probe.read();
  assert.equal(status, 'found');
  assert.equal(result.results[1].input, '2025-02-29');
  assert.equal(result.results[1].output, '2025-03-01');
});

test('H: conceptual counterexample can revise a judgment with no external observation', async t => {
  const { ledger } = create(t);
  const original = ledger.add('revision', { claim: 'Same log implies same inquiry' });
  const model = scripted({ decision: 'branch', reason: 'Test an identical log used for a different aim',
    method: 'Construct a counterexample where the question and the recorded history are copied exactly, but the new agent adopts a different commitment about what would count as resolving it.',
    revision: { claim: 'The commitment matters even if the log is identical', replaces: original } }, ({ activeJudgments }) => {
    assert.equal(activeJudgments[0].body.source, 'model_interpretation');
    return terminal('stop');
  });
  assert.equal(await run(ledger, model, {}), 'stop');
  assert.equal(state(ledger.read()).observations.length, 0);
  assert.equal(state(ledger.read()).revisions.length, 2);
});

test('rejects a fabricated evidence ID and an unapproved tool', async t => {
  const { ledger } = create(t);
  await assert.rejects(run(ledger, scripted({ ...terminal('stop'), revision: { claim: 'verified', evidence: [900] } }), {}), /unknown observation/);
  assert.equal(ledger.read().at(-1).body.reason, 'model_failed');
  await assert.rejects(run(ledger, scripted(attempt('exec')), {}, { trigger: 'retry with a new condition' }), /not allowed/);
  assert.equal(state(ledger.read()).observations.length, 0);
});

test('recovery settles a saved plan without repeating its recorded observation', async t => {
  const { ledger, file } = create(t);
  const saved = ledger.add('attempt', attempt('probe'));
  ledger.add('observation', { attempt: saved, source: 'probe', status: 'found', result: 'kept' });
  const resumed = openLedger(file);
  try {
    await run(resumed, scripted(({ history }) => {
      assert(history.some(e => e.type === 'observation' && e.body.result === 'kept'));
      return terminal('stop');
    }), { probe: { read: async () => assert.fail('must not run the probe again') } });
    assert.equal(state(resumed.read()).observations.length, 1);
  } finally { resumed.close(); }
});

test('interrupted model request pauses and a concurrent runner cannot acquire the lock', async t => {
  const { ledger, file } = create(t);
  const unlock = lockRun(file);
  try { assert.throws(() => lockRun(file), /already has a runner/); }
  finally { unlock(); }
  ledger.add('model_requested', { backend: 'test' });
  assert.equal(await run(ledger, scripted(), {}), 'pause');
  assert.equal(ledger.read().at(-1).body.reason, 'model_result_unapplied');
});
