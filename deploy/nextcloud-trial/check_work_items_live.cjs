/* Live checks on 8780 for restructuring (V05's software part): move a place, make one independent, merge a project.
 * Uses cognos-admin (password read from .secrets, never printed) and the trial outsider. Report in evidence/work-items-live.json.
 */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const root = __dirname, app = 'http://127.0.0.1:8780/index.php/apps/cognos', ocs = 'http://127.0.0.1:8780/ocs/v2.php/apps/cognos/api/v1.0';
const admin = {user: 'cognos-admin', password: fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim()};
const outsider = JSON.parse(fs.readFileSync(path.join(root, '.secrets/task-journey.json'), 'utf8')).outsider;
const evidence = path.join(root, 'evidence/work-items-live.json');
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
 const A=await req('/projects/data','POST',{title:'验证：真实工作项委托 '+Date.now(),request_id:id()});
 const W=await req('/projects/locations/'+A.location_id+'/work-items','POST',{description:'给出一次来源核验的简短检查建议',owner:'由本次明确委托安排',deliverable:'一段简短回答',acceptance:'返回到当前讨论供人核对',request_id:id()});
 const d=await req('/projects/locations/'+A.location_id+'/delegations','POST',{ask:'这是工作项委托接入的真实验证。请用一句话说明核对旧材料来源时先看什么，不改当前内容、不采用任何材料，回答交回当前讨论即可。',work_item_id:W.id,expected_work_revision:1,may_edit:false,may_adopt:false,request_id:id()});
 Object.assign(report,{project_id:A.project_id,location_id:A.location_id,work_id:W.id,delegation_id:d.delegation_id});
 assert.equal(d.state,'sent',d.note);assert.notEqual(d.run_state,'unknown',d.note);
 let current;const end=Date.now()+240000;
 do{current=(await req('/projects/'+A.project_id+'/overview?location_id='+A.location_id)).delegations.find(x=>x.id===d.delegation_id);if(current.state==='returned')break;if(['failed','unknown'].includes(current.run_state))throw Error('actual run '+current.run_state);await sleep(3000);}while(Date.now()<end);
 assert.equal(current.state,'returned','real Claude result must arrive');
 const work=(await req('/projects/work/'+W.id)).item;assert.equal(work.state,'open');assert.equal(work.delegations.length,1);assert.equal(work.delegations[0].run_state,'delivered');assert.equal(current.work_item_id,W.id);
 const loc=(await req('/projects/locations/'+A.location_id+'/data')).location;
 const messages=(await req('/creations/'+loc.creation_id+'/messages')).messages;
 assert.equal(messages.filter(m=>Number(m.id)===Number(current.result_message_id)).length,1);
 checks.push('real local Claude received a delegation bound to the work, returned exactly one result, and work completion was not inferred from the run receipt');
 const parts=[{description:'后续核对作者和原始位置'},{description:'后续核对取得范围和后来更正'}];
 const preview=await req('/projects/work/'+W.id+'/split/preview','POST',{parts});
 const split=await req('/projects/work/'+W.id+'/split','POST',{parts,basis:preview.basis,request_id:id()});
 const after=(await req('/projects/work/'+W.id)).item;
 assert.equal(after.state,'split');assert.equal(after.delegations[0].id,d.delegation_id);
 for(const child of split.children)assert.equal((await req('/projects/work/'+child.id)).item.delegations.length,0);
 checks.push('splitting the actual returned work retains its original delegation/result and creates independent children without another dispatch');
 Object.assign(report,{run_id:current.run_id,result_message_id:current.result_message_id,children:split.children.map(c=>c.id),result:'pass'});
}
main().catch(e=>{report.result='fail';report.error=e.stack;process.exitCode=1;}).finally(()=>{fs.mkdirSync(path.dirname(evidence),{recursive:true});fs.writeFileSync(evidence,JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));});
