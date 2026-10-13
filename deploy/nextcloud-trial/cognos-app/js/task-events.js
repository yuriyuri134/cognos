(() => {
  'use strict';
  const names={open:'未开始',in_progress:'进行中',blocked:'阻塞',done:'完成'};
  const labels={created:'创建了任务',completed:'完成了任务',reopened:'重新打开了任务',assigned:'指派了负责人',unassigned:'取消了指派',edited:'修改了任务',status_changed:'修改了任务状态'};
  const node=(tag,value)=>{const e=document.createElement(tag);e.textContent=value;return e;};
  window.CognosTaskEvents={render(event,project=false){
    const item=document.createElement('li');item.dataset.eventId=event.id;item.style.cssText='margin-bottom:12px;overflow-wrap:anywhere;';
    const who=node('a',event.actor_name);who.href='/index.php/u/'+encodeURIComponent(event.actor_uid);
    const time=node('time',new Date(event.created_at*1000).toLocaleString('zh-CN'));time.dateTime=new Date(event.created_at*1000).toISOString();
    item.append(who,document.createTextNode(' '+(labels[event.kind]||'更新了任务')+' · '),time);
    if(project){
      const title=event.after_state?.title||event.before_state?.title||'任务 #'+event.card_id;
      const target=node(event.task_url?'a':'span',title);if(event.task_url)target.href=event.task_url;else target.title='任务已移出此看板、删除或不可访问';
      item.append(document.createElement('br'),target);
    }
    const before=event.before_state||{},after=event.after_state||{},detail=document.createElement('details');detail.append(node('summary','查看变化'));
    const format=(field,value)=>{
      if(field==='status')return names[value]||'未记录';
      if(field==='done')return value?'已完成':'未完成';
      if(field==='stack')return value?.title||'无';
      if(field==='assignees'||field==='labels')return(value||[]).map(v=>v.name||v.title).join('、')||'无';
      return value??'无';
    };
    for(const [field,label] of [['status','任务状态'],['title','任务名'],['description','说明'],['done','完成标记'],['stack','所在列'],['assignees','负责人'],['labels','标签'],['duedate','期限'],['startdate','开始时间'],['color','颜色']]){
      if(JSON.stringify(before[field])!==JSON.stringify(after[field]))detail.append(node('p',label+'：'+format(field,before[field])+' → '+format(field,after[field])));
    }
    item.append(detail);return item;
  }};
})();
