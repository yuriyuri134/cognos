(() => {
  const apiBase = '/index.php/apps/cognos';
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const ORIGIN = { observe: '观察', reorganize: '重组', merge: '合并' };
  const MEM = {
    A: ['A · 总结式记忆', 'Hermes 现有的 USER.md：每次反馈总结成一句话追加'],
    B: ['B · 读法引擎', '原话与读法分离，异常积累触发重组'],
    C: ['C · 无记忆', '基线：对你一无所知'],
  };
  const KINDS = { new: '新读法', anomaly: '异常', reorganize: '重组', arbitrate: '裁决', merge: '合并', noise: '判为噪声' };

  let data;

  function quote(oid) {
    const o = data.occurrences[oid];
    if (!o) return '';
    return `<blockquote class="rd-quote">
      ${o.prev_agent ? `<div class="rd-quote-agent">agent：${esc(o.prev_agent.slice(-140))}</div>` : ''}
      <div class="rd-quote-user">${esc(o.text)}</div>
      <div class="rd-quote-ts">${esc(o.ts.slice(0, 16).replace('T', ' '))}</div>
    </blockquote>`;
  }

  function card(r) {
    const anomalies = r.anomalies || [];
    return `<article class="rd-card rd-status-${esc(r.status)}">
      <div class="rd-card-top">
        <span class="rd-badge rd-origin-${esc(r.origin)}">${ORIGIN[r.origin] || esc(r.origin)}</span>
        <span class="rd-id">${esc(r.id)}${r.parents?.length ? ' ← ' + esc(r.parents.join(', ')) : ''}</span>
      </div>
      <p class="rd-claim">${esc(r.claim)}</p>
      ${r.conditions ? `<p class="rd-cond">成立条件：${esc(r.conditions)}</p>` : ''}
      ${r.retired_why ? `<p class="rd-why">退出原因：${esc(r.retired_why)}</p>` : ''}
      <div class="rd-counts"><span>支持 ${r.support.length}</span><span class="${anomalies.length ? 'rd-hot' : ''}">异常 ${anomalies.length}</span></div>
      <details><summary>原话证据</summary>
        ${r.support.slice(0, 6).map(quote).join('')}
        ${anomalies.length ? '<h4>异常</h4>' + anomalies.map((a) => quote(a.occ) + `<p class="rd-why">${esc(a.why)}</p>`).join('') : ''}
      </details>
    </article>`;
  }

  function renderEval() {
    const ev = data.eval;
    if (!ev) { $('rd-eval').innerHTML = '<p class="rd-note">还没有运行对照检验。</p>'; return; }
    const blocks = Object.entries(ev.summary).map(([src, s]) => {
      const label = { hermes: 'Hermes 会话（训练截止后）', 'claude-code': 'Claude Code 会话（另一个 agent）', all: '合计' }[src] || src;
      const max = Math.max(s.A, s.B, s.C);
      return `<div class="rd-eval-block"><h3>${esc(label)} <small>${s.n} 条</small></h3>
        <div class="rd-tiles">${['A', 'B', 'C'].map((m) => `
          <div class="rd-tile ${s[m] === max ? 'rd-tile-best' : ''}">
            <div class="rd-tile-name">${MEM[m][0]}</div>
            <div class="rd-tile-val">${s[m].toFixed(2)}</div>
            <div class="rd-bar"><span style="width:${(s[m] / 3) * 100}%"></span></div>
          </div>`).join('')}</div>
        <p class="rd-note">逐条比较：B 胜 A ${s.B_beats_A} 次，A 胜 B ${s.A_beats_B} 次，其余持平。</p></div>`;
    });
    $('rd-eval').innerHTML = blocks.join('') +
      `<p class="rd-note">${['A', 'B', 'C'].map((m) => `<b>${MEM[m][0]}</b>：${MEM[m][1]}`).join('；')}。</p>`;
  }

  function renderReorg() {
    const byId = Object.fromEntries(data.readings.map((r) => [r.id, r]));
    const items = data.readings.filter((r) => r.origin !== 'observe').map((r) => {
      const parents = r.parents.map((p) => byId[p]).filter(Boolean);
      const arb = data.events.find((e) => e.kind === 'arbitrate' && e.child === r.id);
      return `<div class="rd-reorg-item">
        <div class="rd-reorg-kind">${ORIGIN[r.origin]} · 第 ${r.born_step} 步</div>
        ${parents.map((p) => `<p class="rd-old">旧：${esc(p.claim)}</p>`).join('')}
        <p class="rd-new">新：${esc(r.claim)}</p>
        ${r.what_changed ? `<p class="rd-change">切法变化：${esc(r.what_changed)}</p>` : ''}
        ${arb ? `<p class="rd-arb">裁决：<b>${esc({ replace: '取代旧读法', coexist: '两者并存', reject: '撤回新读法' }[arb.decision] || arb.decision)}</b> — ${esc(arb.why)}</p>` : '<p class="rd-arb">裁决：尚未进行</p>'}
      </div>`;
    });
    $('rd-reorg').innerHTML = items.join('') || '<p class="rd-note">还没有发生重组。</p>';
  }

  function renderTimeline() {
    const steps = {};
    data.events.forEach((e) => { (steps[e.step] ||= {})[e.kind] = ((steps[e.step] || {})[e.kind] || 0) + 1; });
    $('rd-timeline').innerHTML = Object.entries(steps).map(([step, k]) => `
      <div class="rd-step"><span class="rd-step-n">第 ${step} 步</span>
        ${Object.entries(k).map(([kind, n]) => `<span class="rd-chip rd-chip-${esc(kind)}">${KINDS[kind] || esc(kind)} ${n}</span>`).join('')}
      </div>`).join('');
  }

  function renderExamples() {
    const rs = (data.eval?.results || []).filter((r) => r.scores.A !== r.scores.B).slice(0, 12);
    $('rd-examples').innerHTML = rs.map((r) => `<div class="rd-example">
      <div class="rd-quote-agent">agent：${esc(r.prev_agent.slice(-200))}</div>
      <div class="rd-quote-user">你实际说：${esc(r.text)}</div>
      ${['A', 'B', 'C'].map((m) => `<p class="rd-pred"><b>${m}（${r.scores[m] ?? '-'}分）</b> [${esc(r.preds[m]?.type)}] ${esc(r.preds[m]?.gist)}</p>`).join('')}
      <p class="rd-why">评审：${esc(r.why)}</p>
    </div>`).join('') || '<p class="rd-note">没有差异样例。</p>';
  }

  async function load() {
    const res = await fetch(`${apiBase}/readings/data`, { headers: { requesttoken: OC.requestToken } });
    data = await res.json();
    if (!res.ok) { $('rd-eval').innerHTML = `<p class="rd-note">${esc(data.message)}</p>`; return; }
    $('rd-subject').textContent = data.subject;
    const cur = data.readings.filter((r) => r.status === 'current');
    $('rd-meta').textContent = `训练截止 ${data.trained_until.slice(0, 16).replace('T', ' ')} · ${Object.keys(data.occurrences).length} 条被引用的原话 · ${data.readings.length} 条读法（当前 ${cur.length}）· ${data.events.length} 个事件 · 生成于 ${data.generated_at}`;
    renderEval();
    $('rd-current').innerHTML = cur.sort((a, b) => b.support.length - a.support.length).map(card).join('');
    renderReorg();
    renderTimeline();
    renderExamples();
    $('rd-retired').innerHTML = data.readings.filter((r) => ['retired', 'merged'].includes(r.status)).map(card).join('') || '<p class="rd-note">暂无。</p>';
  }

  document.addEventListener('DOMContentLoaded', () => load().catch((e) => { $('rd-eval').textContent = '加载失败：' + e.message; }));
})();
