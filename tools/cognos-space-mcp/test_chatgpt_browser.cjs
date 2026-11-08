// Offline contract checks: no Chrome, login data, model requests or consent changes.
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path');
const {ChatConnection,mcpArgs,uidFor,jsonResult,validateJob,signature}=require('./chatgpt_browser_service.cjs');
const job={run_id:'a'.repeat(32),space_id:'7',prompt:'第一段\n\n第二段',reasoning_effort:'high'};
async function main() {
  const args=mcpArgs();
  assert(args.includes('--autoConnect'));
  assert(args.includes('--usageStatistics=false'));
  assert(args.includes('--performanceCrux=false'));
  assert(args.includes('--pageIdRouting=true'));
  assert(!args.some(a=>/isolated|userDataDir|browserUrl|wsEndpoint|headless|no-sandbox/i.test(a)));
  const loading=new ChatConnection({sleep:async()=>{}}); let reads=0;
  loading.read=async()=>({account:'fixture',composer:true,picker:++reads>=3?'High':''});
  await loading.loaded(1);assert.equal(reads,3,'Wait for the model picker, not merely the early input skeleton');
  const normal=new ChatConnection({authorized:()=>true});
  normal.client={callTool:async({name,arguments:args})=>{
    if(name==='list_pages')return {structuredContent:{pages:[]}};
    assert.equal(name,'new_page');assert.equal(args.url,'https://chatgpt.com/');
    return {structuredContent:{pages:[{id:9,url:args.url}]}};
  }};
  assert.equal(await normal.newPage(),9);
  validateJob(job);
  for(const bad of [{reasoning_effort:'extended'},{run_id:'../unsafe'},{prompt:''},{space_id:'other'}]) assert.throws(()=>validateJob({...job,...bad}));
  assert.notEqual(signature(job),signature({...job,prompt:'另一条'}));
  assert.equal(uidFor('uid=1_2 textbox "询问 ChatGPT"','textbox',/询问 ChatGPT/),'1_2');
  assert.throws(()=>uidFor('uid=1 textbox "询问 ChatGPT"\nuid=2 textbox "询问 ChatGPT"','textbox',/询问 ChatGPT/));
  assert.deepEqual(jsonResult({content:[{type:'text',text:'Script ran:\n```json\n{"complete":true}\n```'}]}),{complete:true});
  const restricted=new ChatConnection({authorized:()=>false});let called=0;
  restricted.client={callTool:async()=>{called++;return {content:[]};}};
  await assert.rejects(()=>restricted.call('fill',{pageId:99,uid:'1',value:'draft'}),/不属于 Cognos/);
  await assert.rejects(()=>restricted.call('evaluate_script',{pageId:99,function:'() => document.cookie'}),/不属于 Cognos/);
  await assert.rejects(()=>restricted.call('new_page',{url:'https://example.com/'}),/只允许/);
  await restricted.start();assert.equal(called,0);
  const dir=fs.mkdtempSync(path.join(os.tmpdir(),'cognos-chat-offline-'));
  try {
    const c=new ChatConnection({authorized:()=>true,receipts:dir,sleep:async()=>{}});
    c.state={ready:true};c.account='fixture\nPlus';c.newPage=async()=>1;c.loaded=async()=>({account:c.account,draft:'\n',turns:0,url:'https://chatgpt.com/'});c.setEffort=async(_page,value)=>assert.equal(value,'high');
    const sequence=[];let submitted=false;
    c.read=async()=>({url:'https://chatgpt.com/c/fixture',account:c.account,draft:submitted?'':job.prompt,
      complete:submitted,stop:false,turns:submitted?1:0,content:submitted?'真实页面的完整回复':''});
    c.call=async(name,args)=>{sequence.push(name);if(name==='take_snapshot')return {content:[{type:'text',text:'uid=5 textbox "询问 ChatGPT"'}]};
      if(name==='fill')assert.equal(args.value,job.prompt);
      if(name==='press_key') {assert.equal(args.key,'Enter');submitted=true;assert(fs.existsSync(path.join(dir,job.run_id+'.json')));}
      return {content:[]};};
    const reply=await c.reply(job);
    assert.equal(reply.content,'真实页面的完整回复');assert.equal(reply.provider,'chatgpt_chat');
    const count=sequence.length;assert.deepEqual(await c.reply(job),reply);assert.equal(sequence.length,count);
    await assert.rejects(()=>c.reply({...job,prompt:'不同输入'}),/其他输入/);
    const uncertain={...job,run_id:'b'.repeat(32)};
    c.call=async(name)=>{if(name==='take_snapshot')return {content:[{type:'text',text:'uid=5 textbox "询问 ChatGPT"'}]};if(name==='press_key')throw Error('连接中断');return {content:[]};};
    submitted=false;
    await assert.rejects(()=>c.reply(uncertain),/连接中断/);
    await assert.rejects(()=>c.reply(uncertain),/不会自动重发/);
    const switched=new ChatConnection({authorized:()=>true,receipts:dir,sleep:async()=>{}});
    switched.state={ready:true};switched.account='fixture';switched.newPage=async()=>2;switched.loaded=async()=>({account:'different'});
    let sent=false;switched.call=async(name)=>{if(name==='fill'||name==='press_key')sent=true;return {};};
    await assert.rejects(()=>switched.reply({...job,run_id:'c'.repeat(32)}),/账号已变化/);assert.equal(sent,false);
  } finally {fs.rmSync(dir,{recursive:true,force:true});}
  console.log('Passed: existing-browser only, explicit consent, page ownership, effort contract, complete replies, run deduplication and account changes. No browser accessed.');
}
main().catch(error=>{console.error(error);process.exitCode=1;});
