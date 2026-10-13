// Live browser network failure/retry; uses the isolated trial only.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {spawn}=require('node:child_process'),readline=require('node:readline'),http=require('node:http');
const output=process.env.COGNOS_TRIAL_EVIDENCE_DIR || path.join(__dirname,'evidence');
fs.mkdirSync(output,{recursive:true});
const state=JSON.parse(fs.readFileSync(path.join(__dirname,'.secrets/task-journey.json'),'utf8'));
const title='Network recovery '+Date.now();
const injected=process.argv.includes('--inject-feedback');
const report={scope:'actual Chrome offline submission and online retry',feedback:injected?'source injected into live browser; Nextcloud app loading unverified':'installed server only',checks:{},full_journey:'unverified'};
const child=spawn(process.execPath,[path.join(__dirname,'browser-shell.cjs')],{windowsHide:true,stdio:['pipe','pipe','pipe']});
let waiter,readyResolve;const ready=new Promise(r=>readyResolve=r);
readline.createInterface({input:child.stdout}).on('line',line=>{if(line==='READY'){readyResolve();return}try{const d=JSON.parse(line);if(waiter){const w=waiter;waiter=null;clearTimeout(w.timer);d.error?w.reject(new Error(d.error)):w.resolve(d.result)}}catch{}});
function send(c){return new Promise((resolve,reject)=>{const timer=setTimeout(()=>{waiter=null;reject(new Error('Browser command timeout'))},90000);waiter={resolve,reject,timer};child.stdin.write(JSON.stringify(c)+'\n')})}
const evaluate=code=>send({eval:code});
const pause=ms=>new Promise(r=>setTimeout(r,ms));
function stacks(){return new Promise((resolve,reject)=>{const u=state.alice;const req=http.get(`http://127.0.0.1:8780/index.php/apps/deck/api/v1.0/boards/${state.board}/stacks`,{headers:{Authorization:'Basic '+Buffer.from(u.user+':'+u.password).toString('base64'),'OCS-APIRequest':'true'}},r=>{let b='';r.on('data',d=>b+=d);r.on('end',()=>{try{if(r.statusCode!==200)throw new Error('Stack read HTTP '+r.statusCode);resolve(JSON.parse(b))}catch(e){reject(e)}})});req.on('error',reject);req.setTimeout(15000,()=>req.destroy(new Error('Stack read timed out')));})}
async function countCards(){const all=await stacks();return all.flatMap(s=>s.cards||[]).filter(c=>c.title===title).length;}
async function main(){
 await Promise.race([ready,pause(20000).then(()=>{throw new Error('Startup timed out')})]);
 await send({login:'alice'});await send({navigate:`http://127.0.0.1:8780/index.php/apps/deck/board/${state.board}`});
 await send({wait:'!!document.querySelector(\'button[aria-label="添加卡片"]\')'});
 if(injected)await send({injectTaskFeedback:true});
 await send({click:'button[aria-label="添加卡片"]'});
 await send({wait:'!!document.querySelector(\'input[placeholder="卡片名"]\')'});
 await evaluate(`(()=>{const e=document.querySelector('input[placeholder="卡片名"]');e.value=${JSON.stringify(title)};e.dispatchEvent(new Event('input',{bubbles:true}));return true})()`);
 await send({offline:true});await send({click:'input[placeholder="卡片名"] + input[type=submit]'});
 let network;for(let i=0;i<30;i++){network=await send({networkSummary:true});if(network.failedRequests.length)break;await pause(200);}
 assert.ok(network.failedRequests.length,'No failed request was observed');
 report.network=network;
 report.checks.failed_request_observed=true;
 report.checks.draft_preserved=await evaluate(`document.querySelector('input[placeholder="卡片名"]')?.value===${JSON.stringify(title)}`);
 report.error_text=await evaluate(`Array.from(document.querySelectorAll('[role="alert"],.toastify,.toast')).filter(e=>e.getBoundingClientRect().width).map(e=>e.innerText).join('; ').slice(0,1200)`);
 report.checks.visible_error=!!report.error_text.trim();
 report.checks.no_server_write=await countCards()===0;
 await send({screenshot:'任务断网-草稿与错误'});
 await send({offline:false});
 assert.ok(report.checks.draft_preserved,'Draft lost; do not reconstruct it to fake recovery');
 await send({click:'input[placeholder="卡片名"] + input[type=submit]'});
 await send({wait:`Array.from(document.querySelectorAll('#app-content-vue h4')).some(e=>e.textContent.trim()===${JSON.stringify(title)})`});
 report.checks.exactly_one_after_retry=await countCards()===1;
 report.checks.draft_cleared_after_success=await evaluate(`document.querySelector('input[placeholder="卡片名"]')?.value===''`);
 await send({screenshot:'任务断网-恢复后成功'});
 report.result=Object.values(report.checks).every(Boolean)?'passed':'failed';
 if(report.result==='failed')process.exitCode=1;
}
main().catch(e=>{report.result='failed';report.failure=e.message;process.exitCode=1}).finally(async()=>{fs.writeFileSync(path.join(output,'任务断网-自动验收.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report));child.stdin.write('{"offline":false}\n{"close":true}\n');setTimeout(()=>child.kill(),2000).unref();});
