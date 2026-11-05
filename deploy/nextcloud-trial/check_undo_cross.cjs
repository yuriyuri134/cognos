/* Live checks on 8780 for restructuring (V05's software part): move a place, make one independent, merge a project.
 * Uses cognos-admin (password read from .secrets, never printed) and the trial outsider. Report in evidence/undo-cross-api.json.
 */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const root = __dirname, app = 'http://127.0.0.1:8780/index.php/apps/cognos', ocs = 'http://127.0.0.1:8780/ocs/v2.php/apps/cognos/api/v1.0';
const admin = {user: 'cognos-admin', password: fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim()};
const outsider = JSON.parse(fs.readFileSync(path.join(root, '.secrets/task-journey.json'), 'utf8')).outsider;
const evidence = path.join(root, 'evidence/undo-cross-api.json');
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
 const create=title=>req('/projects/data','POST',{title:'验证：'+title+' '+Date.now(),request_id:id()});
 const add=async(pid,parent,title)=>(await req('/projects/'+pid+'/locations','POST',{parent_id:parent,title,request_id:id()})).location_id;
 const decision=(pid,content,loc,supersedes='')=>req('/projects/'+pid+'/decisions','POST',{content,scope:loc?'here':'project',location_id:loc||'',supersedes,request_id:id()});
 const execute=async params=>apply(params,(await preview(params)).basis);
 const getLoc=async loc=>(await req('/projects/locations/'+loc+'/data')).location;
 const getProject=async pid=>(await req('/projects/'+pid+'/data')).project;
 const overview=pid=>req('/projects/'+pid+'/overview');
 const edit=loc=>req('/projects/locations/'+loc+'/content','PUT',{expected_revision:1,content:'重组以后新增的当前稿，不能回滚。',source_message_ids:[],request_id:id()});
 // Independent -> return current project to the original parent.
 const A=await create('撤回独立 A'), parent=await add(A.project_id,A.location_id,'原上级'), branch=await add(A.project_id,parent,'独立方向');
 const firstChild=await add(A.project_id,branch,'原有子项');
 const local=await decision(A.project_id,'只管原方向。',branch);
 const wide=await decision(A.project_id,'A 的全项目决定。');
 const split=await execute({action:'independent',location_id:branch});
 const added=await add(split.project_id,branch,'独立后新增'); await edit(branch);
 const newWide=await decision(split.project_id,'独立后全项目决定。');
 const revoked=await decision(split.project_id,'后来已撤回的决定。',branch);
 await req('/projects/decisions/'+revoked.decision_id+'/revoke','POST',{request_id:id()});
 const undo={action:'undo',change_id:split.change_id};let p=await preview(undo);
 assert(p.consequences.some(x=>x.includes('撤回独立')) && p.consequences.some(x=>x.includes('运行资格更新')));
 await preview(undo,404,outsider);
 await decision(A.project_id,'预览之后追加决定。');await apply(undo,p.basis,409);
 p=await preview(undo);const request=id();const back=await req('/projects/restructure','POST',{...undo,basis:p.basis,request_id:request});
 assert.deepEqual(await req('/projects/restructure','POST',{...undo,basis:p.basis,request_id:request}),back);
 assert.equal((await getLoc(branch)).parent_id,parent);assert.equal((await getLoc(branch)).revision,2);
 assert.equal((await getLoc(added)).project_id,A.project_id);assert.equal((await getLoc(firstChild)).project_id,A.project_id);
 assert.equal((await getProject(split.project_id)).merged_into,A.project_id);
 let decisions=(await overview(A.project_id)).decisions;
 assert.deepEqual(decisions.find(d=>d.id===newWide.decision_id).scope_location_ids,[branch]);
 assert.deepEqual(decisions.find(d=>d.id===local.decision_id).scope_location_ids,[branch]);
 assert.equal(decisions.find(d=>d.id===revoked.decision_id).status,'revoked');
 assert.deepEqual(decisions.find(d=>d.id===wide.decision_id).scope_location_ids,[]);
 await preview(undo,409);
 checks.push('undo independence: same parent and ids; later content and children retained; new whole-project decision narrowed to returning part; revoked stays revoked; project shell retained; stale preview and duplicate undo rejected; request replay stable');
 // Merge -> restore original project identity; preserve later changes in both projects.
 const B=await create('撤回合并 B'), C=await create('撤回合并 C');const child=await add(C.project_id,C.location_id,'C 原有子项');
 const cWide=await decision(C.project_id,'C 原来的全项目决定。');
 const cOld=await decision(C.project_id,'C 后来会被替代的决定。',child);
 const bWide=await decision(B.project_id,'B 自己的全项目决定。');
 const merged=await execute({action:'merge',project_id:C.project_id,target_parent_id:B.location_id});
 await edit(C.location_id);const later=await add(B.project_id,C.location_id,'合并后新增');
 const replacement=await decision(B.project_id,'后来替代原来的决定。',child,cOld.decision_id);
 await req('/projects/decisions/'+cWide.decision_id+'/revoke','POST',{request_id:id()});
 const targetLater=await add(B.project_id,B.location_id,'B 后来自己的子项');
 const undoMerge={action:'undo',change_id:merged.change_id};p=await preview(undoMerge);
 assert(p.consequences.some(x=>x.includes('撤回合并')));const restored=await apply(undoMerge,p.basis);
 assert.equal(restored.project_id,C.project_id);assert.equal((await getProject(C.project_id)).merged_into,null);
 assert.equal((await getLoc(C.location_id)).parent_id,null);assert.equal((await getLoc(C.location_id)).revision,2);
 assert.equal((await getLoc(later)).project_id,C.project_id);assert.equal((await getLoc(child)).project_id,C.project_id);assert.equal((await getLoc(targetLater)).project_id,B.project_id);
 decisions=(await overview(C.project_id)).decisions;
 assert.deepEqual(decisions.find(d=>d.id===cWide.decision_id).scope_location_ids,[]);assert.equal(decisions.find(d=>d.id===cWide.decision_id).status,'revoked');
 assert.equal(decisions.find(d=>d.id===cOld.decision_id).status,'superseded');assert.equal(decisions.find(d=>d.id===cOld.decision_id).superseded_by,replacement.decision_id);
 assert(decisions.some(d=>d.id===replacement.decision_id && d.status==='active'));assert(!decisions.some(d=>d.id===bWide.decision_id));
 assert((await overview(B.project_id)).decisions.some(d=>d.id===bWide.decision_id));
 assert((await overview(C.project_id)).history.some(h=>h.reverts_change_id===merged.change_id && h.action==='undo_merge'));
 checks.push('undo merge: original project identity restored; current content/new child preserved; target additions untouched; original wide scope restored without reactivating revoked/superseded decisions; replacement follows; target-wide decision stays');
 // Split-out original material is a conflict rather than silently dragged from a third project.
 const S=await create('后续归属冲突'), sub=await add(S.project_id,S.location_id,'后续拆走');
 const M=await execute({action:'merge',project_id:S.project_id,target_parent_id:B.location_id});
 await execute({action:'independent',location_id:sub});await preview({action:'undo',change_id:M.change_id},409);
 checks.push('a later split-out original part blocks automatic merge undo');
 // Distinct untouched fixtures for actual UI confirmation.
 const UI=await create('页面撤回独立');const uiBranch=await add(UI.project_id,UI.location_id,'页面独立方向');
 const uiSplit=await execute({action:'independent',location_id:uiBranch});
 const US=await create('页面撤回合并源'), UT=await create('页面撤回合并目标');
 await decision(US.project_id,'页面核对：原项目全范围恢复，状态照旧。');
 await decision(UT.project_id,'页面核对：目标项目的决定留下。');
 const uiMerge=await execute({action:'merge',project_id:US.project_id,target_parent_id:UT.location_id});
 Object.assign(report,{independent_change:split.change_id,merge_change:merged.change_id,restored_project:C.project_id,ui_independent:{project:UI.project_id,root:uiBranch,change:uiSplit.change_id,shell:uiSplit.project_id},ui_merge:{project:US.project_id,target:UT.project_id,root:US.location_id,change:uiMerge.change_id}});
 report.result='pass';
}
main().catch(e=>{report.result='fail';report.error=e.stack;process.exitCode=1;}).finally(()=>{fs.mkdirSync(path.dirname(evidence),{recursive:true});fs.writeFileSync(evidence,JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));});
