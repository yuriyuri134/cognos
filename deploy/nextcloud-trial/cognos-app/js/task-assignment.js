/* Local Deck OCS assignment adapter: native response shape, own transaction. */
(() => {
  'use strict';
  if (!/\/apps\/deck(?:\/|$)/.test(location.pathname) || window.__cognosTaskAssignment) return;
  window.__cognosTaskAssignment = true;
  const operations = new WeakMap(), open=XMLHttpRequest.prototype.open, send=XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function(method,url,...args) {
    let metadata;
    try {
      const parsed=new URL(url,location.href);
      const match=parsed.pathname.match(/^\/ocs\/v2\.php\/apps\/deck\/api\/v1\.[01]\/cards\/(\d+)\/(assign|unassign)$/);
      if (parsed.origin===location.origin && match && method.toUpperCase()===(match[2]==='assign'?'POST':'PUT')) {
        parsed.pathname=`/ocs/v2.php/apps/cognos/api/v1.0/cards/${match[1]}/${match[2]}`;
        url=parsed.href; metadata={cardId:Number(match[1]),kind:match[2],method,url};
      }
    } catch {}
    operations.set(this,metadata); return open.call(this,method,url,...args);
  };
  XMLHttpRequest.prototype.send = function(body) {
    const operation=operations.get(this);
    if (!operation) return send.call(this,body);
    const host=document.getElementById('tab-details') || document.body;
    host.querySelector('[data-cognos-task-assignment-feedback]')?.remove();
    const notice=document.createElement('div'); notice.dataset.cognosTaskAssignmentFeedback='true';
    notice.style.cssText='padding:10px;margin:8px 0;border:1px solid var(--color-border);border-radius:8px;white-space:normal;';
    notice.setAttribute('role','status'); notice.textContent='正在保存负责人变化…'; host.prepend(notice);
    let key,requestId,payload;
    const clear=()=>{try{if(key && sessionStorage.getItem(key)===requestId) sessionStorage.removeItem(key);}catch{}};
    const refreshHistory=()=>{document.querySelector('[data-cognos-task-history]')?._refresh?.();window.dispatchEvent(new CustomEvent('cognos:task-changed',{detail:{cardId:operation.cardId}}));};
    const confirmed=()=>{
      clear(); notice.setAttribute('role','status'); notice.textContent='负责人变化已保存。';
      const link=document.createElement('a'); link.textContent='查看更新后的任务';link.href=location.href; notice.append(' ',link); refreshHistory();
    };
    const fail=(confirmedOperation=false)=>{
      notice.setAttribute('role','alert');notice.textContent=confirmedOperation
        ? '此前操作已确认，负责人后来发生了变化。请查看最新任务后再操作。'
        : '负责人变化未能确认。恢复连接后可重试同一次操作。';
      const link=document.createElement('a');link.textContent='查看最新任务';link.href=location.href;notice.append(' ',link);
      if (confirmedOperation) {clear();refreshHistory();return;}
      const retry=document.createElement('button');retry.type='button';retry.textContent='重试';retry.dataset.cognosAssignmentRetry='true';
      retry.onclick=async()=>{
        retry.disabled=true;retry.setAttribute('aria-busy','true');
        try {
          if (!payload || !requestId) throw new Error();
          const response=await fetch(operation.url,{method:operation.method,credentials:'same-origin',headers:{'Content-Type':'application/json',requesttoken:OC.requestToken,Accept:'application/json'},body:JSON.stringify({...payload,requestId})});
          if (response.headers.get('X-Cognos-Operation-Confirmed')==='true') {fail(true);return;}
          const data=await response.json();
          if(!response.ok || ![100,200].includes(Number(data.ocs?.meta?.statuscode))) throw new Error();
          confirmed();
        } catch {retry.disabled=false;} finally {retry.removeAttribute('aria-busy');}
      };notice.append(' ',retry);
    };
    this.addEventListener('loadend',()=>{
      let data;try{data=JSON.parse(this.responseText);}catch{}
      if(this.status>=200&&this.status<300&&[100,200].includes(Number(data?.ocs?.meta?.statuscode))) {clear();notice.remove();refreshHistory();}
      else fail(this.getResponseHeader('X-Cognos-Operation-Confirmed')==='true');
    },{once:true});
    Promise.resolve().then(async()=>{
      payload=typeof body==='string'?JSON.parse(body):{};
      const actor=window.OC?.getCurrentUser?.()?.uid || window.OC?.currentUser || '';
      const digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(JSON.stringify([actor,operation.cardId,operation.kind,payload])));
      key='cognos.task.assignment.'+Array.from(new Uint8Array(digest),n=>n.toString(16).padStart(2,'0')).join('');
      requestId=sessionStorage.getItem(key)||crypto.randomUUID();sessionStorage.setItem(key,requestId);
      send.call(this,JSON.stringify({...payload,requestId}));
    }).catch(()=>{this.dispatchEvent(new ProgressEvent('error'));this.dispatchEvent(new ProgressEvent('loadend'));});
  };
})();
