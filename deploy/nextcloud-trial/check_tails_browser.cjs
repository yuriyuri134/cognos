/* Authenticated browser checks for the project overview page and the side-by-side compares, using a fresh headless profile.
 * Runs after check_tails.cjs and check_delegate.cjs. Credentials are read locally, never printed.
 */
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const root = __dirname, origin = 'http://127.0.0.1:8780', base = origin + '/index.php/apps/cognos';
const evidenceDir = path.join(root, 'evidence');
const tails = JSON.parse(fs.readFileSync(path.join(evidenceDir, 'tails-api.json'), 'utf8'));
const delegated = JSON.parse(fs.readFileSync(path.join(evidenceDir, 'delegate-api.json'), 'utf8'));
const password = fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-tails-ui-'));
const browser = spawn(process.env.COGNOS_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe',
  ['--headless=new', '--disable-gpu', '--no-first-run', '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank'],
  {windowsHide: true, stdio: 'ignore'});
let ws, counter = 0;
const pending = new Map(), errors = [], checks = [];
const report = {at: new Date().toISOString(), checks, javascript_errors: errors};
const sleep = ms => new Promise(r => setTimeout(r, ms));
function call(method, params = {}) {
  return new Promise((resolve, reject) => {
    const id = ++counter;
    const timer = setTimeout(() => {pending.delete(id); reject(Error('Browser timeout: ' + method));}, 20000);
    pending.set(id, {resolve: x => {clearTimeout(timer); resolve(x);}, reject: e => {clearTimeout(timer); reject(e);}});
    ws.send(JSON.stringify({id, method, params}));
  });
}
async function evaluate(expression) {
  const r = await call('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true});
  if (r.exceptionDetails) throw Error('Browser evaluation failed: ' + (r.exceptionDetails.exception?.description?.split('\n')[0] || r.exceptionDetails.text));
  return r.result.value;
}
async function until(expression, tries = 150) {
  for (let i = 0; i < tries; i++) {try {if (await evaluate(expression)) return;} catch {} await sleep(200);}
  throw Error('Browser condition did not become true: ' + expression.slice(0, 120));
}
async function go(url) {await call('Page.navigate', {url});}
async function click(selector) {
  const point = await evaluate('(() => {const e=document.querySelector(' + JSON.stringify(selector) + ');e.scrollIntoView({block:"center"});const r=e.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2}})()');
  await call('Input.dispatchMouseEvent', {type: 'mousePressed', button: 'left', clickCount: 1, ...point});
  await call('Input.dispatchMouseEvent', {type: 'mouseReleased', button: 'left', clickCount: 1, ...point});
}
async function fill(selector, text) {
  await evaluate('document.querySelector(' + JSON.stringify(selector) + ').focus()');
  await call('Input.dispatchKeyEvent', {type: 'rawKeyDown', key: 'a', code: 'KeyA', modifiers: 2, windowsVirtualKeyCode: 65});
  await call('Input.dispatchKeyEvent', {type: 'keyUp', key: 'a', code: 'KeyA', modifiers: 2, windowsVirtualKeyCode: 65});
  await call('Input.insertText', {text});
}
async function shoot(name, selector) {
  await evaluate('document.querySelector(' + JSON.stringify(selector) + ').scrollIntoView({block:"start"})');
  await sleep(300);
  const shot = await call('Page.captureScreenshot');
  fs.writeFileSync(path.join(evidenceDir, name), Buffer.from(shot.data, 'base64'));
}
async function main() {
  let port;
  for (let i = 0; i < 100; i++) {
    try {port = Number(fs.readFileSync(path.join(profile, 'DevToolsActivePort'), 'utf8').split('\n')[0]); if (port) break;} catch {}
    await sleep(100);
  }
  if (!port) throw Error('Headless browser did not start');
  const targets = await (await fetch('http://127.0.0.1:' + port + '/json')).json();
  ws = new WebSocket(targets.find(t => t.type === 'page').webSocketDebuggerUrl);
  await new Promise((r,j) => {ws.addEventListener('open', r, {once:true}); ws.addEventListener('error', j, {once:true});});
  ws.addEventListener('message', e => {
    const d = JSON.parse(e.data);
    if (d.method === 'Runtime.exceptionThrown') errors.push(d.params.exceptionDetails.text);
    if (d.id) {const p = pending.get(d.id); if (!p) return; pending.delete(d.id); d.error ? p.reject(Error(d.error.message)) : p.resolve(d.result);}
  });
  await call('Page.enable'); await call('Runtime.enable');
  // 离开有未保存修改的页面时的确认框一律点确定
  ws.addEventListener('message', e => {
    const d = JSON.parse(e.data);
    if (d.method === 'Page.javascriptDialogOpening') call('Page.handleJavaScriptDialog', {accept: true}).catch(() => {});
  });
  await call('Emulation.setDeviceMetricsOverride', {width: 1360, height: 1000, deviceScaleFactor: 1, mobile: false});
  await go(origin + '/index.php/login');
  await until('!!document.querySelector("#password")');
  // Values never enter tool output; only the login result is recorded.
  await evaluate('(() => {for (const [selector,value] of ' + JSON.stringify([['#user','cognos-admin'],['#password',password]]) +
    ') {const e=document.querySelector(selector);e.value=value;e.dispatchEvent(new Event("input",{bubbles:true}));}document.querySelector("#password").closest("form").requestSubmit();})()');
  await until('!location.pathname.includes("/login") && !!document.querySelector("#app-content")');

  // 项目总览：不是根位置页
  await go(base + '/projects/' + tails.project_id);
  await until('!document.querySelector("#pw-overview").hidden && document.querySelectorAll("#pw-location-states .pw-state").length >= 2 && document.querySelectorAll("#pw-recent li").length >= 1');
  assert.equal(await evaluate('document.querySelector("#pw-title").textContent'), await evaluate('document.title.split(" · ")[0]'));
  assert(await evaluate('document.querySelector("#pw-title").textContent.startsWith("验证：项目总览和依据")'), 'title is the project title');
  assert(await evaluate('[...document.querySelectorAll("[data-location-only]")].every(n => n.hidden)'), 'location-only sections hidden on the overview');
  assert.equal(await evaluate('document.querySelector("#pw-overview-link").getAttribute("aria-current")'), 'page');
  const states = await evaluate('document.querySelector("#pw-location-states").textContent');
  assert(states.includes('入口怎么排') && states.includes('当前内容版本 2') && states.includes('当前内容还是空的'), states);
  const recent = await evaluate('document.querySelector("#pw-recent").textContent');
  assert(recent.includes('依据了') && recent.includes('「入口怎么排」当前内容版本 2'), recent);
  const decisionsText = await evaluate('document.querySelector("#pw-decisions").textContent');
  assert(decisionsText.includes('入口里不放固定的开头和结尾') && decisionsText.includes('回应里依据了它'), decisionsText);
  checks.push('project overview is its own page: project title, decisions with "进了输入" and "回应里依据了它", every place\'s state, recent results with their reported basis; location sections hidden');
  await shoot('tails-overview-desktop.png', '.pw-header');
  await call('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: false});
  await sleep(400);
  assert(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'overview must not overflow at 390px');
  await shoot('tails-overview-mobile.png', '#pw-states-title');
  await call('Emulation.setDeviceMetricsOverride', {width: 1360, height: 1000, deviceScaleFactor: 1, mobile: false});
  checks.push('overview at 390px has no horizontal overflow');

  // 位置页上回到总览
  await go(base + '/projects/locations/' + tails.location_id);
  await until('!document.querySelector("#pw-work-view").hidden && document.querySelector("#pw-title").textContent === "入口怎么排"');
  assert(await evaluate('document.querySelector("#pw-overview").hidden && document.querySelector("#pw-overview-link").getAttribute("href").endsWith("/projects/' + tails.project_id + '")'));
  checks.push('a location page links back to the project overview');

  // 留着待比较的版本：和当前稿并排
  await go(base + '/projects/locations/' + delegated.location_id);
  await until('document.querySelectorAll("#pw-delegations .pw-delegation").length >= 3');
  await evaluate('[...document.querySelectorAll("#pw-delegations .pw-delegation details")].find(d => d.textContent.includes("并排看")).open = true');
  await until('!!document.querySelector("#pw-delegations .pw-compare")');
  const cmp = await evaluate('(() => {const c=document.querySelector("#pw-delegations .pw-compare");const cols=[...c.children];return {cols: cols.length, heads: cols.map(x=>x.querySelector("h4").textContent), del: c.querySelectorAll(".pw-del").length, ins: c.querySelectorAll(".pw-ins").length, side: getComputedStyle(c).gridTemplateColumns.split(" ").length};})()');
  assert.equal(cmp.cols, 2); assert.equal(cmp.side, 2, 'two columns side by side on desktop');
  assert(/^当前稿（版本 \d+）$/.test(cmp.heads[0]) && cmp.heads[1].includes('基于版本 3'), JSON.stringify(cmp.heads));
  assert(cmp.del + cmp.ins > 0, 'lines only on one side are marked');
  report.compare = cmp;
  await shoot('tails-compare-desktop.png', '#pw-delegations .pw-compare');
  checks.push('a kept version and the current draft are shown side by side, lines only on one side marked');

  // 保存冲突：你的文本和已保存的版本并排
  await fill('#pw-content', await evaluate('document.querySelector("#pw-content").value') + '\n浏览器里加的一行。');
  await evaluate('(async () => {const r=await fetch(OC.generateUrl("/apps/cognos/projects/locations/' + delegated.location_id + '/data"),{headers:{Accept:"application/json",requesttoken:OC.requestToken}});const d=await r.json();' +
    'await fetch(OC.generateUrl("/apps/cognos/projects/locations/' + delegated.location_id + '/content"),{method:"PUT",headers:{Accept:"application/json","Content-Type":"application/json",requesttoken:OC.requestToken},' +
    'body:JSON.stringify({expected_revision:d.location.revision,content:d.location.content+"\\n别处同时加的一行。",source_message_ids:[],request_id:crypto.randomUUID()})});})()');
  await click('#pw-save');
  await until('!document.querySelector("#pw-conflict").hidden && !!document.querySelector("#pw-conflict .pw-compare")');
  const conflict = await evaluate('(() => {const c=document.querySelector("#pw-conflict .pw-compare");return {heads:[...c.querySelectorAll("h4")].map(h=>h.textContent), del:[...c.querySelectorAll(".pw-del")].map(l=>l.textContent), ins:[...c.querySelectorAll(".pw-ins")].map(l=>l.textContent)};})()');
  assert(/^已保存的版本 \d+（cognos-admin）$/.test(conflict.heads[0]) && conflict.heads[1] === '你上面的文本', JSON.stringify(conflict.heads));
  assert.deepEqual(conflict.del, ['别处同时加的一行。']); assert.deepEqual(conflict.ins, ['浏览器里加的一行。']);
  report.conflict = conflict;
  await call('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: false});
  await sleep(400);
  assert(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'compare must not overflow at 390px');
  await shoot('tails-conflict-mobile.png', '#pw-conflict');
  checks.push('save conflict shows the saved version and your text side by side (stacked at 390px), each line only on one side marked; nothing overwritten');
  assert.equal(errors.length, 0, 'no uncaught JavaScript exceptions');
  report.result = 'pass';
}
main().catch(e => {report.result='fail';report.error=e.message;process.exitCode=1;}).finally(() => {
  fs.mkdirSync(evidenceDir,{recursive:true});fs.writeFileSync(path.join(evidenceDir,'tails-browser.json'),JSON.stringify(report,null,2));
  console.log(JSON.stringify(report,null,2));if(ws)ws.close();browser.kill();
});
