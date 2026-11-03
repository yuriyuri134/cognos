/* Authenticated browser checks for standing decisions and who-is-doing-what, using a fresh headless profile.
 * Runs after check_decisions.cjs --agent. Credentials are read locally, never printed.
 * This is product verification, not a browser connector or a user profile.
 */
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const root = __dirname, origin = 'http://127.0.0.1:8780', base = origin + '/index.php/apps/cognos';
const evidenceDir = path.join(root, 'evidence');
const fixture = JSON.parse(fs.readFileSync(path.join(evidenceDir, 'decisions-api.json'), 'utf8'));
const password = fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-decisions-ui-'));
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
async function until(expression) {
  for (let i = 0; i < 150; i++) {try {if (await evaluate(expression)) return;} catch {} await sleep(200);}
  throw Error('Browser condition did not become true: ' + expression.slice(0, 100));
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
  // 确认对话框（撤回时）一律点确定
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

  // 交过真实运行的那一处：决定、走到哪一步、起步时还没有的那条
  await go(base + '/projects/locations/' + fixture.child_location_id);
  await until('document.querySelectorAll("#pw-decisions .pw-decision").length >= 2 && document.querySelector("#pw-who").textContent.length > 0');
  const decisionsText = await evaluate('document.querySelector("#pw-decisions").textContent');
  assert(decisionsText.includes('项目部分不再出新的总方案。'), 'active project-wide decision listed');
  assert(decisionsText.includes('Claude Code：进了输入'), 'evidence that the decision was in the input of Claude Code');
  assert(decisionsText.includes('网页会话等叫不醒的端：尚无证据'), 'no claim about unobservable web sessions');
  assert(decisionsText.includes('由 Claude Code 代录'), 'agent-recorded decision shows its recorder');
  checks.push('decisions block: active decisions with author, scope, recorder, and per-participant evidence ("进了输入" / "尚无证据")');
  const who = await evaluate('document.querySelector("#pw-who").textContent');
  assert(who.includes('起步时还没有这条决定「检查中途加的决定：回答尽量短。」'), 'mid-run decision flagged on the returned result');
  checks.push('who-is-doing-what: returned result flagged "起步时还没有这条决定"');
  await click('#pw-decisions-ended-box summary');
  await until('document.querySelector("#pw-decisions-ended").textContent.includes("已撤回") && document.querySelector("#pw-decisions-ended").textContent.includes("已被替代")');
  checks.push('revoked and superseded decisions stay readable');
  await click('#pw-handoff summary');
  await until('document.querySelector("#pw-handoff-text").value.includes("在生效的决定：") && document.querySelector("#pw-handoff-text").value.includes("项目部分不再出新的总方案。")');
  checks.push('copyable entry text for unreachable web sessions contains the applicable decisions and this place');
  await evaluate('document.querySelector(".pw-header").scrollIntoView({block:"start"})');
  await sleep(300);
  let shot = await call('Page.captureScreenshot');
  fs.writeFileSync(path.join(evidenceDir, 'decisions-desktop.png'), Buffer.from(shot.data, 'base64'));

  // 用页面上的控件：从对话里自己说的一句登记；改写过的被拒；再撤回
  const decideButton = 'document.querySelector("#pw-messages .pw-user") && [...document.querySelectorAll("#pw-messages .pw-user button")].find(x => x.textContent === "登记为决定")';
  await until('!!(' + decideButton + ')');
  await evaluate('(' + decideButton + ').click()');
  await until('document.querySelector("#pw-decision-add").open && document.querySelector("#pw-decision-mode").textContent.includes("原话出自对话里你说的")');
  await fill('#pw-decision-content', '这是一次只读检查。');
  await click('#pw-decision-form button[type="submit"]');
  await until('[...document.querySelectorAll("#pw-decisions .pw-decision blockquote")].some(b => b.textContent === "这是一次只读检查。")');
  checks.push('a decision recorded from the user own message through the page');
  await evaluate('(' + decideButton + ').click()');
  await until('document.querySelector("#pw-decision-add").open');
  await fill('#pw-decision-content', '这句话我改写过了');
  await click('#pw-decision-form button[type="submit"]');
  await until('document.querySelector("#pw-notice").classList.contains("pw-error") && document.querySelector("#pw-notice").textContent.includes("原样")');
  checks.push('a paraphrase pointed at the user message is refused with a visible notice');
  await evaluate('document.querySelector("#pw-decision-reset").click()');
  const decisionId = await evaluate('(() => { const li=[...document.querySelectorAll("#pw-decisions .pw-decision")].find(l => l.querySelector("blockquote").textContent === "这是一次只读检查。"); return li ? li.dataset.decisionId : null; })()');
  assert(decisionId, 'the page-recorded decision is listed');
  await click('[data-decision-id="' + decisionId + '"] .pw-inline button');
  await until('![...document.querySelectorAll("#pw-decisions .pw-decision")].some(l => l.dataset.decisionId === ' + JSON.stringify(decisionId) + ')');
  checks.push('revoke through the page (with confirmation) removes it from the active list');

  await call('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: false});
  await sleep(400);
  assert(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'mobile page must not overflow horizontally');
  await evaluate('document.querySelector("#pw-decisions-title").scrollIntoView({block:"start"})');
  await sleep(300);
  shot = await call('Page.captureScreenshot');
  fs.writeFileSync(path.join(evidenceDir, 'decisions-mobile.png'), Buffer.from(shot.data, 'base64'));
  await evaluate('document.querySelector("#pw-who-title").scrollIntoView({block:"start"})');
  await sleep(300);
  shot = await call('Page.captureScreenshot');
  fs.writeFileSync(path.join(evidenceDir, 'decisions-mobile-who.png'), Buffer.from(shot.data, 'base64'));
  checks.push('390px layout has no horizontal overflow');
  assert.equal(errors.length, 0, 'no uncaught JavaScript exceptions');
  report.route = '/index.php/apps/cognos/projects/locations/' + fixture.child_location_id;
  report.result = 'pass';
}
main().catch(e => {report.result='fail';report.error=e.message;process.exitCode=1;}).finally(() => {
  fs.mkdirSync(evidenceDir,{recursive:true});fs.writeFileSync(path.join(evidenceDir,'decisions-browser.json'),JSON.stringify(report,null,2));
  console.log(JSON.stringify(report,null,2));if(ws)ws.close();browser.kill();
});
