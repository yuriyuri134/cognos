const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const origin='http://127.0.0.1:8780',app='/index.php/apps/cognos',ocs='/ocs/v2.php/apps/cognos/api/v1.0';
const password=fs.readFileSync(path.join(__dirname,'.secrets/admin-password'),'utf8').trim();
const headers={Authorization:'Basic '+Buffer.from('cognos-admin:'+password).toString('base64'),'OCS-APIRequest':'true',Accept:'application/json','Content-Type':'application/json'};
const report={checks:[]},id=()=>crypto.randomUUID();
async function req(route,method='GET',body,status=200){const r=await fetch(origin+route,{method,headers,body:body?JSON.stringify(body):undefined});assert.equal(r.status,status,route);const j=await r.json();return j.ocs?j.ocs.data:j;}
async function main(){
 const p=await req(app+'/projects/data','POST',{title:'验证：生命周期操作 '+Date.now(),request_id:id()});
 const loc=(await req(app+'/projects/locations/'+p.location_id+'/data')).location;
 Object.assign(report,p,{creation_id:loc.creation_id});
 const run=async()=> (await req(ocs+'/spaces/creation/'+loc.creation_id+'/runs','POST',{subject:'生命周期接口验证',purpose:'只验证生命周期'},201)).run;
 const old=await run();assert.equal(old.execution_epoch,1);
 const url=app+'/projects/'+p.project_id+'/lifecycle';
 const pause={action:'pause',expected_revision:1,request_id:id(),note:'等待新的材料',reopen_when:'材料到齐后继续'};
 const paused=await req(url,'POST',pause);assert.equal(paused.state,'paused');
 assert.deepEqual(await req(url,'POST',pause),paused);
 await req(url,'POST',{...pause,request_id:id()},409);
 await req(ocs+'/spaces/creation/'+loc.creation_id+'/runs','POST',{subject:'生命周期接口验证'},409);
 const msg=await req(app+'/creations/'+loc.creation_id+'/messages','POST',{message:'暂停时仍然可以记下想法'});
 assert.equal(msg.execution_blocked,true);assert.equal(msg.pending,false);assert.equal(msg.messages.length,1);
 await req(app+'/projects/locations/'+p.location_id+'/content','PUT',{expected_revision:1,content:'人在暂停时整理的当前稿',source_message_ids:[],request_id:id()});
 const late={subject:'生命周期接口验证',run_id:old.id,result_id:id().replaceAll('-',''),content:'暂停前工作返回的结果'};
 await req(ocs+'/spaces/creation/'+loc.creation_id+'/messages','POST',late,201);
 await req(ocs+'/spaces/creation/'+loc.creation_id+'/messages','POST',late);
 const resumed=await req(url,'POST',{action:'resume',expected_revision:2,request_id:id()});assert.equal(resumed.execution_epoch,3);
 const fresh=await run();assert.equal(fresh.execution_epoch,3);
 const overview=await req(app+'/projects/'+p.project_id+'/overview');
 assert.equal(overview.lifecycle_history.length,2);assert.equal(overview.lifecycle_history[1].note,pause.note);assert.equal(overview.lifecycle_history[1].reopen_when,pause.reopen_when);
 await req(url,'POST',{action:'end',expected_revision:3,request_id:id(),note:'这一轮先到这里'});
 await req(url,'POST',{action:'explore',expected_revision:4,request_id:id(),note:'继续自由探索'});
 const final=await req(app+'/projects/'+p.project_id+'/data');assert.equal(final.project.state,'exploring');assert.equal(final.project.locations[0].id,p.location_id);
 report.checks.push('pause is idempotent; stale state revision conflicts; new runs blocked; ordinary messages and human edits saved; late result accepted once; resume starts new epoch; history retains original notes; end/explore preserve links');report.result='pass';
}
main().catch(e=>{report.result='fail';report.error=e.message;process.exitCode=1}).finally(()=>{fs.mkdirSync(path.join(__dirname,'evidence'),{recursive:true});fs.writeFileSync(path.join(__dirname,'evidence/lifecycle-actions.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));});