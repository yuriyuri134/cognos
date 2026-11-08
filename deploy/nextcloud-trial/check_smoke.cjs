/* Quick look that the live trial is up after a deploy: the two entries (Initium, Norm) and a Norm project open logged in
 * at desktop and 390px without uncaught errors or horizontal overflow. No agent call, no data written. Uses lib/live.cjs.
 * Report in evidence/smoke.json; screenshots evidence/smoke-*.png.
 */
const assert = require('node:assert/strict');
const live = require('./lib/live.cjs');

live.run('smoke', async checks => {
  const {req} = live.client();
  const norm = await req('/norm/data');
  checks.push('API answers as cognos-admin (' + norm.projects.length + ' Norm projects)');
  const b = await live.browser();
  try {
    await b.login();
    const pages = [['home', '/', '.cg-door'], ['initium', '/creations', '#cognos-creation-message'], ['norm', '/norm', '.cg-chat-form']];
    if (norm.projects.length) pages.push(['project', '/norm/projects/' + norm.projects[0].id, '#cg-space-chat .cg-chat-form']);
    for (const [name, route, ready] of pages) {
      for (const [size, set] of [['desktop', b.desktop], ['phone', b.phone]]) {
        await set();
        await b.go(live.APP + route);
        await b.until('document.querySelectorAll(' + JSON.stringify(ready) + ').length > 0');
        assert(await b.noOverflow(), name + ' overflows at ' + size);
        await b.shoot('smoke-' + name + '-' + size + '.png');
      }
      checks.push(name + ' opens logged in, desktop and 390px, no horizontal overflow');
    }
    assert.deepEqual(b.errors, [], 'no uncaught page errors');
  } finally { b.close(); }
});
