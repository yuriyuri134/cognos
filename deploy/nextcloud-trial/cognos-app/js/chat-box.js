/* 一个和 agent 说话的框：读一段对话的消息，发一句，等 agent 回。
 * 用法：CognosChatBox.mount(element, creationId, { placeholder, onChange })。onChange 在对话多了一条时调（agent 可能改了别的东西）。消息存在那段对话里，和创立空间的对话是同一种东西。 */
(function () {
  'use strict';
  const base = '/index.php/apps/cognos/creations/';
  function node(tag, text, cls) { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; }
  async function api(url, data) {
    const r = await fetch(url, { method: data ? 'POST' : 'GET', credentials: 'same-origin',
      headers: { Accept: 'application/json', requesttoken: OC.requestToken, ...(data ? { 'Content-Type': 'application/json' } : {}) },
      body: data ? JSON.stringify(data) : undefined });
    const body = await r.json().catch(() => ({}));
    return { ok: r.ok, body };
  }
  function whoName(a) { return !a ? 'Agent' : a === 'GPT' || a === 'Codex' ? 'GPT' : /claude|cognos/i.test(a) ? 'Claude' : a; }
  function mount(root, creationId, opts) {
    opts = opts || {};
    let count = -1;
    root.classList.add('cg-chat');
    const log = node('div', undefined, 'cg-chat-log'); log.setAttribute('role', 'log');
    const status = node('p', '', 'cg-chat-status'); status.setAttribute('aria-live', 'polite');
    const form = node('form', undefined, 'cg-chat-form');
    const input = node('textarea'); input.rows = 3; input.placeholder = opts.placeholder || '和 agent 说点什么（Enter 发送，Shift+Enter 换行）'; input.setAttribute('aria-label', '和 agent 说的话');
    const send = node('button', '发送'); send.type = 'submit';
    form.append(input, send);
    setTimeout(() => window.CognosAgentPick?.mount(form, creationId), 0);
    root.append(log, status, form);
    let waiting = false; let timer = null;
    function render(messages) {
      log.replaceChildren();
      if (!messages.length) log.append(node('p', '还没有说过话。', 'cg-hint'));
      for (const m of messages) {
        const b = node('div', undefined, 'cg-msg ' + (m.role === 'user' ? 'cg-msg-you' : 'cg-msg-agent'));
        b.append(node('span', m.role === 'user' ? '你' : whoName(m.author), 'cg-msg-who'), node('div', m.content, 'cg-msg-text'));
        log.append(b);
      }
      log.scrollTop = log.scrollHeight;
    }
    async function load() {
      const { ok, body } = await api(base + creationId + '/messages');
      if (!ok) { status.textContent = body.message || '读不到这段对话'; return; }
      render(body.messages || []);
      const n = (body.messages || []).length;
      if (count >= 0 && n !== count && opts.onChange) opts.onChange();
      count = n;
      waiting = !!body.pending;
      status.textContent = waiting ? (body.pending_who || 'Agent') + ' 在想，回答会出现在上面。' : '';
      clearTimeout(timer);
      if (waiting) timer = setTimeout(load, 4000);
    }
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const message = input.value.trim(); if (!message) return;
      send.disabled = true;
      try {
        const { ok, body } = await api(base + creationId + '/messages', { message });
        if (ok || (body.messages || []).length) input.value = '';
        status.textContent = ok ? '' : '没叫来 agent：' + (body.message || '') + '（你的话已经留下了）';
      } finally { send.disabled = false; await load(); if (!waiting) setTimeout(load, 4000); }
    });
    input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });
    load();
    return { reload: load };
  }
  window.CognosChatBox = { mount };
})();
