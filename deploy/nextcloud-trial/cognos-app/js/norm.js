(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  if (!$('cg-norm')) return;
  const app = '/index.php/apps/cognos';
  function node(tag, text, cls) { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; }
  function card(s) {
    const a = node('a', undefined, 'cg-card' + (s.is_main ? ' cg-card-main' : ''));
    a.href = app + '/norm/projects/' + s.id;
    a.append(node('span', (s.is_main ? '主项目 · ' : '') + (s.board || '带着自己的做法')), node('strong', s.title));
    if (s.summary) a.append(node('span', s.summary));
    const marks = [];
    if (s.waiting) marks.push(s.waiting + ' 件等你定');
    if (s.new) marks.push('你不在时有 ' + s.new + ' 条新的');
    if (marks.length) a.append(node('span', marks.join(' · ')));
    return a;
  }
  fetch(app + '/norm/data', { credentials: 'same-origin', headers: { Accept: 'application/json', requesttoken: OC.requestToken } })
    .then(r => r.json()).then(data => {
      const spaces = data.projects || [];
      window.CognosNormNav.render($('cg-nav'), spaces, { current: 'norm' });
      $('cg-norm-meta').textContent = spaces.length ? spaces.length + ' 个空间' : '';
      $('cg-norm-cards').replaceChildren(...(spaces.length ? spaces.map(card) : [node('p', '还没有空间。Initium 里的对话读者读过以后会放进来。', 'cg-hint')]));
      window.CognosChatBox.mount($('cg-norm-chat'), data.conversation_id);
    }).catch(() => { $('cg-norm-cards').textContent = '读不到 Norm，刷新试试。'; });
})();
