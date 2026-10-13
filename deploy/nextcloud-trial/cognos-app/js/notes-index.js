(() => {
  'use strict';
  const base = '/index.php/apps/cognos';
  const $ = (selector) => document.querySelector(selector);
  const state = { space: null };
  const api = async (path, options = {}) => {
    const response = await fetch(base + path, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', requesttoken: window.OC?.requestToken || '', ...(options.headers || {}) },
      ...options,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || data.message || `请求失败 ${response.status}`);
    return data;
  };
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const show = (id) => { ['spaces-list', 'notes-list', 'search-results'].forEach((name) => { $(`#${name}`).hidden = name !== id; }); };
  const notice = (text, error = false) => { const node = $('#notes-notice'); if (!node) return; node.textContent = text; node.dataset.error = error ? 'true' : 'false'; node.hidden = !text; };

  async function loadSpaces() {
    show('spaces-list');
    notice('');
    const data = await api('/notes/spaces');
    const root = $('#spaces-container'); root.replaceChildren();
    if (!data.spaces?.length) { root.innerHTML = '<p class="cognos-empty">还没有材料空间。先建立一个，结构可以在使用中出现。</p>'; return; }
    for (const space of data.spaces) {
      const card = document.createElement('article'); card.className = 'cognos-note-card';
      card.innerHTML = `<button type="button"><strong>${escapeHtml(space.title)}</strong><span>${escapeHtml(space.description || '尚未描述')}</span><small>${space.note_count} 份材料</small></button>`;
      card.querySelector('button').addEventListener('click', () => loadNotes(space)); root.append(card);
    }
  }

  async function loadNotes(space) {
    state.space = space; show('notes-list'); $('#space-title-label').textContent = space.title;
    const root = $('#notes-container'); root.replaceChildren();
    const data = await api(`/notes/spaces/${encodeURIComponent(space.id)}/notes`);
    const create = document.createElement('form'); create.className = 'cognos-inline-form'; create.innerHTML = '<input name="title" placeholder="材料标题" required maxlength="255"><textarea name="content" placeholder="先写下材料，不必先决定它是什么"></textarea><button type="submit">保存材料</button>';
    create.addEventListener('submit', async (event) => { event.preventDefault(); const form = new FormData(create); const button = create.querySelector('button'); button.disabled = true; try { await api(`/notes/spaces/${encodeURIComponent(space.id)}/notes`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ title: form.get('title'), content: form.get('content') }) }); await loadNotes(space); } catch (error) { notice(error.message, true); button.disabled = false; } });
    root.append(create);
    if (!data.notes?.length) { root.insertAdjacentHTML('beforeend', '<p class="cognos-empty">这个空间还没有材料。</p>'); return; }
    for (const note of data.notes) { const card = document.createElement('article'); card.className = 'cognos-note-card'; card.innerHTML = `<a href="${base}/notes/view/${encodeURIComponent(note.id)}"><strong>${escapeHtml(note.title)}</strong><span>${escapeHtml(note.preview || '')}</span><small>版本 ${note.revision}</small></a>`; root.append(card); }
  }

  $('#new-space').addEventListener('click', () => { $('#space-form').hidden = !$('#space-form').hidden; if (!$('#space-form').hidden) $('#space-title').focus(); });
  $('#space-form').addEventListener('submit', async (event) => { event.preventDefault(); const title = $('#space-title').value.trim(); if (!title) return; try { const data = await api('/notes/spaces', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ title, description: $('#space-description').value }) }); $('#space-form').reset(); $('#space-form').hidden = true; await loadSpaces(); notice(`已建立材料空间：${data.space.title}`); } catch (error) { notice(error.message, true); } });
  $('#back-to-spaces').addEventListener('click', loadSpaces);
  $('#search-notes').addEventListener('click', () => { $('#search-box').hidden = !$('#search-box').hidden; if (!$('#search-box').hidden) $('#search-input').focus(); });
  let timer;
  $('#search-input').addEventListener('input', () => { clearTimeout(timer); const query = $('#search-input').value.trim(); if (query.length < 2) return; timer = setTimeout(async () => { try { const data = await api(`/notes/search?query=${encodeURIComponent(query)}`); show('search-results'); const root = $('#results-container'); root.replaceChildren(); for (const note of data.results || []) { const card = document.createElement('article'); card.className = 'cognos-note-card'; card.innerHTML = `<a href="${base}/notes/view/${encodeURIComponent(note.id)}"><strong>${escapeHtml(note.title)}</strong><span>${escapeHtml(note.preview || '')}</span></a>`; root.append(card); } } catch (error) { notice(error.message, true); } }, 250); });
  loadSpaces().catch((error) => notice(error.message, true));
})();
