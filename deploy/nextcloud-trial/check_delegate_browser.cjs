/* Authenticated browser checks for "交出去" on a project location, using a fresh headless profile.
 * Runs after check_delegate.cjs. Credentials are read locally, never printed.
 * Looks at what the three real hand-offs left on the page, then hands off one more (answer only) through the form.
 */
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const root = __dirname, origin = 'http://127.0.0.1:8780', base = origin + '/index.php/apps/cognos';
const evidenceDir = path.join(root, 'evidence');
const fixture = JSON.parse(fs.readFileSync(path.join(evidenceDir, 'delegate-api.json'), 'utf8'));
const password = fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-delegate-ui-'));
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
  assert.equal(fixture.result, 'pass', 'run check_delegate.cjs first');
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

  await go(base + '/projects/locations/' + fixture.location_id);
  await until('document.querySelectorAll("#pw-delegations .pw-delegation").length >= 3');
  const items = await evaluate('[...document.querySelectorAll("#pw-delegations .pw-delegation")].map(li => li.textContent)');
  const [answerOnly, held, applied] = items;
  assert(applied.includes('写成了当前内容的版本 3') && applied.includes('交回了结果'), 'applied hand-off shows the revision it wrote');
  assert(held.includes('没有覆盖') && held.includes('被改过'), 'held hand-off says nothing was overwritten and why');
  assert(answerOnly.includes('只要回答') && !answerOnly.includes('写成了') && !answerOnly.includes('没有覆盖'), 'answer-only hand-off is just an answer');
  assert.equal(await evaluate('document.querySelector("#pw-revision").textContent'), '版本 4');
  checks.push('three real hand-offs on the page: one wrote revision 3, one kept its version to compare (no overwrite), one only answered; draft shows revision 4');
  const resultLink = await evaluate('document.querySelector("#pw-delegations .pw-delegation a").getAttribute("href")');
  assert(/^#message-\d+$/.test(resultLink), 'link to the returned answer in this place');
  await until('!!document.querySelector(' + JSON.stringify(resultLink) + ')');
  checks.push('each returned hand-off links to its answer in this place\'s discussion');
  await shoot('delegate-desktop.png', '#pw-delegate-title');

  // 留着待比较的版本：放进编辑区，不保存就不改
  await evaluate('document.querySelectorAll("#pw-delegations .pw-delegation")[1].querySelector("details").open = true');
  await evaluate('[...document.querySelectorAll("#pw-delegations .pw-delegation")[1].querySelectorAll("button")].find(b => b.textContent === "放进编辑区比较").click()');
  await until('document.querySelector("#pw-dirty").textContent.includes("未保存") && document.querySelector("#pw-notice").textContent.includes("当前保存的仍是版本 4")');
  checks.push('the kept version can be put into the editor to compare; nothing is saved until the person saves');
  await go(base + '/projects/locations/' + fixture.location_id);
  await until('document.querySelectorAll("#pw-delegations .pw-delegation").length >= 3');

  // 从页面上再交出去一件（只要回答）
  await fill('#pw-delegate-ask', '只用一句话说说：这一稿的标题合适吗？');
  await click('#pw-delegate-form button[type="submit"]');
  await until('document.querySelectorAll("#pw-delegations .pw-delegation").length >= 4 && document.querySelector("#pw-notice").textContent.length > 0', 300);
  const notice = await evaluate('document.querySelector("#pw-notice").textContent');
  assert(notice.includes('交出去了'), 'hand-off through the form was taken: ' + notice);
  const first = await evaluate('document.querySelector("#pw-delegations .pw-delegation").textContent');
  assert(first.includes('这一稿的标题合适吗') && first.includes('只要回答'));
  await until('document.querySelector("#pw-who").textContent.includes("交出去：只用一句话说说")', 100);
  checks.push('hand-off through the form appears in the list and under who-is-doing-what');

  await call('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: false});
  await sleep(400);
  assert(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'mobile page must not overflow horizontally');
  await shoot('delegate-mobile.png', '#pw-delegate-title');
  await shoot('delegate-mobile-list.png', '#pw-delegations');
  checks.push('390px layout has no horizontal overflow');
  assert.equal(errors.length, 0, 'no uncaught JavaScript exceptions');
  report.route = '/index.php/apps/cognos/projects/locations/' + fixture.location_id;
  report.result = 'pass';
}
main().catch(e => {report.result='fail';report.error=e.message;process.exitCode=1;}).finally(() => {
  fs.mkdirSync(evidenceDir,{recursive:true});fs.writeFileSync(path.join(evidenceDir,'delegate-browser.json'),JSON.stringify(report,null,2));
  console.log(JSON.stringify(report,null,2));if(ws)ws.close();browser.kill();
});
