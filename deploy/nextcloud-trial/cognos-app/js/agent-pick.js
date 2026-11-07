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
