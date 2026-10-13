(() => {
  'use strict';
  if (window.__cognosActivityStart) return;
  window.__cognosActivityStart = true;
  const node = (tag, text) => { const e = document.createElement(tag); if (text !== undefined) e.textContent = text; return e; };
  const api = async (path, options = {}) => { const response = await fetch(`/index.php/apps/cognos${path}`, { credentials: 'same-origin', headers: { Accept: 'application/json', requesttoken: window.OC?.requestToken || '', ...(options.headers || {}) }, ...options }); const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(data.message || `请求失败 ${response.status}`); return data; };
  const participantSelect = (participants) => { const select = document.createElement('select'); select.required = true; select.append(node('option', '选择当前参与者（必选）')); select.options[0].value = ''; for (const p of participants || []) { const available = p.runtime_available === true; const option = node('option', `${p.display_name} · ${p.description}${available ? '' : ' · runtime 未接通'}`); option.value = p.id; select.append(option); } return select; };
  async function ensure() {
    const match = location.pathname.match(/\/apps\/deck\/board\/\d+\/card\/(\d+)/);
    const host = document.querySelector('#app-content-vue .app-sidebar-header') || document.querySelector('#app-content-vue .card-details') || document.querySelector('#app-content-vue');
    if (!match || !host || host.querySelector('[data-cognos-activity-start]')) return;
    const cardId = Number(match[1]); const wrap = node('div'); wrap.dataset.cognosActivityStart = 'true'; wrap.style.cssText = 'margin:12px 0;padding:10px;border:1px solid var(--color-border);border-radius:6px;';
    const title = node('strong', 'Cognos 活动'); title.style.display = 'block'; const hint = node('p', '保留当前 Deck 卡片快照，选择一个参与者，再围绕它继续。'); hint.style.margin = '6px 0'; const select = document.createElement('select'); const button = node('button', '围绕此任务继续'); button.type = 'button'; const status = node('span'); status.setAttribute('role', 'status'); status.style.marginLeft = '8px'; wrap.append(title, hint, select, button, status); host.prepend(wrap);
    try { const data = await api('/participants'); const replacement = participantSelect(data.participants); select.replaceWith(replacement); }
    catch (error) { status.textContent = error.message; button.disabled = true; return; }
    button.onclick = async () => { const participant = wrap.querySelector('select')?.value || ''; if (!participant) { status.textContent = '请先选择一个 Cognos 参与者。'; return; } button.disabled = true; status.textContent = '正在建立起点…'; try { const response = await api(`/activities/from-card/${cardId}`, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ title: '围绕 Deck 卡片继续', participant_id: participant }) }); if (!response.activity?.id) throw new Error('无法建立 Cognos 活动'); status.textContent = '起点已保存。'; const link = node('a', `打开 ${response.activity.participant_name} 的 Activity`); link.href = `/index.php/apps/cognos/activities/${encodeURIComponent(response.activity.id)}`; link.style.marginLeft = '8px'; wrap.append(link); } catch (error) { status.textContent = error.message || '建立失败，可重试。'; button.disabled = false; } };
  }
  let scheduled = false; new MutationObserver(() => { if (scheduled) return; scheduled = true; queueMicrotask(() => { scheduled = false; ensure(); }); }).observe(document.documentElement, {childList: true, subtree: true}); ensure();
})();