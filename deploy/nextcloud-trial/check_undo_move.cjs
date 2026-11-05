/* Live checks on 8780 for restructuring (V05's software part): move a place, make one independent, merge a project.
 * Uses cognos-admin (password read from .secrets, never printed) and the trial outsider. Report in evidence/undo-move-api.json.
 */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const root = __dirname, app = 'http://127.0.0.1:8780/index.php/apps/cognos', ocs = 'http://127.0.0.1:8780/ocs/v2.php/apps/cognos/api/v1.0';
const admin = {user: 'cognos-admin', password: fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim()};
const outsider = JSON.parse(fs.readFileSync(path.join(root, '.secrets/task-journey.json'), 'utf8')).outsider;
const evidence = path.join(root, 'evidence/undo-move-api.json');
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

async function main() {
  const A = await req('/projects/data', 'POST', {title: '验证：撤回移动 ' + Date.now(), request_id: id()});
  const add = async (parent, title) => (await req('/projects/' + A.project_id + '/locations', 'POST', {parent_id: parent, title, request_id: id()})).location_id;
  const oldParent = await add(A.location_id, '原上级'), newParent = await add(A.location_id, '新上级');
  const loc = await add(oldParent, '保留成果的位置');
  const moveParams = {action:'move',location_id:loc,parent_id:newParent};
  const moved = await apply(moveParams,(await preview(moveParams)).basis);
  const original = (await req('/projects/locations/'+loc+'/data')).location;
  await req('/projects/locations/'+loc+'/content','PUT',{expected_revision:1,content:'移动以后写的稿，撤回也要保留。',source_message_ids:[],request_id:id()});
  const later = await add(loc,'移动以后新增的子项');
  await req('/projects/locations/'+loc+'/adoptions','POST',{object_type:'location_content',object_id:loc,object_version:'2',request_id:id()});
  const params={action:'undo_move',change_id:moved.change_id};
  let p=await preview(params);
  assert(p.consequences.some(x=>x.includes('不回滚后来成果')) && p.consequences.some(x=>x.includes('当前子树')));
  await preview(params,404,outsider);
  await call(app+'/projects/restructure','POST',{...params,basis:p.basis,request_id:id()},404,outsider);
  checks.push('another account cannot preview or apply the undo');
  await req('/projects/'+A.project_id+'/decisions','POST',{content:'撤回时也要面对的新决定。',scope:'here',location_id:oldParent,request_id:id()});
  await apply(params,p.basis,409);
  assert.equal((await req('/projects/locations/'+loc+'/data')).location.parent_id,newParent);
  p=await preview(params); const request=id();
  const result=await req('/projects/restructure','POST',{...params,basis:p.basis,request_id:request});
  const replay=await req('/projects/restructure','POST',{...params,basis:p.basis,request_id:request});
  assert.deepEqual(replay,result);
  const after=(await req('/projects/locations/'+loc+'/data')).location;
  assert.equal(after.parent_id,oldParent);assert.equal(after.creation_id,original.creation_id);assert.equal(after.revision,2);assert.equal(after.content,'移动以后写的稿，撤回也要保留。');
  assert.equal((await req('/projects/locations/'+later+'/data')).location.parent_id,loc);
  assert((await req('/projects/locations/'+loc+'/adoptions')).adoptions.some(x=>x.object_id===loc && x.state==='active'));
  const history=(await req('/projects/'+A.project_id+'/overview?location_id='+loc)).location_history;
  assert.equal(history.length,2);assert(history.some(h=>h.change_id===result.change_id && h.reverts_change_id===moved.change_id));
  assert(history.every(h=>!h.can_undo_move));
  await apply(params,p.basis,409);await preview(params,409);
  checks.push('stale preview writes nothing; refreshed undo preserves current content, conversation, adoption, later child and both historical changes; repeat request is idempotent and second undo is rejected');
  // Subsequent structure changes cannot be silently overwritten.
  const x=await add(oldParent,'后来改过归属的位置');
  const mp={action:'move',location_id:x,parent_id:newParent};
  const first=await apply(mp,(await preview(mp)).basis);
  const back={action:'move',location_id:x,parent_id:A.location_id};await apply(back,(await preview(back)).basis);
  await preview({action:'undo_move',change_id:first.change_id},409);
  const independent={action:'independent',location_id:x};const split=await apply(independent,(await preview(independent)).basis);
  await preview({action:'undo_move',change_id:split.change_id},409);
  checks.push('later relocation is not overwritten; legacy move-only undo rejects independent records; generic cross-project undo is checked separately');
  // Leave a separate moved item for a real click-through verification.
  const uiLoc=await add(oldParent,'页面撤回的位置');const uiParams={action:'move',location_id:uiLoc,parent_id:newParent};
  const uiChange=await apply(uiParams,(await preview(uiParams)).basis);
  await req('/projects/locations/'+uiLoc+'/content','PUT',{expected_revision:1,content:'页面撤回仍保留的当前稿。',source_message_ids:[],request_id:id()});
  Object.assign(report,{project_id:A.project_id,loc,old_parent:oldParent,new_parent:newParent,original_change:moved.change_id,undo_change:result.change_id,independent_change:split.change_id,ui_location:uiLoc,ui_change:uiChange.change_id});
  report.result='pass';
}
main().catch(e=>{report.result='fail';report.error=e.stack;process.exitCode=1;}).finally(()=>{fs.mkdirSync(path.dirname(evidence),{recursive:true});fs.writeFileSync(evidence,JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));});
