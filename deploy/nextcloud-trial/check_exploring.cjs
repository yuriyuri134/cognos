const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const origin='http://127.0.0.1:8780',app='/index.php/apps/cognos',ocs='/ocs/v2.php/apps/cognos/api/v1.0';
const password=fs.readFileSync(path.join(__dirname,'.secrets/admin-password'),'utf8').trim();
const headers={Authorization:'Basic '+Buffer.from('cognos-admin:'+password).toString('base64'),'OCS-APIRequest':'true',Accept:'application/json','Content-Type':'application/json'};
const report={checks:[]},id=()=>crypto.randomUUID();
async function req(route,method='GET',body,status=200){const r=await fetch(origin+route,{method,headers,body:body?JSON.stringify(body):undefined});assert.equal(r.status,status,route);const j=await r.json();return j.ocs?j.ocs.data:j;}
async function main(){
 const p=await req(app+'/projects/data','POST',{title:'验证：退回自由探索 '+Date.now(),request_id:id()});
 const loc=(await req(app+'/projects/locations/'+p.location_id+'/data')).location;
 Object.assign(report,p,{creation_id:loc.creation_id});
 const stateUrl=app+'/projects/'+p.project_id+'/lifecycle';
 await req(stateUrl,'POST',{action:'explore',expected_revision:1,request_id:id(),note:'原来的安排先放下，换个入口继续想'});
 const data=await req(app+'/initium/data');assert(data.exploring.some(x=>x.id===p.project_id));
 const payload={text:'从另一个角度重新想这个问题',request_id:id()};
 const newChat=await req(app+'/projects/'+p.project_id+'/explorations','POST',payload);
 assert.deepEqual(await req(app+'/projects/'+p.project_id+'/explorations','POST',payload),newChat);
 report.exploration_id=newChat.creation_id;
 assert.notEqual(newChat.creation_id,loc.creation_id);
 const messages=(await req(app+'/creations/'+newChat.creation_id+'/messages')).messages;
 assert.equal(messages.length,1);assert.equal(messages[0].content,payload.text);
 const detail=await req(app+'/projects/locations/'+p.location_id+'/data');
 assert.equal(detail.location.creation_id,loc.creation_id);assert(detail.usages.some(x=>Number(x.source_creation_id)===newChat.creation_id));
 const list=await req(app+'/initium/data');assert(list.items.some(x=>x.id===newChat.creation_id && x.explored_from.some(y=>y.id===p.project_id)));
 const run=(await req(ocs+'/spaces/creation/'+newChat.creation_id+'/runs','POST',{subject:'自由探索验证'},201)).run;
 assert.equal(run.project_id,null);assert.equal(run.execution_epoch,null);
 await req(app+'/projects/locations/'+p.location_id+'/delegations','POST',{ask:'不应该派出',request_id:id()},409);
 await req(stateUrl,'POST',{action:'resume',expected_revision:2,request_id:id()});
 await req(app+'/projects/'+p.project_id+'/explorations','POST',{text:'旧窗口不应继续另起',request_id:id()},409);
 assert(!(await req(app+'/initium/data')).exploring.some(x=>x.id===p.project_id));
 await req(stateUrl,'POST',{action:'explore',expected_revision:3,request_id:id(),note:'原来的安排先放下，换个入口继续想'});
 report.checks.push('exploring projects appear in Initium; new raw conversation is linked, independent and idempotent; original conversation stays; paused delegation refused; stale exploration rejected after resume');report.result='pass';
}
main().catch(e=>{report.result='fail';report.error=e.message;process.exitCode=1}).finally(()=>{fs.mkdirSync(path.join(__dirname,'evidence'),{recursive:true});fs.writeFileSync(path.join(__dirname,'evidence/exploring.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));});