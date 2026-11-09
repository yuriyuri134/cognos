'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const { openLedger } = require('./ledger.cjs');
const { state } = require('./controller.cjs');

function verify(caseId, file) {
  const ledger = openLedger(file, { readOnly: true });
  let events;
  try { events = ledger.read(); }
  finally { ledger.close(); }
  const { attempts, observations, revisions, status, remaining } = state(events);
  const { config, budget } = events[0].body;
  assert.equal(events[0].body.caseId, caseId);
  assert(!events.some(e => e.type === 'model_failed'));
  const replies = events.filter(e => e.type === 'model_response').map(e => e.body.receipt);
  assert(replies.length > 0 && replies.length === budget - remaining);
  assert(replies.every(r => r.author === 'GPT' && r.chat === config.chat && r.reply > r.sent));
  assert.equal(new Set(replies.map(r => r.sent)).size, replies.length);
  const observation = source => {
    const match = observations.find(e => e.body.source === source);
    assert(match, `Missing ${source} observation`);
    const attempt = attempts.find(a => a.id === match.body.attempt);
    assert(attempt && attempt.id < match.id, 'Attempt must precede observation');
    return [match, attempt];
  };
  if (caseId === 'A') {
    const [inbox] = observation('inbox_search');
    const [records] = observation('records_search');
    assert.equal(inbox.body.status, 'no_match');
    assert.equal(records.body.status, 'inaccessible');
    assert.equal(records.body.result.http, 404);
    assert(config.fixtureTrace && config.records !== config.chat);
    assert(['ask', 'pause'].includes(status));
  }
  if (caseId === 'B') {
    const [host, first] = observation('local_status');
    const [readings, second] = observation('cognos_readings');
    assert(first.body.prediction);
    assert.equal(host.body.result.installed, true);
    assert.equal(readings.body.result.http, 200);
    assert(host.id < second.id);
    const correction = revisions.find(r => r.body.replaces === revisions[0].id && r.body.evidence.includes(host.id));
    assert(correction && correction.id < readings.id);
    assert(attempts.some(a => a.id > readings.id && JSON.parse(events.find(e => e.id === a.body.request).body.input)
      .history.some(e => e.id === correction.id)), 'Next model call must receive the correction');
    assert.equal(status, 'stop');
  }
  if (caseId === 'C') {
    assert.equal(observations.length, 0);
    assert(['ask', 'pause'].includes(status));
    assert(remaining > 0, 'Model should stop trying before the budget is exhausted');
  }
  if (caseId === 'D') {
    const [probe, plan] = observation('node_date_probe');
    assert(plan.body.prediction);
    assert(probe.body.result.results.some(r => r.input === '2025-02-29' && r.output === '2025-03-01'));
    assert(revisions.some(r => r.body.evidence.includes(probe.id)));
    assert.equal(status, 'stop');
  }
  if (caseId === 'H') {
    assert.equal(observations.length, 0);
    assert(revisions.some(r => r.body.replaces === revisions[0].id));
    assert(new Set(attempts.map(a => a.body.method).filter(Boolean)).size >= 2);
    assert.equal(status, 'stop');
  }
  return { case: caseId, chat: config.chat, status, replies,
    observations: observations.map(({ id, body }) => ({ id, ...body })),
    revisions: revisions.map(({ id, body }) => ({ id, ...body })),
    continuation: events.at(-1).body };
}

const [dir, suffix] = process.argv.slice(2);
if (!dir || !suffix) throw Error('Usage: node verify.cjs <ledger directory> <run suffix>');
for (const name of ['A', 'B', 'C', 'D', 'H']) {
  try {
    console.log(JSON.stringify(verify(name, path.join(dir, `${name}-${suffix}.sqlite`))));
  } catch (error) {
    console.error(`${name}: ${error.message}`);
    process.exitCode = 1;
  }
}
