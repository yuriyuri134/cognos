/* Loopback contract checks; creates only labelled trial records, never invokes an AI. */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const origin='http://127.0.0.1:8780', app='/index.php/apps/cognos', ocs='/ocs/v2.php/apps/cognos/api/v1.0';
const password=fs.readFileSync(path.join(__dirname,'.secrets/admin-password'),'utf8').trim();
const headers={Authorization:'Basic '+Buffer.from('cognos-admin:'+password).toString('base64'),'OCS-APIRequest':'true',Accept:'application/json','Content-Type':'application/json'};
const report={at:new Date().toISOString(),checks:[]}, id=()=>crypto.randomUUID();
async function req(route,method='GET',body){
 const r=await fetch(origin+route,{method,headers,body:body?JSON.stringify(body):undefined,signal:AbortSignal.timeout(30000)});
 if(!r.ok)throw Error(method+' '+route+' HTTP '+r.status);
 const j=await r.json();return j.ocs?j.ocs.data:j;
}
async function main(){
 const p=await req(app+'/projects/data','POST',{title:'验证：交回与差异 '+Date.now(),request_id:id()});
 const L=p.location_id, chat=(await req(app+'/projects/locations/'+L+'/data')).location.creation_id;
 Object.assign(report,{project_id:p.project_id,location_id:L,creation_id:chat});
 let revision=1;
 const update=()=>req(app+'/projects/locations/'+L+'/content','PUT',{expected_revision:revision++,content:'工作稿版本 '+revision,source_message_ids:[],request_id:id()});
 await update();
 await req(app+'/projects/locations/'+L+'/adoptions','POST',{object_type:'location_content',object_id:L,object_version:'2',request_id:id()});
 const results=[];
 for(const kind of ['stale','current','legacy']){
   const subject='验证参与者 '+kind;
   const run=(await req(ocs+'/spaces/creation/'+chat+'/runs','POST',{subject,purpose:'只核对交回契约'})).run;
   await req(ocs+'/spaces/creation/'+chat+'/basis','POST',{subject,revision:2});
   await update();
   const before=await req(ocs+'/runs/'+run.id+'/drift');
   assert(before.drift_token&&before.basis_changed.length);
   if(kind==='stale')await update();
   const result={subject,run_id:run.id,result_id:id().replaceAll('-',''),content:'检查结果 '+kind,acknowledged_drift:true};
   if(kind!=='legacy')result.drift_token=before.drift_token;
   await req(ocs+'/spaces/creation/'+chat+'/messages','POST',result);
   const retry=await req(ocs+'/spaces/creation/'+chat+'/messages','POST',result);
   assert.equal(retry.duplicate,true);
   results.push({kind,id:run.id});
 }
 const overview=await req(app+'/projects/'+p.project_id+'/overview');
 const returned=overview.activity.recent;
 for(const r of results){
   const saved=returned.find(x=>x.run_id===r.id);assert(saved,'result visible in overview');
   if(r.kind==='current')assert(saved.drift_seen_at);
   else assert.equal(saved.drift_seen_at,null,'stale/legacy acknowledgment must not claim current changes were seen');
 }
 report.checks.push('matching fingerprint records seen; stale or boolean-only acknowledgment saves result without claiming seen');
 const messages=(await req(app+'/creations/'+chat+'/messages')).messages;
 assert.equal(messages.filter(m=>m.role==='assistant').length,3);
 report.checks.push('each retried result is stored exactly once');
 const correction=await req(app+'/projects/corrections','POST',{object_type:'location_content',object_id:L,object_version:'2',content:'验证用纠正：需要重新检查原依据',request_id:id()});
 assert(correction.review>=1);assert.equal(correction.review_depth2,0);assert(correction.hint_depth2>=1);
 const impacts=(await req(app+'/projects/locations/'+L+'/adoptions')).impacts;
 assert(impacts.filter(x=>x.depth===2).every(x=>x.effect==='hint'));
 assert(impacts.some(x=>x.depth===1&&x.effect==='review'));
 report.checks.push('explicit dependence requires review; time-only relation remains a hint');
 report.result='pass';
}
main().catch(e=>{report.result='fail';report.error=e.message;process.exitCode=1}).finally(()=>{
 fs.mkdirSync(path.join(__dirname,'evidence'),{recursive:true});
 fs.writeFileSync(path.join(__dirname,'evidence/handoff-contracts.json'),JSON.stringify(report,null,2));
 console.log(JSON.stringify(report,null,2));
});
