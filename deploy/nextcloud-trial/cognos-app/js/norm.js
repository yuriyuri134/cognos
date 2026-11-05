(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  if (!$('cg-norm')) return;
  const app = '/index.php/apps/cognos';
  function node(tag, text, cls) { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; }
  function card(p, main) {
    const a = node('a', undefined, main ? 'cg-project cg-project-main' : 'cg-project');
    a.href = app + '/norm/projects/' + p.id;
    if (main) a.append(node('span', '主项目', 'cg-tag'));
    a.append(node('strong', p.title));
    return a;
  }
  fetch(app + '/norm/data', { credentials: 'same-origin', headers: { Accept: 'application/json', requesttoken: OC.requestToken } })
    .then(r => r.json()).then(data => {
      window.CognosChatBox.mount($('cg-norm-chat'), data.conversation_id);
      const projects = data.projects || [];
      const main = projects.find(p => p.is_main);
      $('cg-norm-main').replaceChildren(main ? card(main, true) : node('p', '还没有主项目。', 'cg-hint'));
      const others = projects.filter(p => p !== main);
      $('cg-norm-others').replaceChildren(...(others.length ? others.map(p => { const li = node('li'); li.append(card(p, false)); return li; })
        : [node('li', '还没有。', 'cg-hint')]));
    }).catch(() => { $('cg-norm-main').textContent = '读不到 Norm，刷新试试。'; });
})();
