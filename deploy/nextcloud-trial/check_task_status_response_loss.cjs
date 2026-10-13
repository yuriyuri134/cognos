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
 await send({wait:'document.querySelector("[data-cognos-task-status]")?.value === "open"'});
 const read=()=>evaluate(`fetch('/index.php/apps/cognos/cards/${fixture.objects.card}/events',{headers:{requesttoken:OC.requestToken}}).then(r=>r.json())`);
 const before=await read();
 await evaluate(`(()=>{const s=document.querySelector('[data-cognos-task-status]');s.value='blocked';s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
 await send({dropNextPhaseResponse:true});await send({click:'[data-cognos-status-save]'});
 await send({wait:'!!document.querySelector("[data-cognos-status-control] [role=alert]") && !document.querySelector("[data-cognos-status-save]").disabled'});
 assert.equal(await evaluate('document.querySelector("[data-cognos-task-status]").value'),'blocked');
 const committed=await read();assert.equal(committed.currentState.status,'blocked');assert.equal(committed.events.length,before.events.length+1);
 report.checks.push('server commits blocked phase before HTTP 200 is dropped; visible error preserves selected state and restores submit');
 await send({click:'[data-cognos-status-save]'});
 await send({wait:'document.querySelector("[data-cognos-current-status]")?.innerText === "当前状态：阻塞" && !document.querySelector("[data-cognos-status-control] [role=alert]")'});
 const after=await read();assert.deepEqual(after.events,committed.events);
 const network=await send({networkSummary:true});const writes=network.requests.filter(r=>r.method==='PUT'&&r.path.endsWith('/state'));
 assert.equal(writes.length,2);assert.equal(writes[0].operation_id,writes[1].operation_id);assert.equal(writes[1].status,200);assert.ok(writes[1].replayed);
 assert.equal(network.droppedResponses.length,1);assert.equal(network.faultErrors.length,0);
 assert.equal(await evaluate(`Object.keys(sessionStorage).filter(k=>k.startsWith('cognos.task.phase.')).length`),0);
 report.checks.push('retry uses same ID and returns replay; one event remains and recovery key clears');
 await send({navigate:'http://127.0.0.1:8780'+fixture.card_url});await send({wait:'document.querySelector("[data-cognos-task-status]")?.value === "blocked"'});
 assert.deepEqual((await read()).events,after.events);report.checks.push('fresh navigation reads blocked state and unchanged history');
 await evaluate(`document.querySelector('[data-cognos-status-control]').scrollIntoView({block:'center'})`);await send({screenshot:'任务四状态响应丢失-恢复'});
 report.card_url=fixture.card_url;report.error_recovery='verified for HTTP 200 response-stage loss';
 report.result='passed';
}
main().catch(async e=>{report.result='failed';report.failure=e.message;report.network=await send({networkSummary:true});report.visible_error=await evaluate('document.querySelector("[data-cognos-task-feedback]")?.innerText');process.exitCode=1}).finally(async()=>{fs.writeFileSync(path.join(output,'任务四状态响应丢失-浏览器验收.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report));child.stdin.write('{"close":true}\n');setTimeout(()=>child.kill(),2000).unref();});
