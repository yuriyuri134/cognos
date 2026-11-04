const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const origin='http://127.0.0.1:8780',app='/index.php/apps/cognos',ocs='/ocs/v2.php/apps/cognos/api/v1.0';
const password=fs.readFileSync(path.join(__dirname,'.secrets/admin-password'),'utf8').trim();
const headers={Authorization:'Basic '+Buffer.from('cognos-admin:'+password).toString('base64'),'OCS-APIRequest':'true',Accept:'application/json','Content-Type':'application/json'};
const report={checks:[]},id=()=>crypto.randomUUID();
async function req(route,method='GET',body,status=200){const r=await fetch(origin+route,{method,headers,body:body?JSON.stringify(body):undefined});assert.equal(r.status,status,route);const j=await r.json();return j.ocs?j.ocs.data:j;}
async function main(){
 const {execFileSync}=require('node:child_process');
 const fixture=JSON.parse(execFileSync('docker',['exec','-u','www-data','-w','/var/www/html','cognos-nextcloud-trial-app-1','php','/tmp/seed_followup_review.php'],{encoding:'utf8'}));
 Object.assign(report,fixture);const p=fixture.api_fixture;
 let overview=await req(app+'/projects/'+p.project_id+'/overview');assert(overview.followups.some(x=>x.result_id===p.result_id&&x.state==='unknown'));
 const url=app+'/projects/followups/'+p.result_id+'/close';
 await req(url,'POST',{expected_state:'unknown',note:'',request_id:id()},422);
 await req(url,'POST',{expected_state:'pending',note:'旧状态不应通过',request_id:id()},409);
 const outsider=JSON.parse(fs.readFileSync(path.join(__dirname,'.secrets/task-journey.json'),'utf8')).outsider;
 const unauth=await fetch(origin+url,{method:'POST',headers:{...headers,Authorization:'Basic '+Buffer.from(outsider.user+':'+outsider.password).toString('base64')},body:JSON.stringify({expected_state:'unknown',note:'不应该有权限',request_id:id()})});assert.equal(unauth.status,404);
 const close={expected_state:'unknown',note:'已查看外部记录，这次接续不再自动重放',request_id:id()};
 const saved=await req(url,'POST',close);assert.equal(saved.state,'reconciled');assert.deepEqual(await req(url,'POST',close),saved);
 await req(url,'POST',{...close,request_id:id()},409);
 overview=await req(app+'/projects/'+p.project_id+'/overview');const event=overview.followups.find(x=>x.result_id===p.result_id);assert(event.note.includes(close.note));
 assert.equal((await req(app+'/creations/'+p.creation_id+'/messages')).messages.length,1);
 assert.equal(overview.activity.recent.length,1);assert.equal(overview.activity.runs.length,0);assert(overview.activity.recent.every(x=>x.state==='delivered'));
 report.checks.push('unknown visible; note required; stale state rejected; outsider cannot close; same request replays; reconciliation records note without new result or execution');report.result='pass';
}
main().catch(e=>{report.result='fail';report.error=e.message;process.exitCode=1}).finally(()=>{fs.mkdirSync(path.join(__dirname,'evidence'),{recursive:true});fs.writeFileSync(path.join(__dirname,'evidence/followup-review.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));});