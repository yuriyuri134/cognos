/* Authenticated browser checks for restructuring (move, independent, merge), using a fresh headless profile.
 * Runs after check_restructure.cjs. Credentials are read locally, never printed.
 */
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const root = __dirname, origin = 'http://127.0.0.1:8780', base = origin + '/index.php/apps/cognos';
const evidenceDir = path.join(root, 'evidence');
const fixture = JSON.parse(fs.readFileSync(path.join(evidenceDir, 'restructure-api.json'), 'utf8'));
const password = fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-restructure-ui-'));
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

  const json = async (url, method = 'GET', data) => evaluate('(async () => {const r=await fetch(OC.generateUrl(' + JSON.stringify('/apps/cognos' + url) + '),{method:' + JSON.stringify(method) +
    ',headers:{Accept:"application/json","Content-Type":"application/json",requesttoken:OC.requestToken}' + (data ? ',body:' + JSON.stringify(JSON.stringify(data)) : '') + '});return r.json();})()');
  // 被合并的 C：身份还在，指向 A
  await go(base + '/projects/' + fixture.project_c);
  await until('!document.querySelector("#pw-merged-banner").hidden');
  const banner = await evaluate('document.querySelector("#pw-merged-banner").textContent');
  assert(banner.includes('合并到') && await evaluate('document.querySelector("#pw-merged-banner a").getAttribute("href").endsWith("/projects/' + fixture.project_a + '")'), banner);
  assert(await evaluate('document.querySelector("#pw-merge").hidden'), 'a merged project offers no further merge');
  checks.push('the merged project C still opens, says it was merged into A and links there');

  // 甲一的旧链接：照样打开，归属经过两条
  await go(base + '/projects/locations/' + fixture.jia1);
  await until('document.querySelector("#pw-title").textContent === "甲一" && document.querySelectorAll("#pw-location-history li").length === 2');
  const hist = await evaluate('document.querySelector("#pw-location-history").textContent');
  assert(hist.includes('独立出来') && hist.includes('移到'), hist);
  checks.push('the old link of 甲一 opens in its new project and shows both changes of where it belonged');

  // A 总览：结构的经过
  await go(base + '/projects/' + fixture.project_a);
  await until('!document.querySelector("#pw-project-history-box").hidden');
  const aHist = await evaluate('document.querySelector("#pw-project-history").textContent');
  assert(aHist.includes('独立出来') && aHist.includes('随项目合并'), aHist);
  checks.push('A\'s overview lists what moved out (independent) and what merged in');

  // 在页面上调整：新建「丙」，移到甲下面
  const bing = (await json('/projects/' + fixture.project_a + '/locations', 'POST', {parent_id: (await json('/projects/' + fixture.project_a + '/data')).project.root_location_id, title: '丙', request_id: crypto.randomUUID()})).location_id;
  await go(base + '/projects/locations/' + bing);
  await until('document.querySelector("#pw-title").textContent === "丙" && !document.querySelector("#pw-restructure").hidden');
  await evaluate('document.querySelector("#pw-restructure").open = true; const s=document.querySelector("#pw-move-parent"); s.value=[...s.options].find(o => o.textContent === "甲").value');
  await click('#pw-preview-move');
  await until('!document.querySelector("#pw-restructure-preview").hidden');
  const lines = await evaluate('document.querySelector("#pw-restructure-lines").textContent');
  assert(lines.includes('移到') && lines.includes('/ 甲」下面'), lines);
  await shoot('restructure-preview-desktop.png', '#pw-restructure-preview');
  await click('#pw-restructure-apply');
  await until('document.querySelector("#pw-title").textContent === "丙" && document.querySelectorAll("#pw-location-history li").length === 1');
  assert((await json('/projects/locations/' + bing + '/data')).location.parent_id === (await json('/projects/' + fixture.project_a + '/data')).project.locations.find(l => l.title === '甲').id);
  checks.push('move through the page: preview first, confirm, the place reloads under its new parent with its history');

  // 在页面上合并：新建 D，并到 A 的丙下面
  const D = await json('/projects/data', 'POST', {title: '验证：页面上合并 D ' + Date.now(), request_id: crypto.randomUUID()});
  await go(base + '/projects/' + D.project_id);
  await until('!document.querySelector("#pw-merge").hidden && document.querySelector("#pw-merge-project").options.length > 0');
  await evaluate('document.querySelector("#pw-merge").open = true; document.querySelector("#pw-merge-project").value = ' + JSON.stringify(fixture.project_a) + '; document.querySelector("#pw-merge-project").dispatchEvent(new Event("change"))');
  await until('[...document.querySelector("#pw-merge-parent").options].some(o => o.textContent === "丙")');
  await evaluate('const s=document.querySelector("#pw-merge-parent"); s.value=[...s.options].find(o => o.textContent === "丙").value');
  await click('#pw-preview-merge');
  await until('!document.querySelector("#pw-restructure-preview").hidden');
  assert(await evaluate('document.querySelector("#pw-restructure-lines").textContent.includes("并到项目")'));
  await click('#pw-restructure-apply');
  await until('location.pathname.endsWith("/projects/' + fixture.project_a + '") && document.querySelector("#pw-locations").textContent.includes("验证：页面上合并 D")', 200);
  checks.push('merge through the page: preview, confirm, land on the target project whose structure now holds D');

  await go(base + '/projects');
  await until('document.querySelector("#pw-projects").textContent.includes("已合并到")');
  checks.push('the project list marks merged projects instead of hiding them');

  await call('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: false});
  await go(base + '/projects/locations/' + bing);
  await until('!document.querySelector("#pw-restructure").hidden');
  await evaluate('document.querySelector("#pw-restructure").open = true; const s=document.querySelector("#pw-move-parent"); s.value=s.options[0].value');
  await click('#pw-preview-move');
  await until('!document.querySelector("#pw-restructure-preview").hidden');
  assert(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'no horizontal overflow at 390px');
  await shoot('restructure-preview-mobile.png', '#pw-restructure-preview');
  checks.push('390px: the preview has no horizontal overflow (not confirmed)');
  assert.equal(errors.length, 0, 'no uncaught JavaScript exceptions');
  report.result = 'pass';
}
main().catch(e => {report.result='fail';report.error=e.message;process.exitCode=1;}).finally(() => {
  fs.mkdirSync(evidenceDir,{recursive:true});fs.writeFileSync(path.join(evidenceDir,'restructure-browser.json'),JSON.stringify(report,null,2));
  console.log(JSON.stringify(report,null,2));if(ws)ws.close();browser.kill();
});
