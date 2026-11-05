/* Agent reads and preview use OCS and cannot change owner-attributed work records. */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=__dirname,app='http://127.0.0.1:8780/index.php/apps/cognos',ocs='http://127.0.0.1:8780/ocs/v2.php/apps/cognos/api/v1.0';
const owner={user:'cognos-admin',password:fs.readFileSync(path.join(root,'.secrets/admin-password'),'utf8').trim()};
const agent={user:'cognos-admin',password:fs.readFileSync(path.join(root,'.secrets/codex-app-password'),'utf8').trim()};
const outsider=JSON.parse(fs.readFileSync(path.join(root,'.secrets/task-journey.json'),'utf8')).outsider;
const checks=[],report={checks};const id=()=>crypto.randomUUID();
async function call(base,route,method='GET',data,expected=200,actor=owner){const r=await fetch(base+route,{method,headers:{Authorization:'Basic '+Buffer.from(actor.user+':'+actor.password).toString('base64'),'OCS-APIRequest':'true',Accept:'application/json','Content-Type':'application/json'},body:data?JSON.stringify(data):undefined});const body=await r.json(),payload=body.ocs?body.ocs.data:body;assert.equal(r.status,expected,payload.message);return payload;}
const req=(route,method,data)=>call(app,route,method,data);
const api=(route,method,data,expected=200,actor=agent)=>call(ocs,route+(route.includes('?')?'&':'?')+'format=json',method,data,expected,actor);
async function main(){
 const p=await req('/projects/data','POST',{title:'验证：代理读工作 '+Date.now(),request_id:id()});
 const loc=(await req('/projects/locations/'+p.location_id+'/data')).location;
 let work;
 for(let n=0;n<12;n++)work=await req('/projects/locations/'+p.location_id+'/work-items','POST',{description:n===11?'长范围'.repeat(600):'工作 '+n,request_id:id()});
 const before=(await req('/projects/work/'+work.id)).item;
 const route='/spaces/creation/'+loc.creation_id+'/work-items';
 const list=await api(route);assert.equal(list.items.length,12);
 const entry=(await api('/spaces/creation/'+loc.creation_id+'/enter?subject=Codex')).entry.space.project_context;
 assert.equal(entry.work_items.length,10);assert.equal(entry.work_items_total,12);assert.equal(entry.work_items_omitted,2);
 assert(entry.work_items.some(w=>w.id===work.id&&w.text_truncated&&w.description.length===1000));
 await api(route+'/'+work.id,'GET',null,404,outsider);
 const other=await req('/projects/'+p.project_id+'/locations','POST',{parent_id:p.location_id,title:'另一处',request_id:id()});
 const otherLoc=(await req('/projects/locations/'+other.location_id+'/data')).location;
 await api('/spaces/creation/'+otherLoc.creation_id+'/work-items/'+work.id,'GET',null,404);
 const preview=await api(route+'/'+work.id+'/split/preview','POST',{parts:[{description:'核对作者'},{description:'核对范围'}]});
 assert(preview.basis);const after=(await req('/projects/work/'+work.id)).item;
 assert.equal(after.revision,before.revision);assert.equal(after.state,'open');assert.equal(after.children.length,0);assert.deepEqual(after.events,before.events);
 checks.push('dedicated Codex application credential reads work; outsider and wrong location are refused; split preview changes neither work state nor author/version history');
 checks.push('entry truthfully caps active work summaries at ten, reports two omitted records, and marks truncated long text');
 report.result='pass';Object.assign(report,{project_id:p.project_id,location_id:p.location_id});
}
main().catch(e=>{report.result='fail';report.error=e.stack;process.exitCode=1;}).finally(()=>{fs.mkdirSync(path.join(root,'evidence'),{recursive:true});fs.writeFileSync(path.join(root,'evidence/agent-work-read.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));});
