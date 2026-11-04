const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const origin='http://127.0.0.1:8780',app='/index.php/apps/cognos',ocs='/ocs/v2.php/apps/cognos/api/v1.0';
const password=fs.readFileSync(path.join(__dirname,'.secrets/admin-password'),'utf8').trim();
const headers={Authorization:'Basic '+Buffer.from('cognos-admin:'+password).toString('base64'),'OCS-APIRequest':'true',Accept:'application/json','Content-Type':'application/json'};
const report={checks:[]},id=()=>crypto.randomUUID();
async function req(route,method='GET',body,status=200){const r=await fetch(origin+route,{method,headers,body:body?JSON.stringify(body):undefined});assert.equal(r.status,status,route);const j=await r.json();return j.ocs?j.ocs.data:j;}
async function main(){
 const {execFileSync}=require('node:child_process');const fixture=JSON.parse(execFileSync('docker',['exec','-u','www-data','-w','/var/www/html','cognos-nextcloud-trial-app-1','php','/tmp/seed_queue_resume.php'],{encoding:'utf8'}));Object.assign(report,fixture);const p=fixture.api_fixture;
 const url=app+'/projects/queue/'+p.item_id+'/resume';const body={creation_id:p.creation_id,note:'已核对，只继续第二项，第一项仍暂挂',request_id:id()};
 await req(url,'POST',{...body,note:''},422);await req(url,'POST',{...body,creation_id:fixture.creation_id},404);
 const outsider=JSON.parse(fs.readFileSync(path.join(__dirname,'.secrets/task-journey.json'),'utf8')).outsider;
 const denied=await fetch(origin+url,{method:'POST',headers:{...headers,Authorization:'Basic '+Buffer.from(outsider.user+':'+outsider.password).toString('base64')},body:JSON.stringify(body)});assert.equal(denied.status,404);
 const saved=await req(url,'POST',body);assert.deepEqual(await req(url,'POST',body),saved);await req(url,'POST',{...body,request_id:id()},409);
 let view=await req(app+'/projects/'+p.project_id+'/overview');let intent=view.followups.find(x=>x.result_id===saved.followup_id);assert.equal(intent.run_id,null);assert.equal(intent.origin,'queue_review');assert.equal(intent.request_note,body.note);assert.equal(intent.queue_item_id,p.item_id);assert.equal(view.dispatch_queue.find(x=>x.id===p.first_item_id).state,'held');
 await req(app+'/projects/'+p.project_id+'/lifecycle','POST',{action:'pause',expected_revision:1,request_id:id()});
 execFileSync('docker',['exec','-u','www-data','-w','/var/www/html','cognos-nextcloud-trial-app-1','php','/tmp/seed_queue_resume.php','process',Buffer.from(JSON.stringify(saved)).toString('base64')]);
 view=await req(app+'/projects/'+p.project_id+'/overview');assert.equal(view.followups.find(x=>x.result_id===saved.followup_id).state,'cancelled');assert.equal(view.dispatch_queue.find(x=>x.id===p.item_id).state,'held');
 await req(url,'POST',{...body,request_id:id()},409);
 await req(app+'/projects/'+p.project_id+'/lifecycle','POST',{action:'resume',expected_revision:2,request_id:id()});
 report.new_followup=await req(url,'POST',{...body,request_id:id(),note:'项目再开后重新核对，只继续第二项'});
 report.checks.push('resume requires note and ownership; same request creates one durable permission without inventing a run; first item stays held; pause cancels old permission and reholds selected work; resume requires renewed permission');report.result='pass';
}
main().catch(e=>{report.result='fail';report.error=e.message;process.exitCode=1}).finally(()=>{fs.mkdirSync(path.join(__dirname,'evidence'),{recursive:true});fs.writeFileSync(path.join(__dirname,'evidence/queue-resume.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));});