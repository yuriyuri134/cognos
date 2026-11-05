/* Actual authenticated 8780 checks for attempt identity, versions and authored stage summaries.
 * Uses dedicated fixtures; does not execute a model or equate an authored summary with an execution result.
 */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=__dirname,app='http://127.0.0.1:8780/index.php/apps/cognos',ocs='http://127.0.0.1:8780/ocs/v2.php/apps/cognos/api/v1.0';
const admin={user:'cognos-admin',password:fs.readFileSync(path.join(root,'.secrets/admin-password'),'utf8').trim()};
const outsider=JSON.parse(fs.readFileSync(path.join(root,'.secrets/task-journey.json'),'utf8')).outsider;
const evidence=path.join(root,'evidence/attempts-api.json'),checks=[],report={at:new Date().toISOString(),checks};
const id=()=>crypto.randomUUID();
async function call(url,method,data,expected,actor){
 const r=await fetch(url,{method,redirect:'manual',headers:{Authorization:'Basic '+Buffer.from(actor.user+':'+actor.password).toString('base64'),'OCS-APIRequest':'true',Accept:'application/json',...(data?{'Content-Type':'application/json'}:{})},body:data?JSON.stringify(data):undefined,signal:AbortSignal.timeout(60000)});
 let body;try{body=await r.json()}catch{throw Error(method+' '+url+': non-JSON HTTP '+r.status)}
 const payload=body.ocs?body.ocs.data:body;
 assert((Array.isArray(expected)?expected:[expected]).includes(r.status),method+' '+url+': HTTP '+r.status+' '+(payload.message||''));return payload;
}
const req=(route,method='GET',data,expected=200,actor=admin)=>call(app+route,method,data,expected,actor);
const agent=(route,method='GET',data,expected=200)=>call(ocs+route,method,data,expected,admin);
const identity=r=>r.id||r.attempt_id||r.summary_id;
const attempt=async aid=>(await req('/projects/attempts/'+aid)).item;
const work=async wid=>(await req('/projects/work/'+wid)).item;
const aFields=a=>({title:a.title,introduction:a.introduction,focus:a.focus,state:a.state,next_entry:a.next_entry});
const edit=(a,extra={},expected=200)=>req('/projects/attempts/'+a.id,'PUT',{...aFields(a),expected_revision:a.revision,request_id:id(),...extra},expected);
const summaryFields=s=>({title:s.title,coverage:s.coverage,content:s.content,covered_refs:s.covered_refs});
const revise=(s,extra={},expected=200)=>req('/projects/attempt-summaries/'+s.id,'PUT',{...summaryFields(s),expected_revision:s.revision,request_id:id(),...extra},expected);
async function main(){
 const A=await req('/projects/data','POST',{title:'验证：尝试简介与阶段 '+Date.now(),request_id:id()});
 const branch=(await req('/projects/'+A.project_id+'/locations','POST',{parent_id:A.location_id,title:'同一尝试服务的另一位置',request_id:id()})).location_id;
 const loc=(await req('/projects/locations/'+A.location_id+'/data')).location;
 const trace=(await agent('/spaces/creation/'+loc.creation_id+'/traces','POST',{kind:'attempt',subject:'原尝试记录者',content:'旧发生：试着比较导航，仍没有结论。',about:'导航比较',stopped_at:'还可从另一个入口重来',touched_reality:false},201)).trace;
 const W=await req('/projects/locations/'+A.location_id+'/work-items','POST',{description:'明确工作仍待验收，不随尝试结束而完成',request_id:id()});
 const refs=[{type:'trace',id:String(trace.id),note:'原发生保留原作者'}, {type:'location_content',id:A.location_id,version:1,note:'起步的具体一版'}, {type:'work_item',id:identity(W),version:1,note:'具体承诺的初版'}, {type:'link',id:'https://example.invalid/attempt-reference',note:'只有链接，未取得正文'}];
 const body={title:'比较两种进入方式',introduction:'一句念头即可留下，不预设交付。',focus:'',state:'open',next_entry:'',location_ids:[A.location_id,branch],refs,request_id:id()};
 const created=await req('/projects/locations/'+A.location_id+'/attempts','POST',body),aid=identity(created);assert(aid,'stable attempt id');
 assert.deepEqual(await req('/projects/locations/'+A.location_id+'/attempts','POST',body),created);
 await req('/projects/locations/'+A.location_id+'/attempts','POST',{...body,title:'同一编号偷换内容'},409);
 let a=await attempt(aid);assert.equal(a.revision,1);assert.equal(a.title,body.title);assert(a.author,'attempt author is retained');
 const author=a.author;assert.equal(a.refs.find(r=>r.type==='link').status,'link_only');
 const list=async(pid,lid)=>(await req('/projects/'+pid+'/attempts?location_id='+lid)).items;
 assert((await list(A.project_id,A.location_id)).some(x=>x.id===aid));assert((await list(A.project_id,branch)).some(x=>x.id===aid));
 await req('/projects/attempts/'+aid,'GET',undefined,404,outsider);
 await req('/projects/attempts/'+aid,'PUT',{...aFields(a),expected_revision:a.revision,request_id:id()},404,outsider);
 checks.push('one authored attempt has one stable identity in two positions; create replay is identical, altered replay conflicts and outsider cannot read or edit');
 const minimal=await req('/projects/locations/'+A.location_id+'/attempts','POST',{title:'只留一句没有答案的话',request_id:id()});
 assert.equal((await attempt(identity(minimal))).introduction,'');
 await edit(a,{introduction:'换了一个比较角度，仍不强迫成为任务。',next_entry:'从第二个位置进入'});
 await edit(a,{introduction:'旧版试图覆盖'},409);a=await attempt(aid);
 assert.equal(a.introduction,'换了一个比较角度，仍不强迫成为任务。');
 assert(a.revisions.some(v=>v.revision===1&&v.snapshot.introduction===body.introduction&&v.author===author));
 assert.equal(a.refs.find(r=>r.type==='location_content').version,1);assert.equal(a.refs.find(r=>r.type==='work_item').version,1);assert.deepEqual([...a.location_ids].sort(),[A.location_id,branch].sort());
 checks.push('minimal thought is accepted; introduction revisions retain original author and text; stale update does not overwrite and omitted refs/locations remain');
 const foreign=await req('/projects/data','POST',{title:'验证：不可引用的他人范围 '+Date.now(),request_id:id()},200,outsider);
 for(const ref of [{type:'location_content',id:foreign.location_id,version:1},{type:'location_content',id:A.location_id,version:999999},{type:'work_item',id:identity(W)}]){
  await req('/projects/locations/'+A.location_id+'/attempts','POST',{title:'应拒绝无权或不可回查引用',refs:[ref],request_id:id()},[403,404,422]);
 }
 const sources=await req('/projects/locations/'+A.location_id+'/attempt-sources');assert(Array.isArray(sources.sources));
 assert(sources.sources.some(r=>r.type==='trace'&&String(r.id)===String(trace.id)));
 checks.push('source picker returns actual trace; inaccessible source and nonexistent or missing pinned revision are refused');
 const summaryRoute='/projects/attempts/'+aid+'/summaries';
 const firstBody={title:'先比较入口',coverage:'只覆盖起步的旧发生和第一版安排，不代表全部活动',content:'作者判断：两种进入方式值得再试，尚未验证优劣。',covered_refs:refs.filter(r=>r.type!=='link'),request_id:id()};
 const S1=await req(summaryRoute,'POST',firstBody);assert.deepEqual(await req(summaryRoute,'POST',firstBody),S1);
 const S2=await req(summaryRoute,'POST',{title:'后来换角度',coverage:'只覆盖此段回顾，未绑定具体记录',content:'从另一个位置进入仍有未解问题。',covered_refs:[],request_id:id()});
 assert.notEqual(identity(S1),identity(S2));
 a=await attempt(aid);assert.equal(a.summaries.length,2);
 const first=a.summaries.find(s=>s.id===identity(S1));assert(first);const firstAuthor=first.author;
 await revise(first,{coverage:'补充说明：仅这些固定版本',content:'修订判断：仍未检验，不能称为成功。'});
 await revise(first,{content:'过期总结覆盖'},409);
 a=await attempt(aid);assert.equal(a.summaries.length,2);const revised=a.summaries.find(s=>s.id===identity(S1));
 assert.equal(revised.revision,2);assert.equal(revised.author,firstAuthor);
 assert(revised.revisions.some(v=>v.revision===1&&v.snapshot.coverage===firstBody.coverage&&v.snapshot.content===firstBody.content&&v.author===firstAuthor));
 assert.equal(revised.covered_refs.find(r=>r.type==='location_content').version,1);
 const entered=await agent('/spaces/creation/'+loc.creation_id+'/enter?format=json&subject='+encodeURIComponent('阶段入口检查'));
 const inEntry=entered.entry.space.attempt_records.items.find(x=>x.id===aid);assert(inEntry,'attempt enters actual model-facing context');assert.equal(inEntry.latest_summary.id,identity(S2),'latest appended stage is selected by actual order, even if an older stage was revised');
 checks.push('two stages have distinct identities; revising first retains its old coverage/content/author and does not create a third stage; stale revision is rejected');
 await req('/projects/locations/'+A.location_id+'/content','PUT',{content:'后来修改的稿件，不替换被引用的第一版。',expected_revision:1,source_message_ids:[],request_id:id()});
 const w=await work(identity(W));await req('/projects/work/'+w.id,'PUT',{description:'后来改变的明确范围',owner:w.owner,deliverable:w.deliverable,acceptance:w.acceptance,state:'open',evidence:'',expected_revision:w.revision,request_id:id()});
 a=await attempt(aid);await edit(a,{state:'ended'});a=await attempt(aid);
 assert.equal(a.state,'ended');assert.equal((await work(identity(W))).state,'open');
 assert.equal(a.refs.find(r=>r.type==='location_content').version,1);assert.equal(a.refs.find(r=>r.type==='work_item').version,1);assert.deepEqual([...a.location_ids].sort(),[A.location_id,branch].sort());
 const old=(await req('/projects/locations/'+A.location_id+'/history/1')).revision;
 assert(old&&old.revision===1,'original location version remains readable');
 const pinned=await req('/projects/attempts/reference/read','POST',{ref:{type:'location_content',id:A.location_id,version:1}});assert.equal(pinned.record.content,old.content);
 const originalWork=await req('/projects/attempts/reference/read','POST',{ref:{type:'work_item',id:identity(W),version:1}});assert.equal(originalWork.record.snapshot.description,'明确工作仍待验收，不随尝试结束而完成');
 const originalTrace=await req('/projects/attempts/reference/read','POST',{ref:{type:'trace',id:String(trace.id)}});assert.equal(originalTrace.record.author,'原尝试记录者');const returnedOriginal={...originalTrace.record,touched_reality:originalTrace.record.touched_reality===null?null:!!Number(originalTrace.record.touched_reality)};assert.deepEqual(Object.fromEntries(Object.keys(trace).map(k=>[k,returnedOriginal[k]])),trace);
 const link=await req('/projects/attempts/reference/read','POST',{ref:{type:'link',id:'https://example.invalid/attempt-reference'}});assert.equal(link.status,'link_only');assert.equal(link.record,null);
 await req('/projects/attempts/reference/read','POST',{ref:{type:'location_content',id:A.location_id,version:1}},404,outsider);
 const traces=(await agent('/spaces/creation/'+loc.creation_id+'/traces?q='+encodeURIComponent('旧发生：试着比较导航'))).traces;
 const found=traces.find(t=>String(t.id)===String(trace.id));assert(found);assert.deepEqual(Object.fromEntries(Object.keys(trace).map(k=>[k,found[k]])),trace);
 checks.push('later source changes do not repoint references; authored attempt end leaves work open; old location version and entire original attempt trace remain unchanged');
 const params={action:'independent',location_id:branch};const preview=await req('/projects/restructure/preview','POST',params);
 const independent=await req('/projects/restructure','POST',{...params,basis:preview.basis,request_id:id()});
 assert((await list(A.project_id,A.location_id)).some(x=>x.id===aid));assert((await list(independent.project_id,branch)).some(x=>x.id===aid));
 assert.equal((await attempt(aid)).id,aid);
 checks.push('independent position retains its link to the same attempt while original position remains linked');
 Object.assign(report,{project_id:A.project_id,location_id:A.location_id,branch_id:branch,independent_project_id:independent.project_id,attempt_id:aid,summary_id:identity(S1),second_summary_id:identity(S2),work_id:identity(W),trace_id:trace.id,result:'pass'});
}
main().catch(e=>{report.result='fail';report.error=e.stack;process.exitCode=1}).finally(()=>{fs.mkdirSync(path.dirname(evidence),{recursive:true});fs.writeFileSync(evidence,JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2))});
