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
 const fixture=JSON.parse(fs.readFileSync(path.join(trial,'evidence/status-readonly-case.json'),'utf8'));
 await send({viewport:390});
 await send({navigate:'http://127.0.0.1:8780'+fixture.card_url});
 await send({wait:'!!document.querySelector("[data-cognos-task-status]")'});
 assert.ok(await evaluate('document.querySelector("[data-cognos-task-status]").disabled && document.querySelector("[data-cognos-status-save]").disabled'));
 assert.ok((await evaluate('document.querySelector("[data-cognos-status-control]").innerText')).includes('当前任务只读'));
 await send({click:'[data-cognos-status-save]'});
 const network=await send({networkSummary:true});assert.equal(network.requests.filter(r=>r.method==='PUT'&&r.path.endsWith('/state')).length,0);
 report.checks.push('read-only member sees state/history with disabled controls and a clear explanation');
 report.checks.push('clicking disabled state submit sends no write request');report.card_url=fixture.card_url;
 await evaluate(`document.querySelector('[data-cognos-status-control]').scrollIntoView({block:'center'})`);await send({screenshot:'任务状态-只读手机'});
 report.result='passed';
}
main().catch(async e=>{report.result='failed';report.failure=e.message;report.network=await send({networkSummary:true});report.visible_error=await evaluate('document.querySelector("[data-cognos-task-feedback]")?.innerText');process.exitCode=1}).finally(async()=>{fs.writeFileSync(path.join(output,'任务状态只读-浏览器验收.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report));child.stdin.write('{"close":true}\n');setTimeout(()=>child.kill(),2000).unref();});
