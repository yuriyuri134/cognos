/* Authenticated browser checks for restructuring (move, independent, merge), using a fresh headless profile.
 * Runs after check_restructure.cjs. Credentials are read locally, never printed.
 */
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const root = __dirname, origin = 'http://127.0.0.1:8780', base = origin + '/index.php/apps/cognos';
const evidenceDir = path.join(root, 'evidence');
const fixture = JSON.parse(fs.readFileSync(path.join(evidenceDir, 'work-items-api.json'), 'utf8'));
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
  await go(base+'/projects/locations/'+fixture.location_id);
  const card='#wi-'+fixture.ui_work;
  await until('!!document.querySelector('+JSON.stringify(card+' .wi-edit')+')');
  await evaluate('document.querySelector('+JSON.stringify(card+' .wi-edit')+').open=true');
  await fill(card+' .wi-edit textarea[name="description"]','浏览器中尚未保存的工作范围');
  // Another writer updates the same work while this form is open.
  const old=(await json('/projects/work/'+fixture.ui_work)).item;
  await json('/projects/work/'+fixture.ui_work,'PUT',{description:'另一位刚修改的工作范围',owner:old.owner,deliverable:old.deliverable,acceptance:old.acceptance,state:'open',evidence:'',expected_revision:old.revision,request_id:crypto.randomUUID()});
  await click(card+' .wi-edit button[type="submit"]');
  await until('document.querySelector("#wi-notice").textContent.includes("已有变化")');
  assert.equal(await evaluate('document.querySelector('+JSON.stringify(card+' .wi-edit textarea[name="description"]')+').value'),'浏览器中尚未保存的工作范围');
  await evaluate('[...document.querySelectorAll('+JSON.stringify(card+' .wi-edit button')+')].find(b=>b.textContent.includes("我已比较")).id="check-compare-work"');
  await click('#check-compare-work');await click(card+' .wi-edit button[type="submit"]');
  await until('document.querySelector('+JSON.stringify(card+' h3')+').textContent==="浏览器中尚未保存的工作范围" && !document.querySelector('+JSON.stringify(card+' .wi-edit')+').open');
  checks.push('authenticated work edit preserves local input on a real version conflict and requires explicit comparison before saving on the new version');
  await evaluate('document.querySelector('+JSON.stringify(card+' .wi-split')+').open=true');
  await fill(card+' .wi-split fieldset:nth-child(1) textarea[name="description"]','拆出的导航比较');
  await fill(card+' .wi-split fieldset:nth-child(1) input[name="owner"]','明确指定的负责人');
  await fill(card+' .wi-split fieldset:nth-child(2) textarea[name="description"]','拆出的旧链接核验');
  const mark=async(text,id)=>evaluate('[...document.querySelectorAll('+JSON.stringify(card+' .wi-split button')+')].find(b=>b.textContent==='+JSON.stringify(text)+').id='+JSON.stringify(id));
  await mark('预览拆分','check-split-preview');await click('#check-split-preview');
  await until('[...document.querySelectorAll('+JSON.stringify(card+' .wi-split button')+')].some(b=>b.textContent==="确认拆分")');
  await mark('再加一份','check-split-more');await click('#check-split-more');
  assert(!(await evaluate('[...document.querySelectorAll('+JSON.stringify(card+' .wi-split button')+')].some(b=>b.textContent==="确认拆分")')), 'adding a third part invalidates the previously shown two-part preview');
  await fill(card+' .wi-split fieldset:nth-child(3) textarea[name="description"]','新加的第三份工作');
  await click('#check-split-preview');
  await until('[...document.querySelectorAll('+JSON.stringify(card+' .wi-split button')+')].some(b=>b.textContent==="确认拆分")');
  await mark('确认拆分','check-split-confirm');await shoot('work-split-preview.png',card+' .wi-split');await click('#check-split-confirm');
  await until('document.querySelector('+JSON.stringify(card)+').textContent.includes("已拆分") && !document.querySelector('+JSON.stringify(card+' .wi-split')+')');
  const after=(await json('/projects/work/'+fixture.ui_work)).item;
  assert.equal(after.children.length,3);
  const child=(await json('/projects/work/'+after.children.find(c=>c.description==='拆出的旧链接核验').id)).item;
  assert.equal(child.owner,'');assert.equal(child.delegations.length,0);
  const childCard='#wi-'+child.id;
  await evaluate('[...document.querySelectorAll('+JSON.stringify(childCard+' button')+')].find(b=>b.textContent==="安排这份工作给 AI").id="check-select-work"');
  await click('#check-select-work');
  assert(await evaluate('document.querySelector("#pw-delegate-work-label").textContent.includes("拆出的旧链接核验")'));
  assert.equal(await evaluate('document.querySelector("#pw-delegate-ask").value'),'拆出的旧链接核验');
  assert(!(await evaluate('document.querySelector("#pw-delegate-edit").checked')) && !(await evaluate('document.querySelector("#pw-delegate-adopt").checked')));
  checks.push('actual three-way split: preview required, later part addition invalidates preview, source/children stay visible, blank owner and old authority are not inherited; selecting a child only fills the delegation form');
  // Register and complete a separate work through actual form controls.
  await evaluate('document.querySelector("#wi-create").parentElement.open=true');
  const createdTitle='页面登记和验收 '+Date.now();
  await fill('#wi-description',createdTitle);await fill('#wi-deliverable','一份可回查的核验记录');
  await click('#wi-create button[type="submit"]');
  await until('document.querySelector("#wi-items").textContent.includes('+JSON.stringify(createdTitle)+')');
  const created=(await json('/projects/'+fixture.project_id+'/work-items')).items.find(w=>w.description===createdTitle);
  const createdCard='#wi-'+created.id;
  await evaluate('document.querySelector('+JSON.stringify(createdCard+' .wi-edit')+').open=true;const s=document.querySelector('+JSON.stringify(createdCard+' select[name="state"]')+');s.value="done";s.dispatchEvent(new Event("input",{bubbles:true}));');
  await click(createdCard+' button[type="submit"]');
  await until('document.querySelector("#wi-notice").textContent.includes("完成工作请留下")');
  await fill(createdCard+' textarea[name="evidence"]','核验记录已交付，并逐条核对来源。');
  await click(createdCard+' button[type="submit"]');
  await until('!document.querySelector('+JSON.stringify(createdCard+' .wi-edit')+').open');
  assert.equal((await json('/projects/work/'+created.id)).item.state,'done');
  checks.push('actual create/completion forms: evidence-free completion refused, then explicit delivery evidence saved before the work becomes done');
  const live=JSON.parse(fs.readFileSync(path.join(evidenceDir,'work-items-live.json'),'utf8'));
  await go(base+'/projects/locations/'+live.location_id);
  await until('document.querySelector('+JSON.stringify('#wi-'+live.work_id)+')?.textContent.includes("结果已收回")');
  assert(await evaluate('document.querySelector('+JSON.stringify('#wi-'+live.work_id)+').textContent.includes("仍留在原工作")'));
  await shoot('work-items-real-result.png','#wi-'+live.work_id);
  checks.push('real Claude work receipt remains visible on the split parent while children are separate');
  await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:false});
  await go(base+'/projects/locations/'+fixture.location_id);await until('!!document.querySelector('+JSON.stringify(childCard)+')');
  assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));
  await shoot('work-items-mobile.png',childCard);checks.push('390px: work source, children and actions fit without horizontal overflow');
  assert.equal(errors.length, 0, 'no uncaught JavaScript exceptions');
  report.result = 'pass';
}
main().catch(e => {report.result='fail';report.error=e.message;process.exitCode=1;}).finally(() => {
  fs.mkdirSync(evidenceDir,{recursive:true});fs.writeFileSync(path.join(evidenceDir,'work-items-browser.json'),JSON.stringify(report,null,2));
  console.log(JSON.stringify(report,null,2));if(ws)ws.close();browser.kill();
});
