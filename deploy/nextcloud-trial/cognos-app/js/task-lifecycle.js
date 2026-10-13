/* Local Deck 1.19 completion adapter and read-only task status history. */
(() => {
  'use strict';
  if (!/\/apps\/deck(?:\/|$)/.test(location.pathname) || window.__cognosTaskLifecycle) return;
  window.__cognosTaskLifecycle = true;
  const metadata = new WeakMap();
  const open = XMLHttpRequest.prototype.open, send = XMLHttpRequest.prototype.send;
  const header = XMLHttpRequest.prototype.setRequestHeader;
  const actor = () => window.OC?.getCurrentUser?.()?.uid || window.OC?.currentUser || '';
  const text = (tag, content) => { const e = document.createElement(tag); e.textContent = content; return e; };
  const statusNames = {open:'未开始',in_progress:'进行中',blocked:'阻塞',done:'完成'};
  function statusControl(cardId, host, current, editable) {
    host.dataset.cognosStatusControl='true';
    let baseline=current.status, busy=false, column=null, canEdit=editable===true;
    const label=text('p','当前状态：'+statusNames[baseline]); label.dataset.cognosCurrentStatus='true';
    const select=document.createElement('select');select.dataset.cognosTaskStatus='true';select.setAttribute('aria-label','任务状态');
    for(const [value,name] of Object.entries(statusNames)){const option=text('option',name);option.value=value;select.append(option);}
    select.value=baseline;
    const save=text('button','更新状态');save.type='button';save.dataset.cognosStatusSave='true';save.disabled=true;
    const message=text('div','');message.setAttribute('role','status');
    const controls=()=>{select.disabled=busy||!canEdit;if(column)column.disabled=busy||!canEdit;save.disabled=busy||!canEdit||select.value===baseline||(column&&select.value!=='done'&&!column.value);};
    select.onchange=controls;host.append(label,select,save,message);
    host._setEditable=value=>{canEdit=value===true;controls();if(!canEdit){message.setAttribute('role','status');message.textContent='当前任务只读，可查看状态和历史。';}};host._setEditable(editable);
    host._update=(state,explicit=false)=>{
      if(!(state?.status in statusNames))return;
      label.textContent='当前状态：'+statusNames[state.status];
      if(explicit)baseline=state.status;
      else if(!busy&&select.value===baseline){baseline=state.status;select.value=state.status;}
      controls();
    };
    const latestLink=()=>{const link=text('a','查看更新后的任务');link.href=location.href;return link;};
    const review=text('button','重新核对当前状态');review.type='button';review.dataset.cognosStatusReview='true';
    review.onclick=async()=>{
      review.disabled=true;
      try{
        const response=await fetch(`/index.php/apps/cognos/cards/${cardId}/events`,{headers:{requesttoken:OC.requestToken,Accept:'application/json'}});
        if(!response.ok)throw new Error();const data=await response.json();host._setEditable(data.canEdit);host._update(data.currentState,true);
        message.setAttribute('role','status');message.replaceChildren(text('p','已核对当前状态，所选状态保留。'));document.querySelector('[data-cognos-task-history]')?._refresh?.();
      }catch{message.setAttribute('role','alert');message.textContent='暂时无法核对当前状态，请重试。';message.append(review);}
      finally{review.disabled=false;}
    };
    save.onclick=async()=>{
      if(busy||!canEdit)return;busy=true;controls();save.setAttribute('aria-busy','true');message.setAttribute('role','status');message.textContent='正在保存任务状态…';
      let key,requestId;
      try{
        const payload={status:select.value,expectedStatus:baseline};
        if(column&&column.value&&payload.status!=='done')payload.stackId=Number(column.value);
        const digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(JSON.stringify([actor(),cardId,payload])));
        key='cognos.task.phase.'+Array.from(new Uint8Array(digest),n=>n.toString(16).padStart(2,'0')).join('');
        requestId=sessionStorage.getItem(key)||crypto.randomUUID();sessionStorage.setItem(key,requestId);
        const response=await fetch(`/index.php/apps/cognos/cards/${cardId}/state`,{method:'PUT',credentials:'same-origin',headers:{'Content-Type':'application/json',requesttoken:OC.requestToken,Accept:'application/json'},body:JSON.stringify({...payload,requestId})});
        const data=await response.json();
        if(!response.ok){
          if([400,403,409].includes(response.status)&&sessionStorage.getItem(key)===requestId)sessionStorage.removeItem(key);
          message.setAttribute('role','alert');
          if(Array.isArray(data.reopenColumns)&&data.reopenColumns.length){
            column?.remove();column=document.createElement('select');column.dataset.cognosStatusColumn='true';column.setAttribute('aria-label','重新打开后返回的列');
            const placeholder=text('option','请选择返回列');placeholder.value='';placeholder.disabled=true;placeholder.selected=true;column.append(placeholder);
            for(const choice of data.reopenColumns){const option=text('option',choice.title);option.value=choice.id;column.append(option);}
            column.onchange=controls;save.before(column);message.textContent='选择返回的列后，再次保存所选状态。';
          }else{
            message.textContent=response.status===409?'任务状态已改变，所选状态保留。请先核对当前状态。':response.status===403?'你当前无权修改此任务。':'状态未能确认。恢复连接后可重试，所选状态已保留。';
            if(response.status===409)message.append(review);
          }
          return;
        }
        if(!Number.isInteger(data.card?.id)||!(data.currentState?.status in statusNames))throw new Error();
        if(sessionStorage.getItem(key)===requestId)sessionStorage.removeItem(key);
        window.dispatchEvent(new CustomEvent('cognos:task-changed',{detail:{cardId}}));
        baseline=data.currentState.status;select.value=baseline;column?.remove();column=null;label.textContent='当前状态：'+statusNames[baseline];
        message.setAttribute('role','status');message.replaceChildren(text('p',(response.headers.get('X-Cognos-Replayed')==='true'?'操作已核对，当前状态：':'任务状态已保存：')+statusNames[baseline]),latestLink());
        document.querySelector('[data-cognos-task-history]')?._refresh?.();
      }catch{message.setAttribute('role','alert');message.textContent='结果未能确认。恢复连接后可重试同一次操作，所选状态已保留。';}
      finally{busy=false;save.removeAttribute('aria-busy');controls();}
    };
  }
  function reopenControl(cardId, host, columns = null) {
    const select = columns ? document.createElement('select') : null;
    if (select) {
      select.setAttribute('aria-label', '重新打开后返回的列');
      for (const c of columns) { const option = text('option', c.title); option.value = c.id; select.append(option); }
      host.append(select);
    }
    const retry = text('button', '重新打开任务'); retry.type = 'button';
    retry.dataset.cognosReopen = 'true';
    const status = text('p', ''); status.setAttribute('role', 'status');
    retry.onclick = async () => {
      retry.disabled = true; if (select) select.disabled = true;
      retry.setAttribute('aria-busy', 'true'); status.textContent = '正在重新打开任务…';
      try {
        const payload = select ? {stackId:Number(select.value)} : {};
        const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(JSON.stringify([actor(), cardId, 'undone', payload])));
        const key = 'cognos.task.state.' + Array.from(new Uint8Array(digest), n => n.toString(16).padStart(2,'0')).join('');
        const requestId = sessionStorage.getItem(key) || crypto.randomUUID(); sessionStorage.setItem(key, requestId);
        const response = await fetch(`/index.php/apps/cognos/cards/${cardId}/undone`, {method:'PUT', credentials:'same-origin',
          headers:{'Content-Type':'application/json',requesttoken:window.OC.requestToken,Accept:'application/json'},
          body:JSON.stringify({...payload,requestId})});
        const data = await response.json();
        if (response.status === 409 && Array.isArray(data.reopenColumns) && data.reopenColumns.length) {
          host.replaceChildren(text('p','请选择重新打开任务后要返回的列。')); reopenControl(cardId, host, data.reopenColumns); return;
        }
        if (!response.ok || !Number.isInteger(data.id) || data.done !== null) throw new Error();
        if (sessionStorage.getItem(key) === requestId) sessionStorage.removeItem(key);
        // Navigation is explicit so an unsaved description is never discarded by this adapter.
        window.dispatchEvent(new CustomEvent('cognos:task-changed',{detail:{cardId}}));
        const confirmation = text('div','任务已重新打开。'); confirmation.setAttribute('role','status'); host.after(confirmation); host.replaceChildren();
        const link = text('a','查看更新后的任务'); link.href = location.href; confirmation.append(link);
        document.querySelector('[data-cognos-task-history]')?._refresh?.();
      } catch {
        status.setAttribute('role','alert'); status.textContent = '结果未能确认。恢复连接后可重试同一次操作。';
        retry.disabled = false; if (select) select.disabled = false;
      } finally { retry.removeAttribute('aria-busy'); }
    };
    host.append(retry, status);
  }
  XMLHttpRequest.prototype.open = function(method, url, ...args) {
    let target;
    try {
      const parsed = new URL(url, location.href);
      const match = parsed.pathname.match(/^\/(?:index\.php\/)?apps\/deck\/cards\/(\d+)\/(done|undone)$/);
      if (parsed.origin === location.origin && method.toUpperCase() === 'PUT' && match) {
        target = {cardId: Number(match[1]), operation: match[2], contentType: false};
        parsed.pathname = `/index.php/apps/cognos/cards/${match[1]}/${match[2]}`;
        url = parsed.href;
      }
    } catch {}
    metadata.set(this, target);
    return open.call(this, method, url, ...args);
  };
  XMLHttpRequest.prototype.setRequestHeader = function(name, value) {
    const m = metadata.get(this);
    if (m && name.toLowerCase() === 'content-type') m.contentType = true;
    return header.call(this, name, value);
  };
  XMLHttpRequest.prototype.send = function(body) {
    const m = metadata.get(this);
    if (!m) return send.call(this, body);
    const host = document.getElementById('tab-details') || document.getElementById('app-sidebar-vue') || document.body;
    host.querySelector('[data-cognos-task-state-feedback]')?.remove();
    const notice = text('div', '正在保存任务状态…');
    notice.dataset.cognosTaskStateFeedback = 'true';
    notice.style.cssText = 'padding:10px;margin:8px 0;border:1px solid var(--color-border);border-radius:8px;font-size:14px;white-space:normal;';
    if (host === document.body) notice.style.cssText += 'position:fixed;top:58px;right:12px;z-index:2000;max-width:320px;background:var(--color-main-background);';
    notice.setAttribute('role', 'status'); notice.setAttribute('aria-live', 'polite');
    host.prepend(notice);
    const label = m.operation === 'done' ? '标记为已完成' : '标记为未完成';
    const button = Array.from(document.querySelectorAll('button')).find(e => e.getBoundingClientRect().width && e.innerText.trim() === label);
    const disabled = button?.disabled;
    if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
    let key, requestId;
    const finish = () => {
      if (button) { button.disabled = disabled; button.removeAttribute('aria-busy'); }
      let data; try { data = JSON.parse(this.responseText); } catch {}
      const success = this.status >= 200 && this.status < 300 && Number.isInteger(data?.id);
      if (success) {
        try { if (key && sessionStorage.getItem(key) === requestId) sessionStorage.removeItem(key); } catch {}
        notice.remove();
        window.dispatchEvent(new CustomEvent('cognos:task-changed',{detail:{cardId:m.cardId}}));
        document.querySelector('[data-cognos-task-history]')?._refresh?.();
        return;
      }
      notice.setAttribute('role', 'alert'); notice.setAttribute('aria-live', 'assertive');
      notice.textContent = this.status === 403 ? '你当前无权修改这项任务。' : '任务状态未能确认。请恢复连接后重试同一次操作。';
      if (Array.isArray(data?.reopenColumns) && data.reopenColumns.length) {
        notice.textContent = '请选择重新打开任务后要返回的列。';
        reopenControl(m.cardId, notice, data.reopenColumns);
      }
    };
    this.addEventListener('loadend', finish, {once:true});
    Promise.resolve().then(async () => {
      const payload = typeof body === 'string' && body ? JSON.parse(body) : {};
      const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(JSON.stringify([actor(), m.cardId, m.operation, payload])));
      key = 'cognos.task.state.' + Array.from(new Uint8Array(digest), n => n.toString(16).padStart(2,'0')).join('');
      requestId = sessionStorage.getItem(key) || crypto.randomUUID(); sessionStorage.setItem(key, requestId);
      if (!m.contentType) this.setRequestHeader('Content-Type', 'application/json');
      send.call(this, JSON.stringify({...payload, requestId}));
    }).catch(() => { this.dispatchEvent(new ProgressEvent('error')); this.dispatchEvent(new ProgressEvent('loadend')); });
  };

  function ensureHistory() {
    const match = location.pathname.match(/\/apps\/deck\/board\/\d+\/card\/(\d+)/);
    const host = document.getElementById('tab-details');
    if (!match || !host) return;
    const cardId = Number(match[1]);
    const existing = host.querySelector('[data-cognos-task-history]');
    if (existing?.dataset.cardId === String(cardId)) return;
    existing?.remove();
    const section = document.createElement('section'); section.dataset.cognosTaskHistory = 'true'; section.dataset.cardId = cardId;
    section.style.cssText = 'margin:16px 0;padding:12px;border-top:1px solid var(--color-border);font-size:14px;';
    section.append(text('h3', '任务状态记录'));
    const message = text('p', '正在读取记录…'); message.setAttribute('role', 'status');
    const list = document.createElement('ol'); list.style.cssText = 'padding-left:18px;white-space:normal;overflow-wrap:anywhere;';
    const more = text('button', '更早的记录'); more.type = 'button'; more.hidden = true;
    const actions = document.createElement('div'); actions.dataset.cognosTaskActions = 'true';
    section.append(message, list, more, actions); host.append(section);
    let cursor = null, loading = false;
    const load = async (append = false) => {
      if (loading) return; loading = true; more.disabled = true; message.textContent = '正在读取记录…'; message.setAttribute('role','status');
      try {
        const url = `/index.php/apps/cognos/cards/${cardId}/events` + (append && cursor ? `?beforeId=${cursor}` : '');
        const response = await fetch(url, {credentials:'same-origin', headers:{Accept:'application/json',requesttoken:window.OC.requestToken}});
        if (!response.ok) throw new Error();
        const data = await response.json();
        if (!append) {
          list.replaceChildren();
          let phase=actions.querySelector('[data-cognos-status-control]');
          if(!phase){phase=document.createElement('div');actions.append(phase);statusControl(cardId,phase,data.currentState,data.canEdit);}else{phase._setEditable(data.canEdit);phase._update(data.currentState);}
          let quick=actions.querySelector('[data-cognos-quick-reopen]');if(!quick){quick=document.createElement('div');quick.dataset.cognosQuickReopen='true';actions.append(quick);}
          quick.replaceChildren();if(data.completed&&data.canEdit)reopenControl(cardId,quick);
        }
        for (const event of data.events) list.append(window.CognosTaskEvents.render(event));
        cursor = data.nextBeforeId; more.hidden = !cursor;
        message.textContent = list.children.length ? '' : '尚无新的状态记录。之前的活动可在“活动”页查看。';
      } catch {
        message.textContent = '暂时无法读取记录。'; message.setAttribute('role','alert');
        const retry = text('button','重试'); retry.type = 'button'; retry.onclick = () => load(append); message.append(retry);
      } finally { loading = false; more.disabled = false; }
    };
    section._refresh = () => load(); more.onclick = () => load(true); load();
  }
  let scheduled = false;
  new MutationObserver(() => { if (!scheduled) { scheduled = true; queueMicrotask(() => {scheduled = false; ensureHistory();}); } })
    .observe(document.documentElement, {childList:true, subtree:true});
  ensureHistory();
})();
