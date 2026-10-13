/* Personal assignment view for the local Deck installation. */
(() => {
  'use strict';
  if (!/\/apps\/deck(?:\/|$)/.test(location.pathname) || window.__cognosPersonalTasks) return;
  window.__cognosPersonalTasks = true;
  const names={all:'全部',open:'未开始',in_progress:'进行中',blocked:'阻塞',done:'完成'};
  const node=(tag,value)=>{const e=document.createElement(tag);if(value)e.textContent=value;return e;};
  let mounted=null;
  function ensure(){
    const wrapper=document.querySelector('#app-content-vue .overview-wrapper');
    const visible=/\/apps\/deck\/upcoming(?:\/|$)/.test(location.pathname);
    if(!visible||!wrapper){if(mounted){mounted._dispose();mounted.remove();mounted=null;}return;}
    if(mounted?.isConnected)return;
    mounted?._dispose();
    const section=node('section');mounted=section;section.dataset.cognosPersonalTasks='true';
    section.style.cssText='margin:12px;padding:12px;border:1px solid var(--color-border);border-radius:8px;max-width:100%;box-sizing:border-box;';
    section.append(node('h3','我的任务'),node('p','直接指派给你的任务。打开任务可修改状态并查看记录。'));
    const controls=node('div');controls.style.cssText='display:flex;gap:8px;flex-wrap:wrap;align-items:center';
    const filter=node('select');filter.dataset.cognosPersonalFilter='true';filter.setAttribute('aria-label','筛选我的任务状态');
    for(const [value,label] of Object.entries(names)){const option=node('option',label);option.value=value;filter.append(option);}
    const refresh=node('button','刷新任务');refresh.type='button';refresh.dataset.cognosPersonalRefresh='true';
    controls.append(filter,refresh);section.append(controls);
    const message=node('p');message.setAttribute('role','status');message.setAttribute('aria-live','polite');message.dataset.cognosPersonalMessage='true';
    const list=node('ul');list.style.cssText='padding:0;list-style:none;overflow-wrap:anywhere;';list.dataset.cognosPersonalList='true';
    const more=node('button','更多任务');more.type='button';more.hidden=true;more.dataset.cognosPersonalMore='true';section.append(message,list,more);wrapper.prepend(section);
    let controller=null,generation=0,cursor=null,disposed=false;
    async function load(append=false){
      controller?.abort();controller=new AbortController();const current=++generation;
      if(!append){list.replaceChildren();cursor=null;}more.hidden=true;more.disabled=true;refresh.disabled=true;refresh.setAttribute('aria-busy','true');
      message.setAttribute('role','status');message.textContent='正在读取我的任务…';
      try{
        const params=new URLSearchParams({status:filter.value});if(append&&cursor)params.set('beforeId',cursor);
        const response=await fetch('/index.php/apps/cognos/tasks?'+params,{credentials:'same-origin',headers:{Accept:'application/json',requesttoken:OC.requestToken},signal:controller.signal});
        if(!response.ok)throw new Error();const data=await response.json();
        if(disposed||current!==generation)return;
        if(!Array.isArray(data.tasks))throw new Error();
        for(const task of data.tasks){
          if(!Number.isInteger(task.id)||!Number.isInteger(task.board_id)||!(task.status in names))throw new Error();
          const row=node('li');row.dataset.cognosPersonalCard=task.id;row.style.cssText='padding:10px 0;border-bottom:1px solid var(--color-border);display:flex;gap:8px;flex-wrap:wrap;';
          const link=node('a',task.title);link.href=`/index.php/apps/deck/board/${task.board_id}/card/${task.id}`;link.style.cssText='flex:1 1 180px;overflow-wrap:anywhere';
          const badge=node('span',names[task.status]);badge.dataset.cognosPersonalStatus=task.status;
          row.append(link,badge,node('span',' · '+task.board_title));list.append(row);
        }
        cursor=data.nextBeforeId;more.hidden=!cursor;
        message.textContent=list.children.length?'':filter.value==='all'?'还没有指派给你的任务。可进入项目看板创建任务并指派给自己，或请项目成员指派任务。':'没有“'+names[filter.value]+'”任务。可切换到“全部”查看其他状态。';
      }catch(error){
        if(error.name==='AbortError'||disposed||current!==generation)return;
        message.setAttribute('role','alert');message.textContent='暂时无法读取我的任务。';const retry=node('button','重试');retry.type='button';retry.onclick=()=>load(append);message.append(retry);
      }finally{if(!disposed&&current===generation){refresh.disabled=false;refresh.removeAttribute('aria-busy');more.disabled=false;}}
    }
    filter.onchange=()=>load();refresh.onclick=()=>load();more.onclick=()=>load(true);
    const changed=()=>load();window.addEventListener('cognos:task-changed',changed);
    const focus=()=>load();window.addEventListener('focus',focus);
    section._dispose=()=>{disposed=true;controller?.abort();window.removeEventListener('cognos:task-changed',changed);window.removeEventListener('focus',focus);};
    load();
  }
  let scheduled=false;
  new MutationObserver(()=>{if(scheduled)return;scheduled=true;queueMicrotask(()=>{scheduled=false;ensure();});}).observe(document.documentElement,{childList:true,subtree:true});
  ensure();
})();
