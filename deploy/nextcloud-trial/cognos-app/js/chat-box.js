/* 一个和 agent 说话的框：读一段对话的消息，发一句，等 agent 回。
 * 用法：CognosChatBox.mount(element, creationId, { placeholder, onChange })。onChange 在对话多了一条时调（agent 可能改了别的东西）。消息存在那段对话里，和创立空间的对话是同一种东西。 */
(function () {
  'use strict';
  const base = '/index.php/apps/cognos/creations/';
  function node(tag, text, cls) { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; }
  async function api(url, data, method) {
    const r = await fetch(url, { method: method || (data ? 'POST' : 'GET'), credentials: 'same-origin',
      headers: { Accept: 'application/json', requesttoken: OC.requestToken, ...(data ? { 'Content-Type': 'application/json' } : {}) },
      body: data ? JSON.stringify(data) : undefined });
    const body = await r.json().catch(() => ({}));
    return { ok: r.ok, body };
  }
  // agent 的回答多半是 Markdown：照格式显示（粗体、标题、列表、表格、代码、引用、链接与裸网址），不露出 ** 和 #。
  // 只建 DOM 节点、只用 textContent，不拼 HTML，回答里有什么尖括号都只是字。
  function appendBareUrl(parent, raw) {
    let url = raw;
    let suffix = '';
    while (/[。，；：、？！.,;:!?]$/.test(url)) { suffix = url.slice(-1) + suffix; url = url.slice(0, -1); }
    while (url.endsWith(')') && (url.match(/\(/g) || []).length < (url.match(/\)/g) || []).length) {
      suffix = ')' + suffix; url = url.slice(0, -1);
    }
    while (url.endsWith(']') && (url.match(/\[/g) || []).length < (url.match(/\]/g) || []).length) {
      suffix = ']' + suffix; url = url.slice(0, -1);
    }
    if (!url || !/^https?:\/\/\S+/.test(url)) {
      parent.append(document.createTextNode(raw));
      return;
    }
    const a = node('a', url);
    a.href = url; a.target = '_blank'; a.rel = 'noopener noreferrer';
    parent.append(a);
    if (suffix) parent.append(document.createTextNode(suffix));
  }
  function inline(parent, text) {
    const re = /\*\*(.+?)\*\*|`([^`\n]+)`|\[([^\]\n]+)\]\((https?:\/\/[^)\s]+)\)|(https?:\/\/[^\s<>"'`\u3002\uff0c\uff1b\uff1a\u3001\uff1f\uff01]+)/g;
    let at = 0; let m;
    while ((m = re.exec(text))) {
      if (m.index > at) parent.append(document.createTextNode(text.slice(at, m.index)));
      // 粗体里还可以有代码、链接：接着往里拆
      if (m[1] !== undefined) { const b = node('strong'); inline(b, m[1]); parent.append(b); }
      else if (m[2] !== undefined) parent.append(node('code', m[2]));
      else if (m[3] !== undefined) { const a = node('a', m[3]); a.href = m[4]; a.target = '_blank'; a.rel = 'noopener noreferrer'; parent.append(a); }
      else appendBareUrl(parent, m[5]);
      at = re.lastIndex;
    }
    if (at < text.length) parent.append(document.createTextNode(text.slice(at)));
  }
  function splitTableRow(line) {
    let s = line.trim();
    if (s.startsWith('|')) s = s.slice(1);
    if (s.endsWith('|') && !s.endsWith('\\|')) s = s.slice(0, -1);
    const cells = []; let cur = '';
    for (let i = 0; i < s.length; i++) {
      if (s[i] === '\\' && s[i + 1] === '|') { cur += '|'; i++; continue; }
      if (s[i] === '|') { cells.push(cur.trim()); cur = ''; continue; }
      cur += s[i];
    }
    cells.push(cur.trim());
    return cells;
  }
  function parseTableAlign(line, cols) {
    if (!line || !line.includes('|')) return null;
    const raw = splitTableRow(line);
    if (raw.length < 1 || !raw.every(c => /^:?-{2,}:?$/.test(c))) return null;
    if (cols && raw.length !== cols && !(raw.length >= 2 && cols >= 2)) return null;
    return raw.map(c => (c.startsWith(':') && c.endsWith(':') ? 'center' : c.endsWith(':') ? 'right' : c.startsWith(':') ? 'left' : ''));
  }
  function md(text) {
    const out = document.createDocumentFragment();
    const lines = String(text || '').replace(/\r\n?/g, '\n').split('\n');
    let para = null; let list = null;
    const end = () => { para = null; list = null; };
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];
      if (/^\s*```/.test(line)) {
        const code = [];
        for (i++; i < lines.length && !/^\s*```/.test(lines[i]); i++) code.push(lines[i]);
        const pre = node('pre'); pre.append(node('code', code.join('\n'))); out.append(pre); end(); continue;
      }
      if (!line.trim()) { end(); continue; }
      const att = window.CognosFilePicker?.parseAttachmentLine?.(line);
      if (att) {
        end();
        out.append(window.CognosFilePicker.createAttachmentCard(att));
        continue;
      }
      if (line.includes('|') && i + 1 < lines.length) {
        const header = splitTableRow(line);
        const aligns = header.length >= 2 ? parseTableAlign(lines[i + 1], header.length) : null;
        if (aligns) {
          end();
          const wrap = node('div', undefined, 'cg-md-table-wrap');
          const table = node('table');
          const thead = node('thead'); const htr = node('tr');
          header.forEach((cell, c) => {
            const th = node('th');
            if (aligns[c]) th.style.textAlign = aligns[c];
            inline(th, cell); htr.append(th);
          });
          thead.append(htr); table.append(thead);
          const tbody = node('tbody');
          for (i += 2; i < lines.length; i++) {
            const rowLine = lines[i];
            if (!rowLine.trim() || !rowLine.includes('|') || /^\s*```/.test(rowLine)) { i--; break; }
            const cells = splitTableRow(rowLine);
            const tr = node('tr');
            const n = Math.max(header.length, cells.length);
            for (let c = 0; c < n; c++) {
              const td = node('td');
              if (aligns[c]) td.style.textAlign = aligns[c];
              inline(td, cells[c] || ''); tr.append(td);
            }
            tbody.append(tr);
          }
          table.append(tbody); wrap.append(table); out.append(wrap); continue;
        }
      }
      let m;
      if ((m = /^\s{0,3}#{1,6}\s+(.*)$/.exec(line))) { const h = node('p', undefined, 'cg-md-h'); inline(h, m[1].replace(/\s*#+\s*$/, '')); out.append(h); end(); continue; }
      if (/^\s*([-*_])(\s*\1){2,}\s*$/.test(line)) { out.append(node('hr')); end(); continue; }
      if ((m = /^\s*>\s?(.*)$/.exec(line))) { const q = node('blockquote'); inline(q, m[1]); out.append(q); end(); continue; }
      const ul = /^\s*[-*+]\s+(.*)$/.exec(line); const ol = /^\s*\d+[.)、]\s+(.*)$/.exec(line);
      if (ul || ol) {
        const tag = ul ? 'ul' : 'ol';
        if (!list || list.tagName.toLowerCase() !== tag) { list = node(tag); out.append(list); para = null; }
        const li = node('li'); inline(li, (ul || ol)[1]); list.append(li); continue;
      }
      if (list && /^\s{2,}\S/.test(line)) { const li = list.lastChild; li.append(document.createElement('br')); inline(li, line.trim()); continue; }
      if (!para) { para = node('p'); out.append(para); list = null; } else para.append(document.createElement('br'));
      inline(para, line);
    }
    return out;
  }
  window.CognosMd = { render: md };
  function hmShort(ts) {
    const n = Number(ts);
    if (!n) return '';
    const d = new Date(n * 1000);
    const now = new Date();
    const t = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    return d.toDateString() === now.toDateString() ? t : (d.getMonth() + 1) + '/' + d.getDate() + ' ' + t;
  }
  function whoName(a) { return !a ? 'Agent' : a === 'GPT' || a === 'Codex' ? 'GPT' : /claude|cognos/i.test(a) ? 'Claude' : a; }
  function mount(root, creationId, opts) {
    opts = opts || {};
    let count = -1;
    root.classList.add('cg-chat');
    const log = node('div', undefined, 'cg-chat-log'); log.setAttribute('role', 'log');
    log.tabIndex = 0; log.setAttribute('aria-label', '对话历史');
    const latest = node('button', '回到最新', 'cg-chat-latest'); latest.type = 'button'; latest.hidden = true;
    let followLatest = true; let readingTop = 0; let unseen = false;
    const rows = new Map();
    let pinnedHeight = 0;
    function showLatest() {
      followLatest = true; unseen = false;
      log.scrollTop = log.scrollHeight; readingTop = log.scrollTop; latest.hidden = true; pinnedHeight = log.clientHeight;
    }
    function updateLatest() {
      latest.hidden = followLatest;
      latest.textContent = unseen ? '有新消息 · 回到最新' : '回到最新';
    }
    log.addEventListener('scroll', () => {
      if (!log.clientHeight) return; // 切到另一个工作区时仍保留阅读位置
      // 停在最新时对话区刚变矮（下面多了额度、卡片）：这不是你往上翻，接着停在最新
      if (followLatest && log.clientHeight < pinnedHeight) { showLatest(); return; }
      readingTop = log.scrollTop;
      followLatest = log.scrollHeight - log.scrollTop - log.clientHeight < 48;
      if (followLatest) unseen = false;
      updateLatest();
    });
    latest.addEventListener('click', showLatest);
    // 对话区变矮（下面多出额度、卡片、状态一行）时，原来停在最新的继续停在最新
    if (window.ResizeObserver) new ResizeObserver(() => { if (followLatest && log.clientHeight) showLatest(); }).observe(log);
    const status = node('p', '', 'cg-chat-status'); status.setAttribute('aria-live', 'polite');
    const form = node('form', undefined, 'cg-chat-form');
    const input = node('textarea'); input.rows = 3; input.placeholder = opts.placeholder || '和 agent 说点什么（Enter 发送，Shift+Enter 换行）'; input.setAttribute('aria-label', '和 agent 说的话');
    const send = node('button', '发送'); send.type = 'submit';
    form.append(input, send);
    const filePicker = window.CognosFilePicker?.mount({ creationId, form, input }) || null;
    setTimeout(() => window.CognosAgentPick?.mount(form, creationId, { onImport: () => { followLatest = true; load(); } }), 0);
    const usageLine = node('div', '', 'cg-usage'); usageLine.hidden = true;
    // 「回到最新」放在对话里面、贴着底边浮着，不另占一行（消息都插在它前面）
    log.append(latest);
    const versions = node('a', '查看会话版本', 'cg-small');
    versions.href = base + creationId + '?versions=1';
    versions.hidden = true;
    root.append(log, status, form, usageLine, versions);
    let waiting = false; let timer = null; let editing = null;
    // 编辑和删除保存历史版本；保留未回答消息的重试入口。
    function actions(m, trailingUnanswered) {
      const acts = node('div', undefined, 'cg-msg-acts');
      if (m.role === 'user') {
        const edit = node('button', '改', 'cg-msg-act'); edit.type = 'button'; edit.title = '改这句并继续，改动前的对话会保存';
        edit.addEventListener('click', () => startEdit(m));
        acts.append(edit);
      }
      const del = node('button', '删', 'cg-msg-act'); del.type = 'button';
      del.title = m.role === 'user' ? '删掉这句和回它的回答' : '删掉这条回答';
      del.addEventListener('click', async () => {
        if (waiting || editing !== null) return;
        if (!window.confirm(m.role === 'user' ? '从当前对话收起这句和回它的回答？旧版本会保留。' : '从当前对话收起这条回答？旧版本会保留。')) return;
        const r = await api(base + creationId + '/messages/' + m.id, undefined, 'DELETE');
        status.textContent = r.ok ? '' : (r.body.message || '没删成');
        await load();
      });
      acts.append(del);
      if (trailingUnanswered) {
        const recall = node('button', '再叫一次', 'cg-msg-act cg-msg-recall');
        recall.type = 'button';
        recall.title = '这句还没人回，用它再叫一次 agent';
        recall.addEventListener('click', async () => {
          if (waiting || editing !== null) return;
          recall.disabled = true;
          followLatest = true;
          const again = await api(base + creationId + '/messages', { recall: m.id });
          status.textContent = again.ok ? '' : '没叫来 agent：' + (again.body.message || '');
          await load(); if (!waiting) setTimeout(load, 4000);
        });
        acts.append(recall);
      }
      return acts;
    }
    function startEdit(m) {
      if (waiting || editing !== null) return;
      const row = rows.get(m.id); if (!row) return;
      editing = m.id;
      const text = row.element.querySelector('.cg-msg-text');
      const area = node('textarea', undefined, 'cg-msg-edit'); area.value = m.content;
      area.rows = Math.min(14, Math.max(3, m.content.split('\n').length + 1));
      const ok = node('button', '发送', 'cg-msg-act'); ok.type = 'button';
      const cancel = node('button', '取消', 'cg-msg-act'); cancel.type = 'button';
      const bar = node('div', undefined, 'cg-msg-acts cg-msg-edit-bar'); bar.append(ok, cancel);
      text.replaceChildren(area, bar); area.focus();
      const stop = () => { editing = null; row.version = null; };
      cancel.addEventListener('click', () => { stop(); load(); });
      ok.addEventListener('click', async () => {
        const next = area.value.trim(); if (!next) return;
        ok.disabled = cancel.disabled = true;
        const r = await api(base + creationId + '/messages/' + m.id, { message: next }, 'PUT');
        stop();
        if (!r.ok) { status.textContent = r.body.message || '没改成'; await load(); return; }
        followLatest = true;
        const again = await api(base + creationId + '/messages', { recall: r.body.id });
        status.textContent = again.ok ? '' : '改好了，但没叫来 agent：' + (again.body.message || '');
        await load(); if (!waiting) setTimeout(load, 4000);
      });
    }
    function render(messages) {
      const visible = log.clientHeight > 0;
      const top = log.getBoundingClientRect().top;
      const anchor = visible && !followLatest ? [...log.children].find(b => b.getBoundingClientRect().bottom > top) : null;
      const offset = anchor ? anchor.getBoundingClientRect().top - top : 0;
      const present = new Set(); let changed = false;
      messages.forEach((m, i) => {
        const key = m.id ?? i; present.add(key);
        const trailingUnanswered = i === messages.length - 1 && m.role === 'user' && !waiting;
        const version = JSON.stringify([m.role, m.author, m.content, m.created_at || 0, trailingUnanswered]);
        let row = rows.get(key);
        if (!row) {
          const b = node('div'); b.dataset.messageId = key;
          const head = node('div', undefined, 'cg-msg-head');
          const who = node('span', '', 'cg-msg-who');
          const tm = node('time', '', 'cg-msg-time');
          head.append(who, tm);
          b.append(head, node('div', '', 'cg-msg-text'), node('div', undefined, 'cg-msg-acts'));
          row = { element: b }; rows.set(key, row);
        }
        if (row.version !== version && editing !== key) {
          row.version = version; changed = true;
          row.element.className = 'cg-msg ' + (m.role === 'user' ? 'cg-msg-you' : 'cg-msg-agent');
          row.element.querySelector('.cg-msg-who').textContent = m.role === 'user' ? '你' : whoName(m.author);
          const timeEl = row.element.querySelector('.cg-msg-time');
          const t = hmShort(m.created_at);
          timeEl.textContent = t;
          timeEl.hidden = !t;
          if (t && m.created_at) timeEl.dateTime = new Date(Number(m.created_at) * 1000).toISOString();
          // 你写的照原样显示（含 Nextcloud 附件卡片）；agent 的回答按 Markdown 显示
          const textBox = row.element.querySelector('.cg-msg-text');
          if (m.role === 'user') {
            textBox.classList.remove('cg-md');
            if (window.CognosFilePicker?.renderUserMessage) {
              textBox.replaceChildren(window.CognosFilePicker.renderUserMessage(m.content));
            } else {
              textBox.textContent = m.content;
            }
          } else {
            textBox.classList.add('cg-md');
            textBox.replaceChildren(md(m.content));
          }
          row.element.querySelector('.cg-msg-acts').replaceWith(actions(m, trailingUnanswered));
        }
        if (log.children[i] !== row.element) log.insertBefore(row.element, log.children[i] || null);
      });
      for (const [key, row] of rows) {
        if (!present.has(key)) { row.element.remove(); rows.delete(key); changed = true; }
      }
      log.querySelector('.cg-hint')?.remove();
      if (!messages.length) log.append(node('p', '还没有说过话。', 'cg-hint'));
      if (followLatest && visible) showLatest();
      else {
        if (changed && count >= 0) unseen = true;
        if (anchor?.isConnected) log.scrollTop += anchor.getBoundingClientRect().top - top - offset;
        if (visible) readingTop = log.scrollTop;
        updateLatest();
      }
    }
    async function load() {
      const { ok, body } = await api(base + creationId + '/messages?history=1');
      if (!ok) { status.textContent = body.message || '读不到这段对话'; return; }
      waiting = !!body.pending;
      versions.hidden = !body.revisions?.length;
      render(body.messages || []);
      window.CognosUsage?.show(usageLine, body.usage);
      const n = (body.messages || []).length;
      if (count >= 0 && n !== count && opts.onChange) opts.onChange();
      count = n;
      status.textContent = waiting ? (body.pending_who || 'Agent') + ' 在想，回答会出现在上面。' : '';
      clearTimeout(timer);
      if (waiting) timer = setTimeout(load, 4000);
    }
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const raw = input.value.trim();
      const hasAtt = !!filePicker?.hasAttachments();
      if (!raw && !hasAtt) return;
      const message = filePicker ? filePicker.buildMessage(raw) : raw;
      if (!message) return;
      send.disabled = true;
      try {
        const { ok, body } = await api(base + creationId + '/messages', { message });
        if (ok || (body.messages || []).length) {
          input.value = '';
          filePicker?.clear();
          followLatest = true;
        }
        status.textContent = ok ? '' : '没叫来 agent：' + (body.message || '') + '（你的话已经留下了）';
      } finally { send.disabled = false; await load(); if (!waiting) setTimeout(load, 4000); }
    });
    input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); } });
    // 历史里点「到对话里看」：停到那一句，闪一下
    const first = load();
    async function reveal(id) {
      await first;
      if (!rows.has(Number(id))) await load();
      const row = rows.get(Number(id));
      if (!row || !log.clientHeight) return false;
      followLatest = false;
      log.scrollTop += row.element.getBoundingClientRect().top - log.getBoundingClientRect().top - 24;
      readingTop = log.scrollTop;
      row.element.classList.add('cg-msg-found');
      setTimeout(() => row.element.classList.remove('cg-msg-found'), 2600);
      updateLatest();
      return true;
    }
    return { reload: load, reveal, show() {
      if (followLatest) showLatest(); else log.scrollTop = readingTop;
    } };
  }
  window.CognosChatBox = { mount };
})();
