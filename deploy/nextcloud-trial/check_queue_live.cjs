const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const origin='http://127.0.0.1:8780',app='/index.php/apps/cognos',ocs='/ocs/v2.php/apps/cognos/api/v1.0';
const password=fs.readFileSync(path.join(__dirname,'.secrets/admin-password'),'utf8').trim();
const headers={Authorization:'Basic '+Buffer.from('cognos-admin:'+password).toString('base64'),'OCS-APIRequest':'true',Accept:'application/json','Content-Type':'application/json'};
const report={checks:[]},id=()=>crypto.randomUUID();
async function req(route,method='GET',body,status=200){const r=await fetch(origin+route,{method,headers,body:body?JSON.stringify(body):undefined});assert.equal(r.status,status,route);const j=await r.json();return j.ocs?j.ocs.data:j;}
async function main(){
 const p=await req(app+'/projects/data','POST',{title:'验证：真实忙时排队 '+Date.now(),request_id:id()});
 const loc=(await req(app+'/projects/locations/'+p.location_id+'/data')).location;
 Object.assign(report,p,{creation_id:loc.creation_id});
 const first=await req(app+'/creations/'+loc.creation_id+'/messages','POST',{message:'验证真实排队：先指出这个新项目需要厘清的一个问题，只写两句话，不改内容，不使用外部命令。'});
 assert(first.pending);const firstId=Number(first.messages[0].id);
 const second=await req(app+'/creations/'+loc.creation_id+'/messages','POST',{message:'验证第二条排队：接着提出一个可能推翻前一判断的反例，只写两句话，不改内容，不使用外部命令。'});
 assert.equal(second.queued,true,'second message must encounter a busy real bridge');report.queued_message_id=Number(second.messages[0].id);
 const end=Date.now()+420000;let messages=[];
 while(Date.now()<end){messages=(await req(app+'/creations/'+loc.creation_id+'/messages')).messages;if(messages.some(m=>m.role==='assistant'&&Number(m.reply_to)===firstId)&&messages.some(m=>m.role==='assistant'&&Number(m.reply_to)===report.queued_message_id))break;await new Promise(r=>setTimeout(r,3000));}
 assert.equal(messages.filter(m=>m.role==='assistant'&&Number(m.reply_to)===firstId).length,1);
 assert.equal(messages.filter(m=>m.role==='assistant'&&Number(m.reply_to)===report.queued_message_id).length,1);
 report.answers=messages.filter(m=>m.role==='assistant').map(m=>({reply_to:m.reply_to,content:m.content.slice(0,300)}));
 report.checks.push('real bridge busy queues second original message; both original requests receive exactly one actual AI response');report.result='pass';
}
main().catch(e=>{report.result='fail';report.error=e.message;process.exitCode=1}).finally(()=>{fs.mkdirSync(path.join(__dirname,'evidence'),{recursive:true});fs.writeFileSync(path.join(__dirname,'evidence/queue-live.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));});