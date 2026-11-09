'use strict';

const cases = {
  A: { budget: 5, question: 'Find the approval limit for synthetic project Meridian. Its case file is indexed as MERIDIAN-LIMIT. The project inbox is visible; the records office is a separate space. Work out what you can establish and what next action is warranted.' },
  B: { budget: 5, question: 'Should we start installing the local Nextcloud, or continue the trial by checking the Cognos readings route? An older handoff says the service on port 8780 is not installed. Make a decision based on the current environment and take the appropriate next read-only step.', initial: 'The local Nextcloud on port 8780 is not installed, so installation must precede trying the Cognos readings route.' },
  C: { budget: 5, question: 'Recover the code for a sealed offline box. The owner chose a random, unrecorded six-digit code and is unavailable. There is no log, and trying codes on the box is out of scope. Decide what you can establish and what new condition would allow the inquiry to advance if the code remains undetermined.' },
  D: { budget: 5, question: 'A teammate reviewed the ingestion design on paper and concluded that Node Date.parse rejects invalid calendar dates. The ingestion system relies on that to reject bad date-only records. The code review alone is disputed. Resolve what happens for a leap day and what it means for validation.' },
  H: { budget: 5, question: 'When an agent resumes from a saved inquiry, what could make the inquiry the same inquiry? Start with the tentative view below. Find a case that puts the view under pressure, then see whether your replacement criterion survives a different case.', initial: 'If the question and saved history are identical after restart, it is necessarily the same inquiry.' },
};

const routeFor = id => `/creations/${id}`;
const traceRoute = id => `/spaces/creation/${id}/traces?q=MERIDIAN-LIMIT`;

async function createSpace(client, name) {
  const { creation } = await client.req('/creations/data', 'POST', {
    title: `验证：认知推进 ${name} ${new Date().toISOString()}`,
  }, 201);
  return creation.id;
}

async function setup(caseId, live) {
  const primary = live.client();
  const chat = await createSpace(primary, caseId);
  await primary.req(routeFor(chat) + '/agent', 'POST', { agent: 'gpt', reasoning_effort: 'low' });
  if (caseId !== 'A') return { chat };

  const recordsOwner = live.client(live.outsider());
  const records = await createSpace(recordsOwner, 'A 档案（合成）');
  const { trace } = await recordsOwner.req(routeFor(records) + '/space/traces', 'POST', {
    kind: 'fact', source: '世', content: 'MERIDIAN-LIMIT: approval limit is 730 synthetic units.',
  }, 201);
  const verification = await recordsOwner.api(traceRoute(records));
  if (!verification.traces.some(t => t.id === trace.id)) throw Error('Fixture owner cannot find the seeded trace');
  return { chat, records, fixtureTrace: trace.id };
}

function modelFor(live, chat) {
  const client = live.client();
  const route = routeFor(chat) + '/messages';
  return {
    name: 'cognos-gpt-bridge',
    async reply(protocol, input) {
      const { agent } = await client.req(routeFor(chat) + '/agent');
      if (agent !== 'gpt') throw Error('The experiment requires the read-only GPT bridge in its test space');
      const { pending } = await client.req(route);
      if (pending) throw Error('The test space still has a pending model run; inspect it before resuming');
      const sent = await client.req(route, 'POST', { message: `${protocol}\n\nInquiry input:\n${input}` });
      const userMessage = sent.messages.find(m => m.role === 'user');
      if (!userMessage || sent.who !== 'GPT' || !sent.pending || sent.queued) throw Error('Cognos did not accept a fresh GPT run');
      const deadline = Date.now() + 150_000;
      while (Date.now() < deadline) {
        const { messages } = await client.req(route);
        const reply = messages.find(m => m.role === 'assistant' && m.reply_to === userMessage.id);
        if (reply) {
          if (reply.author !== 'GPT') throw Error('Unexpected model author');
          return { raw: reply.content, receipt: { chat, sent: userMessage.id, reply: reply.id, author: reply.author } };
        }
        await live.sleep(1500);
      }
      throw Error(`GPT result timeout in test space ${chat}, message ${userMessage.id}`);
    },
  };
}

function toolsFor(caseId, config, live) {
  const tools = {};
  if (caseId === 'A') {
    const client = live.client();
    const search = (id, description) => ({ description, async read() {
      const route = traceRoute(id);
      try {
        const { traces } = await client.api(route);
        return { status: traces.length ? 'found' : 'no_match', result: {
          route, http: 200, traces: traces.map(({ id, content }) => ({ id, content })),
        } };
      } catch (error) {
        if ([401, 403, 404].includes(error.actual)) {
          // Cognos intentionally collapses missing and private spaces into 404 for this actor.
          return { status: 'inaccessible', result: { route, http: error.actual, visibility: 'existence not established by this response' } };
        }
        throw error;
      }
    } });
    tools.inbox_search = search(config.chat, 'Search the project inbox traces for MERIDIAN-LIMIT.');
    tools.records_search = search(config.records, 'Try the same trace search in the separate records space using the inquiry account.');
  }
  if (caseId === 'B') {
    tools.local_status = { description: 'Read the current Nextcloud status endpoint.', async read() {
      const url = live.ORIGIN + '/status.php';
      const response = await fetch(url, { redirect: 'error', signal: AbortSignal.timeout(5000) });
      if (!response.ok) throw Error(`HTTP ${response.status}`);
      const { installed, maintenance, version } = await response.json();
      if (typeof installed !== 'boolean') throw Error('Missing installed flag');
      return { status: 'found', result: { url, http: response.status, installed, maintenance, version } };
    } };
    tools.cognos_readings = { description: 'Check the authenticated Cognos readings API and return only the response shape.', async read() {
      const payload = await live.client().req('/readings/data');
      return { status: 'found', result: { route: '/readings/data', http: 200, keys: Object.keys(payload).sort() } };
    } };
  }
  if (caseId === 'D') {
    tools.node_date_probe = { description: 'Run Node Date.parse locally on 2024-02-29, 2025-02-29, 2025-02-28 and 2025-02-30; report the resulting ISO date or null.', async read() {
      const inputs = ['2024-02-29', '2025-02-29', '2025-02-28', '2025-02-30'];
      const results = inputs.map(input => {
        const millis = Date.parse(input);
        return { input, output: Number.isNaN(millis) ? null : new Date(millis).toISOString().slice(0, 10) };
      });
      return { status: 'found', result: { runtime: process.version, results } };
    } };
  }
  return tools;
}

module.exports = { cases, setup, modelFor, toolsFor };
