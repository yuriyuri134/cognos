(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const app = $('project-workspace');
  if (!app) return;
  const base = OC.generateUrl('/apps/cognos');
  const state = { location: null, project: null, sources: [], dirty: false, messages: [], lastMessages: '', conflict: null, sending: false, loadingMessages: false };
  const requests = new Map();
  function node(tag, text, className) {
    const n = document.createElement(tag);
    if (text !== undefined) n.textContent = text;
    if (className) n.className = className;
    return n;
  }
  function link(text, href) { const a = node('a', text); a.href = href; return a; }
  function locationUrl(id) { return base + '/projects/locations/' + encodeURIComponent(id); }
  function notice(text, error = false) { $('pw-notice').textContent = text; $('pw-notice').classList.toggle('pw-error', error); }
  async function api(url, method = 'GET', data) {
    const r = await fetch(base + url, { method, credentials: 'same-origin',
      headers: { Accept: 'application/json', requesttoken: OC.requestToken, ...(data ? {'Content-Type': 'application/json'} : {}) },
      body: data ? JSON.stringify(data) : undefined });
    let body;
    try { body = await r.json(); } catch { throw new Error('未收到有效回复，请刷新检查保存结果。'); }
    if (!r.ok) { const error = new Error(body.message || '请求未完成'); error.status = r.status; error.body = body; throw error; }
    return body;
  }
  async function mutate(url, method, data) {
    const key = method + url + JSON.stringify(data);
    if (!requests.has(key)) requests.set(key, crypto.randomUUID());
    const result = await api(url, method, {...data, request_id: requests.get(key)});
    requests.delete(key);
    return result;
  }
  function date(t) { return new Date(t * 1000).toLocaleString(); }
  function dirty() { state.dirty = true; $('pw-dirty').textContent = '有未保存的修改'; }
  async function busy(form, work) {
    const buttons = [...form.querySelectorAll('button')]; buttons.forEach(b => { b.disabled = true; });
    try { await work(); } catch (e) { notice(e.message, true); }
    finally { buttons.forEach(b => { b.disabled = false; }); }
  }
  function showSources() {
    $('pw-content-sources').textContent = state.sources.length ? '此稿关联的原消息：' + state.sources.map(id => '#' + id).join('、') + '；原作者和原文保留在会话中。' : '';
  }
  async function init() {
    if (!app.dataset.projectId && !app.dataset.locationId) {
      const data = await api('/projects/data');
      $('pw-projects').replaceChildren(...data.projects.map(p => {
        const li = node('li'); li.append(link(p.title, base + '/projects/' + p.id)); return li;
      }));
      if (!data.projects.length) $('pw-projects').append(node('li', '还没有项目。可以先在创立空间开始讨论，也可以在这里直接确立。', 'pw-hint'));
      return;
    }
    let id = app.dataset.locationId;
    if (!id) id = (await api('/projects/' + app.dataset.projectId + '/data')).project.root_location_id;
    const data = await api('/projects/locations/' + id + '/data');
    state.location = data.location; state.project = data.project; state.sources = data.source_message_ids;
    $('pw-list-view').hidden = true; $('pw-work-view').hidden = false;
    $('pw-title').textContent = state.location.title; document.title = state.location.title + ' · Cognos';
    $('pw-content').value = state.location.content;
    $('pw-revision').textContent = '版本 ' + state.location.revision;
    $('pw-original-chat').href = base + '/creations/' + state.location.creation_id;
    renderTree(); renderUsages(data.usages); showSources();
    await Promise.all([loadMessages(), loadSourceChoices()]);
  }
  function renderTree() {
    const nav = $('pw-locations'); nav.replaceChildren();
    function branch(parent, depth) {
      for (const loc of state.project.locations.filter(l => l.parent_id === parent)) {
        const a = link(loc.title, locationUrl(loc.id));
        a.style.paddingInlineStart = (12 + depth * 14) + 'px';
        if (loc.id === state.location.id) a.setAttribute('aria-current', 'page');
        nav.append(a); branch(loc.id, depth + 1);
      }
    }
    branch(null, 0);
  }
  function renderUsages(usages) {
    const list = $('pw-usages'); list.replaceChildren();
    for (const u of usages) {
      const item = node('li'); item.append(link(u.title, base + '/creations/' + u.source_creation_id));
      if (u.purpose) item.append(node('p', u.purpose, 'pw-hint'));
      list.append(item);
    }
    if (!usages.length) list.append(node('li', '可以关联同一份会话，在这里保留独立的用途。', 'pw-hint'));
  }
  async function loadSourceChoices() {
    const data = await api('/creations/data');
    const select = $('pw-source');
    select.replaceChildren();
    const blank = node('option', '选择已有会话'); blank.value = ''; select.append(blank);
    for (const c of data.creations) {
      if (c.id === state.location.creation_id) continue;
      const option = node('option', c.title); option.value = String(c.id); select.append(option);
    }
  }
  async function loadMessages() {
    if (!state.location || state.loadingMessages) return;
    state.loadingMessages = true;
    try {
      const data = await api('/creations/' + state.location.creation_id + '/messages');
      state.messages = data.messages;
      const hash = JSON.stringify(data.messages);
      if (hash !== state.lastMessages) {
        state.lastMessages = hash;
        const list = $('pw-messages');
        const nearBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 100;
        list.replaceChildren();
        for (const m of data.messages) {
          const item = node('article', undefined, 'pw-message ' + (m.role === 'user' ? 'pw-user' : 'pw-assistant'));
          item.id = 'message-' + m.id;
          item.append(node('p', (m.role === 'user' ? '你' : 'AI') + ' · ' + date(m.created_at), 'pw-hint'));
          item.append(node('div', m.content, 'pw-message-body'));
          const add = node('button', '接入当前稿');
          add.type = 'button';
          add.addEventListener('click', () => {
            const current = $('pw-content').value;
            $('pw-content').value = current + (current ? '\n\n' : '') + m.content;
            if (!state.sources.includes(m.id)) state.sources.push(m.id);
            showSources(); dirty();
            notice('已放入编辑区，比较、修改后保存。原消息保留。');
            $('pw-content').focus();
          });
          item.append(add); list.append(item);
        }
        if (!data.messages.length) list.append(node('p', '这里的当前内容和关联材料会带入下一次讨论。', 'pw-hint'));
        if (nearBottom) list.scrollTop = list.scrollHeight;
      }
      const labels = {queued: '等待接下', claimed: '已接下', running: '正在进行', result_pending: '结果待交回', delivered: '结果已交回', failed: '未能开始', unknown: '状态未知，尚不能确认是否完成'};
      const runs = $('pw-runs'); runs.replaceChildren();
      for (const run of (data.runs || []).slice(0, 5)) {
        const p = node('p', run.subject + ' · ' + (labels[run.state] || run.state) + ' · ' + date(run.created_at),
          run.state === 'unknown' || run.state === 'failed' ? 'pw-run pw-error' : 'pw-run');
        runs.append(p);
      }
      $('pw-send').textContent = data.pending ? '发送（等当前回复后接着处理）' : '发送';
    } finally { state.loadingMessages = false; }
  }
  $('pw-create-form').addEventListener('submit', e => {
    e.preventDefault(); busy(e.currentTarget, async () => {
      const r = await mutate('/projects/data', 'POST', {title: $('pw-project-name').value});
      location.href = locationUrl(r.location_id);
    });
  });
  $('pw-location-form').addEventListener('submit', e => {
    e.preventDefault(); busy(e.currentTarget, async () => {
      if (state.dirty) { notice('请先保存当前内容，再从这里展开。', true); return; }
      const r = await mutate('/projects/' + state.project.id + '/locations', 'POST', {parent_id: state.location.id, title: $('pw-location-name').value});
      location.href = locationUrl(r.location_id);
    });
  });
  $('pw-content').addEventListener('input', dirty);
  $('pw-content-form').addEventListener('submit', e => {
    e.preventDefault(); busy(e.currentTarget, async () => {
      const content = $('pw-content').value, sources = [...state.sources];
      try {
        const r = await mutate('/projects/locations/' + state.location.id + '/content', 'PUT',
          {expected_revision: state.location.revision, content, source_message_ids: sources});
        state.location.revision = r.revision;
        state.location.content = content;
        state.dirty = $('pw-content').value !== content || JSON.stringify(state.sources) !== JSON.stringify(sources);
        $('pw-dirty').textContent = state.dirty ? '仍有未保存的修改' : '已保存';
        $('pw-revision').textContent = '版本 ' + r.revision;
        $('pw-conflict').hidden = true; state.conflict = null;
        notice('当前内容已保存，旧版仍在修改经过中。');
        if ($('pw-history').open) await history();
      } catch (error) {
        if (error.status === 409) {
          const latest = await api('/projects/locations/' + state.location.id + '/data');
          state.conflict = latest.location;
          $('pw-conflict-content').textContent = '当前已保存的版本 ' + latest.location.revision + '\n\n' + latest.location.content;
          $('pw-conflict').hidden = false;
        }
        throw error;
      }
    });
  });
  $('pw-rebase').addEventListener('click', () => {
    if (!state.conflict) return;
    state.location.revision = state.conflict.revision;
    $('pw-revision').textContent = '基于版本 ' + state.location.revision + ' 编辑';
    $('pw-conflict').hidden = true; state.conflict = null; dirty();
    notice('上面的文本保持原样，请完成比较与修改后保存。');
  });
  $('pw-usage-form').addEventListener('submit', e => {
    e.preventDefault(); busy(e.currentTarget, async () => {
      await mutate('/projects/locations/' + state.location.id + '/usages', 'POST',
        {source_creation_id: Number($('pw-source').value), purpose: $('pw-purpose').value});
      const data = await api('/projects/locations/' + state.location.id + '/data');
      renderUsages(data.usages); $('pw-purpose').value = '';
      notice('材料已关联；这处的用途单独保留，原会话仍在原处。');
    });
  });
  async function history(before = 0, append = false) {
    const data = await api('/projects/locations/' + state.location.id + '/history?before=' + before);
    const list = $('pw-history-list'); if (!append) list.replaceChildren();
    list.querySelector('.pw-more')?.remove();
    for (const r of data.revisions) {
      const b = node('button', '版本 ' + r.revision + ' · ' + r.editor + ' · ' + date(r.created_at));
      b.type = 'button'; b.addEventListener('click', async () => {
        try {
          const detail = await api('/projects/locations/' + state.location.id + '/history/' + r.revision);
          $('pw-history-content').textContent = detail.revision.content || '（此版尚无内容）';
        } catch (e) { notice(e.message, true); }
      });
      list.append(b);
    }
    if (data.revisions.length === 30) {
      const more = node('button', '更早的修改', 'pw-more'); more.type = 'button';
      more.addEventListener('click', () => history(data.revisions.at(-1).revision, true).catch(e => notice(e.message, true)));
      list.append(more);
    }
  }
  $('pw-history').addEventListener('toggle', () => { if ($('pw-history').open) history().catch(e => notice(e.message, true)); });
  $('pw-message-form').addEventListener('submit', e => {
    e.preventDefault();
    if (state.sending) return;
    busy(e.currentTarget, async () => {
      if (state.dirty) { notice('当前内容有未保存的修改，请先保存，让这次讨论能读到它。', true); return; }
      const message = $('pw-message').value.trim(); if (!message) return;
      state.sending = true;
      try {
        const result = await api('/creations/' + state.location.creation_id + '/messages', 'POST', {message});
        $('pw-message').value = ''; notice(result.queued ? '原话已保存，将在当前回复后继续处理。' : '原话已保存。');
      } catch (error) {
        if (error.body?.messages?.length) {
          $('pw-message').value = ''; notice('原话已保存，但这次未能叫来 AI：' + error.message, true);
        } else {
          notice('尚不能确认发送结果，请先检查下方会话，再决定是否重发。输入保留。', true);
        }
      } finally {
        state.sending = false;
        await loadMessages();
      }
    });
  });
  window.addEventListener('beforeunload', e => {
    if (state.dirty || $('pw-message').value) { e.preventDefault(); e.returnValue = ''; }
  });
  setInterval(() => {
    if (!document.hidden && state.location && !state.sending) loadMessages().catch(e => notice('暂时无法刷新讨论：' + e.message, true));
  }, 5000);
  init().catch(e => notice(e.message, true));
})();
