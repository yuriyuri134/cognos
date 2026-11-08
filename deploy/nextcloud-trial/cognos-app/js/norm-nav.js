/* Norm 的左边导航：Initium、Norm，下面按样子分组列出空间（项目、带着自己做法的、笔记、学习、组织）。
 * 用法：CognosNormNav.render(nav, spaces, { current: 空间 id 或 'norm' }) */
(function () {
  'use strict';
  const app = '/index.php/apps/cognos';
  function node(tag, text, cls) { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; }
  function link(href, text, cls) { const a = node('a', undefined, cls); a.href = href; a.append(node('span', text)); return a; }
  function render(nav, spaces, opts) {
    const current = (opts || {}).current || '';
    const brand = node('a', 'Cognos', 'cg-brand'); brand.href = app + '/';
    const norm = link(app + '/norm', 'Norm', 'cg-door-link cg-strong' + (current === 'norm' ? ' cg-on' : ''));
    if (current === 'norm') norm.setAttribute('aria-current', 'page');
    nav.replaceChildren(brand, link(app + '/creations', 'Initium', 'cg-door-link'), norm);
    const groups = [['项目', s => s.board === '项目'], ['带着自己做法的', s => !s.board], ['笔记', s => s.board === '笔记'], ['学习', s => s.board === '学习'], ['组织', s => s.board === '组织']];
    const empty = [];
    for (const [name, test] of groups) {
      const list = spaces.filter(test);
      if (!list.length) { if (name !== '带着自己做法的') empty.push(name); continue; }
      nav.append(node('div', name, 'cg-nav-group'));
      for (const s of list) {
        const a = link(app + '/norm/projects/' + s.id, s.title, s.id === current ? 'cg-on' : '');
        if (s.id === current) a.setAttribute('aria-current', 'page');
        if (s.waiting || s.new) { const d = node('span', '●', 'cg-dot'); d.title = s.waiting ? '有一件等你定' : '你不在时有新的'; a.append(d); }
        nav.append(a);
      }
    }
    if (empty.length) { nav.append(node('div', empty.join(' · '), 'cg-nav-group'), node('div', '还空着', 'cg-nav-empty')); }
  }
  window.CognosNormNav = { render };
})();
