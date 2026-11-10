/* Read-only Initium regression check: all returned history entries are listed and
 * both the list page and the conversation pane can scroll when content is tall.
 * Does not send messages, invoke agents, or modify persistent user data.
 */
const assert = require('node:assert/strict');
const live = require('./lib/live.cjs');

live.run('initium-readable', async checks => {
  const { req } = live.client();
  const response = await req('/creations/data');
  const creations = response.creations || [];
  const b = await live.browser();
  try {
    await b.login();
    await b.go(live.APP + '/creations');
    await b.until('!!document.querySelector("#cognos-start-conversation") && !!document.querySelector("#cognos-creations-grid")');
    await b.until(`document.querySelectorAll('.cognos-creation-card').length === ${creations.length}`);
    checks.push('all ' + creations.length + ' Initium conversations shown in list');

    const canScrollList = await b.evaluate(`(() => {
      const pane = document.querySelector('.cognos-creation-space');
      const spacer = document.createElement('div');
      spacer.style.height = '200vh';
      pane.appendChild(spacer);
      pane.scrollTop = 180;
      const ok = pane.scrollTop > 0;
      spacer.remove();
      pane.scrollTop = 0;
      return ok;
    })()`);
    assert(canScrollList, 'Initium list must scroll with long content');
    checks.push('Initium list scrolls');

    if (creations.length) {
      await b.go(live.APP + '/creations/' + creations[0].id);
      await b.until('!!document.querySelector("#messages-container") && !!document.querySelector("#messages-inner")');
      const canScrollMessages = await b.evaluate(`(() => {
        const pane = document.getElementById('messages-container');
        const inner = document.getElementById('messages-inner');
        const spacer = document.createElement('div');
        spacer.style.height = '200vh';
        inner.appendChild(spacer);
        pane.scrollTop = 180;
        const ok = pane.scrollTop > 0;
        spacer.remove();
        return ok;
      })()`);
      assert(canScrollMessages, 'Conversation history must scroll');
      checks.push('conversation history scrolls');
    }
    assert.deepEqual(b.errors, [], 'no uncaught page errors');
  } finally { b.close(); }
});
