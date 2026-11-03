(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const app = $('project-workspace');
  if (!app) return;
  const base = OC.generateUrl('/apps/cognos');
  const state = { location: null, project: null, sources: [], dirty: false, messages: [], lastMessages: '', conflict: null, sending: false, loadingMessages: false,
    decisionSource: null, decisionSupersedes: null, lastOverview: '' };
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
    await Promise.all([loadMessages(), loadSourceChoices(), loadOverview()]);
  }
  function day(t) { return t ? new Date(t * 1000).toLocaleDateString() : ''; }
  // 在生效的决定、谁在动什么：只显示有证据的，没有证据就照实说「尚无证据」
  async function loadOverview() {
    if (!state.project) return;
    const data = await api('/projects/' + state.project.id + '/overview?location_id=' + encodeURIComponent(state.location.id));
    const hash = JSON.stringify(data);
    if (hash === state.lastOverview) return;
    state.lastOverview = hash;
    renderDecisions(data.decisions);
    renderWho(data.activity);
    renderDelegations(data.delegations || []);
    await followRevision(data.revision);
    if (document.activeElement !== $('pw-handoff-text')) $('pw-handoff-text').value = data.handoff_text || '';
  }
  function decisionItem(d) {
    const li = node('li', undefined, 'pw-decision pw-decision-' + d.status);
    li.dataset.decisionId = d.id;
    li.append(node('blockquote', d.content));
    const scope = d.scope_titles.length ? '适用：' + d.scope_titles.join('、') + '（及其下面）' : '适用：整个项目';
    const meta = [d.author_name + ' · ' + day(d.said_at || d.created_at), scope];
    if (d.recorded_by_ai) meta.push('由 ' + d.recorded_by + ' 代录');
    if (d.source_message_id) meta.push('原话出自对话里的 #' + d.source_message_id);
    if (d.source_note) meta.push(d.source_note);
    li.append(node('p', meta.join(' · '), 'pw-hint'));
    if (d.status !== 'active') {
      li.append(node('p', (d.status === 'revoked' ? '已撤回 ' : '已被替代 ') + day(d.ended_at), 'pw-hint'));
      return li;
    }
    const reach = node('ul', undefined, 'pw-reach');
    for (const p of d.progress || []) {
      const parts = [];
      if (p.included_at) parts.push('进了输入（' + date(p.included_at) + '）');
      if (p.depended_at) parts.push('回应里依据了它（' + date(p.depended_at) + '）');
      reach.append(node('li', p.subject + '：' + (parts.length ? parts.join('；') : '尚无证据')));
    }
    reach.append(node('li', '网页会话等叫不醒的端：尚无证据', 'pw-hint'));
    li.append(reach);
    const actions = node('div', undefined, 'pw-inline');
    const revoke = node('button', '撤回'); revoke.type = 'button';
    revoke.addEventListener('click', () => busy(actions, async () => {
      if (!confirm('撤回这条决定？旧记录会留着，之后进来的参与者不再看到它。')) return;
      await mutate('/projects/decisions/' + d.id + '/revoke', 'POST', {});
      state.lastOverview = ''; await loadOverview();
      notice('已撤回。旧记录在「已撤回、被替代的」里。');
    }));
    const replace = node('button', '改写（替代）'); replace.type = 'button';
    replace.addEventListener('click', () => {
      openDecisionForm(d.content, null, d.id);
      $('pw-decision-mode').textContent = '登记后，原来那条标为被替代，旧记录留着。';
    });
    actions.append(revoke, replace);
    li.append(actions);
    return li;
  }
  function renderDecisions(decisions) {
    const active = decisions.filter(d => d.status === 'active');
    const ended = decisions.filter(d => d.status !== 'active');
    $('pw-decisions').replaceChildren(...active.map(decisionItem));
    if (!active.length) $('pw-decisions').append(node('li', '还没有登记在生效的决定。', 'pw-hint'));
    $('pw-decisions-ended').replaceChildren(...ended.map(decisionItem));
    $('pw-decisions-ended-box').hidden = !ended.length;
  }
  function renderWho(activity) {
    const box = $('pw-who'); box.replaceChildren();
    const states = {queued: '等待接下', claimed: '已接下', running: '正在做', result_pending: '结果待交回', unknown: '状态未知：不知道做没做完，不会自动重做'};
    if (!activity.runs.length) box.append(node('p', '现在没有参与者在这个项目里做事。', 'pw-hint'));
    for (const r of activity.runs) {
      const where = r.location ? '「' + r.location.title + '」' : '某处';
      box.append(node('p', r.subject + ' 在' + where + '：' + r.purpose + ' · ' + (states[r.state] || r.state) + ' · ' + date(r.created_at),
        'pw-run' + (r.state === 'unknown' ? ' pw-error' : '')));
    }
    for (const r of activity.recent.filter(r => r.decisions_missing.length)) {
      const where = r.location ? '「' + r.location.title + '」' : '某处';
      for (const d of r.decisions_missing) {
        box.append(node('p', r.subject + ' 在' + where + '交回的结果（' + date(r.updated_at) + '）：起步时还没有这条决定「' + d.content + '」。结果照收，没有自动用到共享内容。', 'pw-run pw-warn'));
      }
    }
    if (activity.edits.length) {
      box.append(node('h3', '最近改过的当前内容'));
      const list = node('ul');
      for (const e of activity.edits) {
        const li = node('li'); li.append(link(e.location.title || '某处', locationUrl(e.location.id)));
        li.append(document.createTextNode(' · 版本 ' + e.revision + ' · ' + e.editor + ' · ' + date(e.created_at)));
        list.append(li);
      }
      box.append(list);
    }
  }
  // 交出去的事：走到哪了、结果在哪、写成了哪一版或留着待比较的版本
  function renderDelegations(list) {
    const box = $('pw-delegations'); box.replaceChildren();
    const runStates = {queued: '等待接下', claimed: '已接下', running: '正在做', result_pending: '结果待交回', delivered: '已交回',
      failed: '没能开始', unknown: '不知道做没做完（失联），不会自动重做'};
    for (const d of list) {
      const li = node('li', undefined, 'pw-decision pw-delegation');
      li.dataset.delegationId = d.id;
      li.append(node('blockquote', d.ask));
      const meta = ['交出去 ' + date(d.created_at), '约定 ' + date(d.return_by) + ' 前回来',
        d.may_edit ? '可以改当前内容（起步时版本 ' + d.base_revision + '）' : '只要回答'];
      for (const [label, value] of [['要做成', d.goal], ['用', d.means], ['不要碰', d.forbidden]]) if (value) meta.push(label + '：' + value);
      li.append(node('p', meta.join(' · '), 'pw-hint'));
      let status;
      if (d.state === 'not_taken') status = '没交出去：' + (d.note || '桥没接下') + '。可以再交一次。';
      else if (d.state === 'returned') status = (d.subject || 'AI') + ' 交回了结果。';
      else status = (d.subject || 'Claude Code') + '：' + (runStates[d.run_state] || '正在交出去');
      if (d.overdue) status += ' 已过了约定的时间，还没回来。';
      li.append(node('p', status, 'pw-run pw-delegation-status' + (d.state === 'not_taken' || d.overdue || d.run_state === 'unknown' ? ' pw-warn' : '')));
      if (d.result_message_id) {
        const p = node('p'); p.append(link('看交回的回答', '#message-' + d.result_message_id)); li.append(p);
      }
      if (d.applied_revision) li.append(node('p', (d.subject || 'AI') + ' 写成了当前内容的版本 ' + d.applied_revision + '（署它的名字，旧版在修改经过里）。', 'pw-run pw-applied'));
      if (d.held_content !== null) {
        li.append(node('p', '没有覆盖：' + d.held_reason + '。它的版本留着，等你比较。', 'pw-run pw-warn pw-held'));
        const more = node('details'); more.append(node('summary', '看它的版本（基于版本 ' + d.held_base_revision + '）'));
        more.append(node('pre', d.held_content));
        const use = node('button', '放进编辑区比较'); use.type = 'button';
        use.addEventListener('click', () => {
          $('pw-content').value = d.held_content; dirty();
          notice('它的版本已放进编辑区，当前保存的仍是版本 ' + state.location.revision + '。比较、修改后再保存；不保存就不会改动。');
          $('pw-content').focus();
        });
        more.append(use); li.append(more);
      }
      box.append(li);
    }
  }
  // 别人（交出去的 AI）写了新版本：没有未保存的修改就跟上，有就只提醒
  async function followRevision(revision) {
    if (!revision || !state.location || revision <= state.location.revision) return;
    if (state.dirty) { notice('这里有了新版本 ' + revision + '；你的修改还没保存，保存时会先让你比较。'); return; }
    const latest = await api('/projects/locations/' + state.location.id + '/data');
    state.location = latest.location; state.sources = latest.source_message_ids;
    $('pw-content').value = latest.location.content; $('pw-revision').textContent = '版本 ' + latest.location.revision;
    showSources();
    notice('当前内容更新到版本 ' + latest.location.revision + '（' + latest.location.editor + ' 写的），旧版在修改经过里。');
    if ($('pw-history').open) await history();
  }
  function openDecisionForm(content, sourceMessageId, supersedes) {
    $('pw-decision-add').open = true;
    $('pw-decision-content').value = content;
    state.decisionSource = sourceMessageId; state.decisionSupersedes = supersedes;
    $('pw-decision-mode').textContent = sourceMessageId ? '原话出自对话里你说的 #' + sourceMessageId + '；可以删掉多余的部分，但不要改写。' : '';
    $('pw-decision-content').focus();
  }
  function resetDecisionForm() {
    $('pw-decision-content').value = ''; $('pw-decision-note').value = '';
    state.decisionSource = null; state.decisionSupersedes = null; $('pw-decision-mode').textContent = '';
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
          item.append(add);
          if (m.role === 'user') {
            const decide = node('button', '登记为决定');
            decide.type = 'button';
            decide.addEventListener('click', () => openDecisionForm(m.content, m.id, null));
            item.append(decide);
          }
          list.append(item);
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
  $('pw-decision-form').addEventListener('submit', e => {
    e.preventDefault(); busy(e.currentTarget, async () => {
      const scope = document.querySelector('input[name="pw-decision-scope"]:checked').value;
      const data = {content: $('pw-decision-content').value, scope, location_id: state.location.id,
        source_note: $('pw-decision-note').value};
      if (state.decisionSource) data.source_message_id = state.decisionSource;
      if (state.decisionSupersedes) data.supersedes = state.decisionSupersedes;
      await mutate('/projects/' + state.project.id + '/decisions', 'POST', data);
      resetDecisionForm(); $('pw-decision-add').open = false;
      state.lastOverview = ''; await loadOverview();
      notice('决定已登记。之后进来的参与者，入口最前面会看到它。');
    });
  });
  $('pw-decision-reset').addEventListener('click', resetDecisionForm);
  $('pw-delegate-form').addEventListener('submit', e => {
    e.preventDefault(); busy(e.currentTarget, async () => {
      if ($('pw-delegate-edit').checked && state.dirty) { notice('当前内容有没保存的修改，先保存，再交出去。', true); return; }
      const r = await mutate('/projects/locations/' + state.location.id + '/delegations', 'POST', {
        ask: $('pw-delegate-ask').value, goal: $('pw-delegate-goal').value, means: $('pw-delegate-means').value,
        forbidden: $('pw-delegate-forbidden').value, return_minutes: Number($('pw-delegate-return').value),
        may_edit: $('pw-delegate-edit').checked});
      $('pw-delegate-ask').value = ''; $('pw-delegate-goal').value = ''; $('pw-delegate-means').value = ''; $('pw-delegate-forbidden').value = '';
      state.lastOverview = ''; await Promise.all([loadOverview(), loadMessages()]);
      notice(r.state === 'not_taken' ? '记下了，但没交出去：' + r.note : '交出去了。可以走开，结果会回到这里。', r.state === 'not_taken');
    });
  });
  $('pw-handoff-copy').addEventListener('click', async () => {
    try { await navigator.clipboard.writeText($('pw-handoff-text').value); notice('已复制。'); }
    catch { $('pw-handoff-text').select(); notice('请按 Ctrl+C 复制选中的文字。'); }
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
    if (!document.hidden && state.location && !state.sending) {
      loadMessages().catch(e => notice('暂时无法刷新讨论：' + e.message, true));
      loadOverview().catch(e => notice('暂时无法刷新决定和进展：' + e.message, true));
    }
  }, 5000);
  init().catch(e => notice(e.message, true));
})();
