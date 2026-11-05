(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  if (!$('initium')) return;
  const base = OC.generateUrl('/apps/cognos');
  const requests = new Map();
  let projects = [];
  function node(tag, text, className) {
    const n = document.createElement(tag);
    if (text !== undefined) n.textContent = text;
    if (className) n.className = className;
    return n;
  }
  function link(text, href) { const a = node('a', text); a.href = href; return a; }
  function notice(text, error = false) { $('in-notice').textContent = text; $('in-notice').classList.toggle('pw-error', error); }
  function date(t) { return new Date(t * 1000).toLocaleString(); }
  async function api(url, method = 'GET', data) {
    const r = await fetch(base + url, { method, credentials: 'same-origin',
      headers: { Accept: 'application/json', requesttoken: OC.requestToken, ...(data ? {'Content-Type': 'application/json'} : {}) },
      body: data ? JSON.stringify(data) : undefined });
    let body;
    try { body = await r.json(); } catch { throw new Error('未收到有效回复，请刷新检查结果。'); }
    if (!r.ok) throw new Error(body.message || '请求未完成');
    return body;
  }
  // 同一次提交重试时带同一个请求编号，只生效一次
  async function mutate(url, data) {
    const key = url + JSON.stringify(data);
    if (!requests.has(key)) requests.set(key, crypto.randomUUID());
    const result = await api(url, 'POST', {...data, request_id: requests.get(key)});
    requests.delete(key);
    return result;
  }
  async function busy(el, work) {
    const buttons = [...el.querySelectorAll('button')]; buttons.forEach(b => { b.disabled = true; });
    try { await work(); } catch (e) { notice(e.message, true); }
    finally { buttons.forEach(b => { b.disabled = false; }); }
  }
  // 叫 AI 拿第一句先试（不另存一句新的）
  async function tryWithAi(item) {
    const r = await api('/creations/' + item.id + '/messages', 'POST', {recall: item.first_id});
    notice(r.queued ? (r.who || 'AI') + ' 正忙，这一句排着，它做完会接着试。' : (r.who || 'AI') + ' 在试了，回答会回到这段会话。');
  }
  function formForm(item) {
    const form = node('form', undefined, 'in-form');
    form.append(node('p', '确立：先勾出促成它的那几句。原会话不会被搬动或改写。', 'pw-hint'));
    const picks = node('fieldset', undefined, 'in-picks');
    picks.append(node('legend', '促成它的话'));
    form.append(picks);
    api('/creations/' + item.id + '/messages').then(data => {
      for (const m of data.messages) {
        const label = node('label', undefined, 'pw-check in-pick');
        const box = node('input'); box.type = 'checkbox'; box.value = String(m.id); box.checked = m.id === item.first_id;
        label.append(box, document.createTextNode((m.role === 'user' ? '你：' : 'AI：') + m.content.slice(0, 300) + (m.content.length > 300 ? '…' : '')));
        picks.append(label);
      }
    }).catch(e => notice(e.message, true));
    const target = node('select'); target.id = 'in-target-' + item.id;
    const fresh = node('option', '确立成新项目'); fresh.value = ''; target.append(fresh);
    for (const p of projects) { const o = node('option', '成为「' + p.title + '」的又一段来路'); o.value = p.id; target.append(o); }
    const tl = node('label', '确立成'); tl.htmlFor = target.id;
    const name = node('input'); name.id = 'in-name-' + item.id; name.maxLength = 200; name.value = item.title.replace(/…$/, '');
    const nl = node('label', '项目名字'); nl.htmlFor = name.id;
    const desc = node('textarea'); desc.id = 'in-desc-' + item.id; desc.rows = 3; desc.placeholder = '现在怎么理解这件事（可留空，之后在项目里写）';
    const dl = node('label', '初步说明（可留空）'); dl.htmlFor = desc.id;
    const reason = node('input'); reason.id = 'in-reason-' + item.id; reason.maxLength = 2000; reason.placeholder = '例如：这条值得单独做下去';
    const rl = node('label', '为什么确立（可留空）'); rl.htmlFor = reason.id;
    target.addEventListener('change', () => { for (const n of [nl, name, dl, desc]) n.hidden = target.value !== ''; });
    const submit = node('button', '确立'); submit.type = 'submit';
    form.append(tl, target, nl, name, dl, desc, rl, reason, submit);
    form.addEventListener('submit', e => {
      e.preventDefault(); busy(form, async () => {
        const ids = [...picks.querySelectorAll('input:checked')].map(b => Number(b.value));
        if (!ids.length) throw new Error('先勾出促成它的那几句。');
        const r = await mutate('/initium/form', {creation_id: item.id, message_ids: ids, title: name.value,
          description: desc.value, reason: reason.value, project_id: target.value});
        location.href = base + '/projects/' + r.project_id;
      });
    });
    return form;
  }
  function render(items) {
    const list = $('in-items'); list.replaceChildren();
    for (const item of items) {
      const li = node('li', undefined, 'pw-decision in-item');
      li.dataset.creationId = String(item.id);
      for(const p of item.explored_from || []) {const source=node('p');source.append(document.createTextNode('从项目另起：'),link(p.title,base+'/projects/'+p.id));li.append(source);}
      const head = node('p'); head.append(link(item.title, base + '/creations/' + item.id));
      head.append(document.createTextNode(' · ' + item.message_count + ' 句 · ' + date(item.updated_at)));
      li.append(head);
      if (item.first) li.append(node('blockquote', item.first));
      if (item.formed.length) {
        const p = node('p', '已促成：', 'pw-hint');
        item.formed.forEach((f, i) => { if (i) p.append(document.createTextNode('、')); p.append(link(f.title, base + '/projects/' + f.project_id)); });
        li.append(p);
      }
      if (item.ai_working) li.append(node('p', 'AI 正在这段会话里做事。', 'pw-run'));
      const actions = node('div', undefined, 'pw-inline');
      if (item.first_id && !item.ai_working) {
        const t = node('button', '让 AI 试一下'); t.type = 'button';
        t.addEventListener('click', () => busy(actions, async () => { await tryWithAi(item); await load(); }));
        actions.append(t);
      }
      const f = node('button', item.formed.length ? '再确立一个' : '确立'); f.type = 'button';
      f.addEventListener('click', () => { if (!li.querySelector('.in-form')) li.append(formForm(item)); f.disabled = true; });
      actions.append(f);
      li.append(actions);
      list.append(li);
    }
    if (!items.length) list.append(node('li', '还没有。上面留一句就有了。', 'pw-hint'));
  }
  let exploringHash='';
  function renderExploring(items) {
    const hash=JSON.stringify(items);
    if(hash===exploringHash || document.activeElement?.closest('#in-exploring form')) return;
    exploringHash=hash;
    $('in-exploring-section').hidden=!items.length;
    const list=$('in-exploring');list.replaceChildren();
    for(const p of items){
      const li=node('li',undefined,'pw-decision in-item');li.dataset.projectId=p.id;
      li.append(link(p.title,base+'/projects/'+p.id));
      if(p.latest?.note)li.append(node('p',p.latest.note));
      if(p.latest?.reopen_when)li.append(node('p','再开条件或未完成：'+p.latest.reopen_when));
      const old=node('details');old.append(node('summary','原来的位置与讨论'));
      const locations=node('ul');for(const l of p.locations){const row=node('li');row.append(link(l.title,base+'/projects/locations/'+l.id));locations.append(row);}old.append(locations);li.append(old);
      const form=node('form');const text=node('textarea');text.rows=2;text.maxLength=20000;text.required=true;text.id='in-explore-'+p.id;
      const label=node('label','这次想探索什么');label.htmlFor=text.id;
      const submit=node('button','另起一段探索');submit.type='submit';form.append(label,text,submit);
      form.addEventListener('submit',e=>{e.preventDefault();busy(form,async()=>{
        const result=await mutate('/projects/'+p.id+'/explorations',{text:text.value});location.href=base+'/creations/'+result.creation_id;
      });});
      li.append(form);list.append(li);
    }
  }
  async function load() {
    const data = await api('/initium/data');
    projects = data.projects;
    renderExploring(data.exploring || []);
    if (!document.querySelector('.in-form')) render(data.items);
  }
  $('in-capture-form').addEventListener('submit', e => {
    e.preventDefault(); busy(e.currentTarget, async () => {
      const r = await mutate('/initium/data', {text: $('in-text').value});
      const tryIt = $('in-try').checked;
      $('in-text').value = ''; $('in-try').checked = false;
      if (tryIt && r.message_id) await tryWithAi({id: r.creation_id, first_id: r.message_id});
      else notice('留下了。');
      await load();
    });
  });
  setInterval(() => { if (!document.hidden) load().catch(() => {}); }, 8000);
  load().catch(e => notice(e.message, true));
})();
