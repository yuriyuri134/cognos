(() => {
  'use strict';
  const base = '/index.php/apps/cognos';
  const id = document.querySelector('#note-id').value;
  const api = async (path, options = {}) => { const response = await fetch(base + path, { credentials: 'same-origin', headers: { Accept: 'application/json', requesttoken: window.OC?.requestToken || '', ...(options.headers || {}) }, ...options }); const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(data.error || data.message || `请求失败 ${response.status}`); return data; };
  const renderParticipants = (select, participants) => { select.replaceChildren(); const first = document.createElement('option'); first.value = ''; first.textContent = '选择当前参与者（必选）'; select.append(first); for (const p of participants || []) { const option = document.createElement('option'); option.value = p.id; option.textContent = `${p.display_name} · ${p.description}${p.runtime_available ? '' : ' · runtime 未接通'}`; select.append(option); } };
  async function load() {
    try {
      const data = await api(`/notes/${encodeURIComponent(id)}`); const note = data.note;
      document.querySelector('#note-title').textContent = note.title; document.querySelector('#note-meta').textContent = `${note.space_title} · 版本 ${note.revision}`; document.querySelector('#note-content').textContent = note.content;
      const refs = await api(`/notes/${encodeURIComponent(id)}/references`); const root = document.querySelector('#references-list'); root.replaceChildren(); if (!refs.references?.length) root.innerHTML = '<p class="cognos-empty">还没有从此材料开始的 Activity。</p>'; for (const item of refs.references || []) { const link = document.createElement('a'); link.href = `${base}/activities/${encodeURIComponent(item.id)}`; link.textContent = `${item.participant_name || '未选择参与者'} · ${item.title} · ${new Date(Number(item.created_at) * 1000).toLocaleString('zh-CN')}`; root.append(link); }
      const participants = await api('/participants'); renderParticipants(document.querySelector('#participant-select'), participants.participants);
    } catch (error) { document.querySelector('#note-content').textContent = error.message; }
  }
  document.querySelector('#discuss-button').addEventListener('click', async () => { const button = document.querySelector('#discuss-button'); const status = document.querySelector('#discuss-status'); const participant = document.querySelector('#participant-select').value; if (!participant) { status.textContent = '请先选择一个 Cognos 参与者。'; return; } button.disabled = true; status.textContent = '正在保存来源快照…'; try { const data = await api(`/notes/${encodeURIComponent(id)}/discuss`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ participant_id: participant }) }); status.textContent = data.reused ? '已回到原有 Activity。' : 'Activity 起点已保存。'; setTimeout(() => { window.location.href = `${base}/activities/${encodeURIComponent(data.activity_id)}`; }, 300); } catch (error) { status.textContent = error.message; button.disabled = false; } });
  load();
})();