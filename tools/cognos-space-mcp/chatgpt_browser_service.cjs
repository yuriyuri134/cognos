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
// 一次最多等多久：ChatGPT 在网页里自己用工具（比如 GitHub）时常常要十几二十分钟
const GENERATION_MS = Number(process.env.COGNOS_CHATGPT_TIMEOUT_MIN || 30) * 60 * 1000;
// 适配器自己的日志：每次连接、发送、回完、出错各一行（不记你说的原话，只记长度）
const LOG = path.join(RECEIPTS, 'adapter.log');
function log(event, data={}) {
  try { fs.mkdirSync(RECEIPTS,{recursive:true}); fs.appendFileSync(LOG, JSON.stringify({at:new Date().toISOString(),event,...data})+'\n'); } catch {}
}
// 一段 Cognos 对话接着用同一条 ChatGPT 聊天：只认 chatgpt.com 上的聊天地址（含 ChatGPT 项目里的）
const THREAD = /^https:\/\/chatgpt\.com\/(?:g\/[A-Za-z0-9-]+\/)?c\/[A-Za-z0-9-]+$/;
function normalizeThreadUrl(raw) {
  const s = String(raw || '').trim();
  if (!s) return '';
  try {
    const u = new URL(s);
    if (u.origin !== 'https://chatgpt.com') return '';
    const clean = u.origin + u.pathname.replace(/\/+$/, '');
    return THREAD.test(clean) ? clean : '';
  } catch { return ''; }
}
// 默认在后台开页，不抢你正在用的标签；要看着它打字就设 COGNOS_CHATGPT_FOREGROUND=1
const FOREGROUND = process.env.COGNOS_CHATGPT_FOREGROUND === '1';
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
      || !EFFORTS.includes(job.reasoning_effort)
      || (job.thread_url != null && (!THREAD.test(job.thread_url) || typeof job.followup !== 'string' || !job.followup.trim() || job.followup.length > 500000)))
    throw Error('无效的聊天端运行请求');
}
function chatId(url) {
  return (String(url||'').match(/\/c\/([A-Za-z0-9-]+)/) || [])[1] || '';
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
  const rawWrappers = Array.from(document.querySelectorAll('[data-chatgpt-search-unit-key$=":assistant"]')).filter(visible);
  const wrappers = rawWrappers.length ? rawWrappers : Array.from(document.querySelectorAll('[data-message-author-role="assistant"]')).filter(visible);
  const latest = wrappers.at(-1);
  // 把看得到的回复按 Markdown 抄下来：标题、列表、代码、表格、链接、公式不丢；抄不出来时退回纯文字
  const skip = e => /^(BUTTON|SVG|STYLE|SCRIPT|NOSCRIPT|TEMPLATE)$/i.test(e.tagName) || e.hidden || e.getAttribute('aria-hidden') === 'true'
    || /(^|\s)sr-only(\s|$)/.test(e.getAttribute('class') || '') || getComputedStyle(e).display === 'none';
  const md = (node) => {
    if (node.nodeType === 3) return node.nodeValue.replace(/\s+/g, ' ');
    if (node.nodeType !== 1) return '';
    const e = node, tag = e.tagName.toUpperCase(), kids = () => Array.from(e.childNodes).map(c => md(c)).join('');
    if (e.classList.contains('katex')) {
      const tex = e.querySelector('annotation[encoding="application/x-tex"]')?.textContent || '';
      if (tex) return e.closest('.katex-display') ? '\n\n$$' + tex + '$$\n\n' : '$' + tex + '$';
    }
    if (skip(e)) return '';
    if (/^H[1-6]$/.test(tag)) return '\n\n' + '#'.repeat(Number(tag[1])) + ' ' + kids().trim() + '\n\n';
    if (tag === 'P') return '\n\n' + kids().trim() + '\n\n';
    if (tag === 'BR') return '\n';
    if (tag === 'HR') return '\n\n---\n\n';
    if (tag === 'STRONG' || tag === 'B') { const t = kids().trim(); return t ? '**' + t + '**' : ''; }
    if (tag === 'EM' || tag === 'I') { const t = kids().trim(); return t ? '*' + t + '*' : ''; }
    if (tag === 'DEL' || tag === 'S') { const t = kids().trim(); return t ? '~~' + t + '~~' : ''; }
    if (tag === 'CODE' && !e.closest('pre')) return '`' + e.textContent + '`';
    if (tag === 'PRE') {
      const code = e.querySelector('code'), cm = e.querySelector('.cm-content');
      const lang = (code?.className.match(/language-([\w+#.-]+)/) || [])[1] || '';
      const text = (code ? code.textContent : cm ? cm.innerText : e.innerText).replace(/\n+$/, '');
      return '\n\n```' + lang + '\n' + text + '\n```\n\n';
    }
    if (tag === 'A') { const t = kids().trim(), href = e.getAttribute('href') || ''; return /^https?:/.test(href) && t ? '[' + t + '](' + href + ')' : t; }
    if (tag === 'IMG') { const alt = e.getAttribute('alt') || ''; return alt ? '[图：' + alt + ']' : ''; }
    if (tag === 'UL' || tag === 'OL') {
      const items = Array.from(e.children).filter(li => li.tagName.toUpperCase() === 'LI' && !skip(li));
      return '\n\n' + items.map((li, i) => {
        // 一项里套着的列表紧跟在这一项下面，往里缩两格
        const body = Array.from(li.childNodes).map(c => md(c)).join('').replace(/\n{3,}/g, '\n\n').trim()
          .replace(/\n\n+(?=(?:[-*]|\d+\.) )/g, '\n');
        const mark = tag === 'OL' ? (Number(e.getAttribute('start') || 1) + i) + '. ' : '- ';
        return mark + body.replace(/\n(?!\n|$)/g, '\n  ');
      }).join('\n') + '\n\n';
    }
    if (tag === 'BLOCKQUOTE') return '\n\n' + kids().trim().split('\n').map(l => '> ' + l).join('\n') + '\n\n';
    if (tag === 'TABLE') {
      const rows = Array.from(e.querySelectorAll('tr')).map(tr => Array.from(tr.children).map(c => md(c).replace(/\s+/g, ' ').replace(/\|/g, '\\|').trim()));
      if (!rows.length) return '';
      const width = Math.max(...rows.map(r => r.length)), line = r => '| ' + Array.from({length: width}, (_, i) => r[i] || '').join(' | ') + ' |';
      return '\n\n' + [line(rows[0]), '|' + ' --- |'.repeat(width), ...rows.slice(1).map(line)].join('\n') + '\n\n';
    }
    if (/^(DIV|SECTION|ARTICLE|LI)$/.test(tag)) { const t = kids(); return /\n/.test(t) ? '\n' + t + '\n' : t; }
    return kids();
  };
  const tidy = t => t.replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
  const parts = latest ? Array.from(latest.children).filter(e=>!e.hasAttribute('data-conversation-role') && visible(e)) : [];
  const plain = parts.map(e=>e.innerText).join('\n\n').trim();
  let content = plain;
  try { const formatted = tidy(parts.map(e => md(e)).join('\n\n')); if (formatted) content = formatted; } catch {}
  const turnState = latest?.closest('[data-talvt-turn-state]')?.getAttribute('data-talvt-turn-state');
  const complete = turnState ? turnState === 'complete' : (wrappers.length > 0 && !stop && !!plain);
  const workMode = Array.from(document.querySelectorAll('[role="checkbox"],input[type="checkbox"]')).find(e=>visible(e) && /work|工作/i.test(e.getAttribute('aria-label') || ''));
  return {url:location.href,account,composer:!!composer,draft:composer?.innerText || composer?.value || '',stop,
    picker:picker?.innerText || '',effort,content,plain,complete,turns:wrappers.length,work:workMode?.getAttribute('aria-checked')==='true'};
}
// Read only rendered user/assistant message turns from a ChatGPT conversation tab.
function threadDump() {
  const visible = e => !!e && e.getClientRects().length > 0 && getComputedStyle(e).visibility !== 'hidden';
  const skip = e => /^(BUTTON|SVG|STYLE|SCRIPT|NOSCRIPT|TEMPLATE)$/i.test(e.tagName) || e.hidden || e.getAttribute('aria-hidden') === 'true'
    || /(^|\s)sr-only(\s|$)/.test(e.getAttribute('class') || '') || getComputedStyle(e).display === 'none';
  const md = (node) => {
    if (node.nodeType === 3) return node.nodeValue.replace(/\s+/g, ' ');
    if (node.nodeType !== 1) return '';
    const e = node, tag = e.tagName.toUpperCase(), kids = () => Array.from(e.childNodes).map(c => md(c)).join('');
    if (e.classList.contains('katex')) {
      const tex = e.querySelector('annotation[encoding="application/x-tex"]')?.textContent || '';
      if (tex) return e.closest('.katex-display') ? '\n\n$$' + tex + '$$\n\n' : '$' + tex + '$';
    }
    if (skip(e)) return '';
    if (/^H[1-6]$/.test(tag)) return '\n\n' + '#'.repeat(Number(tag[1])) + ' ' + kids().trim() + '\n\n';
    if (tag === 'P') return '\n\n' + kids().trim() + '\n\n';
    if (tag === 'BR') return '\n';
    if (tag === 'HR') return '\n\n---\n\n';
    if (tag === 'STRONG' || tag === 'B') { const t = kids().trim(); return t ? '**' + t + '**' : ''; }
    if (tag === 'EM' || tag === 'I') { const t = kids().trim(); return t ? '*' + t + '*' : ''; }
    if (tag === 'DEL' || tag === 'S') { const t = kids().trim(); return t ? '~~' + t + '~~' : ''; }
    if (tag === 'CODE' && !e.closest('pre')) return '`' + e.textContent + '`';
    if (tag === 'PRE') {
      const code = e.querySelector('code'), cm = e.querySelector('.cm-content');
      const lang = (code?.className.match(/language-([\w+#.-]+)/) || [])[1] || '';
      const text = (code ? code.textContent : cm ? cm.innerText : e.innerText).replace(/\n+$/, '');
      return '\n\n```' + lang + '\n' + text + '\n```\n\n';
    }
    if (tag === 'A') { const t = kids().trim(), href = e.getAttribute('href') || ''; return /^https?:/.test(href) && t ? '[' + t + '](' + href + ')' : t; }
    if (tag === 'IMG') { const alt = e.getAttribute('alt') || ''; return alt ? '[图：' + alt + ']' : ''; }
    if (tag === 'UL' || tag === 'OL') {
      const items = Array.from(e.children).filter(li => li.tagName.toUpperCase() === 'LI' && !skip(li));
      return '\n\n' + items.map((li, i) => {
        const body = Array.from(li.childNodes).map(c => md(c)).join('').replace(/\n{3,}/g, '\n\n').trim()
          .replace(/\n\n+(?=(?:[-*]|\d+\.) )/g, '\n');
        const mark = tag === 'OL' ? (Number(e.getAttribute('start') || 1) + i) + '. ' : '- ';
        return mark + body.replace(/\n(?!\n|$)/g, '\n  ');
      }).join('\n') + '\n\n';
    }
    if (tag === 'BLOCKQUOTE') return '\n\n' + kids().trim().split('\n').map(l => '> ' + l).join('\n') + '\n\n';
    if (tag === 'TABLE') {
      const rows = Array.from(e.querySelectorAll('tr')).map(tr => Array.from(tr.children).map(c => md(c).replace(/\s+/g, ' ').replace(/\|/g, '\\|').trim()));
      if (!rows.length) return '';
      const width = Math.max(...rows.map(r => r.length)), line = r => '| ' + Array.from({length: width}, (_, i) => r[i] || '').join(' | ') + ' |';
      return '\n\n' + [line(rows[0]), '|' + ' --- |'.repeat(width), ...rows.slice(1).map(line)].join('\n') + '\n\n';
    }
    if (/^(DIV|SECTION|ARTICLE|LI)$/.test(tag)) { const t = kids(); return /\n/.test(t) ? '\n' + t + '\n' : t; }
    return kids();
  };
  const tidy = t => t.replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
  const nodes = Array.from(document.querySelectorAll('[data-message-author-role="user"], [data-message-author-role="assistant"]'))
    .filter(el => visible(el) && !el.parentElement?.closest('[data-message-author-role]'));
  const turns = [];
  for (const node of nodes) {
    const role = node.getAttribute('data-message-author-role') === 'user' ? 'user' : 'assistant';
    if (role === 'user') {
      const pre = Array.from(node.querySelectorAll('.whitespace-pre-wrap')).filter(visible);
      const rawText = (pre.length ? pre.map(e => e.innerText).join('\n\n') : node.innerText || '').trim();
      if (rawText) turns.push({role: 'user', content: rawText});
    } else {
      const mdRoots = Array.from(node.querySelectorAll('.markdown')).filter(visible);
      const parts = mdRoots.length ? mdRoots : Array.from(node.children).filter(e => !e.hasAttribute('data-conversation-role') && visible(e));
      const target = parts.length ? parts : [node];
      const plain = target.map(e => e.innerText).join('\n\n').trim();
      let content = plain;
      try { const formatted = tidy(target.map(e => md(e)).join('\n\n')); if (formatted) content = formatted; } catch {}
      if (content) turns.push({role: 'assistant', content});
    }
  }
  return {url: location.href, title: document.title || '', turns};
}
class ChatConnection {
  constructor(options={}) {
    this.canAccess=options.authorized || authorized; this.receipts=options.receipts || RECEIPTS; this.sleep=options.sleep || delay;
    this.client=null; this.transport=null; this.connecting=null; this.queue=Promise.resolve(); this.state=disconnected(); this.account=''; this.owned=new Set(); this.readableChatTabs=new Set();
  }
  async call(name, args={}) {
    const allowed = ['list_pages','new_page','close_page','take_snapshot','evaluate_script','click','fill','press_key'];
    if (!allowed.includes(name)) throw Error('未允许的浏览器操作');
    const canReadTab = name==='evaluate_script' && this.readableChatTabs.has(args.pageId);
    if (!['list_pages','new_page'].includes(name) && !this.owned.has(args.pageId) && !canReadTab) throw Error('此页面不属于 Cognos，拒绝操作');
    if (name==='new_page' && ((args.url!==HOME && !THREAD.test(args.url)) || args.isolatedContext)) throw Error('只允许在现有登录中打开 ChatGPT 验证页面');
    const allowedEval = (this.owned.has(args.pageId) && args.function === pageState.toString())
      || (this.readableChatTabs.has(args.pageId) && args.function === threadDump.toString());
    if (name==='evaluate_script' && !allowedEval) throw Error('只允许读取聊天页面可见状态');
    let r;
    try { r=await this.client.callTool({name,arguments:args}, undefined, {timeout:this.state.state==='connecting'?180000:60000}); }
    catch { this.state={...disconnected(),message:'Chrome 连接已中断，请重新连接。'}; throw Error(this.state.message); }
    if (r.isError) {this.state={...disconnected(),message:'浏览器操作未完成，请检查 Chrome 后重新连接。'};throw Error(name+': '+textResult(r).slice(0,1600));}
    if (r.structuredContent?.reconnected) {
      this.owned.clear(); this.readableChatTabs.clear(); this.state={...disconnected(),message:'Chrome 已重新连接，请重新检查连接后再发送。'};
      throw Error(this.state.message);
    }
    return r;
  }
  async read(pageId) {
    const state = jsonResult(await this.call('evaluate_script',{pageId,function:pageState.toString(),waitForStableDom:false}));
    if (!state.url.startsWith('https://chatgpt.com/')) throw Error('页面离开了 ChatGPT，已停止');
    return state;
  }
  async newPage(url=HOME) {
    const before = (await this.call('list_pages')).structuredContent?.pages;
    if (!Array.isArray(before)) throw Error('浏览器页面目录格式已变化');
    const result = await this.call('new_page',{url,background:!FOREGROUND,timeout:45000});
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
      log('connect_failed',{error:error.message});
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
      log('connected',{});
      this.state={ready:true,state:'ready',transport:'existing_chrome',account:this.account,
        message:'已连接 Chrome 的 ChatGPT（'+this.account.replaceAll('\n',' / ')+'）。发送后使用该账号的聊天额度。'};
    } finally { await this.call('close_page',{pageId}); this.owned.delete(pageId); }
  }
  async close() {
    try { await this.client?.close(); } catch {}
    this.client=null; this.transport=null; this.owned.clear(); this.readableChatTabs.clear();
  }
  listTabs() {
    const result = this.queue.then(() => this.runListTabs());
    this.queue = result.catch(() => {});
    return result;
  }
  async runListTabs() {
    if (!this.canAccess() || !this.state.ready || !this.client) throw Error('ChatGPT 聊天端未连接');
    const pages = (await this.call('list_pages')).structuredContent?.pages;
    if (!Array.isArray(pages)) throw Error('浏览器页面目录格式已变化');
    return {
      ready: true,
      account: this.account,
      tabs: pages
        .filter(p => typeof p.url === 'string' && p.url.startsWith('https://chatgpt.com/'))
        .map(p => ({
          id: p.id,
          url: p.url,
          clean_url: normalizeThreadUrl(p.url) || '',
          title: String(p.title || ''),
          is_thread: !!normalizeThreadUrl(p.url),
        })),
    };
  }
  inspectThread(spec={}) {
    const result = this.queue.then(() => this.runInspectThread(spec));
    this.queue = result.catch(() => {});
    return result;
  }
  async runInspectThread(spec={}) {
    if (!this.canAccess() || !this.state.ready || !this.client) throw Error('ChatGPT 聊天端未连接');
    const rawUrl = spec.url ? String(spec.url).trim() : '';
    const cleanUrl = rawUrl ? normalizeThreadUrl(rawUrl) : '';
    if (rawUrl && !cleanUrl) throw Error('只支持 https://chatgpt.com/c/... 格式的 ChatGPT 对话地址');
    let targetPageId = spec.pageId != null && spec.pageId !== '' ? spec.pageId : null;
    let tempOwned = false;
    const pages = (await this.call('list_pages')).structuredContent?.pages;
    if (!Array.isArray(pages)) throw Error('浏览器页面目录格式已变化');
    if (targetPageId != null) {
      const match = pages.find(p => String(p.id) === String(targetPageId));
      if (!match || typeof match.url !== 'string' || !match.url.startsWith('https://chatgpt.com/')) {
        throw Error('所选标签页不是 ChatGPT 页面，拒绝读取');
      }
      targetPageId = match.id;
    } else if (cleanUrl) {
      const existing = pages.find(p => normalizeThreadUrl(p.url) === cleanUrl);
      if (existing) {
        targetPageId = existing.id;
      } else {
        targetPageId = await this.newPage(cleanUrl);
        tempOwned = true;
        await this.loaded(targetPageId);
        for (let i = 0; i < 6; i++) {
          this.readableChatTabs.add(targetPageId);
          let probe;
          try { probe = jsonResult(await this.call('evaluate_script', {pageId: targetPageId, function: threadDump.toString(), waitForStableDom: false})); }
          finally { this.readableChatTabs.delete(targetPageId); }
          if (probe.turns && probe.turns.length > 0) break;
          if (!String(probe.url || '').includes('/c/')) break;
          await this.sleep(500);
        }
      }
    } else {
      throw Error('请先选一条 ChatGPT 标签页，或贴入 ChatGPT 对话网址');
    }
    this.readableChatTabs.add(targetPageId);
    try {
      const dump = jsonResult(await this.call('evaluate_script', {pageId: targetPageId, function: threadDump.toString(), waitForStableDom: false}));
      if (!String(dump.url || '').startsWith('https://chatgpt.com/')) throw Error('页面离开了 ChatGPT，已停止');
      const resolvedUrl = normalizeThreadUrl(dump.url) || cleanUrl || '';
      if (!resolvedUrl) {
        throw Error('当前标签页还不是具体对话（需要 chatgpt.com/c/...）');
      }
      return {
        url: resolvedUrl,
        title: String(dump.title || '').replace(/\s*\|\s*ChatGPT\s*$/, '').trim(),
        turns: Array.isArray(dump.turns) ? dump.turns : [],
      };
    } finally {
      this.readableChatTabs.delete(targetPageId);
      if (tempOwned) {
        try { await this.call('close_page', {pageId: targetPageId}); } catch {}
        this.owned.delete(targetPageId);
      }
    }
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
      pageId=await this.newPage(job.thread_url || HOME);
      let state=await this.loaded(pageId);
      if (state.account!==this.account) {this.state={...disconnected(),message:'ChatGPT 登录账号已变化，请重新连接确认'};throw Error(this.state.message);}
      // 接着上一条聊天：页面真打开了那条聊天（没被删掉、没跳回首页）才只发新内容；否则在新聊天里带全文
      const continued=!!job.thread_url && chatId(state.url)===chatId(job.thread_url) && state.turns>0;
      if (!continued && state.turns>0) throw Error('新聊天页面里已经有回复，未发送消息');
      // 说清是哪一样不空闲：输入框里留着草稿（ChatGPT 会把上次没发出去的字留着）、还在生成、或开着工作模式
      const busy=[(state.draft||'').trim()&&'输入框里还有没发出去的文字（'+state.draft.trim().slice(0,30)+'…）',state.stop&&'这条聊天还在生成上一条回复',state.work&&'ChatGPT 开着工作模式'].filter(Boolean);
      if (busy.length) throw Error('聊天页面不是空闲状态，未发送消息：'+busy.join('；'));
      const text=continued?job.followup:job.prompt, before=state.turns;
      // 切不到所选强度（ChatGPT 改了界面、或当前模型没有这一档）：照它现在的设置发，回答末尾说一声，不再整句不发
      let effortNote='';
      try { await this.setEffort(pageId,job.reasoning_effort); }
      catch(error) {
        if (!/强度/.test(error.message)) throw error;
        effortNote='（这次没能切到所选的思考强度，按 ChatGPT 当前的设置回答。）';
        log('effort_skipped',{run_id:job.run_id,reason:error.message});
        try { await this.call('press_key',{pageId,key:'Escape'}); } catch {}
      }
      const snapshot=textResult(await this.call('take_snapshot',{pageId}));
      const composer=uidFor(snapshot,'textbox',/询问 ChatGPT|Ask ChatGPT|有问题，尽管问|Ask anything/i);
      await this.call('fill',{pageId,uid:composer,value:text});
      state=await this.read(pageId);
      if(state.account!==this.account || (state.draft||'').trim()!==text.trim() || state.stop) throw Error('聊天输入未通过确认，未发送消息');
      // Persist BEFORE Enter. A crash or timeout never causes an automatic second send.
      const receipt={run_id:job.run_id,space_id:job.space_id,hash,state:'sending',continued,at:new Date().toISOString(),url:state.url};
      fs.writeFileSync(filename,JSON.stringify(receipt,null,2),{flag:'wx'});
      submitted=true;
      const started=Date.now();
      log('sent',{run_id:job.run_id,space_id:job.space_id,continued,chars:text.length,url:state.url});
      await this.call('press_key',{pageId,key:'Enter'});
      let stable='',stableCount=0;
      const deadline=Date.now()+GENERATION_MS;
      while(Date.now()<deadline) {
        await this.sleep(1000);
        state=await this.read(pageId);
        if (state.account!==this.account) throw Error('ChatGPT 账号发生变化，已停止读取');
        // 接着的聊天里上一条回复一直在页面上：要等出现新的一条回复，不能把旧的当成这次的
        if (state.turns>before && state.complete && !state.stop && state.content) {
          stableCount=state.content===stable?stableCount+1:0; stable=state.content;
          if(stableCount>=1) {
            const result={content:state.content+(effortNote?'\n\n'+effortNote:''),provider:'chatgpt_chat',source_url:state.url,continued,reasoning_effort:effortNote?'unchanged':job.reasoning_effort};
            log('replied',{run_id:job.run_id,space_id:job.space_id,continued,chars:state.content.length,url:state.url,seconds:Math.round((Date.now()-started)/1000)});
            fs.writeFileSync(filename,JSON.stringify({...receipt,state:'complete',result},null,2));
            // 回复已经抄回来、聊天也留在 ChatGPT 的记录里：关掉这次自己开的页，不让标签越堆越多
            try { await this.call('close_page',{pageId}); this.owned.delete(pageId); } catch {}
            return result;
          }
        } else {stable='';stableCount=0;}
      }
      throw Error('聊天端等了 '+Math.round(GENERATION_MS/60000)+' 分钟还没取得完整回复，请看 Chrome 里留着的那页；不会自动重复发送。');
    } catch(error) {
      log('failed',{run_id:job.run_id,space_id:job.space_id,submitted,error:error.message});
      throw error;
    } finally {
      // Keep submitted pages visible as provenance, including uncertain outcomes.
      // Keep failed validation pages visible for inspection.
    }
  }
}
// 不用 fetch：Node 的 fetch 等回复头最多 5 分钟，ChatGPT 一想久就断成「fetch failed」，回复丢掉
function request(route,body={},timeout=5000) {
  const payload=Buffer.from(JSON.stringify(body));
  return new Promise((resolve,reject)=>{
    const req=http.request({host:'127.0.0.1',port:PORT,path:route,method:'POST',
      headers:{'Content-Type':'application/json','Content-Length':payload.length,'X-Cognos-Bridge-Token':token()}},res=>{
      let raw='';res.setEncoding('utf8');res.on('data',c=>raw+=c);
      res.on('end',()=>{
        let result;try{result=JSON.parse(raw||'{}');}catch{return reject(Error('聊天端连接服务回了看不懂的内容'));}
        if(res.statusCode<200||res.statusCode>=300) return reject(Error(result.error||'聊天端连接服务不可用'));
        resolve(result);
      });
    });
    const timer=setTimeout(()=>req.destroy(Error('等聊天端连接服务超过 '+Math.round(timeout/1000)+' 秒')),timeout);
    req.on('error',reject);req.on('close',()=>clearTimeout(timer));
    req.end(payload);
  });
}
async function status() {
  if(!authorized()) return disconnected();
  try {return await request('/status');} catch {return disconnected();}
}
async function open() {
  if(!authorized()) return disconnected();
  try {return await request('/connect');} catch(error) {
    // Only an explicit connect may start the local adapter. It never starts Chrome.
    if(error.code!=='ECONNREFUSED') throw error;
    // 适配器自己崩了也要留得下原因：输出接到日志目录里
    fs.mkdirSync(RECEIPTS,{recursive:true});
    const out=fs.openSync(path.join(RECEIPTS,'adapter.out.log'),'a');
    const child=require('node:child_process').spawn(process.execPath,[__filename,'serve'],{detached:true,stdio:['ignore',out,out],windowsHide:true});
    child.unref();
    for(let i=0;i<25;i++) {await delay(100);try{return await request('/connect');}catch{}}
    throw Error('本机聊天端连接服务未启动');
  }
}
async function tabs() {
  if(!authorized()) return {...disconnected(),tabs:[]};
  return request('/tabs',{},15000);
}
async function thread(spec={}) {
  if(!authorized()) throw Error('尚未允许 Cognos 连接浏览器');
  return request('/thread',spec,60000);
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
      if(req.url==='/tabs') return send(200,await connection.listTabs());
      if(req.url==='/thread') return send(200,await connection.inspectThread(data));
      if(req.url==='/reply') return send(200,await connection.reply(data));
      send(404,{error:'不存在的连接操作'});
    } catch(error) {send(503,{error:error.message});}
  });
  server.requestTimeout=0;
  server.on('error',error=>{log('server_error',{error:error.message});process.exit(1);});
  log('serve',{port:PORT,timeout_min:GENERATION_MS/60000,foreground:FOREGROUND});
  server.listen(PORT,'127.0.0.1');
  const stop=async()=>{server.close();await connection.close();process.exit(0);};
  process.on('SIGTERM',stop);process.on('SIGINT',stop);
}
module.exports={status,open,tabs,thread,reply,ChatConnection,mcpArgs,pageState,threadDump,uidFor,jsonResult,validateJob,signature,chatId,disconnected,authorized,THREAD,normalizeThreadUrl};
if(require.main===module && process.argv[2]==='serve') serve();
