/* Authenticated browser checks for restructuring (move, independent, merge), using a fresh headless profile.
 * Runs after check_restructure.cjs. Credentials are read locally, never printed.
 */
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const root = __dirname, origin = 'http://127.0.0.1:8780', base = origin + '/index.php/apps/cognos';
const evidenceDir = path.join(root, 'evidence');
const fixture = JSON.parse(fs.readFileSync(path.join(evidenceDir, 'undo-cross-api.json'), 'utf8'));
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
  for(const [kind,f] of [['independent',fixture.ui_independent],['merge',fixture.ui_merge]]) {
    // The merged source's old project link also offers the undo through its history.
    await go(kind==='merge' ? base+'/projects/'+f.project : base+'/projects/locations/'+f.root);
    const box=kind==='merge'?'#pw-project-history':'#pw-location-history';
    await until('!!document.querySelector('+JSON.stringify(box+' button')+')');
    await click(box+' button');
    await until('!document.querySelector("#pw-restructure-preview").hidden');
    const lines=await evaluate('document.querySelector("#pw-restructure-lines").textContent');
    assert(lines.includes(kind==='merge'?'撤回合并':'撤回独立') && lines.includes('不回滚'));
    assert(await evaluate('document.querySelector("#pw-restructure-preview").getBoundingClientRect().width>600'), 'desktop preview uses the main content width');
    assert(await evaluate('document.activeElement.id==="pw-restructure-preview"'), 'keyboard focus reaches the preview');
    if(kind==='merge') assert(lines.includes('页面核对：原项目全范围恢复') && lines.includes('页面核对：目标项目的决定留下'));
    await shoot('undo-'+kind+'-preview.png','#pw-restructure-preview');
    await click('#pw-restructure-apply');
    await until('location.pathname.endsWith("/projects/locations/'+f.root+'") && document.querySelectorAll("#pw-location-history li").length===2 && !document.querySelector("#pw-location-history button")');
    const loc=(await json('/projects/locations/'+f.root+'/data')).location;
    assert.equal(loc.project_id,f.project);
    const history=await evaluate('document.querySelector("#pw-location-history").textContent');
    assert(history.includes(kind==='merge'?'撤回合并':'撤回独立'));
    await go(base+'/projects/'+(kind==='merge'?f.project:f.shell));
    if(kind==='merge') await until('document.querySelector("#pw-merged-banner").hidden && document.querySelector("#pw-locations").textContent.includes("页面撤回合并源")');
    else await until('!document.querySelector("#pw-merged-banner").hidden && document.querySelector("#pw-merged-banner").textContent.includes("合并到")');
    checks.push(kind+': authenticated history preview/confirmation, same location link, correct current project identity and readable undo history');
  }
  await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:false});
  await go(base+'/projects/locations/'+fixture.ui_merge.root);
  await until('document.querySelectorAll("#pw-location-history li").length===2');
  assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));
  await shoot('undo-cross-mobile.png','#pw-location-history');
  checks.push('390px: both original merge and undo history remain readable without horizontal overflow');
  assert.equal(errors.length, 0, 'no uncaught JavaScript exceptions');
  report.result = 'pass';
}
main().catch(e => {report.result='fail';report.error=e.message;process.exitCode=1;}).finally(() => {
  fs.mkdirSync(evidenceDir,{recursive:true});fs.writeFileSync(path.join(evidenceDir,'undo-cross-browser.json'),JSON.stringify(report,null,2));
  console.log(JSON.stringify(report,null,2));if(ws)ws.close();browser.kill();
});
