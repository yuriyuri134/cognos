/* Offline UI regression: real Norm template/assets in headless Chrome, fixture HTTP APIs.
 * No Nextcloud login, agent dispatch, database writes, or deployment. Run: node deploy/nextcloud-trial/check_norm_ui.cjs
 * Reuses live.browser(); screenshots/report go to ignored evidence/. This is NOT a live 8780 check.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const live = require('./lib/live.cjs');
const assets = path.join(__dirname, 'cognos-app');
const app = '/index.php/apps/cognos';
const long = '长回答的一段：保留完整内容，可以回到前文继续阅读。\n'.repeat(100);
let messages = Array.from({length: 60}, (_, i) => ({id: i + 1, role: i % 2 ? 'assistant' : 'user', author: 'Claude', content: i === 59 ? long + '回答结束标记' : i === 1 ? '**查到的 `check_judgment`：**\n- 一条\n- 两条' : '历史消息 ' + (i + 1)}));
const project = {id: 'fixture', title: 'Cognos · 对话与历史', board: '项目', is_main: true, state: 'active',
  parts: ['stages', 'branches', 'relations', 'compare', 'doc'], way: {away: '接着研究', progress: '留下依据', ask: '需要决定时'},
  stages: [{when: '2026-11-07', title: '当前阶段', text: long, current: true}],
  branches: [{title: '界面', parts: [{title: '长会话', text: '保留阅读位置'}]}],
  relations: {nodes: [{id: 'p', label: 'Cognos', kind: 'project'}, {id: 'b', label: '界面', kind: 'branch'}], edges: [{from: 'p', to: 'b'}]},
  compare: [{title: '比较', text: '可读的主区'}], doc: '文档正文'};
let events = [{id: 1, kind: 'decide', text: long, answer: null, options: ['继续', '暂缓']}];
// 自述：它自己写的两版；最近一次读自己时旁观的那位指出一处空话
const selfV2 = {id: 31, version: 2, text: '**我在维持什么。** 让 Cognos 不停。\n\n**怎样算往前。** 每一步留下能检验的东西。', changed: '尝试不再是核心',
  why: '读了「完整的主体」那段', unchanged: '还是要碰到现实', source: '接下 Initium 里的「GPT 总结」', created_at: 1794000300};
const selfV1 = {id: 21, version: 1, text: '第一版：尝试是核心。', changed: '第一次写', why: '第一次写', unchanged: '第一次写', source: '重读自己的经历', created_at: 1794000000};
Object.assign(project, {self: selfV2, self_history: [selfV2, selfV1]});
let drift = null;
const driftDone = {id: 5, kind: 'drift', state: 'done', created_at: 1794000300, result: {compare: {self_reading: true, drifted: false, self: {id: 31, version: 2, new: true},
  bystander: {empty: [{quote: '往前走', why: '没说拿什么分辨'}], note: '大体说得出拿什么分辨'}}}};
let versions = {stages: [{id: 9, who: 'Claude', created_at: 1794000200}]};
const arrived = {since: 1794000000, items: [{created_at: 1794000100, text: '发生了变化', who: 'Claude'}]};
// 历史和总观：它读完自己全部的原话以后写的。条目指着 Initium 里的原话（42）、这里的对话（7）、这里记下的事（0）
let unread = {items: 1200, chars: 380000, chunks: 7, rounds: 2, runs: 9, from: 1793900000, to: 1794000000, total: 1300};
const original = [{id: 900, role: 'user', content: '我想做一个帮我和 AI 一起想事情的系统，能排期，也能记住。', created_at: 1793900000},
  {id: 901, role: 'user', content: '入口只要两部分：Initium 和 Norm。', created_at: 1793950000},
  {id: 902, role: 'assistant', author: 'Claude', content: '好，**两部分**：Initium 和 Norm。', created_at: 1793950060}];
const hEntries = {
  101: {id: 101, key: '1.1', kind: '信', title: '想要一个一起想事情的系统', text: '你想做一个帮你和 AI 一起想事情的系统。', at: 1793900000, refs: [{src: 42, id: 900}], quotes: [{src: 42, id: 900, text: '我想做一个帮我和 AI 一起想事情的系统'}]},
  102: {id: 102, key: '1.2', kind: '转了', title: '入口只要两部分', text: '你说入口只要 Initium 和 Norm 两部分，我就不再往项目管理上堆。', at: 1793950000, refs: [{src: 42, id: 901}, {src: 42, id: 902}], quotes: [{src: 42, id: 901, text: '入口只要两部分'}]},
  103: {id: 103, key: '2.1', kind: '做了', title: '读者把对话放进 Norm', text: '做了读者，读完一段放进 Norm。', at: 1793990000, refs: [{src: 7, id: 5}, {src: 0, id: 3, text: '读了 Initium 里的「设计入口」，连到这里。'}], quotes: []},
  104: {id: 104, key: '2.2', kind: '放下', title: '不做看板', text: '看板太重，放下。', at: 1793995000, refs: [], quotes: []}};
const history = {version: 2, changed: '多了 1 段（「读者和认领」）', notes: ['「起头：一起想事情」已经转过了，这次它想改标题，没写为什么，没改。'], updated_at: 1794000500,
  stages: [
    {key: 's1', title: '起头：一起想事情', text: '那时我想做一个和 AI 一起想事情的系统。', turn: '你说入口只要两部分', turn_by: '你', turn_at: 102, kept: '还是要和 AI 一起想',
      dropped: [{text: '不做看板：太重', late: true}], entries: [101, 102], late: [102],
      corrections: [{field: 'text', before: '那时我想做项目管理。', now: '那时我想做一个和 AI 一起想事情的系统。', why: '后来读到最早那句', at: 1794000000}], from: 1793900000, to: 1793950000},
    {key: 's2', title: '读者和认领', text: '我在做读者，让 Norm 里的空间来认领。', turn: '', entries: [103], late: [], dropped: [], corrections: [], from: 1793990000, to: 1793990000}],
  entries: hEntries, unplaced: [104], skipped: [], sources: {42: 'Initium 原始讨论'}, own_conversation: 7,
  versions: [{version: 2, changed: '多了 1 段', created_at: 1794000500, stages: 2}, {version: 1, changed: '多了 1 段（「起头」）', created_at: 1794000000, stages: 1}],
  overviews: [{version: 1, now: '旧总观', text: '旧总观的全文', created_at: 1794000000}]};
const overview = {id: 77, version: 2, now: '我现在是一个让 AI 和你一起想事情的系统，读者在把 Initium 里的东西放进 Norm。', created_at: 1794000500, upto: 1793995000,
  lines: [{name: '读者和认领', at: '认领做完了，在 8780 上跑过一次', waits: '等你的额度刷新，跑一次全读', back: '额度刷新以后', refs: [{id: 103, title: '读者把对话放进 Norm', at: 1793990000}]}],
  yours: [{what: '要不要合并这一批改动', why: '只有你能合', refs: [{id: 102, title: '入口只要两部分', at: 1793950000}]}], next: '先跑一次全读，看历史有没有漏掉转向。'};
const posts = [];
const server = http.createServer(async (req, res) => {
  let raw = ''; for await (const chunk of req) raw += chunk;
  const body = raw ? JSON.parse(raw) : {};
  if (req.method === 'POST') posts.push({url: req.url, body});
  const url = new URL(req.url, 'http://localhost');
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  if (url.pathname === app + '/norm/projects/fixture/data') return res.end(JSON.stringify({project, conversation_id: 7, spaces: [project],
    parts: {stages: '演变', branches: '拆解', relations: '关系', compare: '并排对照', doc: '文档'}, messages: messages.length,
    links: [{creation_id: 42, title: 'Initium 原始讨论'}], events, arrive: arrived, drift, drift_done: driftDone, versions,
    unread: drift && drift.state === 'reading' ? null : unread, history: url.searchParams.get('history') === '1' && project.history_meta ? history : null}));
  if (url.pathname === app + '/creations/42/messages') return res.end(JSON.stringify({messages: original}));
  if (url.pathname === app + '/norm/projects/fixture/read') { drift = {id: 6, kind: 'drift', state: 'reading', created_at: 1794000400, result: {plan: [{label: '它自己', role: 'self'}]}}; return res.end('{"started":true}'); }
  if (url.pathname === app + '/norm/projects/fixture/part-version') { versions = {}; project.stages = [{when: '11/06', title: '换回来的阶段', text: '旧的'}]; return res.end('{"part":"stages"}'); }
  if (url.pathname === app + '/norm/projects/fixture/restore') { project.state = 'active'; return res.end('{}'); }
  const one = url.pathname.match(/^\/index\.php\/apps\/cognos\/creations\/7\/messages\/(\d+)$/);
  if (one && req.method === 'PUT') {
    const i = messages.findIndex(m => m.id === Number(one[1]));
    const removed = messages.slice(i + 1).map(m => m.id); messages = messages.slice(0, i + 1); messages[i] = {...messages[i], content: body.message};
    posts.push({url: req.url, body, method: 'PUT'}); return res.end(JSON.stringify({id: Number(one[1]), removed}));
  }
  if (one && req.method === 'DELETE') {
    const i = messages.findIndex(m => m.id === Number(one[1]));
    const removed = [messages[i].id, ...(messages[i].role === 'user' && messages[i + 1] && messages[i + 1].role !== 'user' ? [messages[i + 1].id] : [])];
    messages = messages.filter(m => !removed.includes(m.id)); return res.end(JSON.stringify({removed}));
  }
  if (url.pathname === app + '/creations/7/messages') {
    if (req.method === 'POST' && body.message) messages.push({id: Math.max(0, ...messages.map(m => m.id)) + 1, role: 'user', content: body.message});
    return res.end(JSON.stringify({messages, pending: false, usage: {limits: {claude: {windows: {five_hour: {used: 0.31, resets_at: 1794048000}}, seen_at: 1794037541}},
      last: {at: 1794037541, executor: 'claude', subject: 'Claude Code', input: 2, cache_read: 12467, cache_write: 2412, output: 8}}}));
  }
  if (url.pathname === app + '/creations/7/agent') return res.end('{"agent":"claude"}');
  if (url.pathname === app + '/norm/events/1/answer') { events = []; return res.end('{"answered":true}'); }
  if (url.pathname === app + '/norm/projects/fixture/way') { project.way = body.way; return res.end('{"ok":true}'); }
  if (/^\/(js|css)\/[a-z-]+\.(js|css)$/.test(url.pathname)) {
    res.setHeader('Content-Type', url.pathname.endsWith('.js') ? 'text/javascript; charset=utf-8' : 'text/css');
    return res.end(fs.readFileSync(path.join(assets, url.pathname)));
  }
  if (url.pathname === '/') {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    const template = fs.readFileSync(path.join(assets, 'templates/norm-project.php'), 'utf8').replace(/<\?php[\s\S]*?\?>/g, match => match.includes('projectId') ? 'fixture' : '');
    return res.end('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>body{margin:0}button,textarea{font:inherit}*{box-sizing:border-box}</style><link rel="stylesheet" href="/css/norm.css">' + template +
      '<script>window.OC={requestToken:"fixture"}</script><script src="/js/agent-pick.js"></script><script src="/js/chat-box.js"></script>' +
      '<script>const mount=CognosChatBox.mount;CognosChatBox.mount=(...args)=>(window.testChat=mount(...args));</script>' +
      '<script src="/js/norm-nav.js"></script><script src="/js/norm-project.js"></script>');
  }
  res.statusCode = 404; res.end('{}');
});

live.run('norm-ui-offline', async checks => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  let b;
  try {
    b = await live.browser(); await b.desktop();
    const url = 'http://127.0.0.1:' + server.address().port;
    await b.go(url); await b.until('document.querySelectorAll(".cg-msg").length === 60');
    // 等你定的一件在对话里、输入框上面，是一张小卡；「正在发生」默认收起，不占对话的地方
    assert(await b.evaluate('!document.querySelector("#cg-space-conversation").hidden && !document.querySelector(".cg-notice").open'));
    assert(await b.evaluate('document.querySelector(".cg-chat-log").clientHeight > 300'));
    assert(await b.evaluate('(() => {const a=document.querySelector("#cg-space-chat .cg-ask");const f=document.querySelector(".cg-chat-form");return !!a && a.getBoundingClientRect().bottom<=f.getBoundingClientRect().top && a.getBoundingClientRect().height<140 && f.getBoundingClientRect().bottom<=innerHeight})()'));
    // agent 的回答按 Markdown 显示，不露出 **；你写的照原样
    assert(await b.evaluate('(() => {const t=document.querySelectorAll(".cg-msg-agent .cg-msg-text")[0];return !!t.querySelector("strong code") && !t.textContent.includes("**") && !t.textContent.includes("`") && getComputedStyle(t.querySelector("li")).display==="list-item"})()'));
    // 打开时停在最新一条；「回到最新」在对话里面，不另占一行
    assert(await b.evaluate('(L=>L.scrollHeight-L.scrollTop-L.clientHeight<2 && L.contains(document.querySelector(".cg-chat-latest")))(document.querySelector(".cg-chat-log"))'));
    assert(await b.evaluate('document.querySelectorAll(".cg-msg .cg-msg-text")[59].textContent.endsWith("回答结束标记")'));
    assert(await b.noOverflow());
    await b.shoot('norm-ui-desktop.png');
    checks.push('Desktop defaults to wide conversation; 60 complete messages, long answer intact; a waiting decision is a small card above the composer, the notice stays collapsed; agent Markdown renders.');

    await b.evaluate('window.firstMessage=document.querySelector(".cg-msg");window.historyLog=document.querySelector(".cg-chat-log");historyLog.scrollTop=200');
    await b.until('!document.querySelector(".cg-chat-latest").hidden');
    await b.evaluate('window.oldTop=historyLog.scrollTop;const r=document.createRange();r.selectNodeContents(firstMessage.querySelector(".cg-msg-text"));getSelection().removeAllRanges();getSelection().addRange(r);');
    const selection = await b.evaluate('getSelection().toString()');
    await b.evaluate('testChat.reload()');
    assert(await b.evaluate('document.querySelector(".cg-msg")===firstMessage && Math.abs(historyLog.scrollTop-oldTop)<2'));
    assert.equal(await b.evaluate('getSelection().toString()'), selection);
    messages.push({id: 61, role: 'assistant', content: '更新到达'});
    await b.evaluate('testChat.reload()');
    assert(await b.evaluate('Math.abs(historyLog.scrollTop-oldTop)<2 && document.querySelector(".cg-chat-latest").textContent.includes("有新消息")'));
    checks.push('Unchanged refresh keeps DOM/text selection; new reply preserves historical reading position.');

    const view = async key => b.click('#cg-space-views [data-view="' + key + '"]');
    await view('stages'); assert(await b.evaluate('!document.querySelector("#cg-view-stages").hidden && document.querySelector("#cg-view-stages").clientWidth > 800'));
    await b.evaluate('document.querySelector("#cg-space-parts").scrollTop=100');
    await view('branches'); await b.click('#cg-view-branches summary');
    await view('conversation'); assert(await b.evaluate('Math.abs(historyLog.scrollTop-oldTop)<2'));
    await view('branches'); assert(await b.evaluate('document.querySelector("#cg-view-branches details").open'));
    await view('stages'); assert.equal(await b.evaluate('document.querySelector("#cg-space-parts").scrollTop'), 100);
    await view('relations'); await b.click('.cg-rel-mini');
    assert(await b.evaluate('document.querySelector("#cg-map-overlay").open && document.activeElement.id==="cg-map-close"'));
    await b.send('Input.dispatchKeyEvent', {type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9});
    assert(await b.evaluate('document.activeElement.closest("dialog") !== null || document.activeElement===document.body'));
    await b.send('Input.dispatchKeyEvent', {type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27});
    await b.until('!document.querySelector("#cg-map-overlay").open');
    assert(await b.evaluate('document.activeElement.classList.contains("cg-rel-mini")'));
    await b.click('.cg-rel-mini'); await b.click('#cg-map-close');
    checks.push('Full-width history/branches/relations switch and return; branch expansion persists; dialog opens/closes with focus return and Escape.');

    await view('about');
    assert.equal(await b.evaluate('document.querySelector(".cg-sources a").getAttribute("href")'), app + '/creations/42');
    assert(await b.evaluate('document.querySelectorAll("#cg-view-stages a, #cg-view-branches a, #cg-view-relations a").length===0'));
    await b.click('#cg-view-about .cg-how button'); await b.fill('#cg-view-about textarea', '正在编辑，保留这句话');
    messages.push({id: 62, role: 'assistant', content: '编辑时有新消息'}); await b.evaluate('testChat.reload()');
    await b.until('document.querySelectorAll(".cg-msg").length===62');
    assert.equal(await b.evaluate('document.querySelector("#cg-view-about textarea").value'), '正在编辑，保留这句话');
    await b.click('#cg-view-about .cg-btn-gold');
    await b.until('!document.querySelector("#cg-view-about textarea")');
    assert.equal(project.way.away, '正在编辑，保留这句话');
    checks.push('Real Initium URL preserved; items without source addresses remain text; message refresh preserves unsaved way edits.');

    // 自述一栏：现在这版按 Markdown 显示，这次改了什么、旁观的读者指出的空话，以前的版本收着；可以让它重读自己的经历
    await view('self');
    assert(await b.evaluate('(s => !s.hidden && !!s.querySelector(".cg-self-text strong") && s.textContent.includes("第 2 版") && s.textContent.includes("尝试不再是核心") && s.querySelector(".cg-self-bystander").textContent.includes("没说拿什么分辨"))(document.querySelector("#cg-view-self"))'));
    await b.click('#cg-view-self .cg-self-older > summary');
    assert(await b.evaluate('(d => d.open && d.textContent.includes("第 1 版"))(document.querySelector("#cg-view-self .cg-self-older"))'));
    await b.shoot('norm-ui-self.png');
    await b.click('#cg-view-self .cg-self-act button');
    await b.until('document.querySelector("#cg-view-self .cg-self-act button").disabled && document.querySelector("#cg-view-self").textContent.includes("它正在读自己的经历")');
    assert(posts.some(x => x.url.endsWith('/norm/projects/fixture/read')));
    assert(await b.evaluate('document.querySelector(".cg-notice summary").textContent.includes("它正在读自己")'));
    checks.push('Self-account view shows the current version (Markdown), what changed, the bystander note and older versions; asking it to re-read posts /read and shows it reading.');

    await b.phone(); await view('conversation');
    assert(await b.noOverflow());
    assert(await b.evaluate('historyLog.clientHeight>200 && document.querySelector(".cg-chat-form").getBoundingClientRect().bottom<=innerHeight'));
    await b.click('.cg-chat-latest');
    await b.until('historyLog.scrollHeight-historyLog.scrollTop-historyLog.clientHeight<2');
    await b.shoot('norm-ui-phone.png');
    // 上面收起过，同一件不再自己打开；再点开
    assert(await b.evaluate('document.querySelector(".cg-ask button").getBoundingClientRect().bottom<=innerHeight && document.querySelector(".cg-chat-form").getBoundingClientRect().bottom<=innerHeight'));
    const msgsBefore = await b.evaluate('document.querySelectorAll(".cg-msg").length');
    await b.click('.cg-ask button'); await b.until('!document.querySelector(".cg-ask")');
    // 定了以后在对话里说一句，agent 接着做
    await b.until('document.querySelectorAll(".cg-msg").length===' + (msgsBefore + 1));
    assert(posts.some(x => x.url.endsWith('/messages') && /我定了：继续/.test(x.body.message)));
    await b.fill('.cg-chat-form textarea', '新问题');
    await b.send('Input.dispatchKeyEvent', {type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13});
    await b.until('document.querySelectorAll(".cg-msg").length===' + (msgsBefore + 2));
    assert(posts.some(x => x.url.endsWith('/messages') && x.body.message === '新问题'));
    checks.push('390px has no page overflow; composer visible, jump-to-latest works, decisions remain usable, Enter sends through existing API.');
    for (const key of ['self', 'stages', 'branches', 'compare', 'doc', 'about']) { await view(key); assert(await b.noOverflow()); if (key === 'self') await b.shoot('norm-ui-phone-self.png'); }
    await view('relations'); await b.click('.cg-rel-mini'); assert(await b.noOverflow());
    assert(await b.evaluate('(() => {const r=document.querySelector("#cg-map-full .cg-node-project").getBoundingClientRect();return r.left>=0 && r.right<=innerWidth})()'));
    await b.shoot('norm-ui-phone-map.png'); await b.click('#cg-map-close');
    checks.push('All work views and relation dialog open/return at 390px without page overflow.');

    await view('conversation');
    assert(await b.evaluate('document.querySelector(".cg-usage").textContent.includes("Claude 额度") && document.querySelector(".cg-usage").textContent.includes("缓存命中 84%")'));
    const lastUser = await b.evaluate('[...document.querySelectorAll(".cg-msg-you")].pop().dataset.messageId');
    await b.evaluate('window.confirm=()=>true;[...document.querySelectorAll(".cg-msg-you")].pop().querySelector(".cg-msg-act").click()');
    await b.until('!!document.querySelector(".cg-msg-edit")');
    await b.evaluate('document.querySelector(".cg-msg-edit").value="改过的问题";document.querySelector(".cg-msg-edit-bar .cg-msg-act").click()');
    await b.until('[...document.querySelectorAll(".cg-msg-you")].pop().querySelector(".cg-msg-text").textContent==="改过的问题"');
    assert(posts.some(x => x.method === 'PUT' && x.url.endsWith('/messages/' + lastUser) && x.body.message === '改过的问题'));
    assert(posts.some(x => x.body.recall === Number(lastUser)));
    const before = await b.evaluate('document.querySelectorAll(".cg-msg").length');
    await b.evaluate('[...document.querySelectorAll(".cg-msg-you")].pop().querySelectorAll(".cg-msg-act")[1].click()');
    await b.until('document.querySelectorAll(".cg-msg").length===' + (before - 1));
    checks.push('Usage line shows quota and cache share; edit sends PUT then recall and shows the new text; delete removes the message.');

    // 一块被改过：显示谁什么时候改的，能换回改之前那一版
    await view('stages');
    assert(await b.evaluate('document.querySelector("#cg-view-stages .cg-version").textContent.includes("Claude")'));
    await b.evaluate('window.confirm=()=>true;document.querySelector("#cg-view-stages .cg-version button").click()');
    await b.until('document.querySelector("#cg-view-stages").textContent.includes("换回来的阶段") && !document.querySelector("#cg-view-stages .cg-version")');
    assert(posts.some(x => x.url.endsWith('/part-version') && x.body.version === 9));
    checks.push('A changed part shows who changed it and can be restored to the version before.');

    // 读过自己以后：进来先看总观；依据点了到历史里那一条；原话在这里展开、能到那段对话里看前后
    Object.assign(project, {overview, history_meta: {version: 2, stages: 2, entries: 4, unplaced: 1, to: 1793995000, updated_at: 1794000500}});
    drift = null; unread = {items: 0, chars: 0, chunks: 0, rounds: 0, runs: 0, from: null, to: null, total: 1300};
    await b.desktop(); await b.go(url);
    await b.until('(s => !!s && !s.hidden && s.textContent.includes("第 2 版"))(document.querySelector("#cg-view-overview"))');
    assert(await b.evaluate('document.querySelector("#cg-space-views [data-view=overview]").getAttribute("aria-pressed")==="true" && document.querySelector("#cg-space-conversation").hidden'));
    assert(await b.evaluate('(s => s.querySelector(".cg-ov-now").textContent.includes("让 AI 和你一起想事情") && s.querySelector(".cg-ov-line dl").textContent.includes("等你的额度刷新") && s.querySelector(".cg-ov-your").textContent.includes("只有你能合") && s.textContent.includes("先跑一次全读") && s.textContent.includes("原话读到"))(document.querySelector("#cg-view-overview"))'));
    // 原话都读过了、还有一条没归段：可以只合一次
    assert(await b.evaluate('(s => s.textContent.includes("原话都读过了") && [...s.querySelectorAll(".cg-read-box button")].some(x => x.textContent.includes("把还没归段的 1 条合起来")))(document.querySelector("#cg-view-overview"))'));
    await b.shoot('norm-ui-overview.png');
    await b.click('#cg-view-overview .cg-ov-line .cg-ref');
    await b.until('(s => !!s && !s.hidden && !!document.querySelector("#cg-h-103.cg-entry-found"))(document.querySelector("#cg-view-history"))');
    assert(await b.evaluate('(s => s.querySelectorAll(".cg-stage").length===3 && s.querySelector(".cg-stage-now h3").textContent==="读者和认领" && s.textContent.includes("现在这一段") && s.querySelector(".cg-stage-fix").textContent.includes("后来读到最早那句") && s.querySelector(".cg-stage-fix").textContent.includes("那时我想做项目管理") && s.textContent.includes("没写为什么，没改") && s.querySelector(".cg-stage-turn").textContent.includes("你说入口只要两部分") && s.querySelector(".cg-stage-turn .cg-quote").textContent.includes("入口只要两部分") && s.textContent.includes("还没归进哪一段的 1 条") && s.textContent.includes("这一条没指到原话") && s.textContent.includes("不做看板：太重（后来补的）"))(document.querySelector("#cg-view-history"))'));
    assert(await b.evaluate('!document.querySelector("#cg-view-history .cg-stage details").open && document.querySelector("#cg-view-history .cg-stage-now details").open'));
    checks.push('With an overview the space opens on 总观 (lines with where they stop and what they wait for, what only you decide, next step, evidence links); everything read and one entry unplaced offers a merge-only run.');
    // 第一段的条目：引文旁边点「原话」，在这里展开整句，能到那段对话里看前后
    await b.click('#cg-view-history .cg-stage summary');
    await b.click('#cg-h-101 .cg-orig-btn');
    await b.until('(x => !!x && x.textContent.includes("能排期，也能记住") && x.querySelector("a").getAttribute("href")==="' + app + '/creations/42#m900" && x.querySelector("a").target==="_blank")(document.querySelector("#cg-h-101 .cg-orig-box"))');
    await b.click('#cg-h-102 .cg-entry-refs .cg-orig-btn');
    await b.until('(x => !!x && !!x.querySelector("strong") && !x.textContent.includes("**"))(document.querySelector("#cg-h-102 .cg-entry-refs .cg-orig-box"))');
    await b.click('#cg-h-103 .cg-entry-refs .cg-orig:nth-of-type(2) .cg-orig-btn');
    await b.until('(x => !!x && x.textContent.includes("连到这里"))(document.querySelector("#cg-h-103 .cg-entry-refs .cg-orig:nth-of-type(2) .cg-orig-box"))');
    await b.shoot('norm-ui-history.png');
    // 这里的对话里的一句：到对话那一栏，停在那句上
    await b.click('#cg-h-103 .cg-entry-refs .cg-orig-btn');
    await b.until('!!document.querySelector("#cg-h-103 .cg-orig-box a")');
    assert.equal(await b.evaluate('document.querySelector("#cg-h-103 .cg-orig-box a").getAttribute("href")'), '#m5');
    await b.click('#cg-h-103 .cg-orig-box a');
    await b.until('!document.querySelector("#cg-space-conversation").hidden && !!document.querySelector(\'.cg-msg[data-message-id="5"].cg-msg-found\')');
    assert(await b.evaluate('(r => r.top >= document.querySelector(".cg-chat-log").getBoundingClientRect().top - 2 && r.bottom <= innerHeight)(document.querySelector(\'.cg-msg[data-message-id="5"]\').getBoundingClientRect())'));
    checks.push('History shows stages in order with the current one open, a correction with what it was and why, a refused silent change, the turn with its quote, dropped paths, unplaced and unsourced entries; originals expand in place (Markdown for AI), Initium ones link to /creations/42#m900 in a new tab, this space\'s own lines jump to the conversation and highlight it.');
    await b.click('#cg-space-views [data-view="overview"]');
    await b.click('#cg-view-overview .cg-read-box button');
    const merged = x => x.url.endsWith('/norm/projects/fixture/read') && x.body.mode === 'merge';
    for (let i = 0; i < 50 && !posts.some(merged); i++) await live.sleep(100);
    assert(posts.some(merged), '只合一次没发 mode=merge');
    await b.phone(); await b.go(url);
    await b.until('!!document.querySelector("#cg-view-overview .cg-ov-now")');
    for (const key of ['overview', 'history']) {
      await view(key);
      if (key === 'history') await b.until('!!document.querySelector("#cg-view-history .cg-stage-now")');
      assert(await b.noOverflow(), key + ' overflows at 390px');
      await b.shoot('norm-ui-phone-' + key + '.png');
    }
    checks.push('Merge-only run posts mode=merge; 总观 and 历史 have no page overflow at 390px.');
    // 正在读：说读到哪了、这一轮读完以后还有几块
    drift = {id: 8, kind: 'drift', state: 'reading', created_at: 1794000600, result: {final: false, plan: [{label: '第 1 块', role: 'past'}], run: {chunks: 5, items: 900, from: 1793900000, to: 1793990000, rest_chunks: 2}}};
    await b.desktop(); await b.go(url);
    await b.until('(s => !!s && s.textContent.includes("后面还有 2 块，下一轮再读") && s.querySelector(".cg-read-box button").disabled)(document.querySelector("#cg-view-overview"))');
    drift = null; delete project.overview; delete project.history_meta;
    unread = {items: 1200, chars: 380000, chunks: 7, rounds: 2, runs: 9, from: 1793900000, to: 1794000000, total: 1300};
    checks.push('While reading, the page says which days it is reading and how many chunks are left for the next round.');

    // 撤回了的空间：说清楚不在 Norm 里，可以放回
    project.state = 'withdrawn';
    await b.go(url); await b.until('!!document.querySelector("#cg-space-top .cg-btn-gold")');
    assert(await b.evaluate('document.querySelector("#cg-space-top").textContent.includes("撤回")'));
    await b.click('#cg-space-top .cg-btn-gold');
    await b.until('!document.querySelector("#cg-space-top").textContent.includes("已经撤回")');
    assert(posts.some(x => x.url.endsWith('/restore')));
    checks.push('A withdrawn space says so and can be put back into Norm.');

    messages = []; events = []; arrived.items = []; project.parts = []; project.board = null; project.way = {}; project.self = null; project.self_history = []; drift = null;
    await b.go(url); await b.until('!!window.testChat && !!document.querySelector(".cg-chat-log .cg-hint")');
    assert(await b.evaluate('!document.querySelector(".cg-ask") && !document.querySelector("#cg-space-conversation").hidden'));
    assert(await b.noOverflow());
    await view('self');
    assert(await b.evaluate('document.querySelector("#cg-view-self .cg-self-act button").textContent==="让它读自己全部的原话" && document.querySelector("#cg-view-self").textContent.includes("读完要 9 次运行，分 2 轮") && !document.querySelector("#cg-view-self .cg-self-older")'));
    await view('overview');
    assert(await b.evaluate('(s => s.textContent.includes("它还没写过总观") && s.querySelector(".cg-read-box button").textContent==="让它读自己全部的原话")(document.querySelector("#cg-view-overview"))'));
    await view('history');
    assert(await b.evaluate('document.querySelector("#cg-view-history").textContent.includes("还没有。让它读一遍自己全部的原话")'));
    checks.push('Empty conversation, no decisions, and custom space with no supplied parts keep conversation available; a space that has not read itself offers to read all its originals (with the run count) from 自述, 总观 and 历史.');
    assert.deepEqual(b.errors, []);
  } finally { b?.close(); await new Promise(resolve => server.close(resolve)); }
});
