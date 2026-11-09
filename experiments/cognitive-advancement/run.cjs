'use strict';

const fs = require('node:fs');
const path = require('node:path');
const live = require('../../deploy/nextcloud-trial/lib/live.cjs');
const { cases, setup, modelFor, toolsFor } = require('./cases.cjs');
const { run, state } = require('./controller.cjs');
const { openLedger, lockRun } = require('./ledger.cjs');

const usage = 'Usage: node run.cjs start <A|B|C|D|H> <new.sqlite> | resume <existing.sqlite> <new condition> | inspect <existing.sqlite>';

async function main([command, ...args]) {
  const starting = command === 'start';
  if (!['start', 'resume', 'inspect'].includes(command) || args.length !== (command === 'inspect' ? 1 : 2)) throw Error(usage);
  const caseId = starting ? args[0] : null;
  if (starting && !Object.hasOwn(cases, caseId)) throw Error(usage);
  const file = path.resolve(args[starting ? 1 : 0]);
  if (starting) fs.mkdirSync(path.dirname(file), { recursive: true });
  else if (!fs.existsSync(file)) throw Error('Inquiry ledger not found');

  const unlock = command === 'inspect' ? () => {} : lockRun(file);
  let ledger;
  try {
    if (starting && fs.existsSync(file)) throw Error('Choose a fresh ledger for start');
    ledger = openLedger(file, { readOnly: command === 'inspect' });
    if (starting) {
      const { question, budget, initial } = cases[caseId];
      const config = await setup(caseId, live);
      ledger.add('inquiry', { caseId, question, budget, source: 'original design, synthetic verification case', config });
      if (initial) ledger.add('revision', { claim: initial, evidence: [], replaces: null, source: 'case_hypothesis' });
    }
    const inquiry = ledger.read()[0];
    if (!inquiry) throw Error('Inquiry setup did not complete');
    if (command === 'inspect') {
      const events = ledger.read();
      console.log(JSON.stringify({ status: state(events).status, events }, null, 2));
      return;
    }
    const { config, caseId: id } = inquiry.body;
    console.log(`Case ${id} test chat ${config.chat}, ledger ${file}`);
    const outcome = await run(ledger, modelFor(live, config.chat), toolsFor(id, config, live), { trigger: starting ? undefined : args[1] });
    console.log(`Case ${id}: ${outcome}`);
  } finally {
    ledger?.close();
    unlock();
  }
}

main(process.argv.slice(2)).catch(error => {
  console.error(error.message);
  process.exitCode = 1;
});
