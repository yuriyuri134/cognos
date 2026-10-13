(() => {
  'use strict';
  if(!/\/apps\/deck(?:\/|$)/.test(location.pathname)||window.__cognosBoardActivity)return;
  window.__cognosBoardActivity=true;
  const node=(tag,value)=>{const e=document.createElement(tag);if(value)e.textContent=value;return e;};
  let panel=null;
  function ensure(){
    const match=location.pathname.match(/\/apps\/deck\/board\/(\d+)(?:\/|$)/),wrapper=document.querySelector('#app-content-vue .board-wrapper'),controls=wrapper?.querySelector('.controls');
    if(!match||!controls){panel?._dispose();panel?.remove();panel=null;return;}
    const boardId=Number(match[1]);if(panel?.isConnected&&panel.dataset.boardId===String(boardId))return;
    panel?._dispose();panel?.remove();const details=node('details');panel=details;details.dataset.cognosBoardActivity='true';details.dataset.boardId=boardId;
    details.style.cssText='margin:8px 12px;padding:10px;border:1px solid var(--color-border);border-radius:8px;box-sizing:border-box;max-width:100%;background:var(--color-main-background);';
    const summary=node('summary','项目动态');summary.dataset.cognosBoardActivityToggle='true';summary.style.cssText='cursor:pointer;font-weight:600;';details.append(summary);
    const body=node('div');body.style.cssText='max-height:45vh;overflow:auto;padding-top:8px;';
    const refresh=node('button','刷新动态');refresh.type='button';refresh.dataset.cognosBoardActivityRefresh='true';
    const message=node('p');message.dataset.cognosBoardActivityMessage='true';message.setAttribute('role','status');message.setAttribute('aria-live','polite');
    const list=node('ol');list.style.cssText='padding-left:22px;white-space:normal';list.dataset.cognosBoardActivityList='true';
    const more=node('button','更早的动态');more.type='button';more.hidden=true;more.dataset.cognosBoardActivityMore='true';body.append(refresh,message,list,more);details.append(body);controls.after(details);
    let controller=null,generation=0,cursor=null,loaded=false,disposed=false;
    async function load(append=false){
      controller?.abort();controller=new AbortController();const current=++generation;
      if(!append){list.replaceChildren();cursor=null;}refresh.disabled=true;more.disabled=true;more.hidden=true;refresh.setAttribute('aria-busy','true');message.setAttribute('role','status');message.textContent='正在读取项目动态…';
      try{
        const response=await fetch(`/index.php/apps/cognos/boards/${boardId}/events`+(append&&cursor?'?beforeId='+cursor:''),{credentials:'same-origin',headers:{Accept:'application/json',requesttoken:OC.requestToken},signal:controller.signal});
        if(!response.ok)throw new Error();const data=await response.json();if(disposed||current!==generation)return;
        if(!Array.isArray(data.events))throw new Error();
        const fragment=document.createDocumentFragment();for(const event of data.events)fragment.append(window.CognosTaskEvents.render(event,true));list.append(fragment);
        cursor=data.nextBeforeId;more.hidden=!cursor;loaded=true;message.textContent=list.children.length?'':'还没有新的任务动态。创建任务或更新状态后，会在这里留下记录。';
      }catch(error){
        if(error.name==='AbortError'||disposed||current!==generation)return;
        message.setAttribute('role','alert');message.textContent='暂时无法读取项目动态。';const retry=node('button','重试');retry.type='button';retry.onclick=()=>load(append);message.append(retry);
      }finally{if(!disposed&&current===generation){refresh.disabled=false;more.disabled=false;refresh.removeAttribute('aria-busy');}}
    }
    details.ontoggle=()=>{if(details.open&&!loaded)load();};refresh.onclick=()=>load();more.onclick=()=>load(true);
    const changed=()=>{if(details.open)load();else loaded=false;};window.addEventListener('cognos:task-changed',changed);
    details._dispose=()=>{disposed=true;controller?.abort();window.removeEventListener('cognos:task-changed',changed);};
  }
  let scheduled=false;new MutationObserver(()=>{if(scheduled)return;scheduled=true;queueMicrotask(()=>{scheduled=false;ensure();});}).observe(document.documentElement,{childList:true,subtree:true});ensure();
})();
