/* Authenticated browser checks for adoption inside a delegation, proposals and correction depth, using a fresh headless profile.
 * Runs after check_delegated_adoption.cjs. Credentials are read locally, never printed.
 */
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const root = __dirname, origin = 'http://127.0.0.1:8780', base = origin + '/index.php/apps/cognos';
const evidenceDir = path.join(root, 'evidence');
const fixture = JSON.parse(fs.readFileSync(path.join(evidenceDir, 'delegated-adoption-api.json'), 'utf8'));
const password = fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-dadopt-ui-'));
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

  const json = async (url, method = 'GET', data, ocsApi = false) => evaluate('(async () => {const r=await fetch(' + JSON.stringify(ocsApi ? '/ocs/v2.php/apps/cognos/api/v1.0' + url + '?format=json' : '') + ' || OC.generateUrl(' + JSON.stringify('/apps/cognos' + url) + '),{method:' + JSON.stringify(method) +
    ',headers:{Accept:"application/json","Content-Type":"application/json","OCS-APIRequest":"true",requesttoken:OC.requestToken}' + (data ? ',body:' + JSON.stringify(JSON.stringify(data)) : '') + '});const b=await r.json();return b.ocs?b.ocs.data:b;})()');
  // 入口定稿：Claude Code 依据委托采用的那条（现在被纠正，待复核），第 2 层的待复核
  await go(base + '/projects/locations/' + fixture.L);
  await until('document.querySelectorAll("#pw-adoption-list .pw-adoption").length > 0');
  const mine = await evaluate('[...document.querySelectorAll("#pw-adoption-list .pw-adoption")].map(li => li.textContent).join("\\n")');
  assert(mine.includes('Claude Code 采用于') && mine.includes('依据交出去的那件事（你的原话 #') && mine.includes('待复核'), mine);
  const imp = await evaluate('document.querySelector("#pw-impacts").textContent');
  assert(imp.includes('第 2 层') && imp.includes('版本 3'), imp);
  assert(await evaluate('document.querySelector("#pw-adoptions-ended").textContent.includes("没有采用")'), 'the declined proposal stays readable');
  checks.push('the place shows the AI adoption with its delegation and the user\'s words, now 待复核; level-2 reviews listed; the declined proposal stays readable');
  await shoot('dadopt-desktop.png', '#pw-adoptions-title');

  // 页面上接受一条新的提议
  await json('/spaces/creation/' + (await json('/projects/locations/' + fixture.L + '/data')).location.creation_id + '/runs', 'POST', {subject: '浏览器检查的参与者', purpose: '提议'}, true);
  const lChat = (await json('/projects/locations/' + fixture.L + '/data')).location.creation_id;
  const msg = (await json('/creations/' + lChat + '/messages')).messages.find(m => m.role === 'user');
  await json('/spaces/creation/' + lChat + '/adoptions', 'POST', {subject: '浏览器检查的参与者', object_type: 'message', object_id: String(msg.id), basis: '交出去时的那句话'}, true);
  await go(base + '/projects/locations/' + fixture.L);
  await until('!document.querySelector("#pw-proposals-box").hidden');
  assert(await evaluate('document.querySelector("#pw-proposals").textContent.includes("浏览器检查的参与者 提议于")'));
  await evaluate('[...document.querySelectorAll("#pw-proposals button")].find(b => b.textContent === "采用").click()');
  await until('document.querySelector("#pw-proposals-box").hidden && [...document.querySelectorAll("#pw-adoption-list .pw-adoption")].some(li => li.textContent.includes("由 浏览器检查的参与者 提议"))');
  checks.push('a proposal shows separately with 采用/不采用; accepting it on the page makes it an active adoption that still names the proposer');

  // 旁边一处：第 3 层只提示，写明到此为止
  await go(base + '/projects/locations/' + fixture.M);
  await until('document.querySelectorAll("#pw-impacts li").length > 0');
  const hint = await evaluate('document.querySelector("#pw-impacts").textContent');
  assert(hint.includes('第 3 层（只提示）') && hint.includes('再往下不追'), hint);
  checks.push('the other place shows only a level-3 hint and says the correction stops there');
  assert(await evaluate('!!document.querySelector("#pw-delegate-adopt")'), 'the hand-off form offers 可以在这一处采用');

  await call('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: false});
  await go(base + '/projects/locations/' + fixture.L);
  await until('document.querySelectorAll("#pw-adoption-list .pw-adoption").length > 0');
  await sleep(400);
  assert(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'no horizontal overflow at 390px');
  await shoot('dadopt-mobile.png', '#pw-adoptions-title');
  checks.push('390px has no horizontal overflow');
  assert.equal(errors.length, 0, 'no uncaught JavaScript exceptions');
  report.result = 'pass';
}
main().catch(e => {report.result='fail';report.error=e.message;process.exitCode=1;}).finally(() => {
  fs.mkdirSync(evidenceDir,{recursive:true});fs.writeFileSync(path.join(evidenceDir,'delegated-adoption-browser.json'),JSON.stringify(report,null,2));
  console.log(JSON.stringify(report,null,2));if(ws)ws.close();browser.kill();
});
