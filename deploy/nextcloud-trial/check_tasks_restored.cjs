// Live browser network failure/retry; uses the isolated trial only.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {spawn}=require('node:child_process'),readline=require('node:readline'),http=require('node:http');
const output=process.env.COGNOS_TRIAL_EVIDENCE_DIR || path.join(__dirname,'evidence');
fs.mkdirSync(output,{recursive:true});
const state=JSON.parse(fs.readFileSync(path.join(__dirname,'.secrets/task-journey.json'),'utf8'));
const title='Network recovery '+Date.now();
const injected=process.argv.includes('--inject-feedback');
const report={scope:'fresh browser reads existing pre-restart card history',feedback:injected?'source injected into live browser; Nextcloud app loading unverified':'installed server only',checks:{},full_journey:'unverified'};
const child=spawn(process.execPath,[path.join(__dirname,'browser-shell.cjs')],{windowsHide:true,stdio:['pipe','pipe','pipe']});
let waiter,readyResolve;const ready=new Promise(r=>readyResolve=r);
readline.createInterface({input:child.stdout}).on('line',line=>{if(line==='READY'){readyResolve();return}try{const d=JSON.parse(line);if(waiter){const w=waiter;waiter=null;clearTimeout(w.timer);d.error?w.reject(new Error(d.error)):w.resolve(d.result)}}catch{}});
function send(c){return new Promise((resolve,reject)=>{const timer=setTimeout(()=>{waiter=null;reject(new Error('Browser command timeout'))},90000);waiter={resolve,reject,timer};child.stdin.write(JSON.stringify(c)+'\n')})}
const evaluate=code=>send({eval:code});
const pause=ms=>new Promise(r=>setTimeout(r,ms));
function stacks(){return new Promise((resolve,reject)=>{const u=state.alice;const req=http.get(`http://127.0.0.1:8780/index.php/apps/deck/api/v1.0/boards/${state.board}/stacks`,{headers:{Authorization:'Basic '+Buffer.from(u.user+':'+u.password).toString('base64'),'OCS-APIRequest':'true'}},r=>{let b='';r.on('data',d=>b+=d);r.on('end',()=>{try{if(r.statusCode!==200)throw new Error('Stack read HTTP '+r.statusCode);resolve(JSON.parse(b))}catch(e){reject(e)}})});req.on('error',reject);req.setTimeout(15000,()=>req.destroy(new Error('Stack read timed out')));})}
async function countCards(){const all=await stacks();return all.flatMap(s=>s.cards||[]).filter(c=>c.title===title).length;}
async function main(){
 const prior=JSON.parse(fs.readFileSync(path.join(output,'任务浏览器-自动验收.json'),'utf8'));
 assert.equal(prior.result,'passed');
 report.card_url=prior.card_url;
 await Promise.race([ready,pause(20000).then(()=>{throw new Error('Startup timed out')})]);
 await send({login:'bob'});
 await send({navigate:'http://127.0.0.1:8780'+prior.card_url});
 await send({wait:'Array.from(document.querySelectorAll("button,[role=tab]")).some(e=>e.innerText.trim()==="活动")'});
 await evaluate('Array.from(document.querySelectorAll("button,[role=tab]")).find(e=>e.innerText.trim()==="活动").click();true');
 await send({wait:'document.body.innerText.includes("指派给了") && document.body.innerText.includes("标记为已完成")'});
 const text=await evaluate('document.body.innerText.slice(-4500)');
 report.checks.creator_and_assignee_names_visible=text.includes('试用同学 A')&&text.includes('试用同学 B');
 report.checks.create_assign_complete_history=text.includes('创建了卡片')&&text.includes('指派给了')&&text.includes('标记为已完成');
 report.checks.feedback_autoloads=await evaluate('window.__cognosTaskFeedback===true');
 await send({screenshot:'任务重启-既有历史'});
 report.result=Object.values(report.checks).every(Boolean)?'passed':'failed';
 if(report.result==='failed')process.exitCode=1;
 report.service_restart='Docker restart performed by parent; compare service-start evidence';
}
main().catch(e=>{report.result='failed';report.failure=e.message;process.exitCode=1}).finally(async()=>{fs.writeFileSync(path.join(output,'任务重启-浏览器验收.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report));child.stdin.write('{"close":true}\n');setTimeout(()=>child.kill(),2000).unref();});