/* Live checks on 8780 for restructuring (V05's software part): move a place, make one independent, merge a project.
 * Uses cognos-admin (password read from .secrets, never printed) and the trial outsider. Report in evidence/work-items-api.json.
 */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const root = __dirname, app = 'http://127.0.0.1:8780/index.php/apps/cognos', ocs = 'http://127.0.0.1:8780/ocs/v2.php/apps/cognos/api/v1.0';
const admin = {user: 'cognos-admin', password: fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim()};
const outsider = JSON.parse(fs.readFileSync(path.join(root, '.secrets/task-journey.json'), 'utf8')).outsider;
const evidence = path.join(root, 'evidence/work-items-api.json');
const checks = []; const report = {at: new Date().toISOString(), checks, previews: {}};
const id = () => crypto.randomUUID();
async function call(url, method, data, expected, actor) {
  const r = await fetch(url, {method, redirect: 'manual', headers: {
    Authorization: 'Basic ' + Buffer.from(actor.user + ':' + actor.password).toString('base64'),
    'OCS-APIRequest': 'true', Accept: 'application/json', ...(data ? {'Content-Type': 'application/json'} : {})
  }, body: data ? JSON.stringify(data) : undefined, signal: AbortSignal.timeout(60000)});
  let body; try { body = await r.json(); } catch { throw Error(method + ' ' + url + ': non-JSON HTTP ' + r.status); }
  const payload = body.ocs ? body.ocs.data : body;
  assert.equal(r.status, expected, method + ' ' + url + ': ' + (payload.message || 'HTTP ' + r.status));
  return payload;
}
const req = (route, method = 'GET', data, expected = 200, actor = admin) => call(app + route, method, data, expected, actor);
const entry = async loc => (await call(ocs + '/spaces/creation/' + (await req('/projects/locations/' + loc + '/data')).location.creation_id + '/enter?format=json&subject=check', 'GET', null, 200, admin)).text;
const preview = (params, expected = 200, actor = admin) => req('/projects/restructure/preview', 'POST', params, expected, actor);
const apply = (params, basis, expected = 200) => req('/projects/restructure', 'POST', {...params, basis, request_id: id()}, expected);

async function main(){
 const A=await req('/projects/data','POST',{title:'验证：工作拆分 '+Date.now(),request_id:id()});
 const route='/projects/locations/'+A.location_id+'/work-items';
 const create=(description,owner='')=>req(route,'POST',{description,owner,deliverable:'可交付稿件',acceptance:'能回查来源',request_id:id()});
 const W=await create('完整比较入口方案','原负责人');
 const work=async wid=>(await req('/projects/work/'+wid)).item;
 const update=(w,extra={},status=200)=>req('/projects/work/'+w.id,'PUT',{description:w.description,owner:w.owner,deliverable:w.deliverable,acceptance:w.acceptance,state:w.state,evidence:w.evidence,expected_revision:w.revision,request_id:id(),...extra},status);
 await req('/projects/work/'+W.id,'GET',null,404,outsider);
 await req('/projects/work/'+W.id,'PUT',{description:'不能越权',state:'open',expected_revision:1,request_id:id()},404,outsider);
 await update(await work(W.id),{state:'done'},422);
 await update(await work(W.id),{state:'blocked'},422);
 let w=await work(W.id);await update(w,{description:'已补充范围的比较工作'});await update(w,{description:'旧版覆盖'},409);
 assert.equal((await work(W.id)).description,'已补充范围的比较工作');
 checks.push('owner-only access, version conflict protection, completion evidence and waiting reason required');
 const parts=[{description:'比较两种导航入口',owner:'甲',deliverable:'优缺点对照',acceptance:'各有来源'}, {description:'检验旧链接的返回体验'}];
 const splitRoute='/projects/work/'+W.id+'/split';
 await req(splitRoute+'/preview','POST',{parts:[parts[0]]},422);
 let p=await req(splitRoute+'/preview','POST',{parts});
 assert(p.consequences.some(t=>t.includes('不复制、不重新派发')));
 w=await work(W.id);await update(w,{acceptance:'补充真实旧链接核对'});
 await req(splitRoute,'POST',{parts,basis:p.basis,request_id:id()},409);
 assert.equal((await work(W.id)).children.length,0);
 p=await req(splitRoute+'/preview','POST',{parts});const request=id();
 const split=await req(splitRoute,'POST',{parts,basis:p.basis,request_id:request});
 assert.deepEqual(await req(splitRoute,'POST',{parts,basis:p.basis,request_id:request}),split);
 const parent=await work(W.id);assert.equal(parent.state,'split');assert.equal(parent.children.length,2);assert.equal(parent.evidence,'');
 const first=await work(split.children[0].id), second=await work(split.children[1].id);
 assert.equal(first.split_from_id,W.id);assert.equal(first.split_from_revision,parent.revision-1);assert.equal(first.owner,'甲');
 assert.equal(second.owner,'');assert.equal(second.deliverable,'');assert.equal(second.acceptance,'');assert.equal(second.delegations.length,0);
 assert(parent.events.some(e=>e.revision===first.split_from_revision && e.snapshot.acceptance==='补充真实旧链接核对'));
 await req(splitRoute,'POST',{parts,basis:p.basis,request_id:id()},409);await update(parent,{state:'open'},409);
 await update(first,{state:'done',evidence:'已交付对照稿，并按来源逐项核对。'});
 const done=await work(first.id);assert.equal(done.state,'done');await update(done,{state:'open'},422);await update(done,{state:'open',note:'需要补一轮实际使用核验'});
 checks.push('preview becomes stale on changed work; atomic idempotent split retains source version, original history and explicit child scope without inheriting owner/delivery/authority; completion and reopening leave evidence');
 // Work identity follows its stable location through independence without project-id rewrites.
 const childLoc=(await req('/projects/'+A.project_id+'/locations','POST',{parent_id:A.location_id,title:'工作将移出的位置',request_id:id()})).location_id;
 const childChat=(await req('/projects/locations/'+childLoc+'/data')).location.creation_id;
 const messageCount=(await req('/creations/'+childChat+'/messages')).messages.length;
 await req('/projects/locations/'+childLoc+'/delegations','POST',{ask:'不应产生一条跨位置委托',work_item_id:W.id,expected_work_revision:parent.revision,request_id:id()},409);
 assert.equal((await req('/creations/'+childChat+'/messages')).messages.length,messageCount);
 checks.push('wrong-location work delegation is rejected atomically without leaving a dispatch message');
 const moving=await req('/projects/locations/'+childLoc+'/work-items','POST',{description:'随位置归属变化的工作',request_id:id()});
 const params={action:'independent',location_id:childLoc}, plan=await preview(params), independent=await apply(params,plan.basis);
 assert.equal((await work(moving.id)).project_id,independent.project_id);
 assert(!(await req('/projects/'+A.project_id+'/work-items')).items.some(x=>x.id===moving.id));
 assert((await req('/projects/'+independent.project_id+'/work-items')).items.some(x=>x.id===moving.id));
 checks.push('stable work id follows current location/project through reorganization; source remains accessible');
 const ui=await create('页面拆分的原工作','不要自动继承的负责人');
 Object.assign(report,{project_id:A.project_id,location_id:A.location_id,parent:W.id,ui_work:ui.id});report.result='pass';
}
main().catch(e=>{report.result='fail';report.error=e.stack;process.exitCode=1;}).finally(()=>{fs.mkdirSync(path.dirname(evidence),{recursive:true});fs.writeFileSync(evidence,JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));});
