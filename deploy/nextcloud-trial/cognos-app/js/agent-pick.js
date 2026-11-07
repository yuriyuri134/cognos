/* 对话框上方的「谁来回」：Claude 或 GPT。两边看到的是同一段对话的全文，可以中途换。
 * 用法：CognosAgentPick.mount(放在哪个元素前面, creationId)。选择存在这段对话上。 */
(function () {
  'use strict';
  const app = '/index.php/apps/cognos';
  const NAMES = { claude: 'Claude', gpt: 'GPT' };
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
    const buttons = {};
    let current = 'claude';
    const show = () => Object.entries(buttons).forEach(([k, b]) => { b.classList.toggle('cg-on', k === current); b.setAttribute('aria-pressed', k === current ? 'true' : 'false'); });
    for (const k of Object.keys(NAMES)) {
      const b = document.createElement('button'); b.type = 'button'; b.textContent = NAMES[k];
      b.addEventListener('click', async () => {
        if (k === current) return;
        const was = current; current = k; show();
        try { await api('/creations/' + creationId + '/agent', { agent: k }); } catch (e) { current = was; show(); alert('没换成：' + e.message); }
      });
      buttons[k] = b; box.append(b);
    }
    before.parentNode.insertBefore(box, before);
    api('/creations/' + creationId + '/agent').then(d => { current = d.agent || 'claude'; show(); }).catch(() => show());
  }
  window.CognosAgentPick = { mount };
})();
