// Real Nextcloud browser operations; creates a uniquely named trial card.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {spawn}=require('node:child_process');
const readline=require('node:readline');
const output=process.env.COGNOS_TRIAL_EVIDENCE_DIR || path.join('C:/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial','evidence');
fs.mkdirSync(output,{recursive:true});
const state=JSON.parse(fs.readFileSync(path.join('C:/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial','.secrets/task-journey.json'),'utf8'));
const boardUrl='http://127.0.0.1:8780/index.php/apps/deck/board/'+state.board;
const name='UI task '+Date.now();
const report={scope:'live native assignee removal and field preservation at 390px',checks:[],full_journey:'unverified',service_restart:'unverified',error_recovery:'unverified'};
const child=spawn(process.execPath,[path.join('C:/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial','browser-shell.cjs')],{windowsHide:true,stdio:['pipe','pipe','pipe']});
let waiting,readyResolve;const ready=new Promise(r=>readyResolve=r);
const lines=readline.createInterface({input:child.stdout});
lines.on('line',line=>{if(line==='READY'){readyResolve();return}try{const result=JSON.parse(line);if(waiting){const waiter=waiting;waiting=null;clearTimeout(waiter.timer);result.error?waiter.reject(new Error(result.error)):waiter.resolve(result.result)}}catch{}});
function send(command){return new Promise((resolve,reject)=>{const timer=setTimeout(()=>{waiting=null;reject(new Error('Browser command timed out'))},90000);waiting={resolve,reject,timer};child.stdin.write(JSON.stringify(command)+'\n')})}
const evaluate=evalCode=>send({eval:evalCode});
async function main(){
 await ready;await send({login:'alice'});
 const trial='C:/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial';
 const fixture=JSON.parse(fs.readFileSync(path.join(trial,'evidence/任务浏览器-自动验收.json'),'utf8'));
 await send({viewport:390});
 await send({navigate:'http://127.0.0.1:8780'+fixture.card_url});
 await send({wait:'!!document.querySelector("[data-cognos-task-history] li")'});
 const initial=await evaluate(`fetch('/index.php/apps/cognos/cards/${fixture.card_url.split('/').pop()}/events',{headers:{requesttoken:OC.requestToken}}).then(r=>r.json())`);
 assert.ok(await evaluate('window.__cognosTaskAssignment'));
 if(initial.completed) {
 await send({click:'[data-cognos-reopen]'});
 await send({wait:'!![...document.querySelectorAll("a")].find(e=>e.textContent==="查看更新后的任务")'});
 }
 if(!initial.events[0].after_state.assignees.some(a=>a.uid===state.bob.user)) {
 const setup=await evaluate(`fetch('/ocs/v2.php/apps/cognos/api/v1.0/cards/${fixture.card_url.split('/').pop()}/assign?format=json',{method:'POST',headers:{'Content-Type':'application/json',requesttoken:OC.requestToken},body:JSON.stringify({requestId:crypto.randomUUID(),userId:${JSON.stringify(state.bob.user)},type:0})}).then(r=>r.status)`);assert.equal(setup,200);
 }
 await send({navigate:'http://127.0.0.1:8780'+fixture.card_url});
 await send({wait:'!!document.querySelector("#tab-details .user-select .vs__deselect")'});

 const before=await evaluate(`fetch('/index.php/apps/cognos/cards/${fixture.card_url.split('/').pop()}/events',{headers:{requesttoken:OC.requestToken}}).then(r=>r.json())`);
 const count=before.events.length;
 await send({click:'#tab-details .user-select .vs__deselect'});
 await send({wait:`document.querySelectorAll('[data-cognos-task-history] li').length === ${count+1}`});
 const after=await evaluate(`fetch('/index.php/apps/cognos/cards/${fixture.card_url.split('/').pop()}/events',{headers:{requesttoken:OC.requestToken}}).then(r=>r.json())`);
 assert.equal(after.events[0].kind,'unassigned');assert.equal(after.events[0].after_state.assignees.length,0);
 for(const field of ['title','description','done','stack','labels','duedate','startdate','color'])assert.deepEqual(after.events[0].after_state[field],before.events[0].after_state[field]);
 report.checks.push('390px native member-selector removal commits cancellation without changing other task fields');
 const network=await send({networkSummary:true});
 const calls=network.requests.filter(r=>r.method==='PUT'&&r.path.includes('/apps/cognos/')&&r.path.endsWith('/unassign'));
 assert.equal(calls.length,1);assert.equal(calls[0].status,200);
 report.checks.push('native cancellation uses Cognos transaction endpoint exactly once and returns HTTP 200');
 await send({navigate:'http://127.0.0.1:8780'+fixture.card_url});
 await send({wait:`document.querySelectorAll('[data-cognos-task-history] li').length === ${count+1}`});
 assert.equal(await evaluate('document.querySelectorAll("#tab-details .user-select .vs__deselect").length'),0);
 await evaluate(`document.querySelector('[data-cognos-task-history]').scrollIntoView({block:'center'})`);
 await send({screenshot:'任务取消指派-手机'});
 report.checks.push('fresh navigation shows empty assignee and cancellation history');
 report.card_url=fixture.card_url;
 report.result='passed';
}
main().catch(async e=>{report.result='failed';report.failure=e.message;report.network=await send({networkSummary:true});report.visible_error=await evaluate('document.querySelector("[data-cognos-task-feedback]")?.innerText');process.exitCode=1}).finally(async()=>{fs.writeFileSync(path.join(output,'任务取消指派-浏览器验收.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report));child.stdin.write('{"close":true}\n');setTimeout(()=>child.kill(),2000).unref();});
