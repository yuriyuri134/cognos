/* Authenticated UI verification for attempt introductions and stage records.
 * Creates a dedicated browser fixture; no AI job is dispatched. Credentials stay local.
 */
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const root = __dirname, origin = 'http://127.0.0.1:8780', base = origin + '/index.php/apps/cognos';
const evidenceDir = path.join(root, 'evidence');
const password = fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-attempts-ui-'));
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
  // 登录后落地页先出 #app-content，Nextcloud 的 OC 稍后才有；用 OC 调接口前先等它
  await until('typeof OC !== "undefined" && !!OC.requestToken');

  const json = async (url, method = 'GET', data) => evaluate('(async () => {const r=await fetch(OC.generateUrl(' + JSON.stringify('/apps/cognos' + url) + '),{method:' + JSON.stringify(method) +
    ',headers:{Accept:"application/json","Content-Type":"application/json",requesttoken:OC.requestToken}' + (data ? ',body:' + JSON.stringify(JSON.stringify(data)) : '') + '});return r.json();})()');


  const uid=()=>crypto.randomUUID();
  const project=await json('/projects/data','POST',{title:'验证：尝试简介与阶段 '+Date.now(),request_id:uid()});assert(project.project_id&&project.location_id);
  const other=await json('/projects/'+project.project_id+'/locations','POST',{parent_id:project.location_id,title:'另一个开展位置',request_id:uid()});assert(other.location_id);
  const originalWork=await json('/projects/locations/'+project.location_id+'/work-items','POST',{description:'独立验收的关联工作',deliverable:'最初的交付约定',request_id:uid()});assert(originalWork.id);
  report.project_id=project.project_id;report.location_id=project.location_id;
  await go(base+'/projects/locations/'+project.location_id);
  await until('!!document.querySelector("#at-create button[type=submit]")');
  await evaluate('document.querySelector("#at-create-box").open=true');
  const title='一句话开始的尝试 <img src=x onerror="window.badAttempt=true">';
  await fill('#at-create [name=title]',title);await click('#at-create button[type=submit]');
  await until('document.querySelector("#at-items").textContent.includes('+JSON.stringify(title)+')');
  let attempt=(await json('/projects/'+project.project_id+'/attempts')).items.find(a=>a.title===title);assert(attempt);
  report.attempt_id=attempt.id;const card='#at-'+attempt.id;
  assert.equal(attempt.introduction,'');assert.equal(attempt.focus,'');assert.equal(attempt.next_entry,'');assert.equal(attempt.refs.length,0);assert.deepEqual(attempt.location_ids,[project.location_id]);
  assert.equal(await evaluate('!!document.querySelector("#at-items img")||!!window.badAttempt'),false);
  checks.push('actual form creates an attempt from one sentence with all other text/references empty; title is rendered as text, no HTML execution');
  const edit=card+' > .at-edit';
  await evaluate('document.querySelector('+JSON.stringify(edit)+').open=true;document.querySelector('+JSON.stringify(edit+' form > details')+').open=true');
  await fill(edit+' [name=introduction]','现在比较两个入口方案');await fill(edit+' [name=focus]','先弄清回到哪里继续');await fill(edit+' [name=next_entry]','从用户上次没有展开的方向重新进入');
  const scope=edit+' input[name="at-location"][value="'+other.location_id+'"]';await click(scope);
  await evaluate('document.querySelector('+JSON.stringify(edit+' .at-ref-picker')+').open=true');
  await evaluate('(()=>{const s=document.querySelector('+JSON.stringify(edit+' .at-ref-source')+');const o=[...s.options].find(o=>o.value&&JSON.parse(o.value).type==="work_item"&&JSON.parse(o.value).id==='+JSON.stringify(originalWork.id)+');if(!o)throw Error("work source absent");s.value=o.value;s.dispatchEvent(new Event("input",{bubbles:true}));})()');
  await fill(edit+' .at-ref-note','这份执行工作服务于当前比较');
  async function mark(selector,text,id){await evaluate('[...document.querySelectorAll('+JSON.stringify(selector+' button')+')].find(b=>b.textContent==='+JSON.stringify(text)+').id='+JSON.stringify(id));}
  await mark(edit,'加入关联','at-add-reference');await click('#at-add-reference');
  await fill(edit+' .at-ref-link','https://example.invalid/cognos-attempt-source');await mark(edit,'加入链接','at-add-link');await click('#at-add-link');
  await click(edit+' button[type=submit]');await until('!document.querySelector('+JSON.stringify(edit)+').open');
  attempt=(await json('/projects/attempts/'+attempt.id)).item;assert.equal(attempt.location_ids.length,2);assert.equal(attempt.refs.length,2);assert.equal(attempt.refs[0].type,'work_item');assert.equal(attempt.refs[0].version,1);
  assert.equal(attempt.refs.find(r=>r.type==='link').status,'link_only');assert(await evaluate('(()=>{const a=document.querySelector('+JSON.stringify(card+' .at-ref-list a')+');return a?.target==="_blank"&&a.rel.includes("noreferrer")&&a.href==="https://example.invalid/cognos-attempt-source"})()'));
  checks.push('actual edit saves introduction, focus, next entry, a second location, a pinned work source and a link explicitly marked without retrieved body; external link uses safe attributes');
  const old=(await json('/projects/work/'+originalWork.id)).item;
  await json('/projects/work/'+old.id,'PUT',{description:old.description,owner:old.owner,deliverable:'后来改变的交付约定',acceptance:old.acceptance,state:old.state,evidence:old.evidence,expected_revision:old.revision,request_id:uid()});
  await mark(card,'查看原记录','at-read-reference');await click('#at-read-reference');
  await until('document.querySelector('+JSON.stringify(card+' .at-ref-list pre')+')?.textContent.includes("最初的交付约定")');
  assert(!(await evaluate('document.querySelector('+JSON.stringify(card+' .at-ref-list pre')+').textContent.includes("后来改变的交付约定")')));
  checks.push('source read after work changes still displays the pinned original work revision');
  // A second editor saves while this actual form remains open.
  await evaluate('document.querySelector('+JSON.stringify(edit)+').open=true;document.querySelector('+JSON.stringify(edit+' form > details')+').open=true');
  await fill(edit+' [name=introduction]','浏览器里尚未保存的简介');await click('#at-refresh');
  assert(await evaluate('document.querySelector("#at-notice").textContent.includes("填写的内容会保留")'));
  assert.equal(await evaluate('document.querySelector('+JSON.stringify(edit+' [name=introduction]')+').value'),'浏览器里尚未保存的简介');
  const revision=attempt.revision;
  await json('/projects/attempts/'+attempt.id,'PUT',{title:attempt.title,introduction:'另一位刚改的简介',focus:attempt.focus,next_entry:attempt.next_entry,state:attempt.state,location_ids:attempt.location_ids,refs:attempt.refs,expected_revision:revision,request_id:uid()});
  await click(edit+' button[type=submit]');
  await until('document.querySelector('+JSON.stringify(edit+' .at-conflict')+').textContent.includes("另一位刚改的简介")');
  assert.equal(await evaluate('document.querySelector('+JSON.stringify(edit+' [name=introduction]')+').value'),'浏览器里尚未保存的简介');
  assert(await evaluate('document.querySelector('+JSON.stringify(edit+' button[type=submit]')+').disabled'));
  await mark(edit,'我已比较，以新版为基础保留上面的输入','at-compare');await click('#at-compare');await click(edit+' button[type=submit]');
  await until('!document.querySelector('+JSON.stringify(edit)+').open');
  attempt=(await json('/projects/attempts/'+attempt.id)).item;assert.equal(attempt.introduction,'浏览器里尚未保存的简介');assert(attempt.revisions.some(r=>r.snapshot.introduction==='另一位刚改的简介'));
  checks.push('refresh preserves local input; a real revision conflict shows the latest introduction and disables saving until explicit comparison; old versions remain');
  const add=card+' .at-summary-create';await evaluate('document.querySelector('+JSON.stringify(add)+').open=true');
  await fill(add+' [name=content]','先留下一个没有结论的观察');await click(add+' button[type=submit]');
  await until('document.querySelector('+JSON.stringify(card+' .at-summaries')+').textContent.includes("先留下一个没有结论的观察")');
  attempt=(await json('/projects/attempts/'+attempt.id)).item;assert.equal(attempt.summaries.length,1);let summary=attempt.summaries[0];report.summary_id=summary.id;
  assert.equal(summary.coverage,'');assert.equal(summary.covered_refs.length,0);assert(await evaluate('document.querySelector('+JSON.stringify('#ats-'+summary.id)+').textContent.includes("未绑定具体记录")'));
  await evaluate('document.querySelector('+JSON.stringify(add)+').open=true;document.querySelector('+JSON.stringify(add+' form > details')+').open=true');
  await fill(add+' [name=content]','第二阶段仍有一个未解方向');await fill(add+' [name=title]','第二阶段');await fill(add+' [name=coverage]','本次入口比较后的再次试用');await click(add+' button[type=submit]');
  await until('document.querySelector('+JSON.stringify(card+' .at-summaries')+').textContent.includes("第二阶段仍有一个未解方向")');
  assert.deepEqual(await evaluate('[...document.querySelectorAll('+JSON.stringify(card+' .at-stage > p:not(.pw-hint)')+')].map(n=>n.textContent)'),['先留下一个没有结论的观察','第二阶段仍有一个未解方向']);
  const summaryEdit='#ats-'+summary.id+' .at-summary-edit';await evaluate('document.querySelector('+JSON.stringify(summaryEdit)+').open=true');
  await fill(summaryEdit+' [name=content]','浏览器正在修订的阶段记录');
  await json('/projects/attempt-summaries/'+summary.id,'PUT',{title:summary.title,coverage:summary.coverage,content:'另一位对阶段记录的修订',covered_refs:summary.covered_refs,expected_revision:summary.revision,request_id:uid()});
  await click(summaryEdit+' button[type=submit]');await until('document.querySelector('+JSON.stringify(summaryEdit+' .at-conflict')+').textContent.includes("另一位对阶段记录的修订")');
  assert.equal(await evaluate('document.querySelector('+JSON.stringify(summaryEdit+' [name=content]')+').value'),'浏览器正在修订的阶段记录');
  await mark(summaryEdit,'我已比较，以新版为基础保留上面的输入','ats-compare');await click('#ats-compare');await click(summaryEdit+' button[type=submit]');await until('!document.querySelector('+JSON.stringify(summaryEdit)+').open');
  attempt=(await json('/projects/attempts/'+attempt.id)).item;summary=attempt.summaries.find(s=>s.id===summary.id);assert.equal(summary.content,'浏览器正在修订的阶段记录');assert.equal(summary.revision,3);assert(summary.revisions.some(r=>r.snapshot.content==='先留下一个没有结论的观察'));
  checks.push('stages append in time order separately from the current introduction; content alone is accepted with explicit unspecified coverage; revision conflict retains the same stage ID and all prior versions');
  await evaluate('document.querySelector('+JSON.stringify(edit)+').open=true;document.querySelector('+JSON.stringify(edit+' form > details')+').open=true;const s=document.querySelector('+JSON.stringify(edit+' [name=state]')+');s.value="ended";s.dispatchEvent(new Event("input",{bubbles:true}));');
  await click(edit+' button[type=submit]');await until('!document.querySelector('+JSON.stringify(edit)+').open');
  assert.equal((await json('/projects/attempts/'+attempt.id)).item.state,'ended');assert.equal((await json('/projects/work/'+old.id)).item.state,'open');
  await shoot('attempts-introduction-stages.png',card);
  await go(base+'/projects/locations/'+other.location_id);await until('!!document.querySelector('+JSON.stringify(card)+')');
  assert.equal((await json('/projects/'+project.project_id+'/attempts?location_id='+other.location_id)).items.filter(a=>a.id===attempt.id).length,1);
  await go(base+'/projects/'+project.project_id);await until('!!document.querySelector('+JSON.stringify(card)+')');
  assert(await evaluate('document.querySelector("#at-create-box").hidden'));
  checks.push('one shared attempt is visible in both locations and the project overview; ending it leaves related execution work open');
  await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:false});
  assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'));await shoot('attempts-mobile.png',card);
  checks.push('390px introduction and stage records fit without horizontal overflow');assert.equal(errors.length,0,'no uncaught JavaScript exceptions');report.result='pass';
}
main().catch(e=>{report.result='fail';report.error=e.message;process.exitCode=1;}).finally(()=>{
  fs.mkdirSync(evidenceDir,{recursive:true});fs.writeFileSync(path.join(evidenceDir,'attempts-browser.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));if(ws)ws.close();browser.kill();
});
