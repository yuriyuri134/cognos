(() => {
  'use strict';
  const app=document.getElementById('project-workspace'), list=document.getElementById('at-items'), section=document.getElementById('pw-attempts-section');
  if(!app||!list||(!app.dataset.projectId&&!app.dataset.locationId))return;
  const base=OC.generateUrl('/apps/cognos'), locationId=app.dataset.locationId, dirty=new Set(), requests=new Map();
  const states={open:'进行中',paused:'暂停',ended:'告一段落'};
  let projectId=app.dataset.projectId, locations=[], sources=[], sourceOmitted=0, sourceTruncated=false;
  const n=(tag,text,cls)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;};
  const tell=text=>document.getElementById('at-notice').textContent=text;
  const date=t=>new Date(Number(t)*1000).toLocaleString();
  const key=r=>JSON.stringify([r.type,String(r.id),r.version??null]);
  async function api(route,method='GET',data){
    const intent=method+route+JSON.stringify(data);if(method!=='GET'&&!requests.has(intent))requests.set(intent,crypto.randomUUID());
    const response=await fetch(base+route,{method,headers:{Accept:'application/json',requesttoken:OC.requestToken,'Content-Type':'application/json'},body:data?JSON.stringify({...data,request_id:requests.get(intent)}):undefined});
    let body;try{body=await response.json();}catch{throw Error('没有收到有效回复，请重试同一操作或刷新核对。');}
    if(!response.ok){const e=Error(body.message||'尝试记录未保存');e.status=response.status;throw e;}requests.delete(intent);return body;
  }
  function button(text,fn,cls){const b=n('button',text,cls);b.type='button';b.addEventListener('click',async()=>{b.disabled=true;try{await fn();}catch(e){tell(e.message);}finally{b.disabled=false;}});return b;}
  function field(form,name,label,value='',required=false,max=8000){const row=n('label',label),input=n(name==='title'?'input':'textarea');input.name=name;input.value=value;input.required=required;input.maxLength=max;if(input.tagName==='TEXTAREA')input.rows=2;row.append(input);form.append(row);return input;}
  function details(text,cls){const e=n('details',undefined,cls);e.append(n('summary',text));return e;}
  function textFields(host,snapshot,isSummary=false){
    for(const [name,label]of(isSummary?[['title','阶段'],['coverage','覆盖范围'],['content','这阶段留下的东西']]:[['title','名字'],['introduction','现在的简介'],['focus','当时关注'],['next_entry','停在哪、还可从哪里进入']]))if(snapshot[name])host.append(n('p',label+'：'+snapshot[name]));
    if(!isSummary&&snapshot.state)host.append(n('p','状态：'+(states[snapshot.state]||snapshot.state),'pw-hint'));
  }
  function referenceText(ref){const choice=sources.find(s=>key(s)===key(ref)),isLink=ref.type==='link'||ref.status==='link_only';const label=isLink?String(ref.id):ref.label||choice?.label||'相关记录',version=ref.version!=null?'第 '+ref.version+' 版':'';
    return label+(version&&!label.includes(version)?' · '+version:'')+(ref.note?' · '+ref.note:'')+(isLink||choice?.status==='link_only'?' · 仅链接，未取得正文':ref.status==='missing'?' · 当前无法取得':'');}
  function showRefs(host,refs,label){host.append(n('p',refs?.length?label+'：':'未绑定具体记录','pw-hint'));if(refs?.length){const ul=n('ul',undefined,'at-ref-list');for(const ref of refs){const li=n('li',referenceText(ref));if(ref.status==='link_only'||ref.type==='link'){try{const url=new URL(ref.id);if(['http:','https:'].includes(url.protocol)){const a=n('a','打开原链接');a.href=url.href;a.target='_blank';a.rel='noreferrer noopener';li.append(document.createTextNode(' '),a);}}catch{}}else li.append(button('查看原记录',async()=>{const result=await api('/projects/attempts/reference/read','POST',{ref});const output=li.querySelector('pre')||n('pre');output.textContent=result.status==='missing'?'这条具体记录目前无法取得，未替换成其他版本。':result.status==='link_only'?'仅链接，未取得正文':typeof result.record==='string'?result.record:JSON.stringify(result.record,null,2);li.append(output);}));ul.append(li);}host.append(ul);}}
  function history(host,revisions,isSummary){const box=details('查看原版本');for(const version of revisions||[]){const row=n('section');row.append(n('h5','第 '+version.revision+' 版 · '+version.author+' · '+date(version.created_at)));textFields(row,version.snapshot||version,isSummary);showRefs(row,(version.snapshot||version)[isSummary?'covered_refs':'refs']||[],'当时关联');box.append(row);}host.append(box);}
  function refPicker(form,initial=[],summary=false){
    let selected=initial.map(r=>({...r}));const box=details(summary?'关联本阶段覆盖的具体记录（可不选）':'关联会话、材料或工作（可不选）','at-ref-picker');
    box.append(n('p','只记录这次关联的具体版本；链接存在不代表已经取得正文。','pw-hint'));
    if(sourceTruncated||sourceOmitted>0)box.append(n('p','这里只列出近期可选记录，另有 '+sourceOmitted+' 条未列出；已有引用仍保留。','pw-hint at-source-omitted'));
    const label=n('label','选择可访问的记录'),select=n('select');select.className='at-ref-source';label.append(select);box.append(label);
    const empty=n('option',sources.length?'选择一条记录':'这里暂时没有可选记录');empty.value='';select.append(empty);
    sources.forEach(source=>{const option=n('option',source.label+(source.status==='link_only'?'（仅链接）':''));option.value=JSON.stringify(source);select.append(option);});
    const note=n('input');note.className='at-ref-note';note.maxLength=2000;const noteLabel=n('label','这条记录在这里的用途（可留空）');noteLabel.append(note);box.append(noteLabel);
    const entries=n('ul',undefined,'at-ref-list');
    function mark(){dirty.add(form);}
    function render(){entries.replaceChildren();selected.forEach((ref,index)=>{const li=n('li',referenceText(ref));li.append(button('移除关联',()=>{selected.splice(index,1);mark();render();}));entries.append(li);});}
    box.append(button('加入关联',()=>{if(select.value===''){tell('先选择一条记录，或直接留空保存。');return;}const source=JSON.parse(select.value);if(selected.some(r=>key(r)===key(source))){tell('这条具体记录已经关联。');return;}selected.push({...source,note:note.value.trim()});note.value='';select.value='';mark();render();}));
    const linkLabel=n('label','外部链接（可选，不读取正文）'),link=n('input');link.type='url';link.className='at-ref-link';link.maxLength=2000;link.placeholder='https://';linkLabel.append(link);box.append(linkLabel,button('加入链接',()=>{if(!link.value.trim())return;let url;try{url=new URL(link.value.trim());if(!['http:','https:'].includes(url.protocol))throw Error();}catch{tell('请填完整的 http 或 https 链接；也可以留空。');return;}const ref={type:'link',id:url.href,version:null,note:note.value.trim(),status:'link_only',label:url.href};if(selected.some(r=>key(r)===key(ref))){tell('这个链接已经关联。');return;}selected.push(ref);link.value='';note.value='';mark();render();}),entries);render();form.append(box);return()=>selected.map(r=>({type:r.type,id:String(r.id),version:r.version??null,note:r.note||''}));
  }
  function attemptFields(form,item={},creating=false){
    field(form,'title','名字或一句话',item.title||'',true,200);const extra=details('简介与开展位置（都可以先留空）');
    field(extra,'introduction','现在的简介',item.introduction||'',false,50000);field(extra,'focus','当时关注、想试什么',item.focus||'',false,4000);field(extra,'next_entry','停在哪、还可从哪里进入',item.next_entry||'',false,4000);
    const row=n('label','这一项尝试的状态'),state=n('select');state.name='state';for(const [value,label]of Object.entries(states)){const option=n('option',label);option.value=value;option.selected=value===(item.state||'open');state.append(option);}row.append(state);extra.append(row,n('p','告一段落只改变这项尝试；关联工作的完成与采用另行记录。','pw-hint'));
    const scope=n('fieldset');scope.append(n('legend','在哪些位置展开（可多选）'));
    const selected=item.location_ids||[locationId];const known=new Set(locations.map(l=>l.id));
    for(const loc of locations){const label=n('label',loc.title,'pw-check'),check=n('input');check.type='checkbox';check.name='at-location';check.value=loc.id;check.checked=selected.includes(loc.id);label.prepend(check);scope.append(label);}
    const elsewhere=selected.filter(id=>!known.has(id));if(elsewhere.length)scope.append(n('p','另有关联的 '+elsewhere.length+' 处位置，保存时继续保留。','pw-hint'));extra.append(scope);form.append(extra);const refs=refPicker(form,item.refs||[]);
    return()=>({title:form.elements.title.value,introduction:form.elements.introduction.value,focus:form.elements.focus.value,next_entry:form.elements.next_entry.value,state:state.value,
      location_ids:[...form.querySelectorAll('[name="at-location"]:checked')].map(c=>c.value).concat(elsewhere),refs:refs()});
  }
  function summaryFields(form,item={}){field(form,'content','这一阶段留下什么',item.content||'',true,50000);const extra=details('阶段名称与覆盖范围（可留空）');field(extra,'title','阶段名称',item.title||'',false,200);field(extra,'coverage','覆盖哪段开展、会话或活动',item.coverage||'',false,4000);form.append(extra);const refs=refPicker(form,item.covered_refs||[],true);return()=>({title:form.elements.title.value,coverage:form.elements.coverage.value,content:form.elements.content.value,covered_refs:refs()});}
  function saveForm(form,box,{label,route,method='POST',values,item,isSummary=false,loadLatest,onSaved}){
    let expected=item?.revision,needsComparison=false;const conflict=n('div',undefined,'at-conflict'),save=n('button',label);save.type='submit';form.append(conflict,save);
    form.addEventListener('submit',async e=>{e.preventDefault();if(needsComparison)return;save.disabled=true;
      try{const payload=values();if(expected!=null)payload.expected_revision=expected;const result=await api(route,method,payload);dirty.delete(form);box.open=false;await onSaved(result);}
      catch(error){tell(error.message);if(error.status===409&&item){try{const latest=loadLatest?await loadLatest():(await api('/projects/attempts/'+item.id)).item;
        needsComparison=true;conflict.replaceChildren(n('p','已有新版，第 '+latest.revision+' 版。你填写的内容仍保留；先比较再保存。'));textFields(conflict,latest,isSummary);showRefs(conflict,latest[isSummary?'covered_refs':'refs']||[],'新版关联');
        conflict.append(button('我已比较，以新版为基础保留上面的输入',()=>{expected=latest.revision;needsComparison=false;save.disabled=false;conflict.replaceChildren(n('p','已以第 '+expected+' 版为基础，请核对上方输入再保存。'));}));
      }catch(fetchError){tell(fetchError.message);}}}finally{save.disabled=needsComparison;}});
    form.append(button('取消本次编辑',async()=>{dirty.delete(form);box.open=false;await refresh();}));
  }
  function editor(item){const box=details('修改简介与关联','at-edit'),form=n('form');const values=attemptFields(form,item);saveForm(form,box,{label:'保存简介',route:'/projects/attempts/'+item.id,method:'PUT',values,item,onSaved:async()=>{await refresh();tell('简介已保存，旧版本保留。');}});box.append(form);return box;}
  function summaryCard(item,attempt){const li=n('li',undefined,'at-stage');li.id='ats-'+item.id;li.append(n('h4',item.title||'阶段记录'),n('p',item.author+' · '+date(item.created_at)+' · 第 '+item.revision+' 版','pw-hint'));
    li.append(n('p',item.coverage?'覆盖范围：'+item.coverage:'覆盖范围未说明','pw-hint'),n('p',item.content));showRefs(li,item.covered_refs||[],'覆盖的记录');history(li,item.revisions,true);
    const box=details('修订这条阶段记录','at-summary-edit'),form=n('form'),values=summaryFields(form,item);
    saveForm(form,box,{label:'保存这条阶段记录',route:'/projects/attempt-summaries/'+item.id,method:'PUT',values,item,isSummary:true,loadLatest:async()=>(await api('/projects/attempts/'+attempt.id)).item.summaries.find(s=>s.id===item.id),onSaved:async()=>{await refresh();tell('阶段记录已修订，原版本保留。');}});box.append(form);li.append(box);return li;
  }
  function card(item){const li=n('li',undefined,'pw-decision at-attempt');li.id='at-'+item.id;li.dataset.attemptId=item.id;li.append(n('h3',item.title),n('p',(states[item.state]||item.state)+' · '+item.author+' · 第 '+item.revision+' 版','pw-hint'));
    if(item.introduction)li.append(n('p','现在的简介：'+item.introduction));if(item.focus)li.append(n('p','关注：'+item.focus));if(item.next_entry)li.append(n('p','停在哪、还可从哪里进入：'+item.next_entry));
    const locs=n('p',undefined,'pw-hint');for(const id of item.location_ids||[]){const location=locations.find(l=>l.id===id),a=n('a',location?.title||'关联位置');a.href=base+'/projects/locations/'+encodeURIComponent(id);locs.append(a,document.createTextNode(' '));}li.append(locs);showRefs(li,item.refs||[],'相关记录');li.append(editor(item));history(li,item.revisions,false);
    const stages=n('section');stages.append(n('h4','阶段留下的东西'));const summaries=n('ol',undefined,'at-summaries');for(const summary of [...(item.summaries||[])].sort((a,b)=>(Number(a.stage_order)||0)-(Number(b.stage_order)||0)||a.created_at-b.created_at||a.id.localeCompare(b.id)))summaries.append(summaryCard(summary,item));if(!item.summaries?.length)summaries.append(n('li','还没有阶段记录。','pw-hint'));stages.append(summaries);
    const box=details('追加一条阶段记录','at-summary-create'),form=n('form'),values=summaryFields(form);saveForm(form,box,{label:'留下阶段记录',route:'/projects/attempts/'+item.id+'/summaries',values,onSaved:async()=>{await refresh();tell('已追加阶段记录；相关工作与采用状态没有改变。');}});box.append(form);stages.append(box);li.append(stages);return li;
  }
  function hasInput(){return [...dirty].some(f=>f.isConnected)||!!section.querySelector('details.at-edit[open],details.at-summary-edit[open],details.at-summary-create[open]');}
  async function refresh(){if(hasInput()){tell('先保存或取消正在编辑的尝试，再刷新；填写的内容会保留。');return false;}const data=await api('/projects/'+projectId+'/attempts'+(locationId?'?location_id='+encodeURIComponent(locationId):''));list.replaceChildren(...data.items.map(card));if(!data.items.length)list.append(n('li','这里还没有留下尝试，一句话也可以开始。','pw-hint'));return true;}
  section.addEventListener('input',e=>{const form=e.target.closest('form');if(form)dirty.add(form);});
  window.addEventListener('beforeunload',e=>{if([...dirty].some(f=>f.isConnected)){e.preventDefault();e.returnValue='';}});
  document.getElementById('at-refresh').addEventListener('click',()=>refresh().catch(e=>tell(e.message)));
  async function init(){
    let project;if(locationId){const data=await api('/projects/locations/'+locationId+'/data');project=data.project;projectId=data.location.project_id;}else project=(await api('/projects/'+projectId+'/data')).project;
    locations=project.locations||[];const sourceLocation=locationId||project.root_location_id;
    try{const choices=await api('/projects/locations/'+sourceLocation+'/attempt-sources');sources=choices.sources||[];sourceOmitted=Math.max(0,Number(choices.omitted)||0);sourceTruncated=choices.truncated===true;}catch{tell('相关记录暂时没有取得；可以先保存文字，稍后再关联。');}
    if(locationId)setupCreate(document.getElementById('at-create'));
    await refresh();
  }
  function setupCreate(form){const values=attemptFields(form);saveForm(form,document.getElementById('at-create-box'),{label:'留下尝试',route:'/projects/locations/'+locationId+'/attempts',values,onSaved:async()=>{dirty.delete(form);const replacement=form.cloneNode(false);form.replaceWith(replacement);setupCreate(replacement);await refresh();tell('尝试已留下，没有自动安排执行工作。');}});}
  init().catch(e=>tell(e.message));
})();
