// Offline checks for continuing one ChatGPT chat per Cognos conversation, and for copying replies as Markdown.
// No Chrome is touched. The Markdown check needs jsdom: JSDOM_PATH=/path/to/node_modules/jsdom node test_chatgpt_thread.cjs
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path');
const {ChatConnection,pageState,validateJob,chatId}=require('./chatgpt_browser_service.cjs');
const thread='https://chatgpt.com/c/6a1b-22cd';
const job={run_id:'d'.repeat(32),space_id:'7',prompt:'全文：入口和整段对话',followup:'只发新的一句',thread_url:thread,reasoning_effort:'default'};

function fixture(dir,{url,turns}) {
  const c=new ChatConnection({authorized:()=>true,receipts:dir,sleep:async()=>{}});
  c.state={ready:true};c.account='fixture';
  const seen={opened:null,filled:null,closed:false};let sent=false,reads=0;
  c.newPage=async(target)=>{seen.opened=target;return 3;};
  c.loaded=async()=>({account:'fixture',draft:'',turns,url});
  c.setEffort=async()=>{};
  c.read=async()=>{
    reads++;
    // Right after Enter the old reply is still the latest one on the page: it must not be taken as this reply.
    if(!sent) return {account:'fixture',draft:seen.filled||'',turns,url,complete:true,stop:false,content:'上一次的回复'};
    if(reads<6) return {account:'fixture',draft:'',turns,url,complete:true,stop:false,content:'上一次的回复'};
    return {account:'fixture',draft:'',turns:turns+1,url:thread,complete:true,stop:false,content:'这次的回复'};
  };
  c.call=async(name,args)=>{
    if(name==='take_snapshot')return {content:[{type:'text',text:'uid=5 textbox "询问 ChatGPT"'}]};
    if(name==='fill')seen.filled=args.value;
    if(name==='press_key'&&args.key==='Enter')sent=true;
    if(name==='close_page')seen.closed=true;
    return {content:[]};
  };
  return {c,seen};
}

async function main() {
  assert.equal(chatId('https://chatgpt.com/g/g-p-abc/c/6a1b-22cd'),'6a1b-22cd');
  validateJob(job);
  for(const bad of [{thread_url:'https://evil.example/c/1'},{thread_url:'https://chatgpt.com/'},{followup:''}])
    assert.throws(()=>validateJob({...job,...bad}));
  const dir=fs.mkdtempSync(path.join(os.tmpdir(),'cognos-chat-thread-'));
  try {
    // The saved chat opens: only the new part is sent, the old reply is skipped, and the page is closed afterwards.
    let {c,seen}=fixture(dir,{url:thread,turns:2});
    let reply=await c.reply(job);
    assert.equal(seen.opened,thread);assert.equal(seen.filled,job.followup);
    assert.equal(reply.content,'这次的回复');assert.equal(reply.continued,true);assert.equal(seen.closed,true);
    // The saved chat was deleted (ChatGPT lands on the home page): start over with the whole text.
    ({c,seen}=fixture(dir,{url:'https://chatgpt.com/',turns:0}));
    reply=await c.reply({...job,run_id:'e'.repeat(32)});
    assert.equal(seen.filled,job.prompt);assert.equal(reply.continued,false);
    // ChatGPT has no matching effort control: send anyway and say so, instead of refusing the whole message.
    ({c,seen}=fixture(dir,{url:thread,turns:2}));
    c.setEffort=async()=>{throw Error('ChatGPT 未切换到所选思考强度，未发送消息。');};
    reply=await c.reply({...job,run_id:'f'.repeat(32),reasoning_effort:'high'});
    assert.equal(seen.filled,job.followup);assert.match(reply.content,/没能切到所选的思考强度/);assert.equal(reply.reasoning_effort,'unchanged');
  } finally {fs.rmSync(dir,{recursive:true,force:true});}

  const jsdomPath=process.env.JSDOM_PATH;
  if(!jsdomPath){console.log('Thread checks passed. Markdown check skipped (set JSDOM_PATH).');return;}
  const {JSDOM}=require(jsdomPath);
  const html=`<div data-chatgpt-search-unit-key="1:assistant"><div data-conversation-role="assistant">ChatGPT 说：</div><div class="markdown">
    <h3>标题</h3><p>一段 <strong>粗体</strong> 和 <code>x=1</code>，还有<a href="https://example.com/a">链接</a>。</p>
    <ul><li>第一项<ul><li>里面一项</li></ul></li><li>第二项</li></ul>
    <ol start="3"><li>三</li><li>四</li></ol>
    <pre><div>python<button>复制</button></div><div><code class="language-python">print("hi")
</code></div></pre>
    <table><thead><tr><th>甲</th><th>乙</th></tr></thead><tbody><tr><td>1</td><td>a|b</td></tr></tbody></table>
    <p>公式 <span class="katex"><span class="katex-mathml"><math><semantics><annotation encoding="application/x-tex">a^2</annotation></semantics></math></span><span class="katex-html" aria-hidden="true">a2</span></span></p>
    <span class="sr-only">隐藏</span></div></div>`;
  const dom=new JSDOM(html,{runScripts:'outside-only'});
  const w=dom.window;
  // jsdom lays nothing out: treat every element as visible, the way a real page would.
  w.Element.prototype.getClientRects=function(){return [1];};
  Object.defineProperty(w.HTMLElement.prototype,'innerText',{get(){return this.textContent;}});
  const state=w.eval('('+pageState.toString()+')()');
  const md=state.content;
  for(const piece of ['### 标题','**粗体**','`x=1`','[链接](https://example.com/a)','- 第一项\n  - 里面一项','- 第二项','3. 三\n4. 四',
                      '```python\nprint("hi")\n```','| 甲 | 乙 |','| --- | --- |','a\\|b','$a^2$'])
    assert(md.includes(piece),'Markdown 缺了：'+piece+'\n---\n'+md);
  assert(!md.includes('复制')&&!md.includes('隐藏')&&!md.includes('ChatGPT 说'),md);
  assert.equal(state.turns,1);
  console.log('Thread and Markdown checks passed. No browser accessed.');
}
main().catch(error=>{console.error(error);process.exitCode=1;});
