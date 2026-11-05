/* Live checks on 8780 for restructuring (V05's software part): move a place, make one independent, merge a project.
 * Uses cognos-admin (password read from .secrets, never printed) and the trial outsider. Report in evidence/work-management-live.json.
 */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const root = __dirname, app = 'http://127.0.0.1:8780/index.php/apps/cognos', ocs = 'http://127.0.0.1:8780/ocs/v2.php/apps/cognos/api/v1.0';
const admin = {user: 'cognos-admin', password: fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim()};
const outsider = JSON.parse(fs.readFileSync(path.join(root, '.secrets/task-journey.json'), 'utf8')).outsider;
const evidence = path.join(root, 'evidence/work-management-live.json');
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

const sleep=ms=>new Promise(r=>setTimeout(r,ms));
async function main(){
 const A=await req('/projects/data','POST',{title:'验证：AI 获准维护工作 '+Date.now(),request_id:id()});
 const W=await req('/projects/locations/'+A.location_id+'/work-items','POST',{description:'材料来源核验的执行安排',owner:'待明确安排',deliverable:'核验简稿',acceptance:'可回查来源',request_id:id()});
 const params={ask:'你获准维护关联工作 '+W.id+' 的安排，不改当前稿、不采用、不执行子项。先 get_work_item 读当前工作；用 update_work_item 把交付改为“列出作者、原位置、实际取得范围及后续更正的核验稿”，其余范围、负责人、验收保持原样，状态记 in_progress。成功后重新读取当前工作，再 preview_work_split 预览拆为两份：description 分别为“核对作者与原位置”和“核对取得范围与后来更正”，两份负责人留空；然后用 split_work_item 带预览 basis 实际确认拆分。子项不派发、不做具体核验。最后简述安排发生了什么，不把工作标为完成。',work_item_id:W.id,expected_work_revision:1,may_edit:false,may_adopt:false,may_manage_work:true,request_id:id()};
 const d=await req('/projects/locations/'+A.location_id+'/delegations','POST',params);
 Object.assign(report,{project_id:A.project_id,location_id:A.location_id,work_id:W.id,delegation_id:d.delegation_id});
 assert.equal(d.state,'sent',d.note);assert.notEqual(d.run_state,'unknown');
 let current;const end=Date.now()+300000;
 do{current=(await req('/projects/'+A.project_id+'/overview?location_id='+A.location_id)).delegations.find(x=>x.id===d.delegation_id);if(current.state==='returned')break;if(['failed','unknown'].includes(current.run_state))throw Error('actual run '+current.run_state);await sleep(3000);}while(Date.now()<end);
 assert.equal(current.state,'returned','actual AI must return');assert.equal(current.may_manage_work,true);
 const w=(await req('/projects/work/'+W.id)).item;
 assert.equal(w.state,'split');assert.equal(w.deliverable,'列出作者、原位置、实际取得范围及后续更正的核验稿');
 assert.equal(w.children.length,2);assert(w.events.some(e=>e.action==='updated' && e.actor_subject==='Claude Code' && e.run_id===current.run_id && e.delegation_id===d.delegation_id));
 assert(w.events.some(e=>e.action==='split' && e.actor_subject==='Claude Code'));
 for(const child of w.children){const c=(await req('/projects/work/'+child.id)).item;assert.equal(c.created_subject,'Claude Code');assert.equal(c.owner,'');assert.equal(c.delegations.length,0);assert.equal(c.state,'open');}
 assert.equal((await req('/projects/locations/'+A.location_id+'/data')).location.revision,1);
 const replay=await req('/projects/locations/'+A.location_id+'/delegations','POST',params);assert.equal(replay.delegation_id,d.delegation_id);
 checks.push('real Claude used its explicit one-work grant to update the delivery and then preview/confirm a split; same grant followed its own edit');
 checks.push('work events and child creation name the actual AI/run/delegation; children remain unassigned and unstarted, current draft stays unchanged; delegation request replay does not dispatch again');
 Object.assign(report,{run_id:current.run_id,children:w.children.map(c=>c.id),result_message_id:current.result_message_id,result:'pass'});
}
main().catch(e=>{report.result='fail';report.error=e.stack;process.exitCode=1;}).finally(()=>{fs.mkdirSync(path.dirname(evidence),{recursive:true});fs.writeFileSync(evidence,JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));});
