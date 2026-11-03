/* Authenticated browser checks on the local trial, using a fresh headless profile.
 * Runs after check_project_workspace.cjs. Credentials are read locally, never printed.
 * This is product verification, not a browser connector or a user profile.
 */
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const root = __dirname, origin = 'http://127.0.0.1:8780', base = origin + '/index.php/apps/cognos';
const evidenceDir = path.join(root, 'evidence');
const fixture = JSON.parse(fs.readFileSync(path.join(evidenceDir, 'project-workspace-api.json'), 'utf8'));
const password = fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-project-ui-'));
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
  await call('Emulation.setDeviceMetricsOverride', {width: 1360, height: 1000, deviceScaleFactor: 1, mobile: false});
  await go(origin + '/index.php/login');
  await until('!!document.querySelector("#password")');
  // Values never enter tool output; only the login result is recorded.
  await evaluate('(() => {for (const [selector,value] of ' + JSON.stringify([['#user','cognos-admin'],['#password',password]]) +
    ') {const e=document.querySelector(selector);e.value=value;e.dispatchEvent(new Event("input",{bubbles:true}));}document.querySelector("#password").closest("form").requestSubmit();})()');
  await until('!location.pathname.includes("/login") && !!document.querySelector("#app-content")');
  await go(base);
  await until('!!document.querySelector(".demo-space-card h3")');
  assert.equal(await evaluate('[...document.querySelectorAll(".demo-space-card")].find(e=>e.textContent.includes("项目")).getAttribute("href")'), '/index.php/apps/cognos/projects');
  checks.push('authenticated Cognos home links to projects');
  await go(base + '/projects');
  await until('!!document.querySelector("#pw-projects a")');
  await fill('#pw-project-name', '验证：浏览器建立项目 ' + Date.now());
  await click('#pw-create-form button');
  await until('location.pathname.includes("/projects/locations/") && document.querySelector("#pw-work-view") && !document.querySelector("#pw-work-view").hidden');
  const createdPath = await evaluate('location.pathname');
  report.created_project_route = createdPath;
  await fill('#pw-location-name', '浏览器展开的位置');
  await click('#pw-location-form button');
  await until('document.querySelector("#pw-title")?.textContent === "浏览器展开的位置" && !!document.querySelector("#pw-locations [aria-current]")');
  assert.equal(await evaluate('document.querySelectorAll("#pw-locations a").length'), 2);
  checks.push('project and nested location created through visible controls');
  await go(base + '/projects/locations/' + fixture.location_id);
  await until('document.querySelector("#pw-content")?.value.includes("比较两种项目入口") && !!document.querySelector("#pw-usages a")');
  const original = await evaluate('document.querySelector("#pw-content").value');
  const draft = original + '\n浏览器保留的编辑 <script>不执行</script>';
  await fill('#pw-content', draft);
  // A second editor saves while this page retains its old revision.
  const remoteText = original + '\n另一个页面已经保存的修改';
  const race = await evaluate('(async () => {const base=OC.generateUrl("/apps/cognos"), id=' + JSON.stringify(fixture.location_id) +
    ';const d=await (await fetch(base+"/projects/locations/"+id+"/data",{headers:{requesttoken:OC.requestToken}})).json();const r=await fetch(base+"/projects/locations/"+id+"/content",{method:"PUT",headers:{requesttoken:OC.requestToken,"Content-Type":"application/json"},body:JSON.stringify({expected_revision:d.location.revision,content:' + JSON.stringify(remoteText) +
    ',source_message_ids:d.source_message_ids,request_id:crypto.randomUUID()})});return r.status;})()');
  assert.equal(race, 200);
  await click('#pw-save');
  await until('!document.querySelector("#pw-conflict").hidden');
  assert.equal(await evaluate('document.querySelector("#pw-content").value'), draft);
  assert((await evaluate('document.querySelector("#pw-conflict-content").textContent')).includes(remoteText));
  await click('#pw-rebase');
  await click('#pw-save');
  await until('document.querySelector("#pw-dirty").textContent === "已保存"');
  checks.push('conflict preserves local text and shows current saved content; explicit comparison permits later save');
  await click('#pw-history summary');
  await until('document.querySelectorAll("#pw-history-list button").length >= 4');
  await click('#pw-history-list button:nth-child(2)');
  await until('document.querySelector("#pw-history-content").textContent.includes("另一个页面已经保存的修改")');
  checks.push('prior revision is readable from history; markup displayed as text');
  await call('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: false});
  await sleep(300);
  assert(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'mobile page must not overflow horizontally');
  await evaluate('window.scrollTo(0,0)');
  let shot = await call('Page.captureScreenshot');
  fs.writeFileSync(path.join(evidenceDir, 'project-workspace-mobile.png'), Buffer.from(shot.data, 'base64'));
  checks.push('390px layout has no horizontal overflow');
  await call('Emulation.setDeviceMetricsOverride', {width: 1360, height: 1000, deviceScaleFactor: 1, mobile: false});
  await evaluate('document.querySelector("#pw-history").open=false; window.scrollTo(0,0)');
  shot = await call('Page.captureScreenshot');
  fs.writeFileSync(path.join(evidenceDir, 'project-workspace-desktop.png'), Buffer.from(shot.data, 'base64'));
  if (fixture.agent && typeof fixture.agent === 'object' && fixture.agent.replies === 1) {
    await until('document.querySelectorAll("#pw-messages .pw-assistant").length === 1');
    assert((await evaluate('document.querySelector("#pw-runs").textContent')).includes('结果已交回'));
    checks.push('real returned AI result and delivered state visible in the project page');
  }
  assert.equal(errors.length, 0, 'no uncaught JavaScript exceptions');
  report.route = '/index.php/apps/cognos/projects/locations/' + fixture.location_id;
  report.result = 'pass';
}
main().catch(e => {report.result='fail';report.error=e.message;process.exitCode=1;}).finally(() => {
  fs.mkdirSync(evidenceDir,{recursive:true});fs.writeFileSync(path.join(evidenceDir,'project-workspace-browser.json'),JSON.stringify(report,null,2));
  console.log(JSON.stringify(report,null,2));if(ws)ws.close();browser.kill();
});
