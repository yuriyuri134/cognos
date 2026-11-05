(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const root = $('cg-project'); if (!root) return;
  const app = '/index.php/apps/cognos';
  const SVG = 'http://www.w3.org/2000/svg';
  function node(tag, text, cls) { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; }
  function svg(tag, attrs, text) { const n = document.createElementNS(SVG, tag); for (const k in attrs) n.setAttribute(k, attrs[k]); if (text !== undefined) n.textContent = text; return n; }

  for (const b of document.querySelectorAll('.cg-tabs button')) {
    b.addEventListener('click', () => {
      for (const o of document.querySelectorAll('.cg-tabs button')) o.setAttribute('aria-selected', String(o === b));
      for (const t of ['stages', 'branches', 'map']) $('cg-tab-' + t).hidden = t !== b.dataset.tab;
    });
  }

  function stages(list) {
    const ol = $('cg-stages'); ol.replaceChildren();
    for (const s of list) {
      const li = node('li', undefined, s.current ? 'cg-stage cg-stage-now' : 'cg-stage');
      li.append(node('span', s.when || '', 'cg-when'), node('strong', s.title || ''), node('p', s.text || ''));
      ol.append(li);
    }
    if (!list.length) ol.append(node('li', '还没有。', 'cg-hint'));
  }

  function branches(list) {
    const box = $('cg-branches'); box.replaceChildren();
    for (const br of list) {
      const d = node('details', undefined, 'cg-branch'); d.open = true;
      d.append(node('summary', br.title));
      const ul = node('ul');
      for (const p of br.parts || []) { const li = node('li'); li.append(node('strong', p.title), node('span', p.text ? '：' + p.text : '')); ul.append(li); }
      d.append(ul); box.append(d);
    }
    if (!list.length) box.append(node('p', '还没有。', 'cg-hint'));
  }

  // 中心是项目，第一圈是分支，第二圈是挂在分支下的尝试；其余的线（比如尝试之间）照画，带上说明
  function map(rel) {
    const box = $('cg-map'); box.replaceChildren();
    const nodes = rel.nodes || []; const edges = rel.edges || [];
    if (!nodes.length) { box.append(node('p', '还没有。', 'cg-hint')); return; }
    const W = 900, H = 640, cx = W / 2, cy = H / 2;
    const center = nodes.find(n => n.kind === 'project') || nodes[0];
    const pos = { [center.id]: [cx, cy] };
    const ring1 = nodes.filter(n => n.kind === 'branch');
    ring1.forEach((n, i) => { const a = -Math.PI / 2 + i * 2 * Math.PI / ring1.length; pos[n.id] = [cx + 170 * Math.cos(a), cy + 150 * Math.sin(a)]; n._a = a; });
    const parentOf = {};
    for (const e of edges) if (!e.label && ring1.some(b => b.id === e.from)) parentOf[e.to] = e.from;
    const outer = nodes.filter(n => !pos[n.id]);
    const byParent = {};
    for (const n of outer) (byParent[parentOf[n.id] || '_'] ||= []).push(n);
    for (const [pid, kids] of Object.entries(byParent)) {
      const pa = ring1.find(b => b.id === pid)?._a ?? 0;
      kids.forEach((n, i) => { const a = pa + (i - (kids.length - 1) / 2) * 0.42; pos[n.id] = [cx + 330 * Math.cos(a), cy + 270 * Math.sin(a)]; });
    }
    const s = svg('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': '和各分支、尝试的关系' });
    for (const e of edges) {
      const a = pos[e.from], b = pos[e.to]; if (!a || !b) continue;
      s.append(svg('line', { x1: a[0], y1: a[1], x2: b[0], y2: b[1], class: e.label ? 'cg-edge cg-edge-note' : 'cg-edge' }));
      if (e.label) s.append(svg('text', { x: (a[0] + b[0]) / 2, y: (a[1] + b[1]) / 2 - 4, class: 'cg-edge-label', 'text-anchor': 'middle' }, e.label));
    }
    for (const n of nodes) {
      const p = pos[n.id]; if (!p) continue;
      const g = svg('g', { class: 'cg-node cg-node-' + (n.kind || 'attempt') });
      const w = Math.max(70, [...n.label].length * 14 + 20);
      g.append(svg('rect', { x: p[0] - w / 2, y: p[1] - 16, width: w, height: n.state ? 44 : 32, rx: 8 }));
      g.append(svg('text', { x: p[0], y: p[1] + 5, 'text-anchor': 'middle' }, n.label));
      if (n.state) g.append(svg('text', { x: p[0], y: p[1] + 22, 'text-anchor': 'middle', class: 'cg-node-state' }, n.state));
      s.append(g);
    }
    box.append(s);
  }

  fetch(app + '/norm/projects/' + root.dataset.projectId + '/data', { credentials: 'same-origin', headers: { Accept: 'application/json', requesttoken: OC.requestToken } })
    .then(r => r.json()).then(({ project }) => {
      if (!project) { $('cg-project-title').textContent = '没有这个项目'; return; }
      $('cg-project-title').textContent = project.title; document.title = project.title + ' - Cognos';
      stages(project.stages); branches(project.branches); map(project.relations);
    });
})();
