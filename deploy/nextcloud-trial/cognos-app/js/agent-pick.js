/* 对话框上方的「谁来回」：Claude 或 GPT。两边看到的是同一段对话的全文，可以中途换。
 * 用法：CognosAgentPick.mount(放在哪个元素前面, creationId)。选择存在这段对话上。 */
(function () {
  'use strict';
  const app = '/index.php/apps/cognos';
  const NAMES = { claude: 'Claude', gpt: 'GPT（Codex）' };
  async function api(path, body) {
    const r = await fetch(app + path, { method: body ? 'POST' : 'GET', credentials: 'same-origin',
      headers: { Accept: 'application/json', requesttoken: OC.requestToken, ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined });
    const json = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(json.message || ('HTTP ' + r.status));
    return json;
  }
  function mount(before, creationId) {
    if (!before || !creationId) return;
    before.parentNode.querySelector('.cg-agent-pick')?.remove();
    const box = document.createElement('div'); box.className = 'cg-agent-pick'; box.setAttribute('role', 'group'); box.setAttribute('aria-label', '谁来回');
    const label = document.createElement('span'); label.textContent = '谁来回：'; box.append(label);
    box.style.flexWrap = 'wrap';
    const buttons = {};
    let current = 'claude', effort = 'default', pending = true;
    const effortLabel = document.createElement('label'); effortLabel.textContent = '思考强度：';
    const select = document.createElement('select'); select.setAttribute('aria-label', 'GPT 思考强度');
    for (const [value, text] of Object.entries({ default: '默认', low: '低', medium: '中', high: '高', xhigh: '很高', max: '最高' })) {
      const option = document.createElement('option'); option.value = value; option.textContent = text; select.append(option);
    }
    effortLabel.append(select);
    const info = document.createElement('small'); info.style.flexBasis = '100%';
    info.textContent = '通过本机 Codex 回复，使用 Codex 额度。强度越高，通常耗时和用量越多。';
    const show = () => {
      Object.entries(buttons).forEach(([k, b]) => { b.disabled = pending; b.classList.toggle('cg-on', k === current); b.setAttribute('aria-pressed', k === current ? 'true' : 'false'); });
      select.value = effort; select.disabled = pending; effortLabel.hidden = current !== 'gpt'; info.hidden = current !== 'gpt';
    };
    async function save(next, nextEffort) {
      const was = current, previousEffort = effort; current = next; effort = nextEffort; pending = true; show();
      try {
        const d = await api('/creations/' + creationId + '/agent', { agent: current, reasoning_effort: effort });
        current = d.agent; effort = d.reasoning_effort || 'default';
      } catch (e) { current = was; effort = previousEffort; alert('没保存：' + e.message); }
      finally { pending = false; show(); }
    }
    for (const k of Object.keys(NAMES)) {
      const b = document.createElement('button'); b.type = 'button'; b.textContent = NAMES[k];
      b.addEventListener('click', async () => {
        if (k === current) return;
        await save(k, effort);
      });
      buttons[k] = b; box.append(b);
    }
    box.append(effortLabel, info); select.addEventListener('change', () => save(current, select.value));
    before.parentNode.insertBefore(box, before); show();
    api('/creations/' + creationId + '/agent').then(d => { current = d.agent || 'claude'; effort = d.reasoning_effort || 'default'; pending = false; show(); })
      .catch(e => { info.textContent = '未能读取对话设置：' + e.message; info.hidden = false; });
  }
  window.CognosAgentPick = { mount };
})();

// 额度和用量：桥记下的账号额度（Claude、GPT 各自的 5 小时和 7 天）和这段对话最近一次回话用了多少
(function () {
  const hm = s => { const d = new Date(s * 1000); return d.toLocaleString('zh-CN', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' }); };
  const pct = x => Math.round((x || 0) * 100) + '%';
  function windows(name, l) {
    if (!l || !l.windows) return '';
    const w = l.windows; const parts = [];
    if (w.five_hour) parts.push('5 小时内用了 ' + pct(w.five_hour.used) + (w.five_hour.resets_at ? '（' + hm(w.five_hour.resets_at) + ' 重置）' : ''));
    if (w.seven_day) parts.push('这周 ' + pct(w.seven_day.used) + (w.seven_day.resets_at ? '（' + hm(w.seven_day.resets_at) + ' 重置）' : ''));
    return parts.length ? name + '：' + parts.join('，') + '，记于 ' + hm(l.seen_at) : '';
  }
  function text(usage) {
    if (!usage) return '';
    const lines = [];
    const lim = usage.limits || {};
    const c = windows('Claude 额度', lim.claude); if (c) lines.push(c);
    const g = windows('GPT（Codex）额度', lim.codex); if (g) lines.push(g);
    const r = usage.last;
    if (r) {
      const all = (r.input || 0) + (r.cache_read || 0) + (r.cache_write || 0);
      lines.push('这段对话上一次回话（' + (r.subject || r.executor) + '，' + hm(r.at) + '）：读进 ' + all + ' 个 token，其中缓存命中 '
        + (all ? Math.round(r.cache_read / all * 100) : 0) + '%，新写缓存 ' + (r.cache_write || 0) + '，输出 ' + (r.output || 0));
    }
    return lines.join('\n');
  }
  function show(el, usage) { if (!el) return; const t = text(usage); el.textContent = t; el.hidden = !t; }
  window.CognosUsage = { text, show };
})();
