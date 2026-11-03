/* Authenticated browser checks for adoption and correction, using a fresh headless profile.
 * Runs after check_adoption.cjs. Credentials are read locally, never printed.
 */
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const root = __dirname, origin = 'http://127.0.0.1:8780', base = origin + '/index.php/apps/cognos';
const evidenceDir = path.join(root, 'evidence');
const fixture = JSON.parse(fs.readFileSync(path.join(evidenceDir, 'adoption-api.json'), 'utf8'));
const password = fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-adoption-ui-'));
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

  const items = sel => '[...document.querySelectorAll(' + JSON.stringify(sel) + ')]';
  // 乙：只参考那份材料。它得到的是提示；撤回过的采用在「已撤回、被替代的」里
  await go(base + '/projects/locations/' + fixture.yi);
  await until('document.querySelector("#pw-title").textContent === "乙：只参考" && document.querySelectorAll("#pw-impacts li").length > 0');
  const hintText = await evaluate('document.querySelector("#pw-impacts").textContent');
  assert(hintText.includes('提示：') && hintText.includes('这里没有采用它'), hintText);
  assert(await evaluate('!document.querySelector("#pw-adoptions-ended-box").hidden && document.querySelector("#pw-adoptions-ended").textContent.includes("已撤回")'));
  await evaluate(items('#pw-impacts li') + '.find(li => li.textContent.includes("这里没有采用它")).querySelector("button").click()');
  await until('!' + items('#pw-impacts li') + '.some(li => li.textContent.includes("这里没有采用它"))');
  checks.push('乙 (only references the material) shows a hint, not a review; its withdrawn adoption stays readable; the hint can be marked 知道了');

  // 从材料里采用 Claude Code 的那段回答
  await evaluate('document.querySelector(".pw-materials details:not([open]) summary") && [...document.querySelectorAll("#pw-usages details")][0].setAttribute("open", "")');
  await until('!!document.querySelector("#pw-usages .pw-material-messages")');
  await evaluate(items('#pw-usages .pw-material-messages li') + '.find(li => li.textContent.startsWith("AI：")).querySelector("button").click()');
  await until('!!document.querySelector("#pw-usages .pw-inline-form")');
  await evaluate('document.querySelector("#pw-usages .pw-inline-form textarea").value = "浏览器检查：乙也采用这段"');
  await evaluate('document.querySelector("#pw-usages .pw-inline-form button[type=submit]").click()');
  await until(items('#pw-adoption-list .pw-adoption') + '.some(li => li.textContent.includes("AI 在「") && li.textContent.includes("浏览器检查：乙也采用这段"))');
  checks.push('a sentence from the material can be adopted in this place through the page');

  // 在乙纠正它：乙和甲（都采用了这段回答）进待复核
  const mine = items('#pw-adoption-list .pw-adoption') + '.find(li => li.textContent.includes("浏览器检查：乙也采用这段"))';
  await evaluate('[...' + mine + '.querySelectorAll("button")].find(b => b.textContent === "纠正").click()');
  await until('!!' + mine + '.querySelector(".pw-inline-form")');
  await evaluate(mine + '.querySelector(".pw-inline-form textarea").value = "浏览器检查的纠正：这段回答只适用于不在项目里的会话。"');
  await evaluate(mine + '.querySelector(".pw-inline-form button[type=submit]").click()');
  await until('document.querySelector("#pw-notice").textContent.includes("进了待复核")');
  await until(mine + '.classList.contains("pw-adoption-review") && ' + mine + '.textContent.includes("浏览器检查的纠正")');
  checks.push('correcting it from the page puts this place\'s adoption into 待复核 with the correction shown next to it');
  await shoot('adoption-review-desktop.png', '#pw-adoptions-title');

  // 仍然采用，写下回应
  await evaluate('[...' + mine + '.querySelectorAll("button")].find(b => b.textContent === "仍然采用").click()');
  await until('!!' + mine + '.querySelector(".pw-inline-form")');
  await evaluate(mine + '.querySelector(".pw-inline-form textarea").value = "乙仍然参考它，纠正记下了"');
  await evaluate(mine + '.querySelector(".pw-inline-form button[type=submit]").click()');
  await until(mine + ' && !' + mine + '.classList.contains("pw-adoption-review") && ' + mine + '.textContent.includes("仍然采用：乙仍然参考它")');
  checks.push('"still adopt" with a response returns it to active; correction and response stay visible');

  // 甲：同一段回答在甲也进了待复核，被替代的旧采用还在
  await go(base + '/projects/locations/' + fixture.jia);
  await until('document.querySelector("#pw-title").textContent === "甲：依赖它" && document.querySelectorAll("#pw-adoption-list .pw-adoption").length > 0');
  assert(await evaluate(items('#pw-adoption-list .pw-adoption') + '.some(li => li.classList.contains("pw-adoption-review") && li.textContent.includes("浏览器检查的纠正"))'), '甲 adopted the same answer and is now 待复核');
  assert(await evaluate('document.querySelector("#pw-adoptions-ended").textContent.includes("已被改采用的一版替代")'));
  checks.push('甲, which adopted the same answer, is 待复核 too; its superseded adoption stays readable');
  await go(base + '/projects/' + fixture.project_id);
  await until('document.querySelector("#pw-location-states").textContent.includes("件待复核")');
  checks.push('the project overview shows which place has something 待复核');

  await call('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: false});
  await go(base + '/projects/locations/' + fixture.jia);
  await until('document.querySelectorAll("#pw-adoption-list .pw-adoption").length > 0');
  await sleep(400);
  assert(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'no horizontal overflow at 390px');
  await shoot('adoption-mobile.png', '#pw-adoptions-title');
  checks.push('390px: the adoption section has no horizontal overflow');
  assert.equal(errors.length, 0, 'no uncaught JavaScript exceptions');
  report.result = 'pass';
}
main().catch(e => {report.result='fail';report.error=e.message;process.exitCode=1;}).finally(() => {
  fs.mkdirSync(evidenceDir,{recursive:true});fs.writeFileSync(path.join(evidenceDir,'adoption-browser.json'),JSON.stringify(report,null,2));
  console.log(JSON.stringify(report,null,2));if(ws)ws.close();browser.kill();
});
