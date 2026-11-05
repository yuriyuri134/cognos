const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const origin='http://127.0.0.1:8780',app='/index.php/apps/cognos',ocs='/ocs/v2.php/apps/cognos/api/v1.0';
const password=fs.readFileSync(path.join(__dirname,'.secrets/admin-password'),'utf8').trim();
const headers={Authorization:'Basic '+Buffer.from('cognos-admin:'+password).toString('base64'),'OCS-APIRequest':'true',Accept:'application/json','Content-Type':'application/json'};
const report={checks:[]},id=()=>crypto.randomUUID();
async function req(route,method='GET',body,status=200){const r=await fetch(origin+route,{method,headers,body:body?JSON.stringify(body):undefined});assert.equal(r.status,status,route);const j=await r.json();return j.ocs?j.ocs.data:j;}
async function main(){
 const {execFileSync}=require('node:child_process');const fixture=JSON.parse(execFileSync('docker',['exec','-u','www-data','-w','/var/www/html','cognos-nextcloud-trial-app-1','php','/tmp/seed_queue_review.php'],{encoding:'utf8'}));Object.assign(report,fixture);const p=fixture.api_fixture;
 let overview=await req(app+'/projects/'+p.project_id+'/overview');assert(overview.dispatch_queue.some(x=>x.id===p.item_id&&x.state==='unknown'&&x.kind==='hit'));
 const url=app+'/projects/queue/'+p.item_id+'/close';const base={creation_id:p.creation_id,expected_state:'unknown',note:'核对后关闭本次独立反对派发',request_id:id()};
 await req(url,'POST',{...base,note:''},422);await req(url,'POST',{...base,expected_state:'queued'},409);
 await req(url,'POST',{...base,creation_id:fixture.creation_id},404);
 const outsider=JSON.parse(fs.readFileSync(path.join(__dirname,'.secrets/task-journey.json'),'utf8')).outsider;
 const denied=await fetch(origin+url,{method:'POST',headers:{...headers,Authorization:'Basic '+Buffer.from(outsider.user+':'+outsider.password).toString('base64')},body:JSON.stringify(base)});assert.equal(denied.status,404);
 const saved=await req(url,'POST',base);assert.equal(saved.state,'reconciled');assert.deepEqual(await req(url,'POST',base),saved);await req(url,'POST',{...base,request_id:id()},409);
 overview=await req(app+'/projects/'+p.project_id+'/overview');const row=overview.dispatch_queue.find(x=>x.id===p.item_id);assert.equal(row.state,'reconciled');assert.equal(row.run_state,'unknown');assert(row.note.includes(base.note));assert(overview.followups.some(x=>x.result_id===p.followup_id&&x.state==='reconciled'));
 report.checks.push('standalone hit unknown is visible; note required; stale state/wrong conversation/outsider rejected; request retry replays; closing retains note and actual unknown run');report.result='pass';
}
main().catch(e=>{report.result='fail';report.error=e.message;process.exitCode=1}).finally(()=>{fs.mkdirSync(path.join(__dirname,'evidence'),{recursive:true});fs.writeFileSync(path.join(__dirname,'evidence/queue-review.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));});