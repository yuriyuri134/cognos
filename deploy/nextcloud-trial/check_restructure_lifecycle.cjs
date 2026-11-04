const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const origin='http://127.0.0.1:8780',app='/index.php/apps/cognos',ocs='/ocs/v2.php/apps/cognos/api/v1.0';
const password=fs.readFileSync(path.join(__dirname,'.secrets/admin-password'),'utf8').trim();
const headers={Authorization:'Basic '+Buffer.from('cognos-admin:'+password).toString('base64'),'OCS-APIRequest':'true',Accept:'application/json','Content-Type':'application/json'};
const report={checks:[]},id=()=>crypto.randomUUID();
async function req(route,method='GET',body,status=200){const r=await fetch(origin+route,{method,headers,body:body?JSON.stringify(body):undefined});assert.equal(r.status,status,route);const j=await r.json();return j.ocs?j.ocs.data:j;}
async function main(){
 for(const state of ['active','paused','ended','exploring']){
  const p=await req(app+'/projects/data','POST',{title:'验证：独立继承 '+state+' '+Date.now(),request_id:id()});
  const child=await req(app+'/projects/'+p.project_id+'/locations','POST',{parent_id:p.location_id,title:'独立的部分',request_id:id()});
  const loc=(await req(app+'/projects/locations/'+child.location_id+'/data')).location;
  const old=(await req(ocs+'/spaces/creation/'+loc.creation_id+'/runs','POST',{subject:'重组资格验证'},201)).run;
  const params={action:'independent',location_id:child.location_id};
  const before=await req(app+'/projects/restructure/preview','POST',params);
  if(state!=='active'){
   const action={paused:'pause',ended:'end',exploring:'explore'}[state];
   await req(app+'/projects/'+p.project_id+'/lifecycle','POST',{action,expected_revision:1,request_id:id(),reopen_when:'等待原始材料'});
   await req(app+'/projects/restructure','POST',{...params,basis:before.basis,request_id:id()},409);
  }
  const preview=await req(app+'/projects/restructure/preview','POST',params);
  assert(preview.consequences.some(t=>t.includes('继承原来的推进状态')));
  const changed=await req(app+'/projects/restructure','POST',{...params,basis:preview.basis,request_id:id()});
  const project=(await req(app+'/projects/'+changed.project_id+'/data')).project;
  assert.equal(project.state,state);assert.equal(project.root_location_id,child.location_id);
  assert.notEqual(old.project_id,project.id);
  const overview=await req(app+'/projects/'+project.id+'/overview');
  assert(overview.lifecycle_history[0].note.includes('系统记录'));
  if(state!=='active'){assert.equal(overview.lifecycle_history[0].reopen_when,'等待原始材料');await req(ocs+'/spaces/creation/'+loc.creation_id+'/runs','POST',{subject:'重组资格验证 新'},409);}
  Object.assign(report,{project_id:project.id,location_id:child.location_id,creation_id:loc.creation_id});
 }
 const source=await req(app+'/projects/data','POST',{title:'验证：合并状态来源 '+Date.now(),request_id:id()});
 const params={action:'merge',project_id:source.project_id,target_parent_id:report.location_id};
 const preview=await req(app+'/projects/restructure/preview','POST',params);
 assert(preview.consequences.some(t=>t.includes('目标项目状态：自由探索')&&t.includes('资格不会迁移')));
 await req(app+'/projects/restructure','POST',{...params,basis:preview.basis,request_id:id()});
 const moved=(await req(app+'/projects/locations/'+source.location_id+'/data')).location;
 assert.equal(moved.project_id,report.project_id);
 const chat=moved.creation_id;await req(ocs+'/spaces/creation/'+chat+'/runs','POST',{subject:'目标停用验证'},409);
 report.checks.push('independence inherits all four states and reopen conditions; original location stays; state changes invalidate preview; merge preview declares target state and revoked old authority; inactive target blocks new work');report.result='pass';
}
main().catch(e=>{report.result='fail';report.error=e.message;process.exitCode=1}).finally(()=>{fs.mkdirSync(path.join(__dirname,'evidence'),{recursive:true});fs.writeFileSync(path.join(__dirname,'evidence/restructure-lifecycle.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));});