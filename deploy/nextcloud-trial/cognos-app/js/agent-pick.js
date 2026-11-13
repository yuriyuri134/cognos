/* 对话框上方的「谁来回」：Claude、GPT（聊天端）、Codex。
 * 支持在已有对话上直接一键连接 Chrome 中的 ChatGPT 标签页：导入并绑定、同步新消息、只绑定网址或解除绑定。
 * 用法：CognosAgentPick.mount(放在哪个元素前面, creationId, { onImport })。 */
(function () {
  'use strict';
  const app = '/index.php/apps/cognos';
  const NAMES = { claude: 'Claude', chatgpt: 'GPT（聊天端）', gpt: 'Codex' };

  function el(tag, text, cls) {
    const n = document.createElement(tag);
    if (text !== undefined && text !== null) n.textContent = text;
    if (cls) n.className = cls;
    return n;
  }

  async function api(path, body) {
    const r = await fetch(app + path, {
      method: body ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', requesttoken: OC.requestToken, ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    });
    const json = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(json.message || ('HTTP ' + r.status));
    return json;
  }

  function isThreadUrl(url) {
    return /^https:\/\/chatgpt\.com\/(?:g\/[A-Za-z0-9-]+\/)?c\/[A-Za-z0-9-]+(?:\/.*)?$/i.test(String(url || '').trim());
  }

  function mount(before, creationId, opts) {
    opts = opts || {};
    if (!before || !creationId) return;
    before.parentNode.querySelector('.cg-agent-pick')?.remove();

    const box = el('div', undefined, 'cg-agent-pick');
    box.setAttribute('role', 'group');
    box.setAttribute('aria-label', '谁来回');

    const topRow = el('div', undefined, 'cg-agent-top');
    const label = el('span', '谁来回：', 'cg-agent-label');
    topRow.append(label);

    const buttons = {};
    let current = 'claude';
    let effort = 'default';
    let pending = true;
    let bound = null;
    let cgReady = false;
    let cgMessage = '';
    let tabsLoaded = false;
    let tabs = [];
    let drawerOpen = false;
    let busy = false;

    for (const k of Object.keys(NAMES)) {
      const b = el('button', NAMES[k], 'cg-agent-btn');
      b.type = 'button';
      b.addEventListener('click', async () => {
        if (k === current || pending) return;
        await save(k);
      });
      buttons[k] = b;
      topRow.append(b);
    }

    const effortLabel = el('label', '思考强度：', 'cg-agent-effort');
    const select = el('select');
    select.setAttribute('aria-label', '思考强度');
    let optionsFor = null;
    function options() {
      if (optionsFor === current) return;
      optionsFor = current;
      select.replaceChildren();
      const values = current === 'chatgpt'
        ? { default: '默认', low: '低', medium: '中', high: '高' }
        : { default: '默认', low: '低', medium: '中', high: '高', xhigh: '很高', max: '最高' };
      for (const [value, text] of Object.entries(values)) {
        const o = el('option', text);
        o.value = value;
        select.append(o);
      }
    }
    effortLabel.append(select);
    topRow.append(effortLabel);

    // 聊天端快捷条：已绑时显示「已绑 ChatGPT + 打开原页 + 同步新消息」，任何模式都可一键展开「聊天端导入/绑定」
    const boundBadge = el('span', '已绑 ChatGPT', 'cg-agent-bound-tag');
    boundBadge.hidden = true;
    const openOriginal = el('a', '打开原页', 'cg-agent-link');
    openOriginal.target = '_blank';
    openOriginal.rel = 'noopener noreferrer';
    openOriginal.hidden = true;
    const syncQuickBtn = el('button', '同步新消息', 'cg-agent-btn cg-agent-action');
    syncQuickBtn.type = 'button';
    syncQuickBtn.hidden = true;

    const drawerToggle = el('button', '导入 ▾', 'cg-agent-btn cg-agent-drawer-btn');
    drawerToggle.type = 'button';
    drawerToggle.setAttribute('aria-expanded', 'false');

    const statusBadge = el('span', '', 'cg-agent-conn');
    statusBadge.setAttribute('role', 'status');

    topRow.append(boundBadge, openOriginal, syncQuickBtn, drawerToggle, statusBadge);
    box.append(topRow);

    // 可展开的 ChatGPT 聊天端面板：列标签页 / 粘贴链接 / 导入并绑定 / 同步 / 解绑
    const drawer = el('div', undefined, 'cg-agent-drawer');
    drawer.hidden = true;

    const connRow = el('div', undefined, 'cg-agent-drawer-row');
    const connText = el('span', '尚未检查 Chrome 聊天端连接', 'cg-agent-drawer-status');
    const connectBtn = el('button', '连接现有 Chrome', 'cg-agent-btn');
    connectBtn.type = 'button';
    const refreshTabsBtn = el('button', '刷新标签页', 'cg-agent-btn');
    refreshTabsBtn.type = 'button';
    connRow.append(connText, connectBtn, refreshTabsBtn);

    const switchWrap = el('label', undefined, 'cg-agent-switch-opt');
    const switchCb = document.createElement('input');
    switchCb.type = 'checkbox';
    switchWrap.append(switchCb, document.createTextNode(' 导入或绑定后切到 GPT（聊天端）继续回'));

    const tabsBox = el('div', undefined, 'cg-agent-tabs');
    const pasteRow = el('div', undefined, 'cg-agent-paste');
    const urlInput = document.createElement('input');
    urlInput.type = 'url';
    urlInput.placeholder = '粘贴 https://chatgpt.com/c/... 链接';
    urlInput.setAttribute('aria-label', 'ChatGPT 对话网址');
    const pasteImportBtn = el('button', '导入并绑定', 'cg-agent-btn cg-agent-btn-primary');
    pasteImportBtn.type = 'button';
    const pasteBindBtn = el('button', '只绑定', 'cg-agent-btn');
    pasteBindBtn.type = 'button';
    pasteRow.append(urlInput, pasteImportBtn, pasteBindBtn);

    const boundRow = el('div', undefined, 'cg-agent-bound-row');
    boundRow.hidden = true;

    const drawerMsg = el('div', '', 'cg-agent-drawer-msg');
    drawerMsg.setAttribute('role', 'status');

    drawer.append(connRow, switchWrap, tabsBox, pasteRow, boundRow, drawerMsg);
    box.append(drawer);

    function show() {
      Object.entries(buttons).forEach(([k, b]) => {
        b.disabled = pending;
        b.classList.toggle('cg-on', k === current);
        b.setAttribute('aria-pressed', k === current ? 'true' : 'false');
      });
      options();
      if (!Array.from(select.options).some(o => o.value === effort)) effort = 'default';
      select.value = effort;
      select.disabled = pending;
      effortLabel.hidden = current === 'claude';

      const hasBound = !!(bound && bound.url);
      boundBadge.hidden = !hasBound;
      openOriginal.hidden = !hasBound;
      syncQuickBtn.hidden = !hasBound;
      if (hasBound) openOriginal.href = bound.url;

      drawerToggle.textContent = drawerOpen ? '收起 ▴' : (hasBound ? '管理绑定 ▾' : '绑定/导入 ▾');
      drawerToggle.setAttribute('aria-expanded', drawerOpen ? 'true' : 'false');
      drawerToggle.classList.toggle('cg-on', drawerOpen);
      drawer.hidden = !drawerOpen;

      if (current === 'chatgpt' && !hasBound) {
        statusBadge.textContent = cgMessage ? cgMessage : '（发送时将自动新开 ChatGPT 对话，或点击右侧绑定已有标签页）';
        statusBadge.hidden = false;
      } else if (current === 'chatgpt' && cgMessage && !cgReady) {
        statusBadge.textContent = cgMessage;
        statusBadge.hidden = false;
      } else {
        statusBadge.textContent = '';
        statusBadge.hidden = true;
      }

      switchWrap.hidden = current === 'chatgpt';
      renderDrawer();
    }

    function notifyImported(res) {
      if (typeof opts.onImport === 'function') opts.onImport(res);
      window.CognosCreationDetail?.reload?.(res);
    }

    function renderDrawer() {
      if (!drawerOpen) return;
      connText.textContent = cgMessage || (cgReady ? 'Chrome 已连接（ChatGPT 已登录）' : '点击「连接现有 Chrome」读取已打开的 ChatGPT 标签页');
      connectBtn.disabled = busy;
      refreshTabsBtn.disabled = busy;
      pasteImportBtn.disabled = busy;
      pasteBindBtn.disabled = busy;
      urlInput.disabled = busy;

      tabsBox.replaceChildren();
      if (!tabsLoaded) {
        tabsBox.append(el('div', '正在读取 Chrome 中的 ChatGPT 标签页…', 'cg-agent-drawer-hint'));
      } else if (!tabs.length) {
        tabsBox.append(el('div', '当前 Chrome 里没有打开 chatgpt.com 标签页，可以直接粘贴下方对话链接，或先在 Chrome 中打开对话后点「刷新标签页」。', 'cg-agent-drawer-hint'));
      } else {
        tabsBox.append(el('div', 'Chrome 里已打开的 ChatGPT 标签页：', 'cg-agent-drawer-sub'));
        const list = el('div', undefined, 'cg-agent-tab-list');
        for (const t of tabs) {
          const row = el('div', undefined, 'cg-agent-tab-item');
          const info = el('div', undefined, 'cg-agent-tab-info');
          const title = el('strong', t.title || t.url || 'ChatGPT 标签页');
          const u = el('span', t.url, 'cg-agent-tab-url');
          info.append(title, u);
          row.append(info);

          const acts = el('div', undefined, 'cg-agent-tab-acts');
          const isThread = isThreadUrl(t.url);
          const isCurrentBound = bound && bound.url && t.url && bound.url === t.url.replace(/[?#].*$/, '').replace(/\/+$/, '');
          if (isCurrentBound) {
            acts.append(el('span', '当前已绑', 'cg-agent-bound-tag'));
            const pullB = el('button', '同步新消息', 'cg-agent-btn cg-agent-btn-primary');
            pullB.type = 'button';
            pullB.disabled = busy;
            pullB.addEventListener('click', () => runThread('pull', { pageId: t.pageId, url: t.url }));
            acts.append(pullB);
          } else if (isThread) {
            const impB = el('button', '导入并绑定', 'cg-agent-btn cg-agent-btn-primary');
            impB.type = 'button';
            impB.disabled = busy;
            impB.addEventListener('click', () => runThread('import_bind', { pageId: t.pageId, url: t.url }));
            const bindB = el('button', '只绑定', 'cg-agent-btn');
            bindB.type = 'button';
            bindB.disabled = busy;
            bindB.addEventListener('click', () => runThread('bind_only', { url: t.url }));
            acts.append(impB, bindB);
          } else {
            acts.append(el('span', '新聊天页（先在网页点进具体会话）', 'cg-agent-drawer-hint'));
          }
          row.append(acts);
          list.append(row);
        }
        tabsBox.append(list);
      }

      boundRow.replaceChildren();
      if (bound && bound.url) {
        boundRow.hidden = false;
        const head = el('div', undefined, 'cg-agent-bound-info');
        head.append(el('span', '当前已绑定：'));
        const a = el('a', bound.url);
        a.href = bound.url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        head.append(a);
        const acts = el('div', undefined, 'cg-agent-bound-acts');
        const pullBtn = el('button', '同步新消息', 'cg-agent-btn cg-agent-btn-primary');
        pullBtn.type = 'button';
        pullBtn.disabled = busy;
        pullBtn.addEventListener('click', () => runThread('pull', { url: bound.url }));
        const unbindBtn = el('button', '解除绑定', 'cg-agent-btn');
        unbindBtn.type = 'button';
        unbindBtn.disabled = busy;
        unbindBtn.addEventListener('click', unbindThread);
        acts.append(pullBtn, unbindBtn);
        const hint = el('div', '解除绑定后，已经导入到 Cognos 的聊天内容会完整保留；之后选「GPT（聊天端）」发送时会在网页新开对话。', 'cg-agent-drawer-hint');
        boundRow.append(head, acts, hint);
      } else {
        boundRow.hidden = true;
      }
    }

    async function checkStatus(loadTabs) {
      try {
        if (loadTabs) {
          const d = await api('/creations/' + creationId + '/chatgpt/tabs');
          cgReady = !!d.ready;
          cgMessage = d.message || '';
          bound = d.bound || bound;
          tabs = Array.isArray(d.tabs) ? d.tabs : [];
          tabsLoaded = true;
        } else {
          const d = await api('/creations/' + creationId + '/agent?chatgpt_status=1');
          cgReady = !!(d.chatgpt && d.chatgpt.ready);
          cgMessage = (d.chatgpt && d.chatgpt.message) || '';
          bound = (d.chatgpt && d.chatgpt.bound) || bound;
        }
      } catch (e) {
        cgReady = false;
        cgMessage = '聊天端未连接：' + e.message;
        if (loadTabs) tabsLoaded = true;
      }
      show();
    }

    async function runThread(mode, payload) {
      if (busy) return;
      busy = true;
      drawerMsg.textContent = mode === 'bind_only' ? '正在绑定…' : mode === 'pull' ? '正在从 ChatGPT 网页读取最新消息…' : '正在读取 ChatGPT 对话并导入…';
      show();
      try {
        const res = await api('/creations/' + creationId + '/chatgpt/thread', Object.assign({ mode }, payload));
        bound = res.bound || bound;
        if (mode === 'bind_only') {
          drawerMsg.textContent = '已绑定。之后在 Cognos 里用「GPT（聊天端）」发消息会接着这条对话往下说。';
        } else {
          const n = Number(res.imported || 0);
          const s = Number(res.skipped || 0);
          drawerMsg.textContent = n > 0
            ? '已导入 ' + n + ' 条新消息' + (s > 0 ? '（跳过 ' + s + ' 条已有消息）' : '') + '，并已绑定原对话。'
            : '已是最新（共匹配 ' + s + ' 条已有消息），已同步绑定状态。';
          notifyImported(res);
        }
        if (switchCb.checked && current !== 'chatgpt') {
          await save('chatgpt');
        }
      } catch (e) {
        drawerMsg.textContent = '失败：' + e.message;
      } finally {
        busy = false;
        show();
      }
    }

    async function unbindThread() {
      if (busy) return;
      busy = true;
      drawerMsg.textContent = '正在解除绑定…';
      show();
      try {
        const res = await api('/creations/' + creationId + '/chatgpt/bind', { url: '' });
        bound = res.bound || null;
        drawerMsg.textContent = '已解除绑定，Cognos 中的历史消息保持不变。';
      } catch (e) {
        drawerMsg.textContent = '解除失败：' + e.message;
      } finally {
        busy = false;
        show();
      }
    }

    async function save(next, nextEffort = null) {
      const was = current;
      const previousEffort = effort;
      current = next;
      effort = nextEffort || 'default';
      pending = true;
      show();
      try {
        const d = await api('/creations/' + creationId + '/agent', {
          agent: current,
          ...(nextEffort === null ? {} : { reasoning_effort: nextEffort }),
        });
        current = d.agent;
        effort = d.reasoning_effort || 'default';
      } catch (e) {
        current = was;
        effort = previousEffort;
        alert('没保存：' + e.message);
      } finally {
        pending = false;
        show();
        if (current === 'chatgpt') checkStatus(drawerOpen);
      }
    }

    select.addEventListener('change', () => save(current, select.value));

    drawerToggle.addEventListener('click', () => {
      drawerOpen = !drawerOpen;
      show();
      if (drawerOpen && !tabsLoaded) checkStatus(true);
    });

    connectBtn.addEventListener('click', async () => {
      if (busy) return;
      busy = true;
      drawerMsg.textContent = '正在连接现有 Chrome…';
      show();
      try {
        const d = await api('/creations/' + creationId + '/agent', { agent: current, connect_chatgpt: true });
        if (d.chatgpt) {
          cgReady = !!d.chatgpt.ready;
          cgMessage = d.chatgpt.message || '';
          bound = d.chatgpt.bound || bound;
        }
        await checkStatus(true);
        drawerMsg.textContent = cgReady ? '已连接 Chrome。' : (cgMessage || '未连接');
      } catch (e) {
        drawerMsg.textContent = '连接失败：' + e.message;
      } finally {
        busy = false;
        show();
      }
    });

    refreshTabsBtn.addEventListener('click', async () => {
      if (busy) return;
      busy = true;
      drawerMsg.textContent = '正在刷新标签页…';
      show();
      try {
        await checkStatus(true);
        drawerMsg.textContent = '已刷新标签页列表。';
      } finally {
        busy = false;
        show();
      }
    });

    syncQuickBtn.addEventListener('click', () => {
      if (bound && bound.url) runThread('pull', { url: bound.url });
    });

    pasteImportBtn.addEventListener('click', () => {
      const u = urlInput.value.trim();
      if (!u) { drawerMsg.textContent = '请先粘贴 https://chatgpt.com/c/... 对话链接。'; return; }
      runThread('import_bind', { url: u });
    });

    pasteBindBtn.addEventListener('click', () => {
      const u = urlInput.value.trim();
      if (!u) { drawerMsg.textContent = '请先粘贴 https://chatgpt.com/c/... 对话链接。'; return; }
      runThread('bind_only', { url: u });
    });

    before.parentNode.insertBefore(box, before);
    show();

    api('/creations/' + creationId + '/agent?chatgpt_status=1')
      .then(d => {
        current = d.agent || 'claude';
        effort = d.reasoning_effort || 'default';
        if (d.chatgpt) {
          cgReady = !!d.chatgpt.ready;
          cgMessage = d.chatgpt.message || '';
          bound = d.chatgpt.bound || null;
        }
        pending = false;
        show();
      })
      .catch(e => {
        pending = false;
        statusBadge.textContent = '未能读取对话设置：' + e.message;
        statusBadge.hidden = false;
        show();
      });
  }

  window.CognosAgentPick = { mount };
})();

// 额度和用量：收成一行可点击展开的摘要，不挤占对话窗口高度
(function () {
  const hm = s => {
    const d = new Date(s * 1000);
    return d.toLocaleString('zh-CN', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  };
  const pct = x => Math.round((x || 0) * 100) + '%';

  function windows(name, l) {
    if (!l || !l.windows) return '';
    const w = l.windows;
    const parts = [];
    if (w.five_hour) parts.push('5 小时内用了 ' + pct(w.five_hour.used) + (w.five_hour.resets_at ? '（' + hm(w.five_hour.resets_at) + ' 重置）' : ''));
    if (w.seven_day) parts.push('这周 ' + pct(w.seven_day.used) + (w.seven_day.resets_at ? '（' + hm(w.seven_day.resets_at) + ' 重置）' : ''));
    return parts.length ? name + '：' + parts.join('，') + '，记于 ' + hm(l.seen_at) : '';
  }

  function summaryText(usage) {
    if (!usage) return '';
    const parts = [];
    const lim = usage.limits || {};
    if (lim.claude?.windows?.five_hour) parts.push('Claude 5h ' + pct(lim.claude.windows.five_hour.used));
    if (lim.codex?.windows?.five_hour) parts.push('Codex 5h ' + pct(lim.codex.windows.five_hour.used));
    const r = usage.last;
    if (r) {
      const all = (r.input || 0) + (r.cache_read || 0) + (r.cache_write || 0);
      parts.push('上次（' + (r.subject || r.executor) + '）读进 ' + all + ' · 命中 ' + (all ? Math.round(r.cache_read / all * 100) : 0) + '% · 输出 ' + (r.output || 0));
    }
    return parts.join(' ｜ ') || '查看额度和用量';
  }

  function text(usage) {
    if (!usage) return '';
    const lines = [];
    const lim = usage.limits || {};
    const c = windows('Claude 额度', lim.claude); if (c) lines.push(c);
    const g = windows('GPT（Codex）额度', lim.codex); if (g) lines.push(g);
    const r = usage.last;
    if (r) {
      const all = (r.input || 0) + (r.cache_read || 0) + (r.cache_write || 0);
      lines.push('这段对话上一次回话（' + (r.subject || r.executor) + '，' + hm(r.at) + '）：读进 ' + all + ' 个 token，其中缓存命中 '
        + (all ? Math.round(r.cache_read / all * 100) : 0) + '%，新写缓存 ' + (r.cache_write || 0) + '，输出 ' + (r.output || 0));
    }
    return lines.join('\n');
  }

  function show(el, usage) {
    if (!el) return;
    const t = text(usage);
    if (!t) {
      el.replaceChildren();
      delete el.dataset.usageFull;
      el.hidden = true;
      return;
    }
    if (el.dataset.usageFull === t && el.querySelector('details')) {
      el.hidden = false;
      return;
    }
    const wasOpen = !!el.querySelector('details')?.open;
    const det = document.createElement('details');
    det.className = 'cg-usage-details';
    if (wasOpen) det.open = true;
    const sum = document.createElement('summary');
    sum.textContent = summaryText(usage);
    const body = document.createElement('div');
    body.className = 'cg-usage-full';
    body.textContent = t;
    det.append(sum, body);
    el.dataset.usageFull = t;
    el.replaceChildren(det);
    el.hidden = false;
  }

  window.CognosUsage = { text, show };
})();
