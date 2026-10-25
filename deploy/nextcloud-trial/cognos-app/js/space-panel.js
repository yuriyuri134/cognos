// 侧栏「这里」：一片空间的入口。和 Hermes 被叫进来时拿到的是同一份内容。
// 设计见项目文件 空间入口/第一片空间-实现说明.md 第五节。
(function() {
  'use strict';

  const KIND_LABELS = {
    intent: '意图',
    fact: '事实',
    feedback: '现实碰回来的',
    attempt: '尝试',
    material: '材料',
  };
  const STATUS_LABELS = { said: '说过', confirmed: '确认', rejected: '被否', withdrawn: '撤回' };

  const panel = {
    creationId: null,
    entry: null,
    root: null,
    body: null,
    collapsed: false,
  };

  function init(creationId) {
    panel.creationId = creationId;
    panel.root = document.getElementById('space-panel');
    panel.body = document.getElementById('space-panel-body');
    if (!panel.root || !panel.body) return;

    // 宽屏默认展开；窄屏它会盖住对话，默认收起
    panel.collapsed = window.innerWidth <= 1100;
    try {
      const stored = window.localStorage.getItem('cognos-space-panel-collapsed');
      if (stored !== null) panel.collapsed = stored === '1';
    } catch (e) { /* 存不了就用默认 */ }
    applyCollapsed();

    document.getElementById('space-panel-toggle')?.addEventListener('click', toggle);
    document.getElementById('space-panel-close')?.addEventListener('click', toggle);
    panel.body.addEventListener('click', handleClick);
    panel.body.addEventListener('submit', handleSubmit);
    reload();
  }

  function toggle() {
    panel.collapsed = !panel.collapsed;
    try {
      window.localStorage.setItem('cognos-space-panel-collapsed', panel.collapsed ? '1' : '0');
    } catch (e) { /* ignore */ }
    applyCollapsed();
  }

  function applyCollapsed() {
    document.querySelector('.cognos-chat-app')?.classList.toggle('space-panel-collapsed', panel.collapsed);
    document.getElementById('space-panel-toggle')?.setAttribute('aria-pressed', panel.collapsed ? 'false' : 'true');
  }

  async function reload() {
    if (!panel.body) return;
    try {
      const response = await fetch(`/index.php/apps/cognos/creations/${panel.creationId}/space`, {
        headers: { 'requesttoken': OC.requestToken },
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const data = await response.json();
      panel.entry = data.entry;
      render();
    } catch (error) {
      console.error('Load space error:', error);
      panel.body.innerHTML = '<p class="space-empty">这片空间没加载出来。</p>';
    }
  }

  function render() {
    const e = panel.entry;
    if (!e) return;
    const newIds = new Set((e.since?.traces || []).map(t => t.id));
    const parts = [];

    if (e.since && (e.since.traces.length || e.since.readings.length)) {
      parts.push(section('你上次来之后', `
        <p class="space-note">上次是 ${formatDate(e.since.last_seen)}。多了 ${e.since.traces.length} 条发生、${e.since.readings.length} 条读法，下面标「新」的就是。</p>
      `, 'space-section-since'));
    }

    parts.push(section('意图', list([...e.intents, ...e.intents_closed], t => traceItem(t, newIds)), '', '这里还没有写下意图。'));

    const attemptsNote = e.attempts_total > e.attempts.length
      ? `<p class="space-note">只列最近 ${e.attempts.length} 条，还有 ${e.attempts_total - e.attempts.length} 条。</p>` : '';
    parts.push(section('以前的尝试', attemptsNote + list(e.attempts, t => traceItem(t, newIds)), '', '还没有尝试。'));

    parts.push(section('事实和现实碰回来的', list([...e.facts, ...e.others], t => traceItem(t, newIds)), '', '还没有。'));

    parts.push(section('读法', list(e.readings, readingItem), '', '还没有谁留下读法。'));

    parts.push(section('空白', e.gaps.length
      ? `<ul class="space-gaps">${e.gaps.map(g => `<li>${escapeHtml(g)}</li>`).join('')}</ul>`
      : '<p class="space-note">没有算出空白。这不等于没有空白。</p>'));

    parts.push(section('还有谁在', e.subjects.length
      ? `<ul class="space-subjects">${e.subjects.map(s => `<li><strong>${escapeHtml(s.author)}</strong>：${escapeHtml(snippet(s.last.content, 50))}</li>`).join('')}</ul>`
      : '<p class="space-note">还没有谁在这里留下过东西。</p>'));

    parts.push(section('留一条', addForm()));

    parts.push(section('走之前', `
      <p class="space-note">这片空间请每个进来的主体（你和 agent 一样）：</p>
      <ul class="space-gaps">${e.before_leaving.map(l => `<li>${escapeHtml(l)}</li>`).join('')}</ul>
    `));

    panel.body.innerHTML = `
      <div class="space-where">
        <div class="space-where-title">${escapeHtml(e.space.kind_label)}「${escapeHtml(e.space.title)}」</div>
        ${e.purpose.length ? `<ul class="space-purpose">${e.purpose.map(p => `<li>${escapeHtml(p)}</li>`).join('')}</ul>` : ''}
      </div>
      ${parts.join('')}
      <p class="space-legend">〔原〕交互对象的原话　〔世〕现实碰回来的　〔AI〕某个 AI 推的</p>
    `;
  }

  function section(title, inner, extraClass = '', emptyText = '') {
    const content = inner && inner.trim() !== '' ? inner : `<p class="space-note">${escapeHtml(emptyText)}</p>`;
    return `<section class="space-section ${extraClass}"><h3 class="space-section-title">${escapeHtml(title)}</h3>${content}</section>`;
  }

  function list(items, render) {
    if (!items || !items.length) return '';
    return `<ul class="space-list">${items.map(render).join('')}</ul>`;
  }

  function sourceTag(t) {
    if (t.source === '原') return '<span class="space-tag space-tag-yuan">原</span>';
    if (t.source === '世') return '<span class="space-tag space-tag-shi">世</span>';
    const conf = t.confidence !== null && t.confidence !== undefined ? ` ${t.confidence}%` : '';
    return `<span class="space-tag space-tag-ai">AI · ${escapeHtml(t.author)}${conf}</span>`;
  }

  function traceItem(t, newIds) {
    const status = t.effective_status || t.status;
    const closed = status === 'rejected' || status === 'withdrawn';
    const meta = [];
    if (t.said_at) meta.push(formatDate(t.said_at));
    if (t.act) meta.push(escapeHtml(t.act));
    if (t.about) meta.push(t.kind === 'intent' ? `标为 ${escapeHtml(t.about)}` : `关于 ${escapeHtml(t.about)}`);
    if (t.stale) meta.push('可能已经变了');
    const attemptMeta = t.kind === 'attempt' ? `
      <div class="space-item-attempt">
        ${t.touched_reality === true ? '碰过现实' : t.touched_reality === false ? '只在模型上推过' : ''}
        ${t.stopped_at ? `<span>停在：${escapeHtml(t.stopped_at)}</span>` : '<span class="space-missing">没写停在哪</span>'}
      </div>` : '';
    const corrections = (t.corrections || []).map(c => `
      <div class="space-correction">→ ${escapeHtml(STATUS_LABELS[c.status] || c.status)}（${escapeHtml(c.source === 'AI' ? c.author : c.source)}）${escapeHtml(c.content)}</div>
    `).join('');
    return `
      <li class="space-item ${closed ? 'space-item-closed' : ''}" data-trace-id="${t.id}">
        <div class="space-item-head">
          ${newIds.has(t.id) ? '<span class="space-new">新</span>' : ''}
          ${sourceTag(t)}
          <span class="space-item-id">#${t.id}</span>
          ${status !== 'said' ? `<span class="space-status space-status-${status}">${escapeHtml(STATUS_LABELS[status] || status)}</span>` : ''}
        </div>
        <div class="space-item-content">${escapeHtml(t.content)}</div>
        ${meta.length ? `<div class="space-item-meta">${meta.join(' · ')}</div>` : ''}
        ${attemptMeta}
        ${corrections}
        <div class="space-item-actions">
          <button type="button" class="space-link-btn" data-action="confirm">确认</button>
          <button type="button" class="space-link-btn" data-action="reject">不对</button>
        </div>
      </li>
    `;
  }

  function readingItem(r) {
    const conf = r.confidence !== null && r.confidence !== undefined ? ` ${r.confidence}%` : '';
    const refs = r.refs && r.refs.length ? `<div class="space-item-meta">读的是 ${r.refs.map(id => `#${id}`).join(' ')}</div>` : '';
    return `
      <li class="space-item">
        <div class="space-item-head"><span class="space-tag space-tag-ai">读法 · ${escapeHtml(r.reader)}${conf}</span></div>
        <div class="space-item-content">${escapeHtml(r.content)}</div>
        ${refs}
      </li>
    `;
  }

  function addForm(prefill = '') {
    return `
      <form class="space-add-form" id="space-add-form">
        <div class="space-add-row">
          <select name="kind" aria-label="这是什么">
            <option value="intent">我的意图（原话）</option>
            <option value="fact">我给的事实</option>
            <option value="feedback">现实碰回来的</option>
            <option value="attempt">我做的一次尝试</option>
          </select>
          <input type="text" name="about" placeholder="关于哪个意图（可不填）" maxlength="255">
        </div>
        <textarea name="content" rows="3" placeholder="照原话写，不用整理" required>${escapeHtml(prefill)}</textarea>
        <input type="text" name="stopped_at" class="space-attempt-only" placeholder="尝试停在哪、为什么停（可不填）">
        <button type="submit" class="space-submit-btn">留下</button>
      </form>
    `;
  }

  async function post(body) {
    const response = await fetch(`/index.php/apps/cognos/creations/${panel.creationId}/space/traces`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
      body: JSON.stringify(body),
    });
    if (!response.ok) {
      const data = await response.json().catch(() => ({}));
      throw new Error(data.message || `HTTP ${response.status}`);
    }
    return response.json();
  }

  async function handleClick(event) {
    const button = event.target.closest('button[data-action]');
    if (!button) return;
    const item = button.closest('[data-trace-id]');
    if (!item) return;
    const refId = parseInt(item.dataset.traceId, 10);
    const action = button.dataset.action;

    if (action === 'confirm') {
      await correct(refId, 'confirmed', '确认');
      return;
    }
    if (action === 'reject') {
      if (item.querySelector('.space-reject-form')) return;
      item.insertAdjacentHTML('beforeend', `
        <form class="space-reject-form" data-ref-id="${refId}">
          <input type="text" name="reason" placeholder="哪里不对（可不填）">
          <button type="submit" class="space-link-btn">记下</button>
        </form>
      `);
      item.querySelector('.space-reject-form input')?.focus();
    }
  }

  async function correct(refId, status, content) {
    try {
      await post({ kind: 'correction', source: '原', ref_id: refId, status, content });
      await reload();
    } catch (error) {
      console.error('Correction error:', error);
      window.alert('没记下：' + error.message);
    }
  }

  async function handleSubmit(event) {
    const form = event.target;
    if (form.classList.contains('space-reject-form')) {
      event.preventDefault();
      const reason = form.querySelector('input[name="reason"]').value.trim();
      await correct(parseInt(form.dataset.refId, 10), 'rejected', reason || '不对');
      return;
    }
    if (form.id === 'space-add-form') {
      event.preventDefault();
      const data = new FormData(form);
      const kind = data.get('kind');
      const body = {
        kind,
        source: kind === 'feedback' ? '世' : '原',
        content: String(data.get('content') || '').trim(),
        about: String(data.get('about') || '').trim() || null,
      };
      if (kind === 'attempt') {
        body.stopped_at = String(data.get('stopped_at') || '').trim() || null;
        body.touched_reality = true;
      }
      if (!body.content) return;
      try {
        await post(body);
        await reload();
      } catch (error) {
        console.error('Add trace error:', error);
        window.alert('没留下：' + error.message);
      }
    }
  }

  // 对话里「留在空间里」：把这句原话放进下面的表单，由交互对象选它是意图还是事实
  function keep(content) {
    if (panel.collapsed) toggle();
    const form = document.getElementById('space-add-form');
    if (!form) return;
    form.querySelector('textarea[name="content"]').value = content;
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    form.querySelector('select[name="kind"]').focus();
  }

  function snippet(text, max) {
    const t = String(text || '').replace(/\s+/g, ' ').trim();
    return t.length > max ? t.slice(0, max) + '…' : t;
  }

  function formatDate(ts) {
    return new Date(ts * 1000).toLocaleString('zh-CN', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str === null || str === undefined ? '' : String(str);
    return div.innerHTML;
  }

  window.CognosSpacePanel = { init, reload, keep };
})();
