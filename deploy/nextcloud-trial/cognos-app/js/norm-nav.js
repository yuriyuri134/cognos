/* Norm 的左边导航：Initium、Norm、Cognos 自己（内观，不在「项目」里），下面按样子分组列出普通空间（去掉空分组和「还空着」）。
 * 用法：CognosNormNav.render(nav, spaces, { current: 空间 id 或 'norm' }) */
(function () {
  'use strict';
  const app = '/index.php/apps/cognos';
  function node(tag, text, cls) { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; }
  function link(href, text, cls) { const a = node('a', undefined, cls); a.href = href; a.append(node('span', text)); return a; }
  function spaceLink(s, current, extraCls) {
    const a = link(app + '/norm/projects/' + s.id, s.title, (extraCls || '') + (s.id === current ? (extraCls ? ' ' : '') + 'cg-on' : ''));
    if (s.id === current) a.setAttribute('aria-current', 'page');
    if (s.is_self) {
      const tag = node('span', '内观', 'cg-nav-tag');
      a.append(tag);
    }
    if (s.waiting || s.new) {
      const d = node('span', '●', 'cg-dot');
      d.title = s.waiting ? '有一件等你定' : '你不在时有新的';
      a.append(d);
    }
    return a;
  }
  function render(nav, spaces, opts) {
    const current = (opts || {}).current || '';
    const brand = node('a', 'Cognos', 'cg-brand'); brand.href = app + '/';
    const norm = link(app + '/norm', 'Norm', 'cg-door-link cg-strong' + (current === 'norm' ? ' cg-on' : ''));
    if (current === 'norm') norm.setAttribute('aria-current', 'page');
    nav.replaceChildren(brand, link(app + '/creations', 'Initium', 'cg-door-link'), norm);

    const selfSpaces = (spaces || []).filter(s => s.is_self);
    for (const s of selfSpaces) nav.append(spaceLink(s, current, 'cg-door-link cg-self-link'));

    const regular = (spaces || []).filter(s => !s.is_self);
    const groups = [['项目', s => s.board === '项目'], ['带着自己做法的', s => !s.board], ['笔记', s => s.board === '笔记'], ['学习', s => s.board === '学习'], ['组织', s => s.board === '组织']];
    for (const [name, test] of groups) {
      const list = regular.filter(test);
      if (!list.length) continue;
      nav.append(node('div', name, 'cg-nav-group'));
      for (const s of list) nav.append(spaceLink(s, current, ''));
    }

    const util = link(app + '/notes', '已有材料', 'cg-nav-util');
    nav.append(util);
  }
  window.CognosNormNav = { render };
})();
