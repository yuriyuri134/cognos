(() => {
  'use strict';
  const base = '/index.php/apps/cognos';
  const id = document.querySelector('#note-id').value;
  const api = async (path, options = {}) => { const response = await fetch(base + path, { credentials: 'same-origin', headers: { Accept: 'application/json', requesttoken: window.OC?.requestToken || '', ...(options.headers || {}) }, ...options }); const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(data.error || data.message || `请求失败 ${response.status}`); return data; };
  const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // 行内：先转义，再处理代码、链接、粗体、斜体（代码里的内容不再二次处理）
  const inline = (text) => escapeHtml(text).split(/(`[^`]+`)/).map((part) => part.startsWith('`') && part.endsWith('`') && part.length > 1
    ? `<code>${part.slice(1, -1)}</code>`
    : part.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>')).join('');
  // 导入的材料常带 YAML 头和大段对话记录，按块渲染：标题、引用、列表、代码块、分隔线、段落；YAML 头收进可展开的「来源信息」
  const renderMarkdown = (source) => {
    const lines = String(source || '').replace(/\r\n?/g, '\n').split('\n'); const out = []; let i = 0;
    if (lines[0]?.trim() === '---') { const end = lines.indexOf('---', 1); if (end > 0) { out.push(`<details class="cognos-note-frontmatter"><summary>来源信息</summary><pre>${escapeHtml(lines.slice(1, end).join('\n'))}</pre></details>`); i = end + 1; } }
    let para = []; const flush = () => { if (para.length) { out.push(`<p>${para.map(inline).join('<br>')}</p>`); para = []; } };
    for (; i < lines.length; i++) {
      const line = lines[i];
      if (/^```/.test(line)) { flush(); const code = []; for (i++; i < lines.length && !/^```/.test(lines[i]); i++) code.push(lines[i]); out.push(`<pre><code>${escapeHtml(code.join('\n'))}</code></pre>`); continue; }
      if (!line.trim()) { flush(); continue; }
      let m;
      if ((m = line.match(/^(#{1,6})\s+(.*)$/))) { flush(); const level = Math.min(m[1].length + 1, 6); out.push(`<h${level}>${inline(m[2])}</h${level}>`); continue; }
      if (/^(-{3,}|\*{3,})\s*$/.test(line)) { flush(); out.push('<hr>'); continue; }
      if (/^>\s?/.test(line)) { flush(); const quote = []; for (; i < lines.length && /^>\s?/.test(lines[i]); i++) quote.push(lines[i].replace(/^>\s?/, '')); i--; out.push(`<blockquote>${quote.map(inline).join('<br>')}</blockquote>`); continue; }
      if (/^\s*([-*+]|\d+\.)\s+/.test(line)) { flush(); const ordered = /^\s*\d+\./.test(line); const items = []; for (; i < lines.length && /^\s*([-*+]|\d+\.)\s+/.test(lines[i]); i++) items.push(`<li>${inline(lines[i].replace(/^\s*([-*+]|\d+\.)\s+/, ''))}</li>`); i--; out.push(ordered ? `<ol>${items.join('')}</ol>` : `<ul>${items.join('')}</ul>`); continue; }
      para.push(line);
    }
    flush(); return out.join('\n');
  };
  const renderParticipants =(select, participants) => { select.replaceChildren(); const first = document.createElement('option'); first.value = ''; first.textContent = '选择当前参与者（必选）'; select.append(first); for (const p of participants || []) { const option = document.createElement('option'); option.value = p.id; option.textContent = `${p.display_name} · ${p.description}${p.runtime_available ? '' : ' · runtime 未接通'}`; select.append(option); } };
  async function load() {
    try {
      const data = await api(`/notes/${encodeURIComponent(id)}`); const note = data.note;
      document.querySelector('#note-title').textContent = note.title; document.querySelector('#note-meta').textContent = `${note.space_title} · 版本 ${note.revision}`; document.querySelector('#note-content').innerHTML = renderMarkdown(note.content);
      const refs = await api(`/notes/${encodeURIComponent(id)}/references`); const root = document.querySelector('#references-list'); root.replaceChildren(); if (!refs.references?.length) root.innerHTML = '<p class="cognos-empty">还没有从此材料开始的 Activity。</p>'; for (const item of refs.references || []) { const link = document.createElement('a'); link.href = `${base}/activities/${encodeURIComponent(item.id)}`; link.textContent = `${item.participant_name || '未选择参与者'} · ${item.title} · ${new Date(Number(item.created_at) * 1000).toLocaleString('zh-CN')}`; root.append(link); }
      const participants = await api('/participants'); renderParticipants(document.querySelector('#participant-select'), participants.participants);
    } catch (error) { document.querySelector('#note-content').textContent = error.message; }
  }
  document.querySelector('#discuss-button').addEventListener('click', async () => { const button = document.querySelector('#discuss-button'); const status = document.querySelector('#discuss-status'); const participant = document.querySelector('#participant-select').value; if (!participant) { status.textContent = '请先选择一个 Cognos 参与者。'; return; } button.disabled = true; status.textContent = '正在保存来源快照…'; try { const data = await api(`/notes/${encodeURIComponent(id)}/discuss`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ participant_id: participant }) }); status.textContent = data.reused ? '已回到原有 Activity。' : 'Activity 起点已保存。'; setTimeout(() => { window.location.href = `${base}/activities/${encodeURIComponent(data.activity_id)}`; }, 300); } catch (error) { status.textContent = error.message; button.disabled = false; } });
  load();
})();