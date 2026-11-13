/* Nextcloud Files 附件选择、上传、粘贴截图与消息附件卡片渲染（Initium + Norm 共用） */
(function () {
  'use strict';

  const appBase = '/index.php/apps/cognos';
  const ATT_LINE_RE = /^\[📎\s+(.+?)\s+\((\d+(?:\.\d+)?\s*(?:B|KB|MB|GB))\)\s+·\s+(\/[^\]\n]+)\]\((\/index\.php\/apps\/files\/files\/(\d+)\?openfile=true)\)$/;
  const IMAGE_NAME_RE = /\.(png|jpe?g|gif|webp)$/i;

  function el(tag, text, cls) {
    const n = document.createElement(tag);
    if (text !== undefined && text !== null) n.textContent = text;
    if (cls) n.className = cls;
    return n;
  }

  function formatBytes(bytes) {
    const n = Number(bytes) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(1).replace(/\.0$/, '') + ' KB';
    if (n < 1024 * 1024 * 1024) return (n / (1024 * 1024)).toFixed(1).replace(/\.0$/, '') + ' MB';
    return (n / (1024 * 1024 * 1024)).toFixed(2).replace(/\.?0+$/, '') + ' GB';
  }

  function safeLabelText(s) {
    return String(s || '').replace(/[\[\]\r\n]+/g, ' ').trim();
  }

  function parseAttachmentLine(line) {
    const m = ATT_LINE_RE.exec(String(line || '').trim());
    if (!m) return null;
    const name = m[1];
    const sizeText = m[2];
    const path = m[3];
    const filesUrl = m[4];
    const id = Number(m[5]);
    return {
      id,
      name,
      sizeText,
      path,
      filesUrl,
      rawUrl: appBase + '/files/' + id + '/raw',
      isImage: IMAGE_NAME_RE.test(name),
    };
  }

  function createAttachmentCard(att) {
    const card = el('div', undefined, 'cg-att-card');
    const row = el('div', undefined, 'cg-att-card-row');
    const info = el('div', undefined, 'cg-att-card-info');
    const icon = el('span', att.isImage ? '🖼️' : '📎', 'cg-att-card-icon');
    const textBox = el('div', undefined, 'cg-att-card-text');
    textBox.append(
      el('span', att.name, 'cg-att-card-name'),
      el('span', att.path + ' · ' + att.sizeText, 'cg-att-card-path')
    );
    info.append(icon, textBox);

    const acts = el('div', undefined, 'cg-att-card-acts');
    const openFiles = el('a', '在 Files 中打开', 'cg-att-card-link');
    openFiles.href = att.filesUrl;
    openFiles.target = '_blank';
    openFiles.rel = 'noopener noreferrer';
    acts.append(openFiles);
    row.append(info, acts);
    card.append(row);

    if (att.isImage) {
      const link = document.createElement('a');
      link.href = att.rawUrl;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      const img = document.createElement('img');
      img.className = 'cg-att-card-preview';
      img.src = att.rawUrl;
      img.alt = att.name;
      img.loading = 'lazy';
      img.addEventListener('error', () => link.remove());
      link.append(img);
      card.append(link);
    }

    return card;
  }

  function formatPlainSegment(parent, text) {
    const lines = String(text || '').replace(/\r\n?/g, '\n').split('\n');
    let para = null;
    const flushPara = () => { para = null; };
    for (const line of lines) {
      const att = parseAttachmentLine(line);
      if (att) {
        flushPara();
        parent.append(createAttachmentCard(att));
        continue;
      }
      if (!para) {
        para = document.createElement('span');
        parent.append(para);
      } else {
        para.append(document.createElement('br'));
      }
      if (line) para.append(document.createTextNode(line));
    }
  }

  function renderUserMessage(text) {
    const frag = document.createDocumentFragment();
    formatPlainSegment(frag, text);
    return frag;
  }

  async function requestJson(url, opts) {
    const headers = Object.assign({ Accept: 'application/json', requesttoken: OC.requestToken }, (opts && opts.headers) || {});
    const res = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts || {}, { headers }));
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
    return data;
  }

  function mount(cfg) {
    cfg = cfg || {};
    const creationId = Number(cfg.creationId);
    const form = cfg.form;
    const input = cfg.input;
    if (!creationId || !form || !input) return null;

    let triggerBtn = cfg.triggerBtn || null;
    if (!triggerBtn) {
      triggerBtn = el('button', '📎', 'cg-att-trigger');
      triggerBtn.type = 'button';
      triggerBtn.title = '从 Nextcloud Files 选择或上传附件';
      triggerBtn.setAttribute('aria-label', '从 Nextcloud Files 选择或上传附件');
      const submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn) form.insertBefore(triggerBtn, submitBtn);
      else form.append(triggerBtn);
    }

    const tray = el('div', undefined, 'cg-att-tray');
    tray.hidden = true;
    const inputWrapper = form.querySelector('.input-wrapper');
    if (inputWrapper) {
      form.insertBefore(tray, inputWrapper);
    } else if (form.parentNode) {
      form.parentNode.insertBefore(tray, form);
    } else {
      form.prepend(tray);
    }

    const pending = []; // { id, name, path, size, mime, is_image, is_text, files_url, raw_url, includeText, textExcerpt, textTruncated }
    const hiddenUpload = document.createElement('input');
    hiddenUpload.type = 'file';
    hiddenUpload.multiple = true;
    hiddenUpload.hidden = true;
    form.append(hiddenUpload);

    let dialog = null;
    let dlgState = {
      dir: '__attachments__',
      q: '',
      items: [],
      breadcrumbs: [],
      attachmentsDir: 'Cognos/attachments/' + creationId,
      loading: false,
      uploading: false,
      statusText: '',
    };
    let dlgEls = null;

    function isAttached(id) {
      return pending.some(item => item.id === id);
    }

    async function ensureTextLoaded(entry) {
      if (!entry.is_text || entry.textExcerpt !== undefined) return;
      try {
        const res = await requestJson(appBase + '/creations/' + creationId + '/files/' + entry.id + '/read');
        if (res.excerpt) {
          entry.textExcerpt = res.excerpt.content || '';
          entry.textTruncated = !!res.excerpt.truncated;
        } else {
          entry.textExcerpt = '';
          entry.textTruncated = false;
        }
      } catch (err) {
        entry.includeText = false;
        window.alert('没能读取文本内容：' + err.message);
      }
    }

    function renderTray() {
      tray.replaceChildren();
      tray.hidden = pending.length === 0;
      pending.forEach((item, idx) => {
        const chip = el('div', undefined, 'cg-att-chip');
        if (item.is_image) {
          const thumb = document.createElement('img');
          thumb.className = 'cg-att-chip-thumb';
          thumb.src = item.raw_url;
          thumb.alt = item.name;
          chip.append(thumb);
        } else {
          chip.append(el('span', '📎'));
        }

        const meta = el('div', undefined, 'cg-att-chip-meta');
        meta.append(
          el('span', item.name, 'cg-att-chip-name'),
          el('span', item.path + ' · ' + formatBytes(item.size), 'cg-att-chip-sub')
        );
        chip.append(meta);

        if (item.is_text) {
          const opt = el('label', undefined, 'cg-att-chip-opt');
          const cb = document.createElement('input');
          cb.type = 'checkbox';
          cb.checked = !!item.includeText;
          cb.addEventListener('change', async () => {
            item.includeText = cb.checked;
            if (cb.checked) {
              cb.disabled = true;
              await ensureTextLoaded(item);
              cb.disabled = false;
              cb.checked = !!item.includeText;
            }
          });
          opt.append(cb, document.createTextNode('附带文本'));
          chip.append(opt);
        }

        const rm = el('button', '×', 'cg-att-chip-rm');
        rm.type = 'button';
        rm.setAttribute('aria-label', '移除附件 ' + item.name);
        rm.addEventListener('click', () => {
          pending.splice(idx, 1);
          renderTray();
          renderDialogList();
        });
        chip.append(rm);
        tray.append(chip);
      });
    }

    async function addFileNode(fileMeta, defaultIncludeText) {
      if (!fileMeta || !fileMeta.id) return;
      if (isAttached(fileMeta.id)) return;
      const entry = {
        id: Number(fileMeta.id),
        name: fileMeta.name,
        path: fileMeta.path,
        size: Number(fileMeta.size) || 0,
        mime: fileMeta.mime || '',
        is_image: !!fileMeta.is_image,
        is_text: !!fileMeta.is_text,
        files_url: fileMeta.files_url,
        raw_url: fileMeta.raw_url || (appBase + '/files/' + fileMeta.id + '/raw'),
        includeText: false,
        textExcerpt: fileMeta.text_excerpt,
        textTruncated: !!fileMeta.text_truncated,
      };
      if (entry.is_text && defaultIncludeText) {
        entry.includeText = true;
        await ensureTextLoaded(entry);
      }
      pending.push(entry);
      renderTray();
      renderDialogList();
    }

    async function uploadOne(file) {
      const fd = new FormData();
      fd.append('file', file, file.name || ('pasted-' + Date.now() + '.png'));
      const data = await requestJson(appBase + '/creations/' + creationId + '/files', {
        method: 'POST',
        body: fd,
      });
      await addFileNode(data.file, !!data.file?.is_text && data.file.size <= 32768);
      return data.file;
    }

    async function uploadFiles(fileList) {
      const list = Array.from(fileList || []).filter(f => f && f.size > 0);
      if (!list.length) return;
      dlgState.uploading = true;
      dlgState.statusText = '正在上传到 /' + dlgState.attachmentsDir + ' ...';
      renderDialogStatus();
      try {
        for (const f of list) {
          await uploadOne(f);
        }
        dlgState.statusText = '已上传 ' + list.length + ' 个文件并加入待发送附件';
        if (dialog && dialog.open) {
          await loadDir('__attachments__', '');
        }
      } catch (err) {
        dlgState.statusText = '上传失败：' + err.message;
        if (!dialog || !dialog.open) window.alert('上传附件失败：' + err.message);
      } finally {
        dlgState.uploading = false;
        renderDialogStatus();
      }
    }

    function ensureDialog() {
      if (dialog) return dialog;
      dialog = document.createElement('dialog');
      dialog.className = 'cg-file-dialog';
      dialog.setAttribute('aria-label', '选择或上传 Nextcloud 文件');

      const box = el('div', undefined, 'cg-file-dlg-box');
      const head = el('div', undefined, 'cg-file-dlg-head');
      const title = el('h3', '从 Nextcloud Files 附加文件');
      const closeBtn = el('button', '×', 'cg-file-dlg-close');
      closeBtn.type = 'button';
      closeBtn.setAttribute('aria-label', '关闭');
      closeBtn.addEventListener('click', () => dialog.close());
      head.append(title, closeBtn);

      const tools = el('div', undefined, 'cg-file-dlg-tools');
      const tabs = el('div', undefined, 'cg-file-tabs');
      const tabDefs = [
        { label: '本次对话附件', dir: '__attachments__' },
        { label: 'Cognos 目录', dir: 'Cognos' },
        { label: '全部文件', dir: '' },
      ];
      const tabBtns = new Map();
      tabDefs.forEach((def) => {
        const b = el('button', def.label, 'cg-file-tab');
        b.type = 'button';
        b.addEventListener('click', () => {
          searchInput.value = '';
          loadDir(def.dir, '');
        });
        tabBtns.set(def.dir, b);
        tabs.append(b);
      });

      const searchRow = el('div', undefined, 'cg-file-search-row');
      const searchInput = document.createElement('input');
      searchInput.type = 'search';
      searchInput.className = 'cg-file-search';
      searchInput.placeholder = '按文件名搜索 Nextcloud Files…';
      searchInput.setAttribute('aria-label', '搜索 Nextcloud Files');

      let searchTimer = null;
      searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
          loadDir(dlgState.dir, searchInput.value.trim());
        }, 250);
      });

      const uploadBtn = el('button', '+ 上传本地文件', 'cg-file-upload-btn');
      uploadBtn.type = 'button';
      uploadBtn.addEventListener('click', () => hiddenUpload.click());

      searchRow.append(searchInput, uploadBtn);
      tools.append(tabs, searchRow);

      const crumbs = el('div', undefined, 'cg-file-crumbs');
      const statusEl = el('div', '', 'cg-file-status');
      const list = el('div', undefined, 'cg-file-list');

      const foot = el('div', undefined, 'cg-file-dlg-foot');
      const countEl = el('span', '未选附件');
      const doneBtn = el('button', '完成', 'cg-file-done-btn');
      doneBtn.type = 'button';
      doneBtn.addEventListener('click', () => dialog.close());
      foot.append(countEl, doneBtn);

      box.append(head, tools, crumbs, statusEl, list, foot);
      dialog.append(box);
      dialog.addEventListener('click', (e) => {
        if (e.target === dialog) dialog.close();
      });
      document.body.append(dialog);

      dlgEls = { tabBtns, searchInput, uploadBtn, crumbs, statusEl, list, countEl };
      return dialog;
    }

    function renderDialogStatus() {
      if (!dlgEls) return;
      dlgEls.uploadBtn.disabled = dlgState.uploading;
      dlgEls.statusEl.textContent = dlgState.loading ? '正在读取 Nextcloud Files...' : dlgState.statusText;
      dlgEls.countEl.textContent = pending.length ? ('已附加 ' + pending.length + ' 个文件') : '可点文件右侧「附加」，或直接上传本地文件';
    }

    function renderDialogList() {
      if (!dlgEls) return;
      for (const [k, b] of dlgEls.tabBtns) {
        const active = !dlgState.q && (
          (k === '__attachments__' && dlgState.dir === dlgState.attachmentsDir) ||
          dlgState.dir === k
        );
        b.classList.toggle('cg-on', active);
      }

      dlgEls.crumbs.replaceChildren();
      if (dlgState.q) {
        dlgEls.crumbs.append(el('span', '搜索结果：“' + dlgState.q + '”'));
      } else {
        dlgState.breadcrumbs.forEach((c, idx) => {
          if (idx > 0) dlgEls.crumbs.append(el('span', '/'));
          const b = el('button', c.name, 'cg-file-crumb');
          b.type = 'button';
          b.addEventListener('click', () => loadDir(c.path, ''));
          dlgEls.crumbs.append(b);
        });
      }

      dlgEls.list.replaceChildren();
      if (!dlgState.items.length && !dlgState.loading) {
        dlgEls.list.append(el('p', dlgState.q ? '没有找到匹配的文件' : '这个目录里还没有文件，可以直接点上方「上传本地文件」。', 'cg-hint'));
      } else {
        dlgState.items.forEach((item) => {
          const row = el('div', undefined, 'cg-file-row');
          if (item.type === 'dir') {
            const btn = el('button', undefined, 'cg-file-row-btn');
            btn.type = 'button';
            btn.append(el('span', '📁'));
            const info = el('div', undefined, 'cg-file-row-info');
            info.append(
              el('span', item.name, 'cg-file-row-name'),
              el('span', item.path, 'cg-file-row-sub')
            );
            btn.append(info);
            btn.addEventListener('click', () => {
              dlgEls.searchInput.value = '';
              loadDir(item.rel_path, '');
            });
            row.append(btn);
          } else {
            const main = el('div', undefined, 'cg-file-row-main');
            main.append(el('span', item.is_image ? '🖼️' : item.is_text ? '📄' : '📎'));
            const info = el('div', undefined, 'cg-file-row-info');
            info.append(
              el('span', item.name, 'cg-file-row-name'),
              el('span', item.path + ' · ' + formatBytes(item.size), 'cg-file-row-sub')
            );
            main.append(info);

            const picked = isAttached(item.id);
            const pickBtn = el('button', picked ? '已附加 ✓' : '+ 附加', 'cg-file-row-pick' + (picked ? ' cg-picked' : ''));
            pickBtn.type = 'button';
            pickBtn.addEventListener('click', async () => {
              if (isAttached(item.id)) {
                const idx = pending.findIndex(x => x.id === item.id);
                if (idx >= 0) pending.splice(idx, 1);
                renderTray();
                renderDialogList();
              } else {
                pickBtn.disabled = true;
                await addFileNode(item, !!item.is_text && item.size <= 32768);
                pickBtn.disabled = false;
              }
            });
            row.append(main, pickBtn);
          }
          dlgEls.list.append(row);
        });
      }
      renderDialogStatus();
    }

    async function loadDir(dir, q) {
      dlgState.loading = true;
      dlgState.q = q || '';
      renderDialogList();
      try {
        const params = new URLSearchParams();
        if (dir) params.set('dir', dir);
        if (q) params.set('q', q);
        const data = await requestJson(appBase + '/creations/' + creationId + '/files?' + params.toString());
        dlgState.dir = data.dir || '';
        dlgState.attachmentsDir = data.attachments_dir || dlgState.attachmentsDir;
        dlgState.breadcrumbs = data.breadcrumbs || [];
        dlgState.items = data.items || [];
        if (!dlgState.uploading) dlgState.statusText = '';
      } catch (err) {
        dlgState.statusText = '读取失败：' + err.message;
      } finally {
        dlgState.loading = false;
        renderDialogList();
      }
    }

    function openPicker() {
      const d = ensureDialog();
      if (!d.open) d.showModal();
      loadDir('__attachments__', '');
    }

    triggerBtn.addEventListener('click', openPicker);
    hiddenUpload.addEventListener('change', () => {
      if (hiddenUpload.files?.length) uploadFiles(hiddenUpload.files);
      hiddenUpload.value = '';
    });

    // 拖拽文件到输入框 / 表单上传
    const dropTarget = inputWrapper || form;
    dropTarget.addEventListener('dragover', (e) => {
      if (e.dataTransfer?.types?.includes('Files')) {
        e.preventDefault();
        dropTarget.classList.add('cg-att-drop-active');
      }
    });
    dropTarget.addEventListener('dragleave', () => dropTarget.classList.remove('cg-att-drop-active'));
    dropTarget.addEventListener('drop', (e) => {
      dropTarget.classList.remove('cg-att-drop-active');
      if (e.dataTransfer?.files?.length) {
        e.preventDefault();
        uploadFiles(e.dataTransfer.files);
      }
    });

    // 剪贴板粘贴图片 / 文件直接上传附加
    input.addEventListener('paste', (e) => {
      const files = Array.from(e.clipboardData?.files || []);
      if (files.length) {
        e.preventDefault();
        uploadFiles(files);
      }
    });

    function buildMessage(typedText) {
      const text = String(typedText || '').trim();
      if (!pending.length) return text;
      const chunks = [];
      if (text) chunks.push(text);
      for (const item of pending) {
        const cleanName = safeLabelText(item.name);
        const cleanPath = safeLabelText(item.path);
        const linkLine = '[📎 ' + cleanName + ' (' + formatBytes(item.size) + ') · ' + cleanPath + '](/index.php/apps/files/files/' + item.id + '?openfile=true)';
        chunks.push(linkLine);
        if (item.includeText && typeof item.textExcerpt === 'string' && item.textExcerpt !== '') {
          chunks.push('[文件内容：' + cleanName + (item.textTruncated ? '（已截取前 16 KB）' : '') + ']\n```\n' + item.textExcerpt + '\n```');
        }
      }
      return chunks.join('\n\n');
    }

    return {
      open: openPicker,
      hasAttachments: () => pending.length > 0,
      buildMessage,
      clear() {
        pending.length = 0;
        renderTray();
        renderDialogList();
      },
    };
  }

  window.CognosFilePicker = {
    mount,
    parseAttachmentLine,
    createAttachmentCard,
    renderUserMessage,
  };
})();
