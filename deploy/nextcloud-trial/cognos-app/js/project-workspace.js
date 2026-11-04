(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const app = $('project-workspace');
  if (!app) return;
  const base = OC.generateUrl('/apps/cognos');
  const state = { location: null, project: null, sources: [], dirty: false, messages: [], lastMessages: '', conflict: null, sending: false, loadingMessages: false,
    decisionSource: null, decisionSupersedes: null, lastOverview: '', mode: 'location', pending: null };
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
        const li = node('li'); li.append(link(p.title, base + '/projects/' + p.id));
        const into = p.merged_into && data.projects.find(x => x.id === p.merged_into);
        if (p.merged_into) li.append(node('span', '（已合并到「' + (into ? into.title : '另一个项目') + '」）', 'pw-hint'));
        return li;
      }));
      if (!data.projects.length) $('pw-projects').append(node('li', '还没有项目。可以先在创立空间开始讨论，也可以在这里直接确立。', 'pw-hint'));
      return;
    }
    let id = app.dataset.locationId;
    // 只有项目、没有位置：项目总览（在生效的决定、谁在动什么、各位置现在怎样），不拿根位置页顶替
    let own = null;
    if (!id) { state.mode = 'project'; own = (await api('/projects/' + app.dataset.projectId + '/data')).project; id = own.root_location_id; }
    const data = await api('/projects/locations/' + id + '/data');
    // 总览看的是这个项目本身：合并以后根位置已经在别的项目里，不能跟着它走
    state.location = data.location; state.project = own || data.project; state.sources = data.source_message_ids;
    $('pw-list-view').hidden = true; $('pw-work-view').hidden = false;
    $('pw-overview-link').href = base + '/projects/' + state.project.id;
    if (state.mode === 'project') {
      for (const n of document.querySelectorAll('[data-location-only]')) n.hidden = true;
      $('pw-overview').hidden = false;
      $('pw-overview-link').setAttribute('aria-current', 'page');
      $('pw-merge').hidden = false; loadMergeTargets().catch(e => notice(e.message, true));
      $('pw-location-name').previousElementSibling.textContent = '在项目下展开';
      $('pw-title').textContent = state.project.title; document.title = state.project.title + ' · Cognos';
      renderTree();
      await loadOverview();
      return;
    }
    $('pw-title').textContent = state.location.title; document.title = state.location.title + ' · Cognos';
    $('pw-content').value = state.location.content;
    $('pw-revision').textContent = '版本 ' + state.location.revision;
    $('pw-original-chat').href = base + '/creations/' + state.location.creation_id;
    renderTree(); renderUsages(data.usages); showSources(); fillMoveParents();
    await Promise.all([loadMessages(), loadSourceChoices(), loadOverview(), loadAdoptions()]);
  }
  function day(t) { return t ? new Date(t * 1000).toLocaleDateString() : ''; }
  // 在生效的决定、谁在动什么：只显示有证据的，没有证据就照实说「尚无证据」
  async function loadOverview() {
    if (!state.project) return;
    const data = await api('/projects/' + state.project.id + '/overview' + (state.mode === 'location' ? '?location_id=' + encodeURIComponent(state.location.id) : ''));
    const hash = JSON.stringify(data);
    if (hash === state.lastOverview) return;
    state.lastOverview = hash;
    if (data.project) { state.project = data.project; renderLifecycle(data.lifecycle_history || []); }
    renderDecisions(data.decisions);
    renderWho(data.activity);
    renderFollowups(data.followups || []);
    renderDispatchQueue(data.dispatch_queue || []);
    if (state.mode === 'location') renderHistory($('pw-location-history'), data.location_history || [], true);
    if (state.mode === 'project') {
      renderStates(data.locations, data.activity.runs); renderRecent(data.activity.recent, data.decisions); renderOrigin(data.formed_from || []);
      renderHistory($('pw-project-history'), data.history || [], false); $('pw-project-history-box').hidden = !(data.history || []).length;
      const merged = data.merged_into;
      $('pw-merged-banner').hidden = !merged;
      if (merged) {
        $('pw-merged-banner').replaceChildren(document.createTextNode('这个项目已于 ' + date(merged.merged_at) + ' 合并到 '), link('「' + merged.title + '」', base + '/projects/' + merged.project_id),
          document.createTextNode('。原来的位置都在那里，链接不变；这里的经过和来路照样可读。'));
        $('pw-merge').hidden = true;
      }
      return;
    }
    renderDelegations(data.delegations || []);
    await followRevision(data.revision);
    if (document.activeElement !== $('pw-handoff-text')) $('pw-handoff-text').value = data.handoff_text || '';
  }
  const lifecycleNames = {active:'正在推进',paused:'已暂停',ended:'已结束',exploring:'自由探索'};
  function renderLifecycle(history) {
    const current = state.project.state || 'active';
    $('pw-lifecycle-state').textContent = lifecycleNames[current] + (current === 'active' ? '。' : '。已停止派新工作；已在执行的参与者可能仍在运行，结果可以交回。');
    $('pw-explore-entry').hidden=current!=='exploring';
    const select = $('pw-lifecycle-action');
    if (select.dataset.current !== current) {
      select.replaceChildren();
      for (const [action,target,label] of [['pause','paused','暂停'],['end','ended','结束这一轮'],['explore','exploring','退回自由探索'],['resume','active','重新开始推进']]) {
        if (target !== current) { const option=node('option',label); option.value=action; select.append(option); }
      }
      select.dataset.current=current;
    }
    $('pw-lifecycle-form').hidden=!!state.project.merged_into;
    $('pw-lifecycle-history').replaceChildren(...history.map(h => node('li', date(h.created_at)+' · '+h.actor+'：'+lifecycleNames[h.from_state]+' → '+lifecycleNames[h.to_state]+(h.note?'；'+h.note:'')+(h.reopen_when?'；再开条件或未完成：'+h.reopen_when:''))));
  }
  $('pw-lifecycle-form').addEventListener('submit', e => {
    e.preventDefault();
    busy(e.currentTarget,async()=>{
      try {
        await mutate('/projects/'+state.project.id+'/lifecycle','POST',{action:$('pw-lifecycle-action').value,expected_revision:Number(state.project.lifecycle_revision),note:$('pw-lifecycle-note').value,reopen_when:$('pw-lifecycle-reopen').value});
        $('pw-lifecycle-note').value=''; $('pw-lifecycle-reopen').value=''; notice('推进安排已保存。');
      } finally { state.lastOverview=''; await loadOverview(); }
    });
  });
  function renderFollowups(items) {
    if(document.activeElement?.closest('#pw-followups form')) return;
    $('pw-followups-section').hidden=!items.length;
    const labels={pending:'待接续',claimed:'正在处理接续',attempted:'已尝试接续，执行情况看对应运行',cancelled:'本次接续已取消',unknown:'接续状态未知，先核对，不能直接重试',reconciled:'已核清并关闭本次接续'};
    const list=$('pw-followups');list.replaceChildren();
    for(const item of items){
      const li=node('li',undefined,'pw-decision'+(item.state==='unknown'?' pw-warn':''));li.dataset.resultId=item.result_id;
      li.append(node('p',labels[item.state]||item.state));
      const source=node('p');source.append(link('查看对应讨论',base+'/creations/'+item.space_id),document.createTextNode(' · '+date(item.updated_at)));li.append(source);
      if(item.note){const note=node('p',item.note);note.style.whiteSpace='pre-wrap';li.append(note);}
      if(['pending','unknown'].includes(item.state)){
        const form=node('form');const input=node('textarea');input.required=true;input.maxLength=2000;input.rows=2;input.id='pw-close-followup-'+item.result_id;
        const label=node('label',item.state==='unknown'?'核对了什么，为什么可以关闭':'为什么关闭这次接续');label.htmlFor=input.id;
        const button=node('button',item.state==='unknown'?'已核清，关闭本次接续':'关闭本次接续');button.type='submit';form.append(label,input,button);
        form.addEventListener('submit',e=>{e.preventDefault();busy(form,async()=>{
          try{await mutate('/projects/followups/'+item.result_id+'/close','POST',{expected_state:item.state,note:input.value});input.value='';notice('本次接续已关闭，没有重放，也没有改变实际运行状态。');}
          finally{document.activeElement?.blur();state.lastOverview='';await loadOverview();}
        });});li.append(form);
      }
      list.append(li);
    }
  }
  function renderDispatchQueue(items) {
    if(document.activeElement?.closest('#pw-dispatch-queue form'))return;
    $('pw-dispatch-section').hidden=!items.length;
    const labels={queued:'等待派发',claimed:'已领取，尚未确认派出',sent:'已派出',unknown:'派发状态未知，请先核对',skipped:'未派发，已跳过',reconciled:'已核清并关闭',cancelled:'已取消本次排队'};
    const runs={queued:'等待确认',running:'正在执行',unknown:'状态未知',delivered:'结果已交回',failed:'未能开始'};
    const list=$('pw-dispatch-queue');list.replaceChildren();
    for(const item of items){
      const li=node('li',undefined,'pw-decision'+(item.state==='unknown'?' pw-warn':''));li.dataset.queueId=item.id;
      li.append(node('p',(item.kind==='hit'?'反对 #':'消息 #')+item.ref_id+' · '+(labels[item.state]||item.state)+(item.run_state?' · 对应运行：'+(runs[item.run_state]||item.run_state):'')));
      li.append(link('查看原讨论',base+'/creations/'+item.space_id));
      if(item.note){const text=item.note.replace(/^运行 [a-f0-9]{32}：(running|delivered|failed|unknown)([^\n]*)/,(_,status,tail)=>'运行状态：'+(runs[status]||status)+tail);const note=node('p',text);note.style.whiteSpace='pre-wrap';li.append(note);}
      if(['queued','unknown'].includes(item.state)){
        const form=node('form');const input=node('textarea');input.required=true;input.rows=2;input.maxLength=2000;input.id='pw-queue-note-'+item.id;
        const label=node('label','核对或取消说明');label.htmlFor=input.id;
        const button=node('button',item.state==='unknown'?'已核清，关闭本次派发':'取消本次排队');button.type='submit';form.append(label,input,button);
        form.addEventListener('submit',e=>{e.preventDefault();busy(form,async()=>{
          try{await mutate('/projects/queue/'+item.id+'/close','POST',{creation_id:Number(item.space_id),expected_state:item.state,note:input.value});notice('队列记录已关闭，没有重放，也没有改变实际运行状态。');}
          finally{document.activeElement?.blur();state.lastOverview='';await loadOverview();}
        });});li.append(form);
      }
      list.append(li);
    }
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
      if (p.seen_before_return_at && !p.included_at) parts.push('起步时还没有，交回前看到了（' + date(p.seen_before_return_at) + '）');
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
    for (const r of activity.recent.filter(r => r.decisions_missing.length || (r.basis_changed || []).length)) {
      const where = r.location ? '「' + r.location.title + '」' : '某处';
      const seen = r.drift_seen_at ? '交回前它看到了这些差异。' : '';
      for (const d of r.decisions_missing) {
        box.append(node('p', r.subject + ' 在' + where + '交回的结果（' + date(r.updated_at) + '）：起步时还没有这条决定「' + d.content + '」。' + seen + '结果照收，没有自动用到共享内容。', 'pw-run pw-warn'));
      }
      for (const b of r.basis_changed || []) {
        box.append(node('p', r.subject + ' 在' + where + '交回的结果（' + date(r.updated_at) + '）：它依据的「' + b.title + '」当前内容版本 ' + b.version + '，交回时已经是版本 ' + b.current + '（' + b.editor + ' 改的）。' + seen + '结果照收。', 'pw-run pw-warn'));
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
      if (d.may_adopt) meta.push('可以在这里采用');
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
        const more = node('details'); more.append(node('summary', '和当前稿并排看（它基于版本 ' + d.held_base_revision + '）'));
        more.append(compareView('当前稿（版本 ' + state.location.revision + '）', state.location.content,
          (d.subject || 'AI') + ' 的版本（基于版本 ' + d.held_base_revision + '）', d.held_content));
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
  // 两版并排：逐行比，只在一边有的行标出来（左边删掉的、右边新加的）
  function compareView(leftTitle, left, rightTitle, right) {
    const a = left.split('\n'), b = right.split('\n');
    const n = a.length, m = b.length, keepA = new Array(n).fill(false), keepB = new Array(m).fill(false);
    if (n * m <= 4000000) {
      const dp = Array.from({length: n + 1}, () => new Uint32Array(m + 1));
      for (let i = n - 1; i >= 0; i--) for (let j = m - 1; j >= 0; j--)
        dp[i][j] = a[i] === b[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
      for (let i = 0, j = 0; i < n && j < m;) {
        if (a[i] === b[j]) { keepA[i++] = true; keepB[j++] = true; }
        else if (dp[i + 1][j] >= dp[i][j + 1]) i++; else j++;
      }
    }
    const box = node('div', undefined, 'pw-compare');
    for (const [title, lines, keep, cls] of [[leftTitle, a, keepA, 'pw-del'], [rightTitle, b, keepB, 'pw-ins']]) {
      const col = node('div'); col.append(node('h4', title));
      const list = node('ol');
      lines.forEach((line, i) => list.append(node('li', line, keep[i] ? undefined : cls)));
      col.append(list); box.append(col);
    }
    return box;
  }
  // 项目总览：各位置现在怎样
  function renderStates(locations, runs) {
    const box = $('pw-location-states'); box.replaceChildren();
    // 按结构排：上级在前，下级跟在后面
    const depth = new Map(), ordered = [];
    (function walk(parent, d) {
      for (const l of locations.filter(x => (x.parent_id || null) === parent)) { depth.set(l.id, d); ordered.push(l); walk(l.id, d + 1); }
    })(null, 0);
    const working = new Map();
    for (const r of runs) if (r.location) working.set(r.location.id, (working.get(r.location.id) || 0) + 1);
    for (const l of ordered) {
      const li = node('li', undefined, 'pw-decision pw-state');
      li.style.marginInlineStart = (depth.get(l.id) * 14) + 'px';
      const head = node('p'); head.append(link(l.title, locationUrl(l.id))); li.append(head);
      const facts = [l.length ? '当前内容版本 ' + l.revision + '（' + l.length + ' 字）' : '当前内容还是空的',
        l.revision > 1 ? '最后由 ' + l.editor + ' 改于 ' + date(l.updated_at) : ''].filter(Boolean);
      li.append(node('p', facts.join(' · '), 'pw-hint'));
      const now = [];
      if (working.get(l.id)) now.push(working.get(l.id) + ' 件正在做');
      if (l.open) now.push(l.open + ' 件交出去还没回来');
      if (l.held) now.push(l.held + ' 个留着待比较的版本');
      if (l.review) now.push(l.review + ' 件待复核');
      if (now.length) li.append(node('p', now.join(' · '), 'pw-run' + (l.held || l.review ? ' pw-warn' : '')));
      box.append(li);
    }
  }
  // 来路：确立时指向的那几句，原样；原会话还能接着聊
  function renderOrigin(list) {
    $('pw-origin').hidden = !list.length;
    const box = $('pw-origin-list'); box.replaceChildren();
    for (const f of list) {
      const li = node('li', undefined, 'pw-decision pw-formation');
      const head = node('p'); head.append(link(f.creation_title || '会话 #' + f.creation_id, base + '/creations/' + f.creation_id));
      head.append(document.createTextNode(' · ' + f.formed_by + ' 确立于 ' + date(f.formed_at)));
      li.append(head);
      for (const q of f.quotes) li.append(node('blockquote', (q.role === 'user' ? '' : 'AI：') + q.content));
      if (f.reason) li.append(node('p', '理由：' + f.reason, 'pw-hint'));
      box.append(li);
    }
  }
  function renderRecent(recent, decisions) {
    const box = $('pw-recent'); box.replaceChildren();
    const quote = Object.fromEntries(decisions.map(d => [d.id, d.content]));
    const titles = Object.fromEntries(state.project.locations.map(l => [l.id, l.title]));
    for (const r of recent) {
      const where = r.location ? '「' + r.location.title + '」' : '某处';
      const basis = (r.basis || []).map(b => b.type === 'decision' ? '决定「' + (quote[b.id] || b.id) + '」'
        : b.type === 'location_content' ? '「' + (titles[b.id] || '某处') + '」当前内容版本 ' + b.version : b.type + ' ' + b.id);
      box.append(node('li', r.subject + ' 在' + where + '（' + date(r.updated_at) + '）：' + (basis.length ? '依据了 ' + basis.join('、') : '未上报依据')));
    }
    if (!recent.length) box.append(node('li', '还没有交回的结果。', 'pw-hint'));
  }
  // 采用和纠正
  function inlineForm(container, label, placeholder, submitText, onSubmit) {
    const old = container.querySelector(':scope > .pw-inline-form'); if (old) { old.remove(); return; }
    const form = node('form', undefined, 'pw-inline-form');
    const box = node('textarea'); box.rows = 2; box.placeholder = placeholder; box.setAttribute('aria-label', label);
    const ok = node('button', submitText); ok.type = 'submit';
    const cancel = node('button', '算了'); cancel.type = 'button'; cancel.addEventListener('click', () => form.remove());
    form.append(node('label', label), box, ok, cancel);
    form.addEventListener('submit', e => { e.preventDefault(); busy(form, async () => { await onSubmit(box.value); form.remove(); }); });
    container.append(form); box.focus();
  }
  function adoptButton(type, id, version) {
    const b = node('button', '在这里采用'); b.type = 'button'; b.dataset.adopt = type + ':' + id + ':' + (version || '');
    b.addEventListener('click', () => inlineForm(b.parentElement, '为什么在这里采用（可留空）', '例如：这一处按它来', '采用', async basis => {
      await mutate('/projects/locations/' + state.location.id + '/adoptions', 'POST', {object_type: type, object_id: id, object_version: version || '', basis});
      await loadAdoptions(); notice('已在这里采用。别处不受影响。');
    }));
    return b;
  }
  function correctButton(container, type, id, version) {
    const b = node('button', '纠正'); b.type = 'button';
    b.addEventListener('click', () => inlineForm(container, '哪里不对', '写下纠正；原来的不会被改掉', '提交纠正', async content => {
      const r = await mutate('/projects/corrections', 'POST', {object_type: type, object_id: id, object_version: version || '', content});
      await loadAdoptions(); state.lastOverview = ''; await loadOverview();
      notice('纠正已记下：第 1 层 ' + r.review + ' 处待复核、' + r.hint + ' 处提示；第 2 层 ' + (r.hint_depth2 || 0) + ' 处可能相关提示；第 3 层 ' + (r.hint_depth3 || 0) + ' 处只提示，再往下不追。');
    }));
    return b;
  }
  function adoptionItem(a) {
    const li = node('li', undefined, 'pw-decision pw-adoption' + (a.state === 'review' ? ' pw-adoption-review' : ''));
    li.dataset.adoptionId = a.id;
    const what = a.object_type === 'message'
      ? (a.preview.role === 'user' ? '你在「' : 'AI 在「') + (a.preview.creation_title || '') + '」里的一段'
      : '「' + (a.preview.location_title || '') + '」当前内容的版本 ' + a.object_version;
    li.append(node('p', what, 'pw-hint'));
    li.append(node('blockquote', a.preview.text));
    const meta = [(a.state === 'proposed' ? a.proposed_by + ' 提议于 ' : a.adopted_by + ' 采用于 ') + date(a.created_at)];
    if (a.authority) meta.push('依据交出去的那件事' + (a.source_message_id ? '（你的原话 #' + a.source_message_id + '）' : ''));
    if (a.proposed_by && a.state !== 'proposed') meta.push('由 ' + a.proposed_by + ' 提议');
    if (a.state === 'declined') meta.push('没有采用 ' + date(a.ended_at));
    if (a.basis) meta.push('依据：' + a.basis);
    if (a.state === 'withdrawn') meta.push('已撤回 ' + date(a.ended_at));
    if (a.state === 'superseded') meta.push('已被改采用的一版替代 ' + date(a.ended_at));
    li.append(node('p', meta.join(' · '), 'pw-hint'));
    for (const c of a.corrections) {
      li.append(node('p', '纠正' + (c.version ? '（针对版本 ' + c.version + '）' : '') + '：' + c.content + ' — ' + c.author + ' · ' + date(c.created_at), 'pw-correction'));
    }
    if (a.response) li.append(node('p', '仍然采用：' + a.response, 'pw-run'));
    if (a.state === 'proposed') {
      const row = node('div', undefined, 'pw-inline');
      const yes = node('button', '采用'); yes.type = 'button';
      yes.addEventListener('click', () => busy(row, async () => { await mutate('/projects/adoptions/' + a.id + '/accept', 'POST', {}); await loadAdoptions(); notice('已采用。采用者是你，提议者留着。'); }));
      const no = node('button', '不采用'); no.type = 'button';
      no.addEventListener('click', () => busy(row, async () => { await mutate('/projects/adoptions/' + a.id + '/decline', 'POST', {}); await loadAdoptions(); }));
      row.append(yes, no); li.append(row);
      return li;
    }
    if (a.state !== 'active' && a.state !== 'review') return li;
    if (a.state === 'review') li.append(node('p', '待复核：它被纠正了。仍然采用、撤回，或改采用别的一版。', 'pw-run pw-warn'));
    const actions = node('div', undefined, 'pw-inline');
    if (a.state === 'review') {
      const keep = node('button', '仍然采用'); keep.type = 'button';
      keep.addEventListener('click', () => inlineForm(li, '为什么仍然采用', '纠正和你的回应都会留着', '仍然采用', async response => {
        await mutate('/projects/adoptions/' + a.id + '/reaffirm', 'POST', {response}); await loadAdoptions();
      }));
      actions.append(keep);
    }
    const drop = node('button', '撤回'); drop.type = 'button';
    drop.addEventListener('click', () => busy(actions, async () => {
      await mutate('/projects/adoptions/' + a.id + '/withdraw', 'POST', {}); await loadAdoptions();
      notice('已在这里撤回。别处的采用不变，它们会得到提示。');
    }));
    actions.append(drop);
    if (a.object_type === 'location_content' && a.preview.current_revision > Number(a.object_version)) {
      const newer = node('button', '改采用版本 ' + a.preview.current_revision); newer.type = 'button';
      newer.addEventListener('click', () => busy(actions, async () => {
        await mutate('/projects/locations/' + state.location.id + '/adoptions', 'POST',
          {object_type: 'location_content', object_id: a.object_id, object_version: String(a.preview.current_revision), supersedes: a.id});
        await loadAdoptions();
      }));
      actions.append(newer);
    }
    if (a.state === 'active') actions.append(correctButton(li, a.object_type, a.object_id, a.object_version));
    li.append(actions);
    return li;
  }
  async function loadAdoptions() {
    if (state.mode !== 'location') return;
    const data = await api('/projects/locations/' + state.location.id + '/adoptions');
    const live = data.adoptions.filter(a => a.state === 'active' || a.state === 'review');
    const proposed = data.adoptions.filter(a => a.state === 'proposed');
    const ended = data.adoptions.filter(a => !['active', 'review', 'proposed'].includes(a.state));
    $('pw-proposals').replaceChildren(...proposed.map(adoptionItem));
    $('pw-proposals-box').hidden = !proposed.length;
    $('pw-adoption-list').replaceChildren(...live.map(adoptionItem));
    if (!live.length) $('pw-adoption-list').append(node('li', '这里还没有正式采用什么。讨论里的回答、材料里的话、当前内容的某一版，都可以在这里采用。', 'pw-hint'));
    $('pw-adoptions-ended').replaceChildren(...ended.map(adoptionItem));
    $('pw-adoptions-ended-box').hidden = !ended.length;
    const box = $('pw-impacts'); box.replaceChildren();
    for (const i of data.impacts.filter(i => !(i.target_kind === 'adoption' && i.effect === 'review'))) {
      const li = node('li', (i.effect === 'review' ? '待复核：' : '提示：') + i.note + (i.correction ? '。纠正：「' + i.correction.content + '」' : '')
        + (i.depth >= 3 ? '（纠正传到这里为止，再往下不追）' : ''),
        'pw-run' + (i.effect === 'review' ? ' pw-warn' : ''));
      li.dataset.impactId = i.id;
      const ok = node('button', i.effect === 'review' ? '看过了' : '知道了'); ok.type = 'button';
      ok.addEventListener('click', () => busy(li, async () => { await mutate('/projects/impacts/' + i.id + '/resolve', 'POST', {}); await loadAdoptions(); }));
      li.append(' ', ok); box.append(li);
    }
  }
  // 结构的经过：当时在哪、去了哪
  function renderHistory(box, rows, here) {
    box.replaceChildren();
    for (const h of rows) {
      const li = node('li', undefined, 'pw-hint');
      const who = here ? '这一处' : '「' + (h.location_title || '某处') + '」';
      if (h.action === 'move') li.textContent = date(h.changed_at) + ' ' + who + '从「' + (h.from_parent_title || '顶层') + '」下面移到「' + (h.to_parent_title || '顶层') + '」下面';
      else {
        li.append(document.createTextNode(date(h.changed_at) + ' ' + who + (h.action === 'merge' ? '随项目合并，' : '独立出来，') + '从项目 '));
        li.append(link('「' + h.from_project_title + '」', base + '/projects/' + h.from_project_id), document.createTextNode(' 到项目 '));
        li.append(link('「' + h.to_project_title + '」', base + '/projects/' + h.to_project_id));
      }
      box.append(li);
    }
  }
  // 重组：先预览，确认时带回预览的 basis
  function fillMoveParents() {
    const sel = $('pw-move-parent'); sel.replaceChildren();
    const below = new Set([state.location.id]);
    for (let grew = true; grew;) { grew = false; for (const l of state.project.locations) if (l.parent_id && below.has(l.parent_id) && !below.has(l.id)) { below.add(l.id); grew = true; } }
    for (const l of state.project.locations) {
      if (below.has(l.id) || l.id === state.location.parent_id) continue;
      const o = node('option', l.title); o.value = l.id; sel.append(o);
    }
    $('pw-restructure').hidden = !state.location.parent_id;
    $('pw-preview-move').disabled = !sel.options.length;
  }
  async function loadMergeTargets() {
    const data = await api('/projects/data');
    const sel = $('pw-merge-project'); sel.replaceChildren();
    for (const p of data.projects) if (p.id !== state.project.id && !p.merged_into) { const o = node('option', p.title); o.value = p.id; sel.append(o); }
    await loadMergeParents();
  }
  async function loadMergeParents() {
    const sel = $('pw-merge-parent'); sel.replaceChildren();
    if (!$('pw-merge-project').value) return;
    const p = (await api('/projects/' + $('pw-merge-project').value + '/data')).project;
    for (const l of p.locations) { const o = node('option', l.title); o.value = l.id; sel.append(o); }
  }
  async function previewRestructure(params) {
    const r = await api('/projects/restructure/preview', 'POST', params);
    state.pending = {...params, basis: r.basis};
    $('pw-restructure-lines').replaceChildren(...r.consequences.map(c => node('li', c)));
    $('pw-restructure-preview').hidden = false;
    $('pw-restructure-preview').scrollIntoView({block: 'nearest'});
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
        if (state.mode === 'location' && loc.id === state.location.id) a.setAttribute('aria-current', 'page');
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
      const look = node('details'); look.append(node('summary', '看这份材料里的话'));
      look.addEventListener('toggle', () => {
        if (!look.open || look.querySelector('ul')) return;
        api('/creations/' + u.source_creation_id + '/messages').then(data => {
          const ul = node('ul', undefined, 'pw-material-messages');
          for (const m of data.messages) {
            const li = node('li', (m.role === 'user' ? '你：' : 'AI：') + m.content.slice(0, 600) + (m.content.length > 600 ? '…' : ''));
            li.append(document.createElement('br'), adoptButton('message', String(m.id), null));
            ul.append(li);
          }
          look.append(ul);
        }).catch(e => notice(e.message, true));
      });
      item.append(look);
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
          item.append(adoptButton('message', String(m.id), null));
          if (m.role === 'assistant') item.append(correctButton(item, 'message', String(m.id), null));
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
          $('pw-conflict-content').replaceChildren(compareView('已保存的版本 ' + latest.location.revision + '（' + latest.location.editor + '）',
            latest.location.content, '你上面的文本', content));
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
  $('pw-preview-move').addEventListener('click', () => busy($('pw-restructure'), () => previewRestructure({action: 'move', location_id: state.location.id, parent_id: $('pw-move-parent').value})));
  $('pw-preview-independent').addEventListener('click', () => busy($('pw-restructure'), () => previewRestructure({action: 'independent', location_id: state.location.id})));
  $('pw-merge-project').addEventListener('change', () => loadMergeParents().catch(e => notice(e.message, true)));
  $('pw-preview-merge').addEventListener('click', () => busy($('pw-merge'), () => previewRestructure({action: 'merge', project_id: state.project.id, target_parent_id: $('pw-merge-parent').value})));
  $('pw-restructure-cancel').addEventListener('click', () => { state.pending = null; $('pw-restructure-preview').hidden = true; });
  $('pw-restructure-apply').addEventListener('click', () => busy($('pw-restructure-preview'), async () => {
    if (!state.pending) return;
    try {
      const r = await mutate('/projects/restructure', 'POST', state.pending);
      const action = state.pending.action; state.pending = null;
      location.href = action === 'move' ? locationUrl(r.location_id) : base + '/projects/' + r.project_id;
    } catch (e) {
      if (e.status === 409) { $('pw-restructure-preview').hidden = true; state.pending = null; }
      throw e;
    }
  }));
  $('pw-adopt-content').addEventListener('click', () => {
    if (state.dirty) { notice('先保存，再采用保存下来的那一版。', true); return; }
    busy($('pw-content-form'), async () => {
      await mutate('/projects/locations/' + state.location.id + '/adoptions', 'POST',
        {object_type: 'location_content', object_id: state.location.id, object_version: String(state.location.revision)});
      await loadAdoptions(); notice('已在这里采用当前内容的版本 ' + state.location.revision + '。');
    });
  });
  $('pw-delegate-form').addEventListener('submit', e => {
    e.preventDefault(); busy(e.currentTarget, async () => {
      if ($('pw-delegate-edit').checked && state.dirty) { notice('当前内容有没保存的修改，先保存，再交出去。', true); return; }
      const r = await mutate('/projects/locations/' + state.location.id + '/delegations', 'POST', {
        ask: $('pw-delegate-ask').value, goal: $('pw-delegate-goal').value, means: $('pw-delegate-means').value,
        forbidden: $('pw-delegate-forbidden').value, return_minutes: Number($('pw-delegate-return').value),
        may_edit: $('pw-delegate-edit').checked, may_adopt: $('pw-delegate-adopt').checked});
      $('pw-delegate-ask').value = ''; $('pw-delegate-goal').value = ''; $('pw-delegate-means').value = ''; $('pw-delegate-forbidden').value = '';
      state.lastOverview = ''; await Promise.all([loadOverview(), loadMessages()]);
      notice(r.run_state === 'unknown' ? '还不能确认对方是否已经开工，已记为状态未知；请先核对，勿重复派发。' : (r.state === 'not_taken' ? '记下了，但没交出去：' + r.note : '交出去了。可以走开，结果会回到这里。'), r.state === 'not_taken' || r.run_state === 'unknown');
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
        $('pw-message').value = ''; notice(result.execution_blocked ? '原话已保存。'+result.message : (result.queued ? '原话已保存，将在当前回复后继续处理。' : '原话已保存。'));
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
      if (state.mode === 'location') loadMessages().catch(e => notice('暂时无法刷新讨论：' + e.message, true));
      if (state.mode === 'location' && !document.querySelector('.pw-adoptions .pw-inline-form')) loadAdoptions().catch(() => {});
      loadOverview().catch(e => notice('暂时无法刷新决定和进展：' + e.message, true));
    }
  }, 5000);
  init().catch(e => notice(e.message, true));
})();
