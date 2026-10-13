/* Deck full-card save -> explicit field changes against the loaded card. */
(() => {
  'use strict';
  if (!/\/apps\/deck(?:\/|$)/.test(location.pathname) || window.__cognosTaskEdit) return;
  window.__cognosTaskEdit=true;
  const fields=['title','description','duedate','startdate','color'];
  const baselines=new Map(), requests=new WeakMap();
  const open=XMLHttpRequest.prototype.open,send=XMLHttpRequest.prototype.send;
  const normalize=(field,value)=>{
    if(['duedate','startdate'].includes(field)) return value ? new Date(value).toISOString() : null;
    if(field==='color') return value || null;
    return value;
  };
  function scan(value,depth=0) {
    if(!value || typeof value!=='object' || depth>8) return;
    if(Number.isInteger(value.id) && typeof value.title==='string' && 'description' in value && 'stackId' in value) {
      baselines.set(value.id,Object.fromEntries(fields.map(f=>[f,normalize(f,value[f])])));return;
    }
    for(const child of Object.values(value)) if(child && typeof child==='object')scan(child,depth+1);
  }
  XMLHttpRequest.prototype.open=function(method,url,...args) {
    let metadata;
    try {
      const parsed=new URL(url,location.href);
      if(parsed.origin===location.origin && parsed.pathname.includes('/apps/')) {
        const ocs=parsed.pathname.match(/^\/ocs\/v2\.php\/apps\/deck\/api\/v1\.[01]\/cards\/(\d+)$/);
        const rename=parsed.pathname.match(/^\/(?:index\.php\/)?apps\/deck\/cards\/(\d+)\/rename$/);
        if(method.toUpperCase()==='PUT' && (ocs||rename)) {
          const id=Number((ocs||rename)[1]);metadata={cardId:id,rename:!!rename,url:null};
          parsed.pathname=ocs?`/ocs/v2.php/apps/cognos/api/v1.0/cards/${id}`:`/index.php/apps/cognos/cards/${id}/rename`;
          url=parsed.href;metadata.url=url;
        }
        if(parsed.pathname.includes('/apps/deck/') || parsed.pathname.includes('/apps/cognos/')) {
          this.addEventListener('loadend',()=>{
            if(this.status>=200&&this.status<300) {try {const data=JSON.parse(this.responseText);scan(data.ocs?.data || data);}catch{}}
          },{once:true});
        }
      }
    } catch {}
    requests.set(this,metadata);return open.call(this,method,url,...args);
  };
  XMLHttpRequest.prototype.send=function(body) {
    const m=requests.get(this);if(!m)return send.call(this,body);
    const host=document.getElementById('tab-details') || document.body;
    host.querySelector('[data-cognos-task-edit-feedback]')?.remove();
    const notice=document.createElement('div');notice.dataset.cognosTaskEditFeedback='true';
    notice.style.cssText='padding:10px;margin:8px 0;border:1px solid var(--color-border);border-radius:8px;white-space:normal;';
    notice.setAttribute('role','status');notice.textContent='正在保存任务修改…';host.prepend(notice);
    const buttons=[...host.querySelectorAll('button[type=submit]')].map(button=>({button,disabled:button.disabled}));
    for(const {button} of buttons){button.disabled=true;button.setAttribute('aria-busy','true');}
    let payload,key,requestId;
    const clear=()=>{try{if(key&&sessionStorage.getItem(key)===requestId)sessionStorage.removeItem(key);}catch{}};
    this.addEventListener('loadend',()=>{
      for(const {button,disabled} of buttons){button.disabled=disabled;button.removeAttribute('aria-busy');}
      let data;try{data=JSON.parse(this.responseText);data=m.rename?data:data.ocs?.data;}catch{}
      if(this.status>=200&&this.status<300&&Number.isInteger(data?.id)) {
        clear();notice.remove();document.querySelector('[data-cognos-task-history]')?._refresh?.();window.dispatchEvent(new CustomEvent('cognos:task-changed',{detail:{cardId:m.cardId}}));return;
      }
      notice.setAttribute('role','alert');notice.textContent=this.status===409
        ? '任务已发生变化。当前输入已保留，请查看最新任务后再保存。'
        : '修改未能确认。当前输入已保留，恢复连接后可重试。';
      const link=document.createElement('a');link.textContent='查看最新任务';link.href=location.href;notice.append(' ',link);
      if(this.status===400||this.status===403||this.status===409) {clear();return;}
      const retry=document.createElement('button');retry.type='button';retry.textContent='重试';retry.dataset.cognosEditRetry='true';
      retry.onclick=async()=>{
        retry.disabled=true;
        try {
          if(!payload||!requestId)throw new Error();
          const response=await fetch(m.url,{method:'PUT',credentials:'same-origin',headers:{'Content-Type':'application/json',requesttoken:OC.requestToken},body:JSON.stringify({...payload,requestId})});
          const raw=await response.json(),card=m.rename?raw:raw.ocs?.data;
          if(!response.ok||!Number.isInteger(card?.id))throw new Error();
          clear();if(!notice.isConnected)(document.getElementById('tab-details')||host).prepend(notice);notice.setAttribute('role','status');notice.textContent='修改已核对。';notice.append(' ',link);
          // Leave the baseline aligned with the still-visible form until explicit navigation.
          const baseline=baselines.get(m.cardId);
          for(const f of Object.keys(payload.changes||{})) if(baseline && normalize(f,card[f])===payload.changes[f])baseline[f]=payload.changes[f];
          document.querySelector('[data-cognos-task-history]')?._refresh?.();window.dispatchEvent(new CustomEvent('cognos:task-changed',{detail:{cardId:m.cardId}}));
        } catch {retry.disabled=false;}
      };notice.append(' ',retry);
    },{once:true});
    Promise.resolve().then(async()=>{
      const input=typeof body==='string'?JSON.parse(body):{};
      const baseline=baselines.get(m.cardId);
      if(!baseline)throw new Error('Loaded task baseline unavailable');
      const changes={},expected={};
      for(const field of (m.rename?['title']:fields)) {
        if(!Object.prototype.hasOwnProperty.call(input,field))continue;
        const value=normalize(field,input[field]);
        if(value!==baseline[field]){changes[field]=value;expected[field]=baseline[field];}
      }
      payload=m.rename?{title:input.title,expectedTitle:baseline.title}:{changes,expected};
      if(Number.isInteger(input.boardId))payload.boardId=input.boardId;
      const actor=OC.getCurrentUser?.()?.uid || OC.currentUser || '';
      const digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(JSON.stringify([actor,m.cardId,m.rename,payload])));
      key='cognos.task.edit.'+Array.from(new Uint8Array(digest),n=>n.toString(16).padStart(2,'0')).join('');
      requestId=sessionStorage.getItem(key)||crypto.randomUUID();sessionStorage.setItem(key,requestId);
      send.call(this,JSON.stringify({...payload,requestId}));
    }).catch(()=>{this.dispatchEvent(new ProgressEvent('error'));this.dispatchEvent(new ProgressEvent('loadend'));});
  };
})();
