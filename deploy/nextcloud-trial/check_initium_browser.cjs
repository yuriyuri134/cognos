/* Authenticated browser checks for Initium and 确立, using a fresh headless profile.
 * Runs after check_initium.cjs. Credentials are read locally, never printed.
 */
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const root = __dirname, origin = 'http://127.0.0.1:8780', base = origin + '/index.php/apps/cognos';
const evidenceDir = path.join(root, 'evidence');
const fixture = JSON.parse(fs.readFileSync(path.join(evidenceDir, 'initium-api.json'), 'utf8'));
const password = fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-initium-ui-'));
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

  // 首页和项目列表都能看到 Initium
  await go(base + '/home');
  await until('!!document.querySelector("#home-initium-link") && /段会话还没成为项目/.test(document.querySelector("#home-initium-count").textContent)');
  checks.push('home shows Initium with how many conversations are not yet projects');
  await go(base + '/projects');
  await until('!!document.querySelector("#pw-list-view a[href$=\\"/initium\\"]")');
  checks.push('the project list links to Initium');

  await go(base + '/initium');
  await until('!!document.querySelector(\'.in-item[data-creation-id="' + fixture.creation_id + '"]\')');
  const fixtureText = await evaluate('document.querySelector(\'.in-item[data-creation-id="' + fixture.creation_id + '"]\').textContent');
  assert(fixtureText.includes('已促成') && fixtureText.includes('验证：入口里决定放哪') && fixtureText.includes('验证：笔记标签'), fixtureText);
  checks.push('a conversation that formed two projects stays in Initium and links to both');
  await shoot('initium-desktop.png', '.pw-header');

  // 页面上留一句（不叫 AI），再确立
  const sentence = '浏览器里留下的一句 ' + new Date().toISOString();
  await fill('#in-text', sentence);
  await click('#in-capture-form button[type="submit"]');
  await until('[...document.querySelectorAll(".in-item blockquote")].some(b => b.textContent === ' + JSON.stringify(sentence) + ')');
  const item = '[...document.querySelectorAll(".in-item")].find(li => li.querySelector("blockquote") && li.querySelector("blockquote").textContent === ' + JSON.stringify(sentence) + ')';
  await evaluate('[...' + item + '.querySelectorAll("button")].find(b => b.textContent === "确立").click()');
  await until('!!' + item + '.querySelector(".in-picks input")');
  assert(await evaluate(item + '.querySelector(".in-picks input").checked'), 'the first sentence is preselected');
  await evaluate('(() => {const li=' + item + ';li.querySelector("textarea").value="从浏览器确立的初步说明";li.querySelector("input[id^=in-reason]").value="浏览器检查";})()');
  await evaluate(item + '.querySelector(".in-form button[type=submit]").click()');
  await until('location.pathname.includes("/projects/") && !document.querySelector("#pw-origin").hidden', 200);
  const originText = await evaluate('document.querySelector("#pw-origin").textContent');
  assert(originText.includes(sentence) && originText.includes('理由：浏览器检查'), originText);
  assert(await evaluate('document.querySelector("#pw-location-states").textContent.includes("当前内容版本 2")'), 'the description became revision 2 of the root place');
  checks.push('captured a sentence and formed a project through the page; the overview shows its 来路 with the sentence verbatim and the reason');
  await shoot('initium-formed-desktop.png', '#pw-origin-title');

  await call('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: false});
  await go(base + '/initium');
  await until('document.querySelectorAll(".in-item").length > 0');
  await evaluate('[...document.querySelector(\'.in-item[data-creation-id="' + fixture.creation_id + '"]\').querySelectorAll("button")].find(b => b.textContent === "再确立一个").click()');
  await until('!!document.querySelector(".in-picks input")');
  assert(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'Initium must not overflow at 390px');
  await shoot('initium-mobile.png', '.in-form');
  checks.push('390px: Initium and its 确立 form have no horizontal overflow');
  assert.equal(errors.length, 0, 'no uncaught JavaScript exceptions');
  report.result = 'pass';
}
main().catch(e => {report.result='fail';report.error=e.message;process.exitCode=1;}).finally(() => {
  fs.mkdirSync(evidenceDir,{recursive:true});fs.writeFileSync(path.join(evidenceDir,'initium-browser.json'),JSON.stringify(report,null,2));
  console.log(JSON.stringify(report,null,2));if(ws)ws.close();browser.kill();
});
