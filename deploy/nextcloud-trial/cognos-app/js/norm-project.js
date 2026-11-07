/* Norm 空间默认读对话；动态按需展开，演变、拆解等在同一个主工作区切换。 */
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const root = $('cg-space'); if (!root) return;
  const app = '/index.php/apps/cognos';
  const id = root.dataset.projectId;
  const SVG = 'http://www.w3.org/2000/svg';
  const WAY = [['away', '你不在的时候'], ['progress', '怎样算往前走了'], ['ask', '什么时候找你']];
  let data = null; let arrived = null; let chat = null; let editingWay = false; let readTimer = null;
  let currentView = 'conversation'; let viewKeys = ''; let topVersion = '';
  const openNotices = new Set();
  const viewScroll = new Map();

  function node(tag, text, cls) { const n = document.createElement(tag); if (text !== undefined && text !== null) n.textContent = text; if (cls) n.className = cls; return n; }
  function svg(tag, attrs, text) { const n = document.createElementNS(SVG, tag); for (const k in attrs) n.setAttribute(k, attrs[k]); if (text !== undefined) n.textContent = text; return n; }
  function button(text, cls, onClick) { const b = node('button', text, 'cg-btn ' + (cls || '')); b.type = 'button'; b.addEventListener('click', onClick); return b; }
  async function api(path, body) {
    const r = await fetch(app + path, { method: body ? 'POST' : 'GET', credentials: 'same-origin',
      headers: { Accept: 'application/json', requesttoken: OC.requestToken, ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined });
    const json = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(json.message || ('HTTP ' + r.status));
    return json;
  }
  function hm(ts) { const d = new Date(ts * 1000); const now = new Date(); const t = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); return d.toDateString() === now.toDateString() ? t : (d.getMonth() + 1) + '/' + d.getDate() + ' ' + t; }
  function away(since) {
    if (!since) return '这里发生过的';
    const h = Math.round((Date.now() / 1000 - since) / 3600);
    return h < 1 ? '你刚才离开以后' : h < 48 ? '你不在的这 ' + h + ' 小时' : '你不在的这 ' + Math.round(h / 24) + ' 天';
  }

  async function load(first) {
    try {
      data = await api('/norm/projects/' + id + '/data' + (first ? '?arrive=1' : ''));
    } catch (e) { $('cg-space-title').textContent = e.message; return; }
    // 你不在时发生的，只在刚打开时算一次；之后刷新不把它们记成看过，也不让它们消失
    if (first) arrived = data.arrive;
    render();
    if (!chat && data.conversation_id) {
      chat = window.CognosChatBox.mount($('cg-space-chat'), data.conversation_id, { placeholder: '接着在「' + data.project.title + '」里说……', onChange: () => load(false) });
      renderAsk();
    }
    clearTimeout(readTimer);
    if (data.drift && data.drift.state === 'reading') readTimer = setTimeout(() => load(false), 8000);
  }

  function render() {
    const p = data.project;
    document.title = p.title + ' - Cognos';
    window.CognosNormNav.render($('cg-nav'), data.spaces || [], { current: p.id });
    $('cg-space-title').textContent = p.title;
    const meta = [(p.is_main ? '主项目 · ' : '') + (p.board || '带着自己的做法')];
    if (data.links.length) meta.push('连着 ' + data.links.length + ' 段 Initium 对话');
    if (p.state !== 'active') meta.push(p.state === 'back' ? '已退回 Initium' : '已撤回');
    $('cg-space-meta').textContent = meta.join(' · ');
    renderTop(p); renderParts(p); renderViews(p); renderAsk();
  }

  function setView(key) {
    const changed = currentView !== key;
    if (changed) viewScroll.set(currentView, $('cg-space-parts').scrollTop);
    currentView = key;
    $('cg-space-conversation').hidden = key !== 'conversation';
    $('cg-space-parts').hidden = key === 'conversation';
    for (const panel of $('cg-space-parts').children) panel.hidden = panel.dataset.view !== key;
    for (const b of $('cg-space-views').children) b.setAttribute('aria-pressed', String(b.dataset.view === key));
    if (changed) $('cg-space-parts').scrollTop = viewScroll.get(key) || 0;
    if (key === 'conversation') chat?.show();
  }

  function renderViews(p) {
    const views = [['conversation', '对话'], ...p.parts.map(k => [k, data.parts[k] || k]), ['about', '做法与来源']];
    const keys = JSON.stringify(views);
    if (keys !== viewKeys) {
      viewKeys = keys;
      $('cg-space-views').replaceChildren(...views.map(([key, label]) => {
        const b = button(label, '', () => setView(key)); b.dataset.view = key;
        b.setAttribute('aria-controls', key === 'conversation' ? 'cg-space-conversation' : 'cg-view-' + key);
        return b;
      }));
    }
    if (!views.some(([key]) => key === currentView)) currentView = 'conversation';
    setView(currentView);
  }

  // 「正在发生」默认收起：只在你点开时展开。读者有建议时这一行标亮。等你定的事不在这里，在对话里（见 renderAsk）。
  function notice(key, label, content, highlight) {
    const details = node('details', undefined, 'cg-notice' + (highlight ? ' cg-notice-must' : ''));
    details.open = openNotices.has(key);
    details.append(node('summary', label), content);
    details.addEventListener('toggle', () => {
      if (details.isConnected) details.open ? openNotices.add(key) : openNotices.delete(key);
    });
    return details;
  }

  // ---- 上面：回头读、刚带着做法进来、你不在时、只有你能定的 ----
  function renderTop(p) {
    if (editingWay && $('cg-space-top').querySelector('textarea')) return;
    const version = JSON.stringify([p.state, arrived, data.drift, data.drift_done, p.board, data.messages < 2, p.way, p.parts, p.summary, editingWay]);
    if (version === topVersion) return;
    topVersion = version;
    const top = $('cg-space-top'); top.replaceChildren();
    if (p.state !== 'active') top.append(goneBlock(p));
    const content = node('div'); const labels = [];
    const a = arrived;
    if (a && a.items && a.items.length) {
      const box = node('section', undefined, 'cg-block'); box.setAttribute('aria-label', away(a.since));
      box.append(node('h2', away(a.since)));
      const list = node('div', undefined, 'cg-away-list');
      for (const e of a.items) {
        const row = node('div', undefined, 'cg-away-item');
        const t = node('time', hm(e.created_at)); t.dateTime = new Date(e.created_at * 1000).toISOString();
        const text = node('span', e.text); if (e.who) text.append(node('span', e.who, 'cg-away-who'));
        row.append(t, text); list.append(row);
      }
      box.append(list); content.append(box); labels.push(a.items.length + ' 条动态');
    }
    const drift = data.drift_done;
    const c = drift && drift.result && drift.result.compare;
    if (data.drift && data.drift.state === 'reading') { content.append(node('p', '读者正在回头读这里，读完会放在这里。', 'cg-block cg-hint')); labels.push('读者正在读'); }
    if (c && c.drifted && !(drift.applied && drift.applied.chosen !== undefined)) { content.append(driftBlock(drift, c, p)); labels.push('读者有新建议'); }
    if (!p.board && (data.messages || 0) < 2) { content.append(ownBlock(p)); labels.push('这个空间的做法'); }
    const suggest = !!(c && c.drifted && !(drift.applied && drift.applied.chosen !== undefined));
    if (labels.length) top.append(notice('activity', '正在发生 · ' + labels.join(' · '), content, suggest));
  }

  // 撤回了、退回 Initium 的空间：说清楚它现在不在 Norm 里，agent 在这里说话也不再当它是 Norm 空间；可以放回去。
  function goneBlock(p) {
    const box = node('section', undefined, 'cg-block');
    box.append(node('p', p.state === 'back'
      ? '它已经退回 Initium 重新长。这段对话现在在 Initium 里，agent 不再照这个空间的做法工作，读者也照 Initium 的对话读它。'
      : '它已经撤回，不在 Norm 里了。下面的内容都还在。'));
    const acts = node('div', undefined, 'cg-actions');
    acts.append(button('放回 Norm', 'cg-btn-gold', async () => {
      try { await api('/norm/projects/' + p.id + '/restore', {}); load(false); } catch (e) { alert(e.message); }
    }));
    if (p.state === 'back' && data.conversation_id) {
      const a = node('a', '去 Initium 里接着', 'cg-btn'); a.href = app + '/creations/' + data.conversation_id; acts.append(a);
    }
    box.append(acts); return box;
  }

  // ---- agent 问你的一件：放在对话里输入框上面，像它在对话里问你，不占半屏 ----
  let askVersion = '';
  function renderAsk() {
    const form = $('cg-space-chat')?.querySelector('.cg-chat-form');
    if (!form) return;
    const waiting = (data.events || []).filter(e => e.kind === 'decide' && e.answer === null).sort((x, y) => x.id - y.id);
    const version = JSON.stringify(waiting.map(e => e.id));
    const old = $('cg-space-chat').querySelector('.cg-ask');
    if (version === askVersion && (old || !waiting.length)) return;
    askVersion = version;
    old?.remove();
    if (waiting.length) form.parentNode.insertBefore(askCard(waiting[0], waiting.length), form);
    // 卡片出现或消失会改变对话区的高度：原来停在最新的，接着停在最新
    chat?.show();
  }

  async function answerAsk(e, answer, said) {
    try { await api('/norm/events/' + e.id + '/answer', { answer }); } catch (err) { alert(err.message); load(false); return; }
    // 在这个空间的对话里说一句：agent 马上知道，照你定的接着做，对话里也留下你定过什么
    if (data.conversation_id) {
      try { await api('/creations/' + data.conversation_id + '/messages', { message: said }); }
      catch (err) { /* 没叫来 agent 也不要紧：定下的已经记住，它下次进来会看到 */ }
      chat?.reload();
    }
    load(false);
  }

  function askCard(e, n) {
    const box = node('section', undefined, 'cg-ask'); box.setAttribute('aria-label', 'agent 问你');
    const what = e.text.length > 200 ? e.text.slice(0, 200) + '…' : e.text;
    const head = node('div', (e.who && e.who !== 'agent' ? e.who : 'agent') + ' 问你' + (n > 1 ? '（还有 ' + (n - 1) + ' 件）' : ''), 'cg-ask-who');
    const text = node('p', e.text, 'cg-ask-text');
    // 长的先收成三行，点一下看全
    text.addEventListener('click', () => text.classList.toggle('cg-ask-full'));
    const acts = node('div', undefined, 'cg-ask-acts');
    e.options.forEach((o, i) => acts.append(button(o, i === 0 ? 'cg-btn-dark cg-btn-small' : 'cg-btn-small', ev => {
      ev.target.disabled = true; answerAsk(e, o, '关于「' + what + '」，我定了：' + o + '。');
    })));
    // agent 拿来问你的，有时根本不该你定：划掉，并告诉它自己看着办
    const skip = button('这不用我定', 'cg-btn-small cg-ask-skip', ev => {
      ev.target.disabled = true; answerAsk(e, '不用我定', '关于「' + what + '」：这不用我定，你自己判断着做，做了告诉我。');
    });
    acts.append(skip);
    box.append(head, text, acts); return box;
  }

  function driftBlock(reading, c, p) {
    const box = node('section', undefined, 'cg-drift'); box.setAttribute('aria-label', '读者回头读');
    const head = node('div', undefined, 'cg-drift-head');
    head.append(node('div', '读者只读原话，不看这里是什么样子 · ' + hm(reading.created_at), 'cg-small'),
      node('h2', '「' + p.title + '」已经不太像进来时的样子了'), node('p', c.now || ''));
    const left = node('div', undefined, 'cg-block'); left.style.margin = '0';
    left.append(node('h2', '进来时 和 现在'));
    const tn = node('div', undefined, 'cg-then-now');
    const then = node('div', undefined, 'cg-then'); then.append(node('div', '进来时', 'cg-small'), node('div', c.then || ''));
    const now = node('div', undefined, 'cg-now'); now.append(node('div', '现在读到的', 'cg-small'), node('div', c.now || ''));
    tn.append(then, now); left.append(tn);
    if ((c.quotes || []).length) { left.append(node('div', '读者依据的几处原话', 'cg-small')); for (const q of c.quotes) left.append(node('div', '「' + q + '」', 'cg-quote')); }
    const right = node('div', undefined, 'cg-ways');
    right.append(node('h2', '它可以怎么接着活'));
    (c.options || []).forEach((o, i) => {
      const b = node('button', undefined, 'cg-way-opt' + (i === c.lean ? ' cg-lean' : '')); b.type = 'button';
      if (i === c.lean) b.append(node('span', '读者更倾向这个', 'cg-lean-tag'));
      b.append(node('strong', o.label || ''), node('span', o.detail || ''));
      b.addEventListener('click', async () => {
        let reason = '';
        if (o.kind === 'keep') { reason = prompt('说一句为什么，读者下次读的时候会带上：', '') || ''; }
        else if (!confirm('就这样：' + (o.label || '') + '？')) return;
        try { await api('/readers/' + reading.id + '/choose', { option: i, reason }); } catch (err) { alert(err.message); }
        // 退回 Initium：去它那段对话接着长
        if (o.kind === 'back') location.href = app + '/creations/' + (data.conversation_id || ''); else load(false);
      });
      right.append(b);
    });
    if (c.similar) right.append(node('div', c.similar, 'cg-similar'));
    box.append(head, left, right); return box;
  }

  function ownBlock(p) {
    const box = node('section', undefined, 'cg-own'); box.setAttribute('aria-label', '这个空间带着自己的做法');
    box.append(node('p', '现成的样子都装不下它，所以没有新建一种板块，只让这一个空间先带上自己的做法。' + (p.summary ? '它是什么：' + p.summary : '') + ' 等再有两三个空间长出相似的做法，才把它认成一种板块，到时候由你定。', 'cg-own-intro'));
    const parts = node('div', undefined, 'cg-block'); parts.style.margin = '0';
    parts.append(node('h2', '这个空间用哪几块'), node('p', '从现成的部件里挑，不另写一套页面。对话每个空间都有。', 'cg-small'));
    const chips = node('div', undefined, 'cg-chips');
    for (const [k, name] of Object.entries(data.parts)) chips.append(node('span', name, 'cg-chip' + (p.parts.includes(k) ? ' cg-chip-on' : '')));
    parts.append(chips);
    box.append(parts, howCard(p, true)); return box;
  }

  function howCard(p, withWithdraw) {
    const box = node('section', undefined, 'cg-how');
    box.append(node('h2', 'agent 在这里怎么工作'), node('p', editingWay ? '改完保存，agent 下次进来就照新的做。' : '读者写的，你可以直接改。改这段话，这个空间就跟着变。', 'cg-how-note'));
    const dl = node('dl'); dl.style.margin = '0';
    const inputs = {};
    for (const [k, label] of WAY) {
      dl.append(node('dt', label));
      const dd = node('dd');
      if (editingWay) { const t = node('textarea'); t.value = (p.way || {})[k] || ''; t.setAttribute('aria-label', label); inputs[k] = t; dd.append(t); }
      else dd.textContent = (p.way || {})[k] || '还没写';
      dl.append(dd);
    }
    box.append(dl);
    const acts = node('div', undefined, 'cg-actions');
    if (editingWay) {
      acts.append(button('保存', 'cg-btn-gold', async () => {
        const way = {}; for (const k in inputs) way[k] = inputs[k].value;
        try { await api('/norm/projects/' + p.id + '/way', { way }); editingWay = false; load(false); } catch (e) { alert(e.message); }
      }), button('不改了', '', () => { editingWay = false; render(); }));
    } else {
      acts.append(button('改这段话', '', () => { editingWay = true; render(); }));
      if (withWithdraw) acts.append(button('撤回，留在 Initium', '', async () => {
        if (!confirm('撤回这个空间？它不再在 Norm 里，原来的 Initium 对话还在。')) return;
        try { await api('/norm/projects/' + p.id + '/withdraw', {}); location.href = app + '/creations' + (data.links[0] ? '/' + data.links[0].creation_id : ''); } catch (e) { alert(e.message); }
      }));
    }
    box.append(acts); return box;
  }

  // ---- 主工作区里的几块；保留各视图的阅读和展开状态 ----
  function renderParts(p) {
    const aside = $('cg-space-parts');
    const contents = {};
    const versions = data.versions || {};
    for (const part of p.parts) contents[part] = JSON.stringify([p[part], versions[part] || []]);
    const hasWay = WAY.some(([k]) => (p.way || {})[k]);
    // 做法编辑期间，消息更新不覆盖正在输入的文本。
    contents.about = JSON.stringify([editingWay ? 'editing' : p.way, editingWay, p.board, data.messages < 2, data.drift, data.drift_done, data.links]);
    for (const panel of [...aside.children]) if (!(panel.dataset.view in contents)) panel.remove();
    for (const part of p.parts) {
      const previous = $('cg-view-' + part);
      if (previous && previous.dataset.version === contents[part]) continue;
      const sec = node('section'); const h = node('h2', data.parts[part] || part); sec.append(h);
      sec.id = 'cg-view-' + part; sec.dataset.view = part; sec.dataset.version = contents[part];
      if (part === 'stages') sec.append(stages(p.stages || []));
      else if (part === 'branches') sec.append(branches(p.branches || []));
      else if (part === 'relations') sec.append(relations(p.relations || {}, h));
      else if (part === 'compare') sec.append(compare(p.compare || []));
      else if (part === 'doc') {
        if (!p.doc) sec.append(node('p', '还没有。agent 会照这里的做法写。', 'cg-hint'));
        else { const d = node('div', undefined, 'cg-doc cg-md'); if (window.CognosMd) d.append(window.CognosMd.render(p.doc)); else d.textContent = p.doc; sec.append(d); }
      }
      const last = (versions[part] || [])[0];
      if (last) sec.append(versionLine(p, part, last, versions[part].length));
      if (previous) previous.replaceWith(sec); else aside.append(sec);
    }
    const previousAbout = $('cg-view-about');
    if (previousAbout && (previousAbout.dataset.version === contents.about || (editingWay && previousAbout.querySelector('textarea')))) return;
    const about = node('section'); about.id = 'cg-view-about'; about.dataset.view = 'about'; about.dataset.version = contents.about;
    about.append(node('h2', '做法与来源'));
    if (hasWay && (p.board || (data.messages || 0) >= 2)) about.append(howCard(p, false));
    const tail = node('section');
    tail.append(node('h2', '读者'));
    const d = data.drift;
    tail.append(node('p', d && d.state === 'reading' ? '正在回头读。' : d && d.state === 'failed' ? '上次没读完：' + (d.error || '') : data.drift_done ? '上次回头读在 ' + hm(data.drift_done.created_at) + (data.drift_done.result?.compare?.drifted ? '，觉得它变了样。' : '，觉得它还是进来时的样子。') : '还没回头读过。对话多起来以后会自己来读。', 'cg-hint'));
    tail.append(button('让读者现在回头读', 'cg-btn-small', async ev => {
      ev.target.disabled = true;
      try { const r = await api('/norm/projects/' + p.id + '/read', {}); if (!r.started) alert(r.why || '没读'); } catch (e) { alert(e.message); }
      load(false);
    }));
    about.append(tail);
    if (data.links.length) {
      const src = node('section', undefined, 'cg-sources'); src.append(node('h2', '从 Initium 来'));
      for (const l of data.links) { const a = node('a', l.title || ('对话 ' + l.creation_id)); a.href = app + '/creations/' + l.creation_id; src.append(a); }
      about.append(src);
    }
    if (previousAbout) previousAbout.replaceWith(about); else aside.append(about);
  }

  // 这一块上次是谁什么时候改的；改得不对可以换回改之前那一版（换回也会留一版，能再换回来）
  function versionLine(p, part, v, n) {
    const row = node('div', undefined, 'cg-version');
    row.append(node('span', (v.who || 'agent') + ' 在 ' + hm(v.created_at) + ' 改过这一块' + (n > 1 ? '（最近 ' + n + ' 次都留着）' : ''), 'cg-small'));
    row.append(button('换回改之前那一版', 'cg-btn-small', async () => {
      if (!confirm('把「' + (data.parts[part] || part) + '」换回 ' + hm(v.created_at) + ' 改之前的样子？现在这版也会留着。')) return;
      try { await api('/norm/projects/' + p.id + '/part-version', { version: v.id }); } catch (e) { alert(e.message); }
      load(false);
    }));
    return row;
  }

  function stages(list) {
    if (!list.length) return node('p', '还没有。', 'cg-hint');
    const box = node('div', undefined, 'cg-evo');
    for (const s of list) {
      if (s.proposed) {
        const it = node('div', undefined, 'cg-evo-proposed');
        it.append(node('div', (s.when ? s.when + ' · ' : '') + 'agent 提出，等你定', 'cg-small'), node('div', s.title || ''));
        if (s.text) it.append(node('p', s.text));
        box.append(it); continue;
      }
      const it = node('div', undefined, 'cg-evo-item' + (s.current ? ' cg-evo-now' : ''));
      it.append(node('span', (s.when ? s.when + ' · ' : ''), 'cg-small'), node('strong', s.title || ''));
      if (s.text) it.append(node('p', s.text));
      box.append(it);
    }
    return box;
  }

  function branches(list) {
    if (!list.length) return node('p', '还没有。', 'cg-hint');
    const box = node('div', undefined, 'cg-br');
    for (const b of list) {
      const d = node('details'); const s = node('summary');
      s.append(node('span', b.title || ''), node('span', String((b.parts || []).length), 'cg-small'));
      const ul = node('ul');
      for (const part of b.parts || []) { const li = node('li'); li.append(node('strong', part.title || ''), node('span', part.text ? '：' + part.text : '')); ul.append(li); }
      d.append(s, ul); box.append(d);
    }
    return box;
  }

  function compare(list) {
    if (!list.length) return node('p', '还没有并排的办法。agent 会照这里的做法放上来。', 'cg-hint');
    const box = node('div', undefined, 'cg-cmp');
    for (const c of list) {
      const it = node('div', undefined, 'cg-cmp-item');
      it.append(node('strong', c.title || ''));
      if (c.text) it.append(node('p', c.text));
      if (c.result) it.append(node('p', '试下来：' + c.result));
      if (c.state) it.append(node('span', c.state, 'cg-small'));
      box.append(it);
    }
    return box;
  }

  function relations(rel, heading) {
    if (!(rel.nodes || []).length) return node('p', '还没有。', 'cg-hint');
    const b = node('button', undefined, 'cg-rel-mini'); b.type = 'button'; b.setAttribute('aria-label', '展开关系图');
    b.append(map(rel, true), node('span', '点开铺满', 'cg-open-hint'));
    b.addEventListener('click', () => {
      const full = $('cg-map-full'); full.replaceChildren(map(rel, false));
      $('cg-map-overlay').showModal();
      full.scrollLeft = (full.scrollWidth - full.clientWidth) / 2;
      $('cg-map-close').focus();
    });
    return b;
  }

  // 中心是空间自己，第一圈是分支，第二圈是挂在分支下的尝试；别的线照画，带说明的画虚线
  function map(rel, mini) {
    const nodes = rel.nodes || []; const edges = rel.edges || [];
    const W = 900, H = 640, cx = W / 2, cy = H / 2;
    const center = nodes.find(n => n.kind === 'project') || nodes[0];
    const pos = { [center.id]: [cx, cy] };
    const ring1 = nodes.filter(n => n.kind === 'branch');
    ring1.forEach((n, i) => { const a = -Math.PI / 2 + i * 2 * Math.PI / ring1.length; pos[n.id] = [cx + 190 * Math.cos(a), cy + 160 * Math.sin(a)]; n._a = a; });
    const parentOf = {};
    for (const e of edges) if (!e.label && ring1.some(b => b.id === e.from)) parentOf[e.to] = e.from;
    const byParent = {};
    for (const n of nodes.filter(n => !pos[n.id])) (byParent[parentOf[n.id] || '_'] ||= []).push(n);
    for (const [pid, kids] of Object.entries(byParent)) {
      const pa = ring1.find(b => b.id === pid)?._a ?? 0;
      kids.forEach((n, i) => { const a = pa + (i - (kids.length - 1) / 2) * 0.42; pos[n.id] = [cx + 340 * Math.cos(a), cy + 280 * Math.sin(a)]; });
    }
    const s = svg('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': '关系图' });
    for (const e of edges) {
      const a = pos[e.from], b = pos[e.to]; if (!a || !b) continue;
      s.append(svg('line', { x1: a[0], y1: a[1], x2: b[0], y2: b[1], class: e.label ? 'cg-edge cg-edge-note' : 'cg-edge' }));
      if (e.label && !mini) s.append(svg('text', { x: (a[0] + b[0]) / 2, y: (a[1] + b[1]) / 2 - 4, class: 'cg-edge-label', 'text-anchor': 'middle' }, e.label));
    }
    for (const n of nodes) {
      const p = pos[n.id]; if (!p) continue;
      if (mini && n.kind !== 'project' && n.kind !== 'branch') { s.append(svg('circle', { cx: p[0], cy: p[1], r: 9, class: 'cg-edge', fill: 'none' })); continue; }
      const g = svg('g', { class: 'cg-node cg-node-' + (n.kind || 'attempt') });
      const fs = mini ? 2.2 : 1;
      const w = Math.max(70, [...String(n.label)].length * 14 * fs + 20);
      g.append(svg('rect', { x: p[0] - w / 2, y: p[1] - 18 * fs, width: w, height: (n.state && !mini ? 44 : 34) * fs, rx: 10 * fs }));
      g.append(svg('text', { x: p[0], y: p[1] + 5 * fs, 'text-anchor': 'middle', style: mini ? 'font-size:28px' : '' }, n.label));
      if (n.state && !mini) g.append(svg('text', { x: p[0], y: p[1] + 22, 'text-anchor': 'middle', class: 'cg-node-state' }, n.state));
      s.append(g);
    }
    return s;
  }

  $('cg-map-close').addEventListener('click', () => $('cg-map-overlay').close());
  $('cg-map-overlay').addEventListener('click', e => { if (e.target === $('cg-map-overlay')) $('cg-map-overlay').close(); });
  load(true);
})();
