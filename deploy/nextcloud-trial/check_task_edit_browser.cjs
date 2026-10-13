// Real Nextcloud browser operations; creates a uniquely named trial card.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {spawn}=require('node:child_process');
const readline=require('node:readline');
const output=process.env.COGNOS_TRIAL_EVIDENCE_DIR || path.join('C:/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial','evidence');
fs.mkdirSync(output,{recursive:true});
const state=JSON.parse(fs.readFileSync(path.join('C:/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial','.secrets/task-journey.json'),'utf8'));
const boardUrl='http://127.0.0.1:8780/index.php/apps/deck/board/'+state.board;
const name='UI task '+Date.now();
const report={scope:'live native rich-text description edit at 390px',checks:[],full_journey:'unverified',service_restart:'unverified',error_recovery:'unverified'};
const child=spawn(process.execPath,[path.join('C:/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial','browser-shell.cjs')],{windowsHide:true,stdio:['pipe','pipe','pipe']});
let waiting,readyResolve;const ready=new Promise(r=>readyResolve=r);
const lines=readline.createInterface({input:child.stdout});
lines.on('line',line=>{if(line==='READY'){readyResolve();return}try{const result=JSON.parse(line);if(waiting){const waiter=waiting;waiting=null;clearTimeout(waiter.timer);result.error?waiter.reject(new Error(result.error)):waiter.resolve(result.result)}}catch{}});
function send(command){return new Promise((resolve,reject)=>{const timer=setTimeout(()=>{waiting=null;reject(new Error('Browser command timed out'))},90000);waiting={resolve,reject,timer};child.stdin.write(JSON.stringify(command)+'\n')})}
const evaluate=evalCode=>send({eval:evalCode});
async function main(){
 await ready;await send({login:'alice'});
 const trial='C:/Users/lnp/Documents/Playground/cognos/deploy/nextcloud-trial';
 const fixture=JSON.parse(fs.readFileSync(path.join(trial,'evidence/edit-case.json'),'utf8'));
 await send({viewport:390});
 await send({navigate:'http://127.0.0.1:8780'+fixture.card_url});
 await send({wait:'!!document.querySelector("#tab-details .ProseMirror") && !!document.querySelector("[data-cognos-task-history] li")'});
 assert.ok(await evaluate('window.__cognosTaskEdit'));
 const read=()=>evaluate(`fetch('/index.php/apps/cognos/cards/${fixture.objects.card}/events',{headers:{requesttoken:OC.requestToken}}).then(r=>r.json())`);
 const before=await read();assert.ok(before.completed);
 const description='Mobile native description '+Date.now();
 await send({click:'#tab-details .ProseMirror'});
 await send({selectAllAndType:{selector:'#tab-details .ProseMirror',text:description}});
 await send({wait:`document.querySelectorAll('[data-cognos-task-history] li').length > ${before.events.length}`});
 const after=await read();assert.ok(after.completed);assert.equal(after.events[0].kind,'edited');
 assert.equal(after.events[0].after_state.description,description);
 for(const field of ['title','done','stack','assignees','labels','duedate','startdate','color'])assert.deepEqual(after.events[0].after_state[field],before.currentState[field]);
 report.checks.push('native rich-text description editing at 390px preserves completion, assignees, labels and other fields');
 const network=await send({networkSummary:true});
 const writes=network.requests.filter(r=>r.method==='PUT'&&r.path===`/ocs/v2.php/apps/cognos/api/v1.0/cards/${fixture.objects.card}`);
 assert.ok(writes.length>=1 && writes.every(r=>r.status===200));assert.ok(writes.every(r=>r.body_keys.includes('changes')&&r.body_keys.includes('expected')));
 report.checks.push('native full-card save is converted to explicit changes and loaded-field expectations');
 await send({navigate:'http://127.0.0.1:8780'+fixture.card_url});
 await send({wait:`document.querySelector('#tab-details .ProseMirror')?.innerText.trim() === ${JSON.stringify(description)}`});
 const fresh=await read();assert.deepEqual(fresh.events,after.events);
 report.checks.push('fresh navigation retains edited description and unchanged historical snapshots');
 await evaluate(`document.querySelector('[data-cognos-task-history]').scrollIntoView({block:'center'})`);await send({screenshot:'任务编辑-手机'});
 report.card_url=fixture.card_url;
 report.result='passed';
}
main().catch(async e=>{report.result='failed';report.failure=e.message;report.network=await send({networkSummary:true});report.visible_error=await evaluate('document.querySelector("[data-cognos-task-feedback]")?.innerText');process.exitCode=1}).finally(async()=>{fs.writeFileSync(path.join(output,'任务编辑-浏览器验收.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report));child.stdin.write('{"close":true}\n');setTimeout(()=>child.kill(),2000).unref();});
