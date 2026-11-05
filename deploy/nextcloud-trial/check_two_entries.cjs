/* The two entries end to end, as cognos-admin in a real browser:
 * 1. Initium (/creations): write one line, press 写下, land in the conversation, and the agent answers by itself
 *    (nothing else clicked). 2. Norm: send one line in the chat box and get an answer.
 * Also screenshots home, Norm, the cognos project's three tabs. Calls the real agent through the bridge, writes data.
 * Report in evidence/two-entries.json; screenshots evidence/two-entries-*.png.
 */
const live = require('./lib/live.cjs');

const agentReplied = (id, after) => `fetch('/index.php/apps/cognos/creations/${id}/messages',{credentials:'same-origin',headers:{Accept:'application/json',requesttoken:OC.requestToken}})
  .then(r=>r.json()).then(b=>{const m=b.messages||[];return m.length>${after} && m.slice(${after}).some(x=>x.role!=='user') && !b.pending;})`;

live.run('two-entries', async checks => {
  const b = await live.browser();
  try {
    await b.login();
    const line = '试一下：agent 会不会自己读 ' + new Date().toISOString().slice(0, 16);

    // Initium
    await b.go(live.APP + '/creations');
    await b.until('!!document.querySelector("#cognos-creation-message")');
    await b.fill('#cognos-creation-message', line);
    await b.click('#cognos-creation-form button[type=submit]');
    await b.until('/\\/creations\\/[0-9]+$/.test(location.pathname)');
    const cid = await b.evaluate('location.pathname.split("/").pop()');
    await b.until(`document.body.innerText.includes(${JSON.stringify(line)})`);
    checks.push('Initium: line written, landed in conversation ' + cid + ' and the line is shown');
    await b.until(agentReplied(cid, 1), 300 * 5); // up to ~5 min, nothing clicked
    await live.sleep(5000); // let the page's own poll render the reply
    await b.shoot('two-entries-initium-reply.png');
    checks.push('Initium: agent replied by itself');

    // Norm
    await b.go(live.APP + '/norm');
    await b.until('!!document.querySelector(".cg-chat-form") && !!document.querySelector("#cg-norm-main a")');
    await b.shoot('two-entries-norm-before.png');
    const normId = await b.evaluate(`fetch('/index.php/apps/cognos/norm/data',{credentials:'same-origin',headers:{Accept:'application/json',requesttoken:OC.requestToken}}).then(r=>r.json()).then(d=>d.conversation_id)`);
    const before = await b.evaluate(`fetch('/index.php/apps/cognos/creations/${normId}/messages',{credentials:'same-origin',headers:{Accept:'application/json',requesttoken:OC.requestToken}}).then(r=>r.json()).then(b=>(b.messages||[]).length)`);
    await b.fill('.cg-chat-form textarea', '在 Norm 这边说一句：你看得到主项目 cognos 吗？');
    await b.click('.cg-chat-form button[type=submit]');
    await b.until(agentReplied(normId, before + 1), 300 * 5);
    await live.sleep(5000);
    await b.shoot('two-entries-norm-reply.png');
    checks.push('Norm: message sent in conversation ' + normId + ', agent replied');

    // Home and the project's three tabs
    await b.go(live.APP + '/');
    await b.until('!!document.querySelector(".cg-door")');
    await b.shoot('two-entries-home.png');
    await b.go(live.APP + '/norm');
    await b.until('!!document.querySelector("#cg-norm-main a")');
    const href = await b.evaluate('document.querySelector("#cg-norm-main a").getAttribute("href")');
    await b.go(live.ORIGIN + href);
    await b.until('document.querySelectorAll("#cg-stages li").length > 0');
    await b.shoot('two-entries-project-stages.png');
    for (const tab of ['branches', 'map']) {
      await b.click(`.cg-tabs button[data-tab=${tab}]`);
      await b.until(`!document.querySelector("#cg-tab-${tab}").hidden`);
      await live.sleep(500);
      const vis = await b.evaluate(`['stages','branches','map'].filter(t=>getComputedStyle(document.getElementById('cg-tab-'+t)).display!=='none').join(',')`);
      if (vis !== tab) throw Error('after clicking ' + tab + ' these tabs are visible: ' + vis);
      await b.shoot(`two-entries-project-${tab}.png`);
    }
    checks.push('home, Norm and the cognos project (3 tabs) screenshotted');
    if (b.errors.length) throw Error('uncaught page errors: ' + b.errors.join(' | '));
  } finally { b.close(); }
});
