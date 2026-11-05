/* Read-only product visibility checks on an isolated restored instance. Never targets source 8780.
 * Login creates only normal restored-instance session state. No work, model or provider action is submitted.
 */
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const root=__dirname, origin=(process.argv[2]||process.env.COGNOS_RESTORE_URL||'http://127.0.0.1:8781').replace(/\/$/,''), base=origin+'/index.php/apps/cognos';
const target=new URL(origin);if(!['127.0.0.1','localhost','[::1]'].includes(target.hostname)||target.port!=='8781'||target.protocol!=='http:')throw Error('Restore check requires a separate loopback HTTP instance, never source 8780');
const evidenceDir=process.env.COGNOS_RESTORE_EVIDENCE_DIR||path.join(root,'evidence');fs.mkdirSync(evidenceDir,{recursive:true});
const fixtures=process.env.COGNOS_RESTORE_FIXTURES_DIR||path.join(root,'evidence');
const load=name=>JSON.parse(fs.readFileSync(path.join(fixtures,name),'utf8'));
const attemptFixture=load('attempts-api.json'), workFixture=load('work-management-live.json'), adoptionFixture=load('delegated-adoption-api.json');
const fixture=load('codex-entry.json');
const password=fs.readFileSync(process.env.COGNOS_RESTORE_PASSWORD_FILE||path.join(root,'.secrets/admin-password'),'utf8').trim();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-restore-ui-'));
const browser = spawn(process.env.COGNOS_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe',
  ['--headless=new', '--disable-gpu', '--no-first-run', '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank'],
  {windowsHide: true, stdio: 'ignore'});
