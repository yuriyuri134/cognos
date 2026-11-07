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
let drift = null;
let versions = {stages: [{id: 9, who: 'Claude', created_at: 1794000200}]};
const arrived = {since: 1794000000, items: [{created_at: 1794000100, text: '发生了变化', who: 'Claude'}]};
const posts = [];
const server = http.createServer(async (req, res) => {
  let raw = ''; for await (const chunk of req) raw += chunk;
  const body = raw ? JSON.parse(raw) : {};
  if (req.method === 'POST') posts.push({url: req.url, body});
  const url = new URL(req.url, 'http://localhost');
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  if (url.pathname === app + '/norm/projects/fixture/data') return res.end(JSON.stringify({project, conversation_id: 7, spaces: [project],
    parts: {stages: '演变', branches: '拆解', relations: '关系', compare: '并排对照', doc: '文档'}, messages: messages.length,
    links: [{creation_id: 42, title: 'Initium 原始讨论'}], events, arrive: arrived, drift, drift_done: null, versions}));
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
    for (const key of ['stages', 'branches', 'compare', 'doc', 'about']) { await view(key); assert(await b.noOverflow()); }
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

    // 撤回了的空间：说清楚不在 Norm 里，可以放回
    project.state = 'withdrawn';
    await b.go(url); await b.until('!!document.querySelector("#cg-space-top .cg-btn-gold")');
    assert(await b.evaluate('document.querySelector("#cg-space-top").textContent.includes("撤回")'));
    await b.click('#cg-space-top .cg-btn-gold');
    await b.until('!document.querySelector("#cg-space-top").textContent.includes("已经撤回")');
    assert(posts.some(x => x.url.endsWith('/restore')));
    checks.push('A withdrawn space says so and can be put back into Norm.');

    messages = []; events = []; arrived.items = []; project.parts = []; project.board = null; project.way = {};
    await b.go(url); await b.until('!!window.testChat && !!document.querySelector(".cg-chat-log .cg-hint")');
    assert(await b.evaluate('!document.querySelector(".cg-ask") && !document.querySelector("#cg-space-conversation").hidden'));
    assert(await b.noOverflow());
    checks.push('Empty conversation, no decisions, and custom space with no supplied parts keep conversation available.');
    assert.deepEqual(b.errors, []);
  } finally { b?.close(); await new Promise(resolve => server.close(resolve)); }
});
