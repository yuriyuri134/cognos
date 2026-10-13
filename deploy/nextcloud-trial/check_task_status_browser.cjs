// Real Nextcloud browser operations; creates a uniquely named trial card.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {spawn}=require('node:child_process');
const readline=require('node:readline');
const output=process.env.COGNOS_TRIAL_EVIDENCE_DIR || path.join('C:/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial','evidence');
fs.mkdirSync(output,{recursive:true});
const state=JSON.parse(fs.readFileSync(path.join('C:/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial','.secrets/task-journey.json'),'utf8'));
const boardUrl='http://127.0.0.1:8780/index.php/apps/deck/board/'+state.board;
const name='UI task '+Date.now();
const report={scope:'live Bob four-state workflow at 390px',checks:[],full_journey:'unverified',service_restart:'unverified',error_recovery:'unverified'};
const child=spawn(process.execPath,[path.join('C:/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial','browser-shell.cjs')],{windowsHide:true,stdio:['pipe','pipe','pipe']});
let waiting,readyResolve;const ready=new Promise(r=>readyResolve=r);
const lines=readline.createInterface({input:child.stdout});
lines.on('line',line=>{if(line==='READY'){readyResolve();return}try{const result=JSON.parse(line);if(waiting){const waiter=waiting;waiting=null;clearTimeout(waiter.timer);result.error?waiter.reject(new Error(result.error)):waiter.resolve(result.result)}}catch{}});
function send(command){return new Promise((resolve,reject)=>{const timer=setTimeout(()=>{waiting=null;reject(new Error('Browser command timed out'))},90000);waiting={resolve,reject,timer};child.stdin.write(JSON.stringify(command)+'\n')})}
const evaluate=evalCode=>send({eval:evalCode});
async function main(){
 await ready;await send({login:'bob'});
 const trial='C:/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial';
 const fixture=JSON.parse(fs.readFileSync(path.join(trial,'evidence/status-case.json'),'utf8'));
 await send({viewport:390});
 await send({navigate:'http://127.0.0.1:8780'+fixture.card_url});
 await send({wait:'!!document.querySelector("[data-cognos-task-status]")'});
 const read=()=>evaluate(`fetch('/index.php/apps/cognos/cards/${fixture.objects.card}/events',{headers:{requesttoken:OC.requestToken}}).then(r=>r.json())`);
 const original=await read();assert.equal(original.currentState.status,'open');
 const names={in_progress:'进行中',blocked:'阻塞',done:'完成',open:'未开始'};
 let oldEvents=original.events;
 for(const phase of ['in_progress','blocked','done','open']){
   await evaluate(`(()=>{const s=document.querySelector('[data-cognos-task-status]');s.value=${JSON.stringify(phase)};s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
   await send({click:'[data-cognos-status-save]'});
   await send({wait:`document.querySelector('[data-cognos-current-status]')?.innerText === ${JSON.stringify('当前状态：'+names[phase])} && !document.querySelector('[data-cognos-status-save]').hasAttribute('aria-busy')`});
   const data=await read();assert.equal(data.currentState.status,phase);assert.equal(data.events[0].kind,'status_changed');assert.equal(data.events[0].actor_uid,state.bob.user);
   assert.deepEqual(data.events.slice(1),oldEvents);oldEvents=data.events;
   for(const f of ['title','description','assignees','labels','duedate','startdate','color','owner'])assert.deepEqual(data.currentState[f],original.currentState[f]);
   assert.equal(data.currentState.stack.id,phase==='done'?fixture.objects.done:fixture.objects.todo);
 }
 report.checks.push('Bob changes open, in-progress, blocked, done and open through visible controls at 390px');
 report.checks.push('every transition records Bob and preserves fields and original snapshots; completion/reopen columns are consistent');
 const network=await send({networkSummary:true});const writes=network.requests.filter(r=>r.method==='PUT'&&r.path.endsWith('/state'));
 assert.equal(writes.length,4);assert.ok(writes.every(r=>r.status===200&&r.body_keys.includes('expectedStatus')));
 report.checks.push('four explicit state submissions each receive HTTP 200');
 await send({navigate:'http://127.0.0.1:8780'+fixture.card_url});
 await send({wait:'document.querySelector("[data-cognos-task-status]")?.value === "open"'});
 assert.deepEqual((await read()).events,oldEvents);
 await evaluate(`document.querySelector('[data-cognos-status-control]').scrollIntoView({block:'center'})`);await send({screenshot:'任务四状态-手机'});
 report.checks.push('fresh navigation retains the selected state and full history');
 report.card_url=fixture.card_url;
 report.result='passed';
}
main().catch(async e=>{report.result='failed';report.failure=e.message;report.network=await send({networkSummary:true});report.visible_error=await evaluate('document.querySelector("[data-cognos-task-feedback]")?.innerText');process.exitCode=1}).finally(async()=>{fs.writeFileSync(path.join(output,'任务四状态-浏览器验收.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report));child.stdin.write('{"close":true}\n');setTimeout(()=>child.kill(),2000).unref();});
