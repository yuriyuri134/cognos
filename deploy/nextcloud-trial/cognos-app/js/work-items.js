(() => {
  'use strict';
  const app=document.getElementById('project-workspace'), list=document.getElementById('wi-items');
  if (!app || !list || (!app.dataset.projectId && !app.dataset.locationId)) return;
  const base=OC.generateUrl('/apps/cognos'), locationId=app.dataset.locationId;
  let projectId=app.dataset.projectId;
  const labels={open:'待开展',in_progress:'进行中',blocked:'等待',done:'完成',cancelled:'取消',split:'已拆分'};
  const delegationLabels={sending:'等待派发',starting:'正在交接',sent:'已交出',returned:'已交回',not_taken:'尚未接下'};
  const runLabels={queued:'等待开始',claimed:'已领取',running:'执行中',result_pending:'等待保存',delivered:'结果已收回',failed:'明确失败',unknown:'状态未知'};
  const actions={created:'登记',updated:'修改安排',delegated:'登记委托',split:'拆分'};
  const ids=new Map(), dirtyForms=new Set();
  document.getElementById('pw-work-items-section').addEventListener('input',e=>{const f=e.target.closest('form');if(f)dirtyForms.add(f);});
  window.addEventListener('beforeunload',e=>{if([...dirtyForms].some(f=>f.isConnected)){e.preventDefault();e.returnValue='';}});
  function n(tag,text,cls) {const x=document.createElement(tag);if(text!==undefined)x.textContent=text;if(cls)x.className=cls;return x;}
  function tell(text) {document.getElementById('wi-notice').textContent=text;}
  async function api(route,method='GET',data) {
    const key=method+route+JSON.stringify(data);
    if(method!=='GET' && !ids.has(key))ids.set(key,crypto.randomUUID());
    const r=await fetch(base+route,{method,headers:{Accept:'application/json',requesttoken:OC.requestToken,'Content-Type':'application/json'},
      body:data?JSON.stringify({...data,request_id:ids.get(key)}):undefined});
    const body=await r.json();if(!r.ok){const e=new Error(body.message||'工作操作未完成');e.status=r.status;throw e;}
    ids.delete(key);return body;
  }
  function button(text,fn) {const b=n('button',text);b.type='button';b.addEventListener('click',async()=>{b.disabled=true;try{await fn();}catch(e){tell(e.message);}finally{b.disabled=false;}});return b;}
  function field(form,key,label,value='',required=false,max=4000) {
    const labelEl=n('label',label), input=n(key==='owner'?'input':'textarea');input.name=key;input.value=value;input.required=required;input.maxLength=max;
    if(input.tagName==='TEXTAREA')input.rows=2;labelEl.append(input);form.append(labelEl);return input;
  }
  function fields(form,w={}) {
    field(form,'description','工作范围',w.description||'',true);
    field(form,'owner','负责人',w.owner||'',false,200);
    field(form,'deliverable','交付什么',w.deliverable||'');field(form,'acceptance','怎样验收',w.acceptance||'');
  }
  const values=f=>Object.fromEntries(new FormData(f));
  async function refresh() {
    if([...dirtyForms].some(f=>list.contains(f)) || list.querySelector('details.wi-edit[open], details.wi-split[open]')){tell('先保存或关闭正在编辑的工作，再刷新列表；输入不会自动丢弃。');return;}
    if(locationId)projectId=(await api('/projects/locations/'+locationId+'/data')).location.project_id;
    const {items}=await api('/projects/'+projectId+'/work-items'+(locationId?'?location_id='+encodeURIComponent(locationId):''));
    list.replaceChildren(...items.map(card));if(!items.length)list.append(n('li','这里还没有登记执行工作。','pw-hint'));
  }
  function card(w) {
    const li=n('li',undefined,'pw-decision');li.id='wi-'+w.id;li.dataset.workId=w.id;
    li.append(n('h3',w.description),n('p',labels[w.state]+' · '+(w.owner||'负责人未指定')+' · 第 '+w.revision+' 版','pw-hint'));
    if(!locationId){const a=n('a','所在位置：'+w.location_title);a.href=base+'/projects/locations/'+w.location_id+'#wi-'+w.id;li.append(a);}
    if(w.deliverable)li.append(n('p','交付：'+w.deliverable));if(w.acceptance)li.append(n('p','验收：'+w.acceptance));if(w.evidence)li.append(n('p','完成依据：'+w.evidence));
    if(w.split_from_id){const a=n('a','由原工作第 '+w.split_from_revision+' 版拆出');a.href='#wi-'+w.split_from_id;li.append(a);}
    if(w.children.length){const ul=n('ul');for(const child of w.children){const a=n('a',child.description+' · '+labels[child.state]);a.href='#wi-'+child.id;const row=n('li');row.append(a);ul.append(row);}li.append(ul);}
    for(const d of w.delegations)li.append(n('p','原委托：'+(delegationLabels[d.state]||d.state)+'；实际运行：'+(runLabels[d.run_state]||d.run_state||'尚无运行记录')+(w.state==='split'?'。仍留在原工作，未复制到子项。':''),'pw-hint'));
    if(w.state!=='split')li.append(editor(w));
    if(['open','in_progress','blocked'].includes(w.state))li.append(splitForm(w));
    if(locationId && ['open','in_progress'].includes(w.state))li.append(button('安排这份工作给 AI',()=>document.dispatchEvent(new CustomEvent('cognos:delegate-work',{detail:w}))));
    const history=n('details'),summary=n('summary','查看安排经过与原版本');history.append(summary);
    for(const e of w.events){const row=n('section');row.append(n('h4','第 '+e.revision+' 版 · '+(actions[e.action]||e.action)+' · '+e.actor+' · '+new Date(Number(e.created_at)*1000).toLocaleString()));
      const s=e.snapshot;for(const [key,label] of [['description','范围'],['owner','负责人'],['deliverable','交付'],['acceptance','验收'],['evidence','完成依据']])if(s[key])row.append(n('p',label+'：'+s[key]));
      if(e.action==='split')row.append(n('p','原工作保留；子项有各自来源记录，旧委托没有复制。'));else if(e.action==='delegated')row.append(n('p','委托已登记，原始交接记录保留；实际返回与工作完成分别记录。','pw-hint'));else if(e.note)row.append(n('p',e.note,'pw-hint'));history.append(row);}
    li.append(history);return li;
  }
  function editor(w) {
    const details=n('details',undefined,'wi-edit');details.append(n('summary','修改安排或记录结果'));const form=n('form');fields(form,w);
    const label=n('label','状态'),select=n('select');select.name='state';for(const [key,text]of Object.entries(labels))if(key!=='split'){const o=n('option',text);o.value=key;o.selected=key===w.state;select.append(o);}label.append(select);form.append(label);
    field(form,'evidence','实际交付或验收依据（完成时需要）',w.evidence,false,8000);field(form,'note','调整、等待、取消或再开的说明','',false,2000);
    let expected=w.revision;const compare=n('div');form.append(compare);const save=n('button','保存安排');save.type='submit';form.append(save);
    form.addEventListener('submit',async e=>{e.preventDefault();save.disabled=true;try{await api('/projects/work/'+w.id,'PUT',{...values(form),expected_revision:expected});dirtyForms.delete(form);details.open=false;await refresh();tell('工作安排已保存；运行返回不会自动将它标为完成。');}
      catch(err){tell(err.message);if(err.status===409){const latest=(await api('/projects/work/'+w.id)).item;compare.replaceChildren(n('p','新版第 '+latest.revision+' 版：'+latest.description+'；状态：'+labels[latest.state]+'；负责人：'+(latest.owner||'未指定')),n('p','新版交付：'+latest.deliverable),n('p','新版验收：'+latest.acceptance),n('p','新版完成依据：'+latest.evidence),
        button('我已比较，以新版为基础保留上面的输入',()=>{expected=latest.revision;compare.replaceChildren(n('p','已以第 '+expected+' 版为基础；请核对上方输入再保存。'));}));}}finally{save.disabled=false;}});
    form.append(button('取消本次编辑',async()=>{dirtyForms.delete(form);details.open=false;await refresh();}));
    details.append(form);return details;
  }
  function splitForm(w) {
    const details=n('details',undefined,'wi-split');details.append(n('summary','拆成几份独立工作'));const form=n('form'),parts=n('div'),preview=n('div');
    const forms=[];let basis=null,chosen=null;
    function add(){if(forms.length>=12)return;basis=null;chosen=null;preview.replaceChildren();const f=n('fieldset'),legend=n('legend','一份独立工作');f.append(legend);fields(f);forms.push(f);parts.append(f);}
    form.addEventListener('submit',e=>e.preventDefault());
    add();add();form.append(n('p','每份写自己的范围和安排。原工作、委托、结果会保留，旧授权不复制。','pw-hint'),parts,button('再加一份',add),preview);
    const show=button('预览拆分',async()=>{if(!form.reportValidity())return;chosen=forms.map(f=>Object.fromEntries([...f.querySelectorAll('[name]')].map(i=>[i.name,i.value])));
      const p=await api('/projects/work/'+w.id+'/split/preview','POST',{parts:chosen});basis=p.basis;
      preview.replaceChildren(...p.consequences.map(t=>n('p',t)),button('确认拆分',async()=>{try{await api('/projects/work/'+w.id+'/split','POST',{parts:chosen,basis});dirtyForms.delete(form);details.open=false;await refresh();tell('已拆分。子项尚未派发，原委托和结果仍在原工作。');}catch(e){basis=null;preview.replaceChildren(n('p',e.message+'；输入仍保留，请重新预览。'));throw e;}}));preview.scrollIntoView({block:'nearest'});});
    form.addEventListener('input',()=>{basis=null;preview.replaceChildren();});form.append(show,button('取消拆分',async()=>{dirtyForms.delete(form);details.open=false;await refresh();}));details.append(form);return details;
  }
  document.getElementById('wi-refresh').addEventListener('click',()=>refresh().catch(e=>tell(e.message)));
  document.addEventListener('cognos:work-updated',()=>refresh().catch(e=>tell(e.message)));
  document.getElementById('wi-create').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget,b=f.querySelector('button');b.disabled=true;
    try{const data={};for(const key of ['description','owner','deliverable','acceptance'])data[key]=document.getElementById('wi-'+key).value;await api('/projects/locations/'+locationId+'/work-items','POST',data);dirtyForms.delete(f);f.reset();await refresh();tell('工作已登记，尚未自动派发。');}catch(err){tell(err.message);}finally{b.disabled=false;}});
  refresh().catch(e=>tell(e.message));
})();
