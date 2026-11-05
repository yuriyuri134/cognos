/* Authenticated UI verification for explicitly granted AI work maintenance.
 * Uses the real Claude fixture; no new AI job is dispatched. Credentials stay local.
 */
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const root = __dirname, origin = 'http://127.0.0.1:8780', base = origin + '/index.php/apps/cognos';
const evidenceDir = path.join(root, 'evidence');
const fixture = JSON.parse(fs.readFileSync(path.join(evidenceDir, 'work-management-live.json'), 'utf8'));
const password = fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-work-management-ui-'));
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
  try {await fetch(origin+'/status.php',{signal:AbortSignal.timeout(5000)});} catch {throw Error('Cognos 8780 runtime is unavailable; browser checks have not reached authentication');}
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
  const card='#wi-'+fixture.work_id, first='#wi-'+fixture.children[0], second='#wi-'+fixture.children[1];
  await until('document.querySelector('+JSON.stringify(card)+')?.textContent.includes("结果已收回")');
  await until('document.querySelector("#pw-delegations").textContent.includes("可以维护关联工作安排，子项另行授权")');
  const parent=(await json('/projects/work/'+fixture.work_id)).item;
  assert.equal(parent.state,'split');
  assert.equal(parent.delegations.length,1);
  assert.equal(parent.delegations[0].id,fixture.delegation_id);
  assert(await evaluate('document.querySelector('+JSON.stringify(card)+').textContent.includes("仍留在原工作，未复制到子项")'));
  const author=parent.events.find(e=>e.action==='split').actor_subject;
  assert(author);
  assert(await evaluate('document.querySelector('+JSON.stringify(card)+').textContent.includes('+JSON.stringify('最近修改：AI · '+author)+')'));
  await evaluate('document.querySelector('+JSON.stringify(card+' > details')+').open=true');
  assert(await evaluate('document.querySelector('+JSON.stringify(card+' > details')+').textContent.includes('+JSON.stringify('AI · '+author)+')'));
  for(const childId of fixture.children){
    const child=(await json('/projects/work/'+childId)).item;
    assert.equal(child.created_subject,author);
    assert.equal(child.owner,'');
    assert.equal(child.state,'open');
    assert.equal(child.delegations.length,0);
    assert(await evaluate('document.querySelector('+JSON.stringify('#wi-'+childId)+').textContent.includes('+JSON.stringify('拆出或登记者：AI · '+author)+')'));
  }
  checks.push('authenticated real Claude result: parent remains split with original delegation/receipt; actual AI author appears in history and children, children remain unassigned without grants or runs');
  await shoot('work-management-ai-provenance.png',card);
  const permission=()=>evaluate('({disabled:document.querySelector("#pw-delegate-manage-work").disabled,checked:document.querySelector("#pw-delegate-manage-work").checked})');
  assert.deepEqual(await permission(),{disabled:true,checked:false});
  const select=async(selector,id)=>{
    await evaluate('[...document.querySelectorAll('+JSON.stringify(selector+' button')+')].find(b=>b.textContent==="安排这份工作给 AI").id='+JSON.stringify(id));
    await click('#'+id);
  };
  await select(first,'check-select-first');
  assert.deepEqual(await permission(),{disabled:false,checked:false});
  await click('#pw-delegate-manage-work');
  assert.deepEqual(await permission(),{disabled:false,checked:true});
  await select(second,'check-select-second');
  assert.deepEqual(await permission(),{disabled:false,checked:false});
  assert(!(await evaluate('document.querySelector("#pw-delegate-edit").checked')));
  assert(!(await evaluate('document.querySelector("#pw-delegate-adopt").checked')));
  await click('#pw-delegate-manage-work');
  assert.deepEqual(await permission(),{disabled:false,checked:true});
  await shoot('work-management-explicit-grant.png','#pw-delegate-form');
  await click('#pw-delegate-work-clear');
  assert.deepEqual(await permission(),{disabled:true,checked:false});
  assert.equal(await evaluate('document.querySelector("#pw-delegate-work-label").textContent'),'');
  await select(first,'check-select-first');
  assert.deepEqual(await permission(),{disabled:false,checked:false});
  checks.push('real form clicks: maintenance starts disabled/unchecked, selecting a work enables without granting, switching or clearing removes the previous permission; draft/adoption remain unchecked; no delegation submitted');
  await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:false});
  assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));
  await shoot('work-management-mobile-grant.png','#pw-delegate-form');
  await shoot('work-management-mobile-author.png',second);
  checks.push('390px: grant controls and AI-authored work fit without horizontal page overflow');
  for(const childId of fixture.children)assert.equal((await json('/projects/work/'+childId)).item.delegations.length,0);
  assert.equal(errors.length,0,'no uncaught JavaScript exceptions');
  report.result='pass';report.location_id=fixture.location_id;report.work_id=fixture.work_id;
}
main().catch(e=>{report.result='fail';report.error=e.message;process.exitCode=1;}).finally(()=>{
  fs.mkdirSync(evidenceDir,{recursive:true});fs.writeFileSync(path.join(evidenceDir,'work-management-browser.json'),JSON.stringify(report,null,2));
  console.log(JSON.stringify(report,null,2));if(ws)ws.close();browser.kill();
});
