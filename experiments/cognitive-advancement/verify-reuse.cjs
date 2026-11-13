'use strict';

const assert = require('node:assert/strict');
const { openLedger } = require('./ledger.cjs');
const { requestText } = require('./cases.cjs');
const { reuse, verifyReceipt } = require('./reuse.cjs');

function read(file) {
  const ledger = openLedger(file, { readOnly: true });
  try {
    return ledger.read();
  } finally {
    ledger.close();
  }
}

function summarize(events, treatment) {
  const { config, budget, caseId } = events[0].body;
  assert.equal(caseId, 'E');
  assert.equal(Boolean(config.source), treatment);
  const calls = events.filter(e => e.type === 'model_requested');
  const replies = events.filter(e => e.type === 'model_response');
  const observations = events.filter(e => e.type === 'observation');
  const lookup = observations.find(e => e.body.source === 'find_prior_method' &&
    e.body.status === (treatment ? 'found' : 'no_match'));
  const ref = lookup?.body.result.ref;
  const application = observations.find(e => e.id > lookup?.id && e.body.source === 'check_export' &&
    e.body.status === 'found' && e.body.result.strategy === 'calendar_bounds' &&
    e.body.result.ref === ref && e.body.result.discovery === (treatment ? lookup.id : undefined));
  const revision = events.filter(e => e.type === 'revision').at(-1);
  const cited = Boolean(application && revision?.id > application.id &&
    revision.body.evidence.includes(application.id) && (!treatment || revision.body.claim.includes(ref)));
  const rows = application?.body.result.rows ?? [];
  const correct = JSON.stringify(rows.map(row => [row.input, row.accepted])) === JSON.stringify([
    ['1900-02-29', false], ['2000-02-29', true], ['2026-04-31', false], ['2026-04-30', true], ['2026-4-01', false],
  ]);
  const failures = events.filter(e => e.type === 'model_failed').map(e => e.body.reason);
  const complete = events.at(-1).body.decision === 'stop' && replies.length === calls.length && !failures.length &&
    calls.length <= budget && Boolean(lookup) && cited && correct;
  return { chat: config.chat, budget, calls: calls.length, receipts: replies.map(e => e.body.receipt),
    lookup: lookup?.id, ref, application: application?.id, revision: revision?.id, cited, correct, rows, complete,
    failures };
}

async function main([controlFile, treatmentFile, flag]) {
  assert.ok(controlFile && treatmentFile && (flag == null || flag === '--live'),
    'Usage: node verify-reuse.cjs <control.sqlite> <treatment.sqlite> [--live]');
  const control = read(controlFile);
  const treatment = read(treatmentFile);
  const reports = [summarize(control, false), summarize(treatment, true)];
  assert.notEqual(reports[0].chat, reports[1].chat);
  assert.equal(reports[0].budget, reports[1].budget);
  assert.equal(control[0].body.question, treatment[0].body.question);
  const firstRequest = events => events.find(e => e.type === 'model_requested')?.body;
  assert.deepEqual(firstRequest(control), firstRequest(treatment), 'The first request must match in both arms');
  console.log(JSON.stringify({ control: reports[0], treatment: reports[1] }, null, 2));
  if (flag === '--live') {
    const live = require('../../deploy/nextcloud-trial/lib/live.cjs');
    const client = live.client();
    for (const events of [control, treatment]) {
      for (const reply of events.filter(e => e.type === 'model_response')) {
        const request = events.find(e => e.id === reply.body.request);
        assert.equal(reply.body.receipt.chat, events[0].body.config.chat);
        await verifyReceipt(client, reply.body.receipt, requestText(request.body.protocol, request.body.input), reply.body.raw);
      }
    }
    const config = treatment[0].body.config;
    const found = await reuse(config, client).tools.find_prior_method.read();
    assert.equal(found.status, 'found');
    assert.equal(found.result.ref, reports[1].ref);
    const inaccessible = await reuse(config, live.client(live.outsider())).tools.find_prior_method.read();
    assert.deepEqual(inaccessible, { status: 'inaccessible', result: {} });
    console.log('Live receipts and the separate account’s access denial verified.');
  }
  if (!reports.every(report => report.complete)) process.exitCode = 1;
}

main(process.argv.slice(2)).catch(error => {
  console.error(error.message);
  process.exitCode = 1;
});
