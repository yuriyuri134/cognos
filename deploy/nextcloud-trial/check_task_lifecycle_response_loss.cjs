// Real Nextcloud browser operations; creates a uniquely named trial card.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {spawn}=require('node:child_process');
const readline=require('node:readline');
const output=process.env.COGNOS_TRIAL_EVIDENCE_DIR || path.join('C:/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial','evidence');
fs.mkdirSync(output,{recursive:true});
const state=JSON.parse(fs.readFileSync(path.join('C:/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial','.secrets/task-journey.json'),'utf8'));
const boardUrl='http://127.0.0.1:8780/index.php/apps/deck/board/'+state.board;
const name='UI task '+Date.now();
const report={scope:'live browser completion/reopening response-loss recovery at 390px',checks:[],full_journey:'unverified',service_restart:'unverified',error_recovery:'unverified'};
const child=spawn(process.execPath,[path.join('C:/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial','browser-shell.cjs')],{windowsHide:true,stdio:['pipe','pipe','pipe']});
let waiting,readyResolve;const ready=new Promise(r=>readyResolve=r);
const lines=readline.createInterface({input:child.stdout});
lines.on('line',line=>{if(line==='READY'){readyResolve();return}try{const result=JSON.parse(line);if(waiting){const waiter=waiting;waiting=null;clearTimeout(waiter.timer);result.error?waiter.reject(new Error(result.error)):waiter.resolve(result.result)}}catch{}});
function send(command){return new Promise((resolve,reject)=>{const timer=setTimeout(()=>{waiting=null;reject(new Error('Browser command timed out'))},90000);waiting={resolve,reject,timer};child.stdin.write(JSON.stringify(command)+'\n')})}
const evaluate=evalCode=>send({eval:evalCode});
async function main(){
 await ready;await send({login:'alice'});
 const trial='C:/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial';
 const fixture=JSON.parse(fs.readFileSync(path.join(trial,'evidence/lifecycle-case.json'),'utf8'));
 await send({viewport:390});
 await send({navigate:'http://127.0.0.1:8780'+fixture.card_url});
 await send({wait:'!!document.querySelector("[data-cognos-task-history] li")'});
 const count=await evaluate('document.querySelectorAll("[data-cognos-task-history] li").length');
 const initial=await evaluate(`fetch('/index.php/apps/cognos/cards/${fixture.objects.card}/events',{headers:{requesttoken:OC.requestToken}}).then(r=>r.json())`);
 const beforeReopen=initial.completed ? count : count+1;
 if(!initial.completed){
 await evaluate(`(()=>{const b=[...document.querySelectorAll('button')].find(e=>e.innerText.trim()==='标记为已完成');if(!b)throw Error('completion control missing');b.dataset.stateLossDone='true'})()`);
 await send({click:'[data-state-loss-done]'});
 await send({wait:`document.querySelectorAll('[data-cognos-task-history] li').length === ${count+1}`});
 }
 await send({navigate:'http://127.0.0.1:8780'+fixture.card_url});
 await send({wait:'!!document.querySelector("[data-cognos-reopen]")'});
 await send({dropNextReopenResponse:true});
 await send({click:'[data-cognos-reopen]'});
 await send({wait:'!!document.querySelector("[data-cognos-task-actions] [role=alert]") && !document.querySelector("[data-cognos-reopen]").disabled'});
 const read=()=>evaluate(`fetch('/index.php/apps/cognos/cards/${fixture.objects.card}/events',{headers:{requesttoken:OC.requestToken}}).then(r=>r.json())`);
 const committed=await read(); assert.equal(committed.completed,false); assert.equal(committed.events.length,beforeReopen+1);
 report.checks.push('HTTP 200 response is dropped after server commits reopening; visible error restores retry control');
 await send({click:'[data-cognos-reopen]'});
 await send({wait:`document.querySelectorAll('[data-cognos-task-history] li').length === ${beforeReopen+1}`});
 const after=await read(); assert.deepEqual(after.events,committed.events);
 const network=await send({networkSummary:true});
 const calls=network.requests.filter(r=>r.method==='PUT'&&r.path.endsWith('/undone')&&r.path.includes('/apps/cognos/cards/'));
 assert.equal(calls.length,2); assert.equal(calls[0].operation_id,calls[1].operation_id); assert.equal(calls[1].status,200); assert.ok(calls[1].replayed);
 assert.equal(network.droppedResponses.length,1); assert.equal(network.faultErrors.length,0);
 report.checks.push('retry uses the same request ID, returns replay and does not append another history event');
 assert.equal(after.events[0].after_state.stack.id,fixture.objects.todo);
 report.checks.push('recovered reopening retains the original column and description');
 report.card_url=fixture.card_url; report.service_restart='unverified'; report.error_recovery='verified for response-stage HTTP 200 loss';
 await send({screenshot:'任务重开响应丢失-恢复'});
 report.result='passed';
}
main().catch(async e=>{report.result='failed';report.failure=e.message;report.network=await send({networkSummary:true});report.visible_error=await evaluate('document.querySelector("[data-cognos-task-feedback]")?.innerText');process.exitCode=1}).finally(async()=>{fs.writeFileSync(path.join(output,'任务重开响应丢失-浏览器验收.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report));child.stdin.write('{"close":true}\n');setTimeout(()=>child.kill(),2000).unref();});
