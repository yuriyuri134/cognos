/* Authenticated page-to-bridge check. Submits one real Codex delegation.
 * --inspect checks the completed fixture from the saved report without submitting.
 */
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const root = __dirname, origin = 'http://127.0.0.1:8780', base = origin + '/index.php/apps/cognos';
const evidenceDir = path.join(root, 'evidence');
const evidence=path.join(evidenceDir,'codex-delegation-browser.json');
const prior=process.argv.includes('--inspect')?JSON.parse(fs.readFileSync(evidence,'utf8')):null;
if(prior&&!prior.post_payload)throw Error('Inspection requires a saved completed delegation fixture');
const password = fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-codex-delegation-ui-'));
const browser = spawn(process.env.COGNOS_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe',
  ['--headless=new', '--disable-gpu', '--no-first-run', '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank'],
  {windowsHide: true, stdio: 'ignore'});
let ws, counter = 0, delegationPath, originalPost;
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
    if(d.method==='Network.requestWillBeSent'&&delegationPath&&!originalPost){
      const {request}=d.params;
      if(request.method==='POST'&&new URL(request.url).pathname.endsWith(delegationPath)&&request.postData){
        try{originalPost=JSON.parse(request.postData);}catch{}
      }
    }
    if (d.method === 'Runtime.exceptionThrown') errors.push(d.params.exceptionDetails.text);
    if (d.id) {const p = pending.get(d.id); if (!p) return; pending.delete(d.id); d.error ? p.reject(Error(d.error.message)) : p.resolve(d.result);}
  });
  await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable',{maxPostDataSize:65536});
  // 离开有未保存修改的页面时的确认框一律点确定
  ws.addEventListener('message', e => {
    const d = JSON.parse(e.data);
    if (d.method === 'Page.javascriptDialogOpening') call('Page.handleJavaScriptDialog', {accept: true}).catch(() => {});
  });
  await call('Emulation.setDeviceMetricsOverride', {width: 1360, height: 1000, deviceScaleFactor: 1, mobile: false});
  await go(origin + '/index.php/login');
  await until('!!document.querySelector("#password")');
  const login=await evaluate('({page:location.origin,form:new URL(document.querySelector("#password").closest("form").action).origin})');
  assert.deepEqual(login,{page:origin,form:origin});
  // Values never enter tool output; only the login result is recorded.
  await evaluate('(() => {for (const [selector,value] of ' + JSON.stringify([['#user','cognos-admin'],['#password',password]]) +
    ') {const e=document.querySelector(selector);e.value=value;e.dispatchEvent(new Event("input",{bubbles:true}));}document.querySelector("#password").closest("form").requestSubmit();})()');
  await until('!location.pathname.includes("/login") && !!document.querySelector("#app-content")');


  async function api(route,method='GET',body,expected){
    return evaluate(`(async()=>{
      const r=await fetch(${JSON.stringify(route)},{method:${JSON.stringify(method)},headers:{Accept:"application/json","Content-Type":"application/json","OCS-APIRequest":"true",requesttoken:OC.requestToken},body:${JSON.stringify(body===undefined?undefined:JSON.stringify(body))}});
      if(${JSON.stringify(expected)}===undefined?!r.ok:r.status!==${JSON.stringify(expected)})throw Error('Cognos HTTP '+r.status+' '+${JSON.stringify(route)});
      const payload=await r.json();return payload.ocs?payload.ocs.data:payload;
    })()`);
  }
  const app=(route,method,body,expected)=>api('/index.php/apps/cognos'+route,method,body,expected);
  const ocs=route=>api('/ocs/v2.php/apps/cognos/api/v1.0'+route+'?format=json');
  const request=()=>crypto.randomUUID();
  const stamp=Date.now().toString(36);
  const deliverable=prior?.deliverable||'Codex 已核对来源范围 '+stamp;
  const marker=prior?.marker||'Codex 页面委托回执 '+stamp;
  const project=prior||await app('/projects/data','POST',{title:'验收：页面直接委托 Codex '+stamp,request_id:request()});
  const loc=project.location_id;
  const locRoute='/projects/locations/'+loc;
  const detail=(await app(locRoute+'/data')).location;
  assert.equal(detail.revision,1);
  const original={description:'核对资料回查路径',owner:'原负责人',deliverable:'初始交付说明',acceptance:'原作者和来源可回查'};
  const w=prior?{id:prior.work_id}:await app(locRoute+'/work-items','POST',{...original,request_id:request()});
  const workRoute='/projects/work/'+w.id;
  const overview='/projects/'+project.project_id+'/overview?location_id='+loc;
  Object.assign(report,{project_id:project.project_id,location_id:loc,space_id:detail.creation_id,work_id:w.id,marker,deliverable});
  await go(base+locRoute);
  const card='#wi-'+w.id;
  await until('!!document.querySelector('+JSON.stringify(card)+')&&!!document.querySelector("#pw-delegate-executor option[value=codex]")');
  const ask=`验收这份页面委托。先用 note_basis 记录这一处当前稿第 1 版（revision=1）。用 get_work_item 读取这次委托关联的工作 ${w.id} 当前版本。用 update_work_item 在实际版本上保留读取到的范围、负责人、验收，交付只改成「${deliverable}」，状态设为 in_progress。不得改当前稿、采用、拆分或创建新运行。只有工具成功后，最终回答原样包含「${marker}」。如遇错误，请报告具体错误。`;
  delegationPath=locRoute+'/delegations';
  if(prior){
    originalPost=prior.post_payload;
    report.submitted_at=prior.submitted_at;
    report.inspected_existing_run=true;
  }else{
    assert.deepEqual((await app(overview)).delegations,[]);
    const pick=await evaluate('(()=>{const b=[...document.querySelectorAll('+JSON.stringify(card+' button')+')].find(x=>x.textContent==="安排这份工作给 AI");if(!b)return false;b.id="codex-check-pick-work";return true})()');
    assert(pick,'work card offers delegation');await click('#codex-check-pick-work');
    assert(await evaluate('document.querySelector("#pw-delegate-work-label").textContent.includes('+JSON.stringify(original.description)+')'));
    // Operate the native select with keyboard events, then check the actual submitted value.
    await evaluate('document.querySelector("#pw-delegate-executor").focus()');
    for(const type of ['rawKeyDown','keyUp'])await call('Input.dispatchKeyEvent',{type,key:'End',code:'End',windowsVirtualKeyCode:35});
    assert.equal(await evaluate('document.querySelector("#pw-delegate-executor").value'),'codex');
    await fill('#pw-delegate-ask',ask);
    await click('#pw-delegate-manage-work');
    assert.deepEqual(await evaluate('({manage:document.querySelector("#pw-delegate-manage-work").checked,edit:document.querySelector("#pw-delegate-edit").checked,adopt:document.querySelector("#pw-delegate-adopt").checked})'),{manage:true,edit:false,adopt:false});
    await shoot('codex-delegation-selected.png','#pw-delegate-form');
    report.submitted_at=new Date().toISOString();
    fs.writeFileSync(evidence,JSON.stringify(report,null,2));
    await click('#pw-delegate-form button[type="submit"]');
  }
  const deadline=Date.now()+6*60*1000;
  let delegation;
  while(Date.now()<deadline){
    const rows=(await app(overview)).delegations;
    assert(rows.length<=1,'only one delegation should exist');
    if(rows.length===1){
      delegation=rows[0];
      Object.assign(report,{delegation_id:delegation.id,run_id:delegation.run_id,delegation_state:delegation.state,run_state:delegation.run_state});
      assert.equal(delegation.executor,'codex');
      assert(!['not_taken','failed'].includes(delegation.state)&&!['failed','unknown'].includes(delegation.run_state),'bridge did not complete the Codex run: '+String(delegation.note||delegation.run_state||delegation.state));
      if(delegation.state==='returned'&&delegation.result_message_id)break;
    }
    await sleep(1500);
  }
  assert(delegation?.result_message_id,'Codex result has not returned; inspect the saved fixture before considering any retry');
  assert.equal(delegation.subject,'Codex');assert.equal(delegation.run_state,'delivered');
  assert.equal(delegation.work_item_id,w.id);assert.equal(delegation.may_manage_work,true);assert.equal(delegation.may_edit,false);assert.equal(delegation.may_adopt,false);
  const runs=(await ocs('/spaces/creation/'+detail.creation_id+'/runs')).runs;
  assert.equal(runs.length,1);assert.equal(runs[0].id,delegation.run_id);assert.equal(runs[0].target_type,'delegation');assert.equal(runs[0].target_id,delegation.id);assert.equal(runs[0].subject,'Codex');assert.equal(runs[0].caller,'cognos-admin');
  const basis=(await ocs('/runs/'+delegation.run_id)).depends_on;
  assert(basis.some(b=>b.type==='location_content'&&b.id===loc&&Number(b.version)===1),'Codex recorded its basis on the delegated run');
  const after=(await app(workRoute)).item;
  for(const key of ['description','owner','acceptance'])assert.equal(after[key],original[key]);
  assert.equal(after.deliverable,deliverable);assert.equal(after.state,'in_progress');assert.equal(after.children.length,0);
  assert(after.events.some(e=>e.action==='updated'&&e.actor_subject==='Codex'&&e.run_id===delegation.run_id&&e.delegation_id===delegation.id));
  assert.equal((await app(locRoute+'/data')).location.revision,1);
  const messages=(await app('/creations/'+detail.creation_id+'/messages')).messages;
  const replies=messages.filter(m=>m.role==='assistant');assert.equal(replies.length,1);
  assert.equal(Number(replies[0].id),Number(delegation.result_message_id));assert.equal(Number(replies[0].reply_to),Number(delegation.message_id));assert(replies[0].content.includes(marker),'actual answer contains the unique marker');
  assert(originalPost&&originalPost.request_id,'actual page POST body was captured');
  assert.equal(originalPost.executor,'codex');assert.equal(originalPost.ask,ask);assert.equal(originalPost.work_item_id,w.id);
  report.post_payload=originalPost;
  fs.writeFileSync(evidence,JSON.stringify(report,null,2));
  if(!prior){
    const replay=await app(delegationPath,'POST',originalPost);
    assert.equal(replay.delegation_id,delegation.id);assert.equal(replay.run_id,delegation.run_id);
    await app(delegationPath,'POST',{...originalPost,executor:'claude-code'},409);
    assert.equal((await app(overview)).delegations.length,1);
    assert.equal((await ocs('/spaces/creation/'+detail.creation_id+'/runs')).runs.length,1);
    checks.push('replaying the actual page POST returns the same delegation and run; changing the executor under its request id returns 409');
  }else{
    checks.push(...prior.checks.filter(check=>check.startsWith('replaying the actual page POST')));
  }
  checks.push('one authenticated page submission selected Codex and created one bound delegation, one real run and one linked reply; basis is attached to that run');
  checks.push('explicit work permission updated only delivery and progress; the exact work history names Codex, with no draft change, adoption grant, or split');
  await go(base+locRoute);
  const receipt='#message-'+delegation.result_message_id;
  await until('document.querySelector('+JSON.stringify(receipt)+')?.textContent.includes('+JSON.stringify(marker)+')');
  const row='[data-delegation-id="'+delegation.id+'"]';
  await until('document.querySelector('+JSON.stringify(row)+')?.textContent.includes("Codex 交回了结果")');
  assert(await evaluate('!!document.querySelector('+JSON.stringify(row+' a[href="'+receipt+'"]')+')'));
  await until('document.querySelector('+JSON.stringify(card)+')?.textContent.includes("最近修改：AI · Codex")');
  await shoot('codex-delegation-returned.png',row);
  await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:false});
  assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));
  await shoot('codex-delegation-mobile.png',row);
  assert.equal(errors.length,0,'no uncaught JavaScript exceptions');
  checks.push('the returned Codex delegation, work author and original linked message are visible in the authenticated page; 390px does not overflow');
  Object.assign(report,{result_message_id:delegation.result_message_id,result:'pass'});
}
main().catch(e=>{report.result='fail';report.error=e.message;process.exitCode=1;}).finally(()=>{
 fs.mkdirSync(evidenceDir,{recursive:true});fs.writeFileSync(evidence,JSON.stringify(report,null,2));const {post_payload,...visibleReport}=report;console.log(JSON.stringify(visibleReport,null,2));if(ws)ws.close();browser.kill();
});
