// Product adapter, independent of any Codex assistant session. Browser access is opt-in.
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const crypto = require('node:crypto');
const {createRequire} = require('node:module');
const HERE = __dirname;
const ROOT = path.resolve(HERE, '../..');
const SECRETS = path.join(ROOT, 'deploy/nextcloud-trial/.secrets');
const RECEIPTS = path.join(ROOT, 'deploy/nextcloud-trial/evidence/chatgpt-browser-runs');
const CONSENT = path.join(SECRETS, 'chatgpt-browser-enabled');
const PORT = Number(process.env.COGNOS_CHATGPT_PORT || 8644);
const HOME = 'https://chatgpt.com/';
const EFFORTS = ['default', 'low', 'medium', 'high'];
const GENERATION_MS = 10 * 60 * 1000;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
function authorized() {
  try { return fs.readFileSync(CONSENT, 'utf8').trim() === 'official_chrome_autoconnect_v1'; }
  catch { return false; }
}
function token() {
  const value = process.env.COGNOS_BRIDGE_TOKEN || fs.readFileSync(path.join(SECRETS, 'claude-bridge-token'), 'utf8').trim();
  if (!value) throw Error('本机桥口令尚未配置');
  return value;
}
function disconnected() {
  return {ready:false, state:authorized()?'disconnected':'authorization_required', transport:'existing_chrome',
    message:authorized()?'尚未连接现有 Chrome，请点“连接现有 Chrome”。':'聊天端适配器已准备，尚待允许 Cognos 连接现有 Chrome。'};
}
function mcpArgs() {
  return [path.join(HERE, 'chatgpt-browser/node_modules/chrome-devtools-mcp/build/src/bin/chrome-devtools-mcp.js'),
    '--autoConnect', '--usageStatistics=false', '--performanceCrux=false',
    '--pageIdRouting=true', '--experimentalStructuredContent=true',
    ...['Emulation','Performance','Network','Memory','Extensions','ExperimentalWebmcp','ExperimentalThirdParty','Pwa'].map(c=>'--category'+c+'=false')];
}
function textResult(result) {
  return (result.content || []).filter(c=>c.type==='text').map(c=>c.text).join('\n');
}
function jsonResult(result) {
  const text = textResult(result);
  const match = text.match(/```json\s*\n([\s\S]*?)\n```/);
  if (!match) throw Error('浏览器未返回预期的页面信息');
  return JSON.parse(match[1]);
}
function uidFor(snapshot, role, pattern) {
  const matches = snapshot.split('\n').filter(line=>new RegExp('\\b'+role+'\\b').test(line) && pattern.test(line));
  if (matches.length !== 1) throw Error('聊天页面控件已变化，未发送消息：'+role);
  const uid = matches[0].match(/uid=([^\s]+)/)?.[1];
  if (!uid) throw Error('无法识别聊天页面控件');
  return uid;
}
function validateJob(job) {
  if (!job || !/^[a-zA-Z0-9]{32}$/.test(job.run_id || '') || !/^[1-9][0-9]*$/.test(job.space_id || '')
      || typeof job.prompt !== 'string' || !job.prompt.trim() || job.prompt.length > 500000
      || !EFFORTS.includes(job.reasoning_effort)) throw Error('无效的聊天端运行请求');
}
function signature(job) {
  return crypto.createHash('sha256').update(JSON.stringify([job.space_id,job.prompt,job.reasoning_effort])).digest('hex');
}
// Read only rendered UI. Never read session storage, cookies, network responses or internal app state.
function pageState() {
  const visible = e => !!e && e.getClientRects().length > 0 && getComputedStyle(e).visibility !== 'hidden';
  const buttons = Array.from(document.querySelectorAll('button'));
  const profile = buttons.filter(e=>visible(e) && /个人资料菜单|profile menu/i.test(e.getAttribute('aria-label') || ''));
  const account = profile.map(e=>(e.parentElement?.innerText || '').split('\n').filter(line=>!/^正在加载个人资料$|^Loading profile$/i.test(line.trim())).join('\n').trim()).find(t=>t && t.length<200) || '';
  const composer = Array.from(document.querySelectorAll('[contenteditable="true"][role="textbox"],textarea')).find(visible);
  const stop = buttons.some(e=>visible(e) && /停止(生成|回复)|stop (generating|response)/i.test(e.getAttribute('aria-label') || e.innerText));
  const picker = buttons.find(e=>visible(e) && /选择 ChatGPT 模型|choose ChatGPT model/i.test(e.getAttribute('aria-label') || ''));
  const menu = Array.from(document.querySelectorAll('[role="menu"]')).find(visible);
  const slider = menu?.querySelector('[data-reasoning-slider] [role="slider"]');
  const effort = slider ? {value:Number(slider.getAttribute('aria-valuenow')),max:Number(slider.getAttribute('aria-valuemax'))} : null;
  const wrappers = Array.from(document.querySelectorAll('[data-chatgpt-search-unit-key$=":assistant"]')).filter(visible);
  const latest = wrappers.at(-1);
  const content = latest ? Array.from(latest.children).filter(e=>!e.hasAttribute('data-conversation-role') && visible(e)).map(e=>e.innerText).join('\n\n').trim() : '';
  const complete = latest?.closest('[data-talvt-turn-state]')?.getAttribute('data-talvt-turn-state') === 'complete';
  const workMode = Array.from(document.querySelectorAll('[role="checkbox"],input[type="checkbox"]')).find(e=>visible(e) && /work|工作/i.test(e.getAttribute('aria-label') || ''));
  return {url:location.href,account,composer:!!composer,draft:composer?.innerText || composer?.value || '',stop,
    picker:picker?.innerText || '',effort,content,complete,work:workMode?.getAttribute('aria-checked')==='true'};
}
class ChatConnection {
  constructor(options={}) {
    this.canAccess=options.authorized || authorized; this.receipts=options.receipts || RECEIPTS; this.sleep=options.sleep || delay;
    this.client=null; this.transport=null; this.connecting=null; this.queue=Promise.resolve(); this.state=disconnected(); this.account=''; this.owned=new Set();
  }
  async call(name, args={}) {
    const allowed = ['list_pages','new_page','close_page','take_snapshot','evaluate_script','click','fill','press_key'];
    if (!allowed.includes(name)) throw Error('未允许的浏览器操作');
    if (!['list_pages','new_page'].includes(name) && !this.owned.has(args.pageId)) throw Error('此页面不属于 Cognos，拒绝操作');
    if (name==='new_page' && (args.url!==HOME || args.isolatedContext)) throw Error('只允许在现有登录中打开 ChatGPT 验证页面');
    if (name==='evaluate_script' && args.function !== pageState.toString()) throw Error('只允许读取聊天页面可见状态');
    let r;
    try { r=await this.client.callTool({name,arguments:args}, undefined, {timeout:this.state.state==='connecting'?180000:60000}); }
    catch { this.state={...disconnected(),message:'Chrome 连接已中断，请重新连接。'}; throw Error(this.state.message); }
    if (r.isError) {this.state={...disconnected(),message:'浏览器操作未完成，请检查 Chrome 后重新连接。'};throw Error(name+': '+textResult(r).slice(0,1600));}
    if (r.structuredContent?.reconnected) {
      this.owned.clear(); this.state={...disconnected(),message:'Chrome 已重新连接，请重新检查连接后再发送。'};
      throw Error(this.state.message);
    }
    return r;
  }
  async read(pageId) {
    const state = jsonResult(await this.call('evaluate_script',{pageId,function:pageState.toString(),waitForStableDom:false}));
    if (!state.url.startsWith('https://chatgpt.com/')) throw Error('页面离开了 ChatGPT，已停止');
    return state;
  }
  async newPage() {
    const before = (await this.call('list_pages')).structuredContent?.pages;
    if (!Array.isArray(before)) throw Error('浏览器页面目录格式已变化');
    const result = await this.call('new_page',{url:HOME,background:false,timeout:45000});
    const pages = result.structuredContent?.pages || [];
    const created = pages.filter(p=>!before.some(old=>old.id===p.id) && p.url.startsWith('https://chatgpt.com/'));
    if (created.length!==1) throw Error('无法确认 Cognos 新建的聊天页，未读取其他页面');
    this.owned.add(created[0].id);
    return created[0].id;
  }
  async loaded(pageId) {
    let state;
    for (let i=0;i<50;i++) {
      state=await this.read(pageId);
      if (state.account && !/正在加载|loading/i.test(state.account) && state.composer && state.picker) return state;
      await this.sleep(1000);
    }
    throw Error('ChatGPT 页面未就绪。请检查现有 Chrome 的登录和网络。');
  }
  async start() {
    if (this.connecting) return this.state;
    if (this.state.ready) return this.state;
    if (!this.canAccess()) return disconnected();
    this.state={...disconnected(),state:'connecting',message:'正在连接现有 Chrome；如 Chrome 提示，请确认允许 Cognos 连接。'};
    this.connecting=this.connect().catch(async(error)=>{
      this.state={...disconnected(),detail:error.message,message:'未连接成功。请在 Chrome 的 chrome://inspect/#remote-debugging 启用远程调试，并确认浏览器的连接提示后重试。'};
      await this.close();
    }).finally(()=>{this.connecting=null;});
    return this.state;
  }
  async connect() {
    await this.close();
    const req=createRequire(path.join(HERE,'chatgpt-browser/package.json'));
    const {Client}=req('@modelcontextprotocol/sdk/client/index.js');
    const {StdioClientTransport,getDefaultEnvironment}=req('@modelcontextprotocol/sdk/client/stdio.js');
    this.transport=new StdioClientTransport({command:process.execPath,args:mcpArgs(),stderr:'ignore',
      env:{...getDefaultEnvironment(),CHROME_DEVTOOLS_MCP_NO_USAGE_STATISTICS:'1'}});
    this.client=new Client({name:'Cognos ChatGPT Chat',version:'0.1.0'});
    await this.client.connect(this.transport,{timeout:60000});
    const pageId=await this.newPage();
    try {
      const state=await this.loaded(pageId);
      this.account=state.account;
      this.state={ready:true,state:'ready',transport:'existing_chrome',account:this.account,
        message:'已连接 Chrome 的 ChatGPT（'+this.account.replaceAll('\n',' / ')+'）。发送后使用该账号的聊天额度。'};
    } finally { await this.call('close_page',{pageId}); this.owned.delete(pageId); }
  }
  async close() {
    try { await this.client?.close(); } catch {}
    this.client=null; this.transport=null; this.owned.clear();
  }
  async setEffort(pageId, effort) {
    if (effort==='default') return;
    let snapshot=textResult(await this.call('take_snapshot',{pageId}));
    const picker=uidFor(snapshot,'button',/选择 ChatGPT 模型|choose ChatGPT model/i);
    await this.call('click',{pageId,uid:picker});
    let state=await this.read(pageId);
    if (!state.effort || state.effort.max!==2) throw Error('当前 ChatGPT 未提供低、中、高三档强度，未发送消息。');
    snapshot=textResult(await this.call('take_snapshot',{pageId}));
    const slider=uidFor(snapshot,'menuitem',/"强度"|"Intensity"/i);
    await this.call('click',{pageId,uid:slider});
    state=await this.read(pageId);
    const target=['low','medium','high'].indexOf(effort);
    const diff=target-state.effort.value;
    for(let i=0;i<Math.abs(diff);i++) await this.call('press_key',{pageId,key:diff>0?'ArrowRight':'ArrowLeft'});
    state=await this.read(pageId);
    if (state.effort?.value!==target) throw Error('ChatGPT 未切换到所选思考强度，未发送消息。');
    await this.call('press_key',{pageId,key:'Escape'});
  }
  reply(job) {
    const result=this.queue.then(()=>this.run(job));
    this.queue=result.catch(()=>{});
    return result;
  }
  async run(job) {
    validateJob(job);
    if (!this.canAccess() || !this.state.ready) throw Error('ChatGPT 聊天端未连接');
    fs.mkdirSync(this.receipts,{recursive:true});
    const filename=path.join(this.receipts,job.run_id+'.json'), hash=signature(job);
    if (fs.existsSync(filename)) {
      const receipt=JSON.parse(fs.readFileSync(filename,'utf8'));
      if(receipt.hash!==hash) throw Error('该运行编号已用于其他输入');
      if(receipt.result) return receipt.result;
      throw Error('此运行已经尝试发送，可能仍在 ChatGPT 中生成。为避免重复用量，请查看原聊天页，不会自动重发。');
    }
    let pageId,submitted=false;
    try {
      pageId=await this.newPage();
      let state=await this.loaded(pageId);
      if (state.account!==this.account) {this.state={...disconnected(),message:'ChatGPT 登录账号已变化，请重新连接确认'};throw Error(this.state.message);}
      if ((state.draft||'').trim() || state.stop || state.work) throw Error('新聊天页面不是空闲聊天状态，未发送消息');
      await this.setEffort(pageId,job.reasoning_effort);
      const snapshot=textResult(await this.call('take_snapshot',{pageId}));
      const composer=uidFor(snapshot,'textbox',/询问 ChatGPT|Ask ChatGPT/i);
      await this.call('fill',{pageId,uid:composer,value:job.prompt});
      state=await this.read(pageId);
      if(state.account!==this.account || (state.draft||'').trim()!==job.prompt.trim() || state.stop) throw Error('聊天输入未通过确认，未发送消息');
      // Persist BEFORE Enter. A crash or timeout never causes an automatic second send.
      const receipt={run_id:job.run_id,space_id:job.space_id,hash,state:'sending',at:new Date().toISOString(),url:state.url};
      fs.writeFileSync(filename,JSON.stringify(receipt,null,2),{flag:'wx'});
      submitted=true;
      await this.call('press_key',{pageId,key:'Enter'});
      let stable='',stableCount=0;
      const deadline=Date.now()+GENERATION_MS;
      while(Date.now()<deadline) {
        await this.sleep(1000);
        state=await this.read(pageId);
        if (state.account!==this.account) throw Error('ChatGPT 账号发生变化，已停止读取');
        if (state.complete && !state.stop && state.content) {
          stableCount=state.content===stable?stableCount+1:0; stable=state.content;
          if(stableCount>=1) {
            const result={content:state.content,provider:'chatgpt_chat',source_url:state.url,reasoning_effort:job.reasoning_effort};
            fs.writeFileSync(filename,JSON.stringify({...receipt,state:'complete',result},null,2));
            return result;
          }
        } else {stable='';stableCount=0;}
      }
      throw Error('聊天端尚未取得完整回复，请检查 Chrome 中保留的聊天页；不会自动重复发送。');
    } finally {
      // Keep submitted pages visible as provenance, including uncertain outcomes.
      // Keep failed validation pages visible for inspection.
    }
  }
}
async function request(route,body={},timeout=5000) {
  const response=await fetch('http://127.0.0.1:'+PORT+route,{method:'POST',headers:{'Content-Type':'application/json','X-Cognos-Bridge-Token':token()},
    body:JSON.stringify(body),signal:AbortSignal.timeout(timeout)});
  const result=await response.json();
  if(!response.ok) throw Error(result.error || '聊天端连接服务不可用');
  return result;
}
async function status() {
  if(!authorized()) return disconnected();
  try {return await request('/status');} catch {return disconnected();}
}
async function open() {
  if(!authorized()) return disconnected();
  try {return await request('/connect');} catch(error) {
    // Only an explicit connect may start the local adapter. It never starts Chrome.
    if(error.cause?.code!=='ECONNREFUSED') throw error;
    const child=require('node:child_process').spawn(process.execPath,[__filename,'serve'],{detached:true,stdio:'ignore',windowsHide:true});
    child.unref();
    for(let i=0;i<25;i++) {await delay(100);try{return await request('/connect');}catch{}}
    throw Error('本机聊天端连接服务未启动');
  }
}
async function reply(job) {validateJob(job);if(!authorized()) throw Error('尚未允许 Cognos 连接浏览器');return request('/reply',job,GENERATION_MS+70000);}
function safeEqual(a,b) {
  const x=Buffer.from(a),y=Buffer.from(b);return x.length===y.length && crypto.timingSafeEqual(x,y);
}
function serve() {
  const secret=token(),connection=new ChatConnection();
  const server=http.createServer(async(req,res)=>{
    const send=(status,data)=>{res.writeHead(status,{'Content-Type':'application/json; charset=utf-8','Cache-Control':'no-store'});res.end(JSON.stringify(data));};
    if(req.method!=='POST' || !safeEqual(String(req.headers['x-cognos-bridge-token']||''),secret)) return send(401,{error:'未授权的连接请求'});
    if(!authorized()) return send(403,{error:'尚未允许 Cognos 连接浏览器'});
    try {
      let raw='';for await (const chunk of req) {raw+=chunk;if(Buffer.byteLength(raw)>2100000) throw Error('请求过大');}
      const data=JSON.parse(raw || '{}');
      if(req.url==='/status') return send(200,connection.state);
      if(req.url==='/connect') return send(200,await connection.start());
      if(req.url==='/reply') return send(200,await connection.reply(data));
      send(404,{error:'不存在的连接操作'});
    } catch(error) {send(503,{error:error.message});}
  });
  server.on('error',()=>process.exit(1));
  server.listen(PORT,'127.0.0.1');
  const stop=async()=>{server.close();await connection.close();process.exit(0);};
  process.on('SIGTERM',stop);process.on('SIGINT',stop);
}
module.exports={status,open,reply,ChatConnection,mcpArgs,pageState,uidFor,jsonResult,validateJob,signature,disconnected,authorized};
if(require.main===module && process.argv[2]==='serve') serve();
