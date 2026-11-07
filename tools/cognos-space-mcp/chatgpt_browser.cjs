// ChatGPT Chat must use the user's existing Chrome session.
// A private profile cannot establish that connection and must never be opened implicitly.
const connectionMessage="当前未连接到你已登录的 Chrome，暂时无法使用 ChatGPT 聊天端。";
async function status(){return {ready:false,state:'connection_unavailable',transport:'existing_chrome',message:connectionMessage};}
async function open(){return status();}
async function reply(){throw new Error(connectionMessage);}
function finalSse(text){let state=null,answer=null;
 function apply(op,prefix=''){
  if(op.message){state=op;return;}
  if(op.v?.message&&!op.o){state=op.v;return;}
  const pointer=prefix+(op.p||'');
  if(op.o==='patch'&&Array.isArray(op.v)){for(const child of op.v)apply(child,pointer);return;}
  if(!op.o||!state)return;
  const parts=pointer.split('/').slice(1).map(p=>p.replaceAll('~1','/').replaceAll('~0','~'));if(!parts.length){if(['replace','add'].includes(op.o))state=op.v;return;}
  let target=state;for(const part of parts.slice(0,-1)){if(target?.[part]===undefined)return;target=target[part];}const key=parts.at(-1);
  if(op.o==='append'&&typeof target?.[key]==='string')target[key]+=op.v;
  else if(['add','replace'].includes(op.o))target[key]=op.v;
  else if(op.o==='remove')delete target[key];
 }
 for(const line of text.split('\n')){if(!line.startsWith('data: '))continue;let op;try{op=JSON.parse(line.slice(6));}catch{continue;}apply(op);const m=state?.message;if(m?.author?.role==='assistant'&&(!m.channel||m.channel==='final')&&m.content?.content_type==='text'&&m.end_turn===true)answer=m.content.parts.filter(p=>typeof p==='string').join('\n');}
 return answer;
}
module.exports={status,open,reply,finalSse};
if(require.main===module)(async()=>{
 const action=process.argv[2]||'status';
 let result;
 if(action==='open')result=await open();
 else if(action==='status')result=await status();
 else if(action==='reply')result=await reply();
 else throw Error('Unknown action');
 process.stdout.write(JSON.stringify(result)+'\n');
})().catch(e=>{process.stdout.write(JSON.stringify({error:e.message})+'\n');process.exitCode=1;});
