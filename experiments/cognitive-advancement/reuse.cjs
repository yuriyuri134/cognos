'use strict';

const { isDeepStrictEqual } = require('node:util');
const { openLedger } = require('./ledger.cjs');
const { requestText } = require('./cases.cjs');

const question = 'You are deciding which rows of a partner calendar export to accept. The vendor promises YYYY-MM-DD dates, but its SDK only checks whether Node can parse them. Decide the import rule, run it on the sample batch, and report the accepted and rejected rows with evidence.';
const dates = ['1900-02-29', '2000-02-29', '2026-04-31', '2026-04-30', '2026-4-01'];
const toolInputProtocol = 'Before deciding the import rule, try the listed discovery tool. You can use toolInput for check_export. If you use a discovered method, pass its exact ref with the strategy and cite the resulting observation and source ref in your final revision. You may work independently when discovery yields nothing. The batch check is required before drawing an evidence-backed conclusion.';
const denied = error => [401, 403, 404].includes(error.actual);

function sourceEvidence(file, revisionId, targetChat) {
  const ledger = openLedger(file, { readOnly: true });
  let events;
  try {
    events = ledger.read();
  } finally {
    ledger.close();
  }
  const inquiry = events[0]?.body;
  const chat = inquiry?.config?.chat;
  const revision = events.find(e => e.id === revisionId && e.type === 'revision');
  const attempt = events.find(e => e.id === revision?.body.attempt && e.type === 'attempt');
  const response = events.find(e => e.type === 'model_response' && e.body.request === attempt?.body.request);
  const request = events.find(e => e.id === response?.body.request && e.type === 'model_requested');
  const observation = events.find(e => e.type === 'observation' && e.id < request?.id &&
    revision?.body.evidence.includes(e.id) && e.body.source === 'node_date_probe' && e.body.status === 'found');
  const receipt = response?.body.receipt;
  if (inquiry?.caseId !== 'D' || !Number.isSafeInteger(chat) || chat === targetChat ||
      receipt?.chat !== chat || receipt.author !== 'GPT' ||
      !Number.isSafeInteger(receipt.sent) || !Number.isSafeInteger(receipt.reply) ||
      !observation || !isDeepStrictEqual(attempt?.body.revision, {
        claim: revision.body.claim, evidence: revision.body.evidence, replaces: revision.body.replaces,
      })) throw Error('Source needs an evidenced GPT revision from a separate D inquiry');
  const plan = JSON.parse(response.body.raw.trim().replace(/^```(?:json)?\s*([\s\S]*?)\s*```$/i, '$1'));
  if (plan.revision?.claim?.trim() !== revision.body.claim ||
      !isDeepStrictEqual(plan.revision.evidence, revision.body.evidence) ||
      !JSON.parse(request.body.input).history.some(e => isDeepStrictEqual(e, observation))) {
    throw Error('The source revision or its evidence does not match the model exchange');
  }
  return {
    candidate: {
      ref: `creation:${chat}/message:${receipt.reply}/revision:${revisionId}`,
      claim: revision.body.claim,
      method: plan.method ?? null,
      evidence: { event: observation.id, ...observation.body.result },
      receipt,
    },
    prompt: requestText(request.body.protocol, request.body.input),
    raw: response.body.raw,
  };
}

async function verifyReceipt(client, receipt, prompt, raw) {
  const { messages } = await client.req(`/creations/${receipt.chat}/messages`);
  const sent = messages.find(m => m.id === receipt.sent && m.role === 'user');
  const reply = messages.find(m => m.id === receipt.reply && m.role === 'assistant');
  if (sent?.content !== prompt || reply?.reply_to !== receipt.sent ||
      reply?.author !== 'GPT' || reply?.content !== raw) throw Error('Cognos receipt does not match the saved model exchange');
}

function reuse(config, client) {
  async function readSource() {
    if (!config.source) return { status: 'no_match', result: {} };
    const { file, revision } = config.source;
    const { candidate, prompt, raw } = sourceEvidence(file, revision, config.chat);
    try {
      await verifyReceipt(client, candidate.receipt, prompt, raw);
    } catch (error) {
      if (denied(error)) return { status: 'inaccessible', result: {} };
      throw error;
    }
    return { status: 'found', result: candidate };
  }

  const discoveries = view => view.observations.filter(o => o.body.source === 'find_prior_method' && o.body.status === 'found');
  const tools = {
    find_prior_method: {
      description: 'Look up a method from a separate inquiry about Node calendar-date validation.',
      read: readSource,
    },
    check_export: {
      description: 'Run the fixed export batch in Node. toolInput is {strategy: "parseable"|"calendar_bounds", ref?: "exact prior method ref"}. parseable uses Node Date.parse; calendar_bounds checks YYYY-MM-DD and Gregorian month/day bounds.',
      parseInput(input, view) {
        if (!['parseable', 'calendar_bounds'].includes(input?.strategy)) throw Error('Choose a batch strategy');
        const { strategy, ref } = input;
        if (ref == null) return { strategy };
        const found = discoveries(view).find(o => o.body.result.ref === ref);
        if (!found) throw Error('Reuse must cite a discovered source');
        if (strategy !== 'calendar_bounds') throw Error('The cited method requires calendar bounds');
        return { strategy, ref, discovery: found.id };
      },
      async read({ strategy, ref, discovery }, events) {
        if (ref) {
          const current = await readSource();
          if (current.status !== 'found') return current;
          if (!isDeepStrictEqual(current.result, events.find(e => e.id === discovery)?.body.result)) {
            throw Error('The discovered method changed');
          }
        }
        const rows = dates.map(input => {
          const parsed = Date.parse(input);
          const iso = Number.isNaN(parsed) ? null : new Date(parsed).toISOString().slice(0, 10);
          const accepted = strategy === 'parseable' ? iso !== null : validCalendarDate(input);
          return { input, iso, accepted };
        });
        return { status: 'found', result: { strategy, ref, discovery, runtime: process.version, rows } };
      },
    },
  };
  return {
    tools,
    toolInputProtocol,
    async beforeDispatch(view) {
      const previous = discoveries(view);
      if (!previous.length) return;
      const current = await readSource();
      if (current.status !== 'found' || previous.some(o => !isDeepStrictEqual(o.body.result, current.result))) {
        throw Error('The discovered source is unavailable or changed. Model dispatch paused.');
      }
    },
  };
}

function validCalendarDate(input) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(input)) return false;
  const [year, month, day] = input.split('-').map(Number);
  const leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
  const lengths = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
  return day > 0 && day <= lengths[month - 1];
}

module.exports = { question, reuse, sourceEvidence, verifyReceipt };