let ws, counter = 0;
const pending = new Map(), errors = [], checks = [];
const report={at:new Date().toISOString(),origin,checks,javascript_errors:errors};
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
  const statusResponse=await fetch(origin+'/status.php',{redirect:'error',signal:AbortSignal.timeout(8000)});
  assert(statusResponse.ok,'isolated restore HTTP status unavailable');
  const status=await statusResponse.json();
  assert(status.installed&&!status.maintenance&&!status.needsDbUpgrade,'isolated restore is not ready for login');
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
    if(d.method==='Fetch.requestPaused'){
      const {requestId,request}=d.params;
      const allowed=new URL(request.url).origin===target.origin;
      call(allowed?'Fetch.continueRequest':'Fetch.failRequest',allowed?{requestId}:{requestId,errorReason:'BlockedByClient'}).catch(()=>{});
    }
    if (d.method === 'Runtime.exceptionThrown') errors.push(d.params.exceptionDetails.text);
    if (d.id) {const p = pending.get(d.id); if (!p) return; pending.delete(d.id); d.error ? p.reject(Error(d.error.message)) : p.resolve(d.result);}
  });
  await call('Page.enable'); await call('Runtime.enable');
  await call('Fetch.enable',{patterns:[{urlPattern:'http://*'},{urlPattern:'https://*'}]});
  // 离开有未保存修改的页面时的确认框一律点确定
  ws.addEventListener('message', e => {
    const d = JSON.parse(e.data);
    if (d.method === 'Page.javascriptDialogOpening') call('Page.handleJavaScriptDialog', {accept: true}).catch(() => {});
  });
  await call('Emulation.setDeviceMetricsOverride', {width: 1360, height: 1000, deviceScaleFactor: 1, mobile: false});
  await go(origin + '/index.php/login');
  await until('!!document.querySelector("#password")');
  const loginTarget=await evaluate('({page:location.origin,form:new URL(document.querySelector("#password").closest("form").action,location.href).origin})');
  assert.deepEqual(loginTarget,{page:target.origin,form:target.origin},'login page and form must both belong to the isolated restore');
  // Values never enter tool output; only the login result is recorded.
  await evaluate('(() => {for (const [selector,value] of ' + JSON.stringify([['#user','cognos-admin'],['#password',password]]) +
    ') {const e=document.querySelector(selector);e.value=value;e.dispatchEvent(new Event("input",{bubbles:true}));}document.querySelector("#password").closest("form").requestSubmit();})()');
  await until('!location.pathname.includes("/login") && !!document.querySelector("#app-content")');


  const json=route=>evaluate('(async()=>{const r=await fetch('+JSON.stringify('/index.php/apps/cognos')+'+'+JSON.stringify(route)+',{headers:{Accept:"application/json","OCS-APIRequest":"true",requesttoken:OC.requestToken}});if(!r.ok)throw Error("restored read HTTP "+r.status);return await r.json()})()');
  const jsonRun=rid=>evaluate('(async()=>{const r=await fetch('+JSON.stringify('/ocs/v2.php/apps/cognos/api/v1.0/runs/')+'+'+JSON.stringify(rid)+',{headers:{Accept:"application/json","OCS-APIRequest":"true",requesttoken:OC.requestToken}});if(!r.ok)throw Error("restored run HTTP "+r.status);const b=await r.json();return b.ocs?b.ocs.data:b})()');
  const a=attemptFixture, card='#at-'+a.attempt_id;
  await go(base+'/projects/locations/'+a.location_id);await until('!!document.querySelector('+JSON.stringify(card)+')');
  report.restore_banner_seen=await evaluate('!!document.querySelector("#cognos-restore-notice")');
  assert(report.restore_banner_seen,'isolated restore warning is visible');
  const restored=(await json('/projects/attempts/'+a.attempt_id)).item;
  assert.equal(restored.id,a.attempt_id);assert.equal(restored.state,'ended');assert.equal(restored.summaries.length,2);
  assert(restored.revisions.some(r=>r.revision===1&&r.snapshot.introduction==='一句念头即可留下，不预设交付。'));
  const first=restored.summaries.find(s=>s.id===a.summary_id);assert(first&&first.revision===2);assert(first.revisions.some(r=>r.revision===1&&r.snapshot.coverage==='只覆盖起步的旧发生和第一版安排，不代表全部活动'));
  assert.equal(restored.refs.find(r=>r.type==='location_content').version,1);assert.equal(restored.refs.find(r=>r.type==='work_item').version,1);assert.equal(restored.refs.find(r=>r.type==='link').status,'link_only');
  assert(await evaluate('document.querySelector('+JSON.stringify(card)+').textContent.includes("cognos-admin")'));
  assert(await evaluate('!!document.querySelector('+JSON.stringify('#ats-'+a.summary_id)+')&&!!document.querySelector('+JSON.stringify('#ats-'+a.second_summary_id)+')'));
  await evaluate('(()=>{const card=document.querySelector('+JSON.stringify(card)+');const li=[...card.querySelectorAll(".at-ref-list li")].find(x=>x.textContent.includes("原发生保留原作者")&&x.querySelector("button"));if(!li)throw Error("original trace reference missing");li.querySelector("button").id="restore-open-original"})()');
  await click('#restore-open-original');await until('document.querySelector("#restore-open-original").parentElement.querySelector("pre")?.textContent.includes("原尝试记录者")');
  assert(await evaluate('document.querySelector("#restore-open-original").parentElement.querySelector("pre").textContent.includes("旧发生：试着比较导航，仍没有结论。")'));
  checks.push('restored attempt and two stage identities, prior introduction/coverage/author, fixed version references and link-only status remain; actual source button reads the original trace and author');
  await shoot('restore-attempts-source.png',card);
  await go(base+'/projects/locations/'+a.branch_id);await until('!!document.querySelector('+JSON.stringify(card)+')');
  assert((await json('/projects/'+a.independent_project_id+'/attempts?location_id='+a.branch_id)).items.some(x=>x.id===a.attempt_id));
  checks.push('old position link after independence opens the same restored attempt in its current project');
  const w=workFixture,wcard='#wi-'+w.work_id;
  await go(base+'/projects/locations/'+w.location_id);await until('!!document.querySelector('+JSON.stringify(wcard)+')&&!!document.querySelector('+JSON.stringify('#message-'+w.result_message_id)+')');
  const parent=(await json('/projects/work/'+w.work_id)).item;
  assert.equal(parent.state,'split');assert.deepEqual(parent.children.map(c=>c.id).sort(),[...w.children].sort());
  assert(parent.events.some(e=>e.actor_subject==='Claude Code'&&e.run_id===w.run_id&&e.delegation_id===w.delegation_id));
  for(const cid of w.children){const child=(await json('/projects/work/'+cid)).item;assert.equal(child.created_subject,'Claude Code');assert.equal(child.delegations.length,0);assert.equal(child.state,'open');}
  const run=(await jsonRun(w.run_id)).run;assert.equal(run.id,w.run_id);assert.equal(run.state,'delivered');
  assert(await evaluate('document.querySelector('+JSON.stringify(wcard)+').textContent.includes("Claude Code")'));
  assert.equal(await evaluate('document.querySelectorAll('+JSON.stringify('#message-'+w.result_message_id)+').length'),1);
  checks.push('real Claude work split, actual author/run/delegation, unstarted children without inherited grants and one original delivered result are visible after restore');
  await shoot('restore-work-real-result.png',wcard);
  await go(base+'/projects/locations/'+adoptionFixture.L);await until('document.querySelectorAll("#pw-adoption-list .pw-adoption").length>0');
  const adoptions=await evaluate('document.querySelector("#pw-adoption-list").textContent');
  assert(adoptions.includes('Claude Code 采用于')&&adoptions.includes('依据交出去的那件事（你的原话 #')&&adoptions.includes('待复核'));
  assert(await evaluate('document.querySelector("#pw-impacts").textContent.includes("第 2 层")'));
  checks.push('restored formal adoption retains the real AI, delegation/user-message authority and review/temporal-impact distinction');
  await go(base+'/projects/locations/'+fixture.location_id);await until('document.querySelector("#pw-who")?.textContent.includes("Codex")&&!!document.querySelector('+JSON.stringify('#message-'+fixture.result_message_id)+')');
  const codex=(await jsonRun(fixture.codex_run_id)).run;assert.equal(codex.id,fixture.codex_run_id);assert.equal(codex.state,'delivered');
  const decisions=await evaluate('document.querySelector("#pw-decisions").textContent');
  assert(decisions.includes('起步时还没有，交回前看到了')&&decisions.includes('网页会话等叫不醒的端：尚无证据'));
  assert.equal(await evaluate('document.querySelectorAll('+JSON.stringify('#message-'+fixture.result_message_id)+').length'),1);
  checks.push('independent Codex run and original unique result remain, including the exact decision-reach evidence and unobserved web participant');
  await shoot('restore-codex-decision-evidence.png','#pw-decisions');
  await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:false});
  await go(base+'/projects/locations/'+a.location_id);await until('!!document.querySelector('+JSON.stringify(card)+')');
  assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));await shoot('restore-attempts-mobile.png',card);
  checks.push('390px restored attempt/stage records remain readable without horizontal overflow');
  assert.equal(errors.length,0,'no uncaught JavaScript exceptions');
  Object.assign(report,{attempt_id:a.attempt_id,work_id:w.work_id,claude_run_id:w.run_id,codex_run_id:fixture.codex_run_id,result:'pass'});
}
main().catch(e=>{report.result='fail';report.error=e.message;process.exitCode=1}).finally(()=>{
 fs.writeFileSync(path.join(evidenceDir,'restore-visible-browser.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));if(ws)ws.close();browser.kill();
});
