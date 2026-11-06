/* Initium 一段对话右边：读完这段。
 * 三个读者只拿原话各自读（顺着读完、只读人写的、从最近往回读），读完放在这里：各自读出了什么、读得不一样的地方、
 * 接着它需要什么、Cognos 照着把它放进了 Norm 哪里（可以撤回）。对话够长时读者自己来读，也可以让它们现在读。
 * 占着原来「这里」那一栏（window.CognosSpacePanel 的名字也沿用，creation-detail.js 会在对话变了时叫 reload）。 */
(function () {
  'use strict';
  const app = '/index.php/apps/cognos';
  const S = { id: null, body: null, data: null, timer: null, collapsed: false, busy: false };

  function node(tag, text, cls) { const n = document.createElement(tag); if (text !== undefined && text !== null) n.textContent = text; if (cls) n.className = cls; return n; }
  function button(text, cls, onClick) { const b = node('button', text, 'cg-btn ' + (cls || '')); b.type = 'button'; b.addEventListener('click', onClick); return b; }
  async function api(path, body) {
    const r = await fetch(app + path, { method: body ? 'POST' : 'GET', credentials: 'same-origin',
      headers: { Accept: 'application/json', requesttoken: OC.requestToken, ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined });
    const json = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(json.message || ('HTTP ' + r.status));
    return json;
  }
  function ago(ts) {
    const s = Math.floor(Date.now() / 1000) - ts;
    if (s < 120) return '刚刚'; if (s < 3600) return Math.floor(s / 60) + ' 分钟前'; if (s < 86400) return Math.floor(s / 3600) + ' 小时前';
    const d = new Date(ts * 1000); return (d.getMonth() + 1) + '/' + d.getDate();
  }

  function init(creationId) {
    S.id = creationId;
    S.body = document.getElementById('space-panel-body');
    S.collapsed = window.innerWidth <= 1100;
    try { const v = localStorage.getItem('cognos-readers-collapsed'); if (v !== null) S.collapsed = v === '1'; } catch (e) { /* 没有本机存储也照样能用 */ }
    apply();
    document.getElementById('space-panel-toggle')?.addEventListener('click', toggle);
    document.getElementById('space-panel-close')?.addEventListener('click', toggle);
    load();
  }
  function toggle() { S.collapsed = !S.collapsed; try { localStorage.setItem('cognos-readers-collapsed', S.collapsed ? '1' : '0'); } catch (e) { /* 同上 */ } apply(); }
  function apply() {
    document.querySelector('.cognos-chat-app')?.classList.toggle('space-panel-collapsed', S.collapsed);
    document.getElementById('space-panel-toggle')?.setAttribute('aria-pressed', S.collapsed ? 'false' : 'true');
  }

  async function load() {
    clearTimeout(S.timer);
    try { S.data = await api('/creations/' + S.id + '/readers'); } catch (e) { S.body.replaceChildren(node('p', '读不到读者：' + e.message, 'cg-hint')); return; }
    render();
    if (S.data.latest && S.data.latest.state === 'reading') S.timer = setTimeout(load, 6000);
  }

  async function readNow() {
    if (S.busy) return; S.busy = true;
    try { const r = await api('/creations/' + S.id + '/readers', {}); if (!r.started && r.why) alert(r.why); }
    catch (e) { alert('读者没来：' + e.message); }
    finally { S.busy = false; load(); }
  }

  function render() {
    const d = S.data; const box = node('div', undefined, 'cg-readers');
    if (d.in_norm) {
      box.append(node('p', '这段是 Norm 里一个空间自己的对话。读者会在那个空间里回头读它。', 'cg-hint'));
      S.body.replaceChildren(box); return;
    }
    const latest = d.latest; const done = d.done;
    const head = node('div');
    if (latest && latest.state === 'reading') {
      const s = node('div', undefined, 'cg-small'); s.append(node('span', '', 'cg-spin'), document.createTextNode('三个读者正在各自读，只读了原话'));
      head.append(s);
    } else if (done) head.append(node('div', ago(done.created_at) + ' · 只读了原话', 'cg-small'));
    head.append(node('h2', done ? '三个读者各自读完' : '还没人读过这段'));
    box.append(head);
    if (latest && latest.state === 'failed') box.append(node('p', '上次没读完：' + (latest.error || '不知道为什么'), 'cg-hint'));
    if (!done) {
      if (!latest || latest.state !== 'reading') {
        box.append(node('p', '读者不带上下文，也不知道 Cognos 是什么，只拿这段原话去读：它到底是什么、往哪走、接着需要什么。对话再长一点，它们会自己来读。', 'cg-hint'));
        box.append(button('现在就读', 'cg-btn-dark', readNow));
      }
      S.body.replaceChildren(box); return;
    }
    const res = done.result || {}; const c = res.compare || {};
    const list = node('div'); list.style.cssText = 'display:flex;flex-direction:column;gap:10px';
    (res.readings || []).forEach((r, i) => {
      const card = node('div', undefined, 'cg-reader');
      card.append(node('div', '读者' + '一二三四五'[i] + ' · ' + r.label, 'cg-reader-label'), node('div', r.what || r.raw || '（这位没读出东西）'));
      if (r.where || (r.needs || []).length || (r.quotes || []).length) {
        const det = node('details'); det.append(node('summary', '往哪走、它依据的原话'));
        if (r.where) det.append(node('p', '往哪走：' + r.where));
        if ((r.needs || []).length) { const ul = node('ul'); r.needs.forEach(n => ul.append(node('li', n))); det.append(node('div', '它觉得接着需要：', 'cg-small'), ul); }
        for (const q of r.quotes || []) det.append(node('p', '「' + q + '」', 'cg-small'));
        card.append(det);
      }
      list.append(card);
    });
    box.append(list);
    if (c.differ) { const df = node('div', undefined, 'cg-differ'); df.append(node('strong', '它们读得不一样的地方'), node('div', c.differ)); box.append(df); }
    if ((c.needs || []).length) {
      const nd = node('div', undefined, 'cg-needs'); nd.append(node('div', '接着它需要什么', 'cg-small'));
      const ul = node('ul'); c.needs.forEach(n => ul.append(node('li', n))); nd.append(ul); box.append(nd);
    }
    if (!c.differ && res.compare_raw) box.append(node('p', res.compare_raw, 'cg-hint'));
    box.append(placed(done, d.linked));
    const again = node('div'); again.append(button(latest && latest.state === 'reading' ? '正在读…' : '让读者再读一遍', 'cg-btn-small', readNow));
    if (latest && latest.state === 'reading') again.firstChild.disabled = true;
    box.append(again);
    S.body.replaceChildren(box);
  }

  function placed(done, linked) {
    const a = done.applied || {}; const box = node('div', undefined, 'cg-placed');
    const go = (space, text) => { const l = node('a', undefined, 'cg-go'); l.href = app + '/norm/projects/' + space; l.append(node('span', text), node('span', '进去看')); return l; };
    if (done.undone_at) {
      box.append(node('div', '撤回了，留在 Initium。', 'cg-hint'));
    } else if (a.action === 'new' || a.action === 'existing' || a.action === 'kept') {
      const row = node('div', undefined, 'cg-placed-row');
      const where = node('div'); where.append(node('span', a.action === 'kept' ? '还在 ' : '已放进 ', 'cg-small'), document.createTextNode('Norm · ' + (a.action === 'new' && !a.board ? '带着自己做法的 · ' : (a.board ? a.board + ' · ' : ''))));
      where.append(node('strong', a.space_title || ''));
      row.append(where);
      if (a.action !== 'kept') row.append(button('撤回', 'cg-btn-small', async () => {
        if (!confirm('撤回？' + (a.action === 'new' ? '新开的这个空间会撤掉，' : '') + '这段对话留在 Initium。')) return;
        try { await api('/readers/' + done.id + '/undo', {}); } catch (e) { alert(e.message); } load();
      }));
      box.append(row);
      if (a.why) box.append(node('div', a.why, 'cg-small'));
      box.append(go(a.space_id, a.action === 'new' && !a.board ? '现成的样子装不下，它带上了自己的做法' : '在 Norm 里接着'));
    } else {
      box.append(node('div', '读者觉得它还没成形，先留在 Initium。' + (a.why ? a.why : ''), 'cg-hint'));
      if (linked) box.append(go(linked.id, '它连着 Norm 里的「' + linked.title + '」'));
    }
    return box;
  }

  window.CognosSpacePanel = {
    init,
    reload() { load(); },
    keep() {},
    hang() { return Promise.resolve(null); },
  };
})();
