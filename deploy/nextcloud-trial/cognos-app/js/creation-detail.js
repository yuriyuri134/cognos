(function() {
  'use strict';

  const state = {
    creationId: null,
    creation: null,
    messages: [],
    creations: [],
    loading: false,
    sending: false,
    // 叫来的 agent 还在本机接着做：{ since: 发出那条的时间, timer }，或者一条没叫来的原因 { error }
    pending: null,
    notice: null,
    lastRun: null,
  };

  const PENDING_POLL_MS = 5000;

  const elements = {};

  function init() {
    const container = document.querySelector('.cognos-chat-app');
    if (!container) return;

    state.creationId = parseInt(container.dataset.creationId, 10);
    if (!state.creationId) {
      console.error('Invalid creation ID');
      return;
    }

    cacheElements();
    attachEventListeners();
    loadCreationsList();
    loadCreationAndMessages();
    window.CognosSpacePanel?.init(state.creationId);
    window.CognosAgentPick?.mount(document.getElementById('chat-input-form'), state.creationId);
  }

  function cacheElements() {
    elements.chatTitle = document.getElementById('chat-title');
    elements.messagesContainer = document.getElementById('messages-inner');
    elements.chatInput = document.getElementById('chat-input');
    elements.chatForm = document.getElementById('chat-input-form');
    elements.sendBtn = document.getElementById('send-btn');
    elements.stopBtn = document.getElementById('stop-btn');
    elements.charCount = document.getElementById('char-count');
    elements.creationsList = document.getElementById('creations-list');
    elements.newCreationBtn = document.getElementById('new-creation-btn');
    elements.sidebar = document.getElementById('chat-sidebar');
    elements.mobileMenuBtn = document.getElementById('mobile-menu-btn');
    elements.sidebarToggle = document.getElementById('sidebar-toggle');
  }

  function attachEventListeners() {
    elements.chatForm?.addEventListener('submit', handleSendMessage);
    elements.chatInput?.addEventListener('input', handleInputChange);
    elements.chatInput?.addEventListener('keydown', handleInputKeydown);
    elements.newCreationBtn?.addEventListener('click', () => {
      window.location.href = '/index.php/apps/cognos/creations';
    });
    elements.mobileMenuBtn?.addEventListener('click', toggleSidebar);
    elements.sidebarToggle?.addEventListener('click', toggleSidebar);
    elements.messagesContainer?.addEventListener('click', (e) => {
      const act = e.target.closest('.msg-act');
      if (act) {
        handleMessageAction(act.dataset.act, parseInt(act.dataset.messageId, 10), act);
        return;
      }
      const button = e.target.closest('.space-keep-btn');
      if (!button) return;
      if (button.classList.contains('recall-btn')) {
        handleRecall(parseInt(button.dataset.messageId, 10));
        return;
      }
      if (button.classList.contains('hang-btn')) {
        handleHang(parseInt(button.dataset.messageId, 10));
        return;
      }
      const message = state.messages.find(m => String(m.id) === button.dataset.messageId);
      if (message) window.CognosSpacePanel?.keep(message.content);
    });
  }

  // 像 ChatGPT 那样：改一句，它后面的都去掉，用改过的这句再叫一次；删一句，连回它的回答一起删
  async function handleMessageAction(act, messageId, button) {
    if (state.sending || state.pending) return;
    const bubble = button.closest('.cognos-message');
    const message = state.messages.find(m => m.id === messageId);
    if (!message || !bubble) return;
    if (act === 'edit') {
      const box = bubble.querySelector('.cognos-message-content');
      box.innerHTML = '';
      const area = document.createElement('textarea');
      area.className = 'msg-edit-area';
      area.value = message.content;
      area.rows = Math.min(14, Math.max(3, message.content.split('\n').length + 1));
      const bar = document.createElement('div');
      bar.className = 'msg-edit-bar';
      bar.innerHTML = '<button type="button" class="msg-act" data-act="save" data-message-id="' + messageId + '">发送</button>'
        + '<button type="button" class="msg-act" data-act="cancel" data-message-id="' + messageId + '">取消</button>';
      box.append(area, bar);
      area.focus();
      return;
    }
    if (act === 'cancel') { renderMessages(); return; }
    if (act === 'save') {
      const text = bubble.querySelector('.msg-edit-area')?.value.trim();
      if (!text) return;
      const r = await changeMessage('PUT', messageId, { message: text });
      if (!r) return;
      const i = state.messages.findIndex(m => m.id === messageId);
      state.messages = state.messages.slice(0, i + 1);
      state.messages[i] = { ...state.messages[i], content: text };
      renderMessages();
      await callAgent({ recall: messageId });
      return;
    }
    if (act === 'delete') {
      const what = message.role === 'user' ? '删掉这句和回它的回答？' : '删掉这条回答？';
      if (!window.confirm(what)) return;
      const r = await changeMessage('DELETE', messageId);
      if (!r) return;
      const gone = new Set(r.removed || [messageId]);
      state.messages = state.messages.filter(m => !gone.has(m.id));
      renderMessages();
    }
  }

  async function changeMessage(method, messageId, body) {
    try {
      const response = await fetch(`/index.php/apps/cognos/creations/${state.creationId}/messages/${messageId}`, {
        method,
        headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
        body: body ? JSON.stringify(body) : undefined,
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        state.notice = data.message || `HTTP ${response.status}`;
        renderMessages();
        return null;
      }
      state.notice = null;
      return data;
    } catch (error) {
      state.notice = '连不上 Cognos：' + error.message;
      renderMessages();
      return null;
    }
  }

  async function handleHang(messageId) {
    try {
      const result = await window.CognosSpacePanel?.hang(messageId);
      const started = result?.started;
      if (started?.called) {
        startPending(Math.floor(Date.now() / 1000) - 5, null);
        renderMessages();
      } else if (started?.message) {
        window.alert(started.message);
      }
    } catch (error) {
      console.error('Hang error:', error);
      window.alert('没挂上：' + error.message);
    }
  }

  function toggleSidebar() {
    elements.sidebar?.classList.toggle('mobile-open');
  }

  function handleInputChange() {
    const length = elements.chatInput.value.length;
    elements.charCount.textContent = `${length} / 8000`;
  }

  function handleInputKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      elements.chatForm?.requestSubmit();
    }
  }

  async function loadCreationsList() {
    if (!elements.creationsList) return;

    try {
      const response = await fetch('/index.php/apps/cognos/creations/data', {
        headers: {
          'requesttoken': OC.requestToken,
        },
      });

      if (!response.ok) {
        throw new Error('Failed to load creations');
      }

      const data = await response.json();
      state.creations = data.creations || [];
      renderCreationsList();
    } catch (error) {
      console.error('Load creations error:', error);
      elements.creationsList.innerHTML = '<div class="loading-state"><span>加载失败</span></div>';
    }
  }

  function renderCreationsList() {
    if (!elements.creationsList || state.creations.length === 0) {
      elements.creationsList.innerHTML = '<div class="loading-state"><span>还没有创立</span></div>';
      return;
    }

    elements.creationsList.innerHTML = state.creations.map(creation => {
      const isActive = creation.id === state.creationId;
      const activeClass = isActive ? 'active' : '';
      return `
        <a href="/index.php/apps/cognos/creations/${creation.id}" class="creation-list-item ${activeClass}">
          <div class="creation-list-title">${escapeHtml(creation.title)}</div>
        </a>
      `;
    }).join('');
  }

  async function loadCreationAndMessages() {
    if (state.loading) return;
    state.loading = true;

    try {
      // Load creation
      const creationResponse = await fetch(`/index.php/apps/cognos/creations/${state.creationId}/data`, {
        headers: {
          'requesttoken': OC.requestToken,
        },
      });

      if (!creationResponse.ok) {
        throw new Error('Failed to load creation');
      }

      const creationData = await creationResponse.json();
      state.creation = creationData.creation;

      // Load messages
      const messagesResponse = await fetch(`/index.php/apps/cognos/creations/${state.creationId}/messages`, {
        headers: {
          'requesttoken': OC.requestToken,
        },
      });

      if (!messagesResponse.ok) {
        throw new Error('Failed to load messages');
      }

      const messagesData = await messagesResponse.json();
      state.messages = messagesData.messages || [];
      state.lastRun = messagesData.runs?.[0] || null;
      showUsage(messagesData.usage);

      renderCreation();
      renderMessages();
      resumePendingIfWaiting(messagesData.pending, messagesData.pending_who);
      readWhatWasWritten();
    } catch (error) {
      console.error('Load error:', error);
      elements.chatTitle.textContent = '加载失败';
    } finally {
      state.loading = false;
    }
  }

  // 刚写下、还没叫过谁的一段：agent 自己来读，不用你点
  let autoRead = false;
  function readWhatWasWritten() {
    if (autoRead || state.pending || state.sending || state.lastRun) return;
    const last = state.messages[state.messages.length - 1];
    if (!last || last.role !== 'user' || state.messages.some(m => m.role !== 'user')) return;
    autoRead = true;
    callAgent({ recall: last.id });
  }

  function renderCreation() {
    if (!state.creation) return;
    elements.chatTitle.textContent = state.creation.title;
  }

  function showUsage(usage) {
    let el = document.getElementById('cognos-usage-line');
    if (!el) {
      const form = document.getElementById('chat-input-form');
      if (!form) return;
      el = document.createElement('p');
      el.id = 'cognos-usage-line';
      el.className = 'cognos-usage-line';
      form.after(el);
    }
    window.CognosUsage?.show(el, usage);
  }

  function renderMessages({ followLatest = false } = {}) {
    if (!elements.messagesContainer) return;
    // 用户在看旧消息时，后台回执或状态刷新不能把阅读位置拉回最新。
    const scroller = document.getElementById('messages-container');
    const oldTop = scroller?.scrollTop ?? 0;
    const nearLatest = !scroller || scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight <= 96;

    // 空对话里也可能有人被叫进来（比如提出了反对写下的想法），那就显状态，不显「开始对话」
    if (state.messages.length === 0 && !state.pending && !state.notice) {
      elements.messagesContainer.innerHTML = `
        <div class="empty-state">
          <div class="empty-icon">💭</div>
          <h3>开始对话</h3>
          <p>在下方输入框中提出你的问题或想法</p>
        </div>
      `;
      return;
    }

    elements.messagesContainer.innerHTML = state.messages
      .map((message, index) => createMessageBubble(message, isUnanswered(index)))
      .join('') + createStatusBubble();

    requestAnimationFrame(() => {
      if (!scroller?.isConnected) return;
      scroller.scrollTop = followLatest || nearLatest ? scroller.scrollHeight : oldTop;
    });
  }

  function isUnanswered(index) {
    // 你说的话没人回，而且现在没人在做。回答记着它回的是哪一句（reply_to）；
    // 被反对叫回来的回答记 0，不算回了谁；以前的回答没记（null），照旧按「紧跟着的就是回答」算
    const message = state.messages[index];
    if (message.role !== 'user' || state.pending || state.sending) return false;
    if (state.messages.some(m => m.role !== 'user' && m.reply_to === message.id)) return false;
    const next = state.messages[index + 1];
    return !next || next.role === 'user' || next.reply_to === 0
      || (next.reply_to !== null && next.reply_to !== undefined);
  }

  function whoName(a) { return !a ? 'Agent' : a === 'GPT' || a === 'Codex' ? 'GPT' : /claude|cognos/i.test(a) ? 'Claude' : a; }

  function createMessageBubble(message, unanswered = false) {
    const role = message.role === 'user' ? 'user' : 'agent';
    const timestamp = new Date(message.created_at * 1000).toLocaleTimeString('zh-CN', {
      hour: '2-digit',
      minute: '2-digit',
    });

    return `
      <div class="cognos-message cognos-message-${role}">
        <div class="cognos-message-header">
          <span class="cognos-message-sender">${role === 'user' ? '你' : escapeHtml(whoName(message.author))}</span>
          <span class="cognos-message-time">${timestamp}</span>
        </div>
        <div class="cognos-message-content">${role === 'user' ? formatMessageContent(message.content) : renderMarkdown(message.content)}</div>
        <div class="msg-actions">
          ${role === 'user' ? `<button type="button" class="msg-act" data-act="edit" data-message-id="${message.id}" title="改这句，后面的对话会去掉，用改过的这句重新回">改</button>` : ''}
          <button type="button" class="msg-act" data-act="delete" data-message-id="${message.id}" title="${role === 'user' ? '删掉这句和回它的回答' : '删掉这条回答'}">删</button>
        </div>
        ${unanswered ? `<button type="button" class="space-keep-btn recall-btn" data-message-id="${message.id}" title="这句没人回，用它再叫一次">没人回，再叫一次</button>` : ''}
      </div>
    `;
  }

  function createStatusBubble() {
    if (!state.pending && state.lastRun?.state === 'unknown') {
      return '<div class="cognos-message cognos-message-status"><div class="cognos-message-content">这次运行状态未知，尚不能确认是否完成。已留下的内容保留；不会因为失联自动重做。</div></div>';
    }
    if (state.notice) {
      return `
        <div class="cognos-message cognos-message-agent cognos-message-status cognos-message-error">
          <div class="cognos-message-content">没叫来：${escapeHtml(state.notice)}<br>你的话已经留下了，不用重发；处理好以后点那句下面的「没人回，再叫一次」。</div>
        </div>
      `;
    }
    if (state.pending) {
      return `
        <div class="cognos-message cognos-message-agent cognos-message-status">
          <div class="cognos-message-content">${escapeHtml(state.pending.who || 'Claude Code')} 进来了，在接着做。${state.pending.queued ? '你刚说的那句排着，它做完这件就接着回你。' : ''}做完的回答会出现在这里；它留下的尝试和读法，右侧「这里」会陆续出现。可以先离开，回来再看。</div>
        </div>
      `;
    }
    return '';
  }

  function startPending(since, who = null) {
    stopPending();
    state.pending = { since, who, timer: window.setInterval(pollPending, PENDING_POLL_MS) };
  }

  function stopPending() {
    if (state.pending?.timer) {
      window.clearInterval(state.pending.timer);
    }
    state.pending = null;
  }

  async function pollPending() {
    if (!state.pending) return;
    const since = state.pending.since;
    try {
      const response = await fetch(`/index.php/apps/cognos/creations/${state.creationId}/messages`, {
        headers: { 'requesttoken': OC.requestToken },
      });
      if (!response.ok) return;
      const data = await response.json();
      const messages = data.messages || [];
      state.lastRun = data.runs?.[0] || null;
      showUsage(data.usage);
      const answered = messages.some(m => m.role !== 'user' && m.created_at >= since);
      window.CognosSpacePanel?.reload();
      if (answered || !data.pending) {
        state.messages = messages;
        if (data.pending) {
          // 一件做完，排着的下一件已经接着叫了：继续等
          state.pending.since = Math.floor(Date.now() / 1000) - 5;
          state.pending.who = data.pending_who || state.pending.who;
          state.pending.queued = false;
        } else {
          stopPending();
        }
        renderMessages();
      }
    } catch (error) {
      console.error('Poll error:', error);
    }
  }

  function resumePendingIfWaiting(pending, who = null) {
    // 刷新页面以后：服务器说叫来的 agent 还在做，就接着等它
    if (!pending) return;
    const lastUser = [...state.messages].reverse().find(m => m.role === 'user');
    startPending(lastUser ? lastUser.created_at : Math.floor(Date.now() / 1000), who);
    renderMessages();
  }

  function renderMarkdown(content) {
    // Basic Markdown rendering
    let html = escapeHtml(content);

    // Headers
    html = html.replace(/^### (.*?)$/gm, '<h3>$1</h3>');
    html = html.replace(/^## (.*?)$/gm, '<h2>$1</h2>');
    html = html.replace(/^# (.*?)$/gm, '<h1>$1</h1>');

    // Bold
    html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');

    // Italic
    html = html.replace(/\*(.*?)\*/g, '<em>$1</em>');

    // Code blocks
    html = html.replace(/```(.*?)```/gs, '<pre><code>$1</code></pre>');

    // Inline code
    html = html.replace(/`(.*?)`/g, '<code>$1</code>');

    // Lists
    html = html.replace(/^- (.*?)$/gm, '<li>$1</li>');
    html = html.replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>');

    // Line breaks
    html = html.replace(/\n/g, '<br>');

    return html;
  }

  function formatMessageContent(content) {
    const div = document.createElement('div');
    div.textContent = content;
    let html = div.innerHTML;

    // Convert line breaks
    html = html.replace(/\n/g, '<br>');

    return html;
  }

  async function handleSendMessage(e) {
    e.preventDefault();

    const message = elements.chatInput.value.trim();
    if (!message || state.sending) return;

    const ok = await callAgent({ message });
    if (ok) {
      elements.chatInput.value = '';
      handleInputChange();
    }
  }

  async function handleRecall(messageId) {
    if (state.sending || state.pending) return;
    await callAgent({ recall: messageId });
  }

  async function callAgent(payload) {
    state.sending = true;
    elements.sendBtn.disabled = true;
    elements.sendBtn.style.display = 'none';
    elements.stopBtn.style.display = 'flex';
    let ok = false;

    try {
      const response = await fetch(`/index.php/apps/cognos/creations/${state.creationId}/messages`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'requesttoken': OC.requestToken,
        },
        body: JSON.stringify(payload),
      });

      const data = await response.json().catch(() => ({}));
      // 没叫来时，你的话可能已经存下了（data.messages），原因放进对话里，不用弹窗
      state.messages.push(...(data.messages || []));
      state.notice = response.ok ? null : (data.message || `HTTP ${response.status}`);
      if (data.pending) {
        if (state.pending) {
          // 已经有人在做（比如写下想法的那位正被叫回来）：这句排着
          state.pending.queued = !!data.queued;
        } else {
          startPending(Math.floor(Date.now() / 1000) - 5, data.who || null);
          state.pending.queued = !!data.queued;
        }
      }
      ok = response.ok || (data.messages || []).length > 0;

      renderMessages({ followLatest: true });
      // 被叫进来的 agent 可能在这片空间里留下了尝试或读法
      window.CognosSpacePanel?.reload();
    } catch (error) {
      console.error('Send message error:', error);
      state.notice = '连不上 Cognos：' + error.message;
    } finally {
      state.sending = false;
      elements.sendBtn.disabled = false;
      elements.sendBtn.style.display = 'flex';
      elements.stopBtn.style.display = 'none';
      elements.chatInput.focus();
      renderMessages();
    }
    return ok;
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // 侧栏里提出了反对、写下想法的那位被叫回来时，对话这边也开始等它的回答
  window.CognosCreationDetail = {
    waitForAgent(who) {
      if (state.pending) return;
      startPending(Math.floor(Date.now() / 1000) - 5, who || null);
      renderMessages();
    },
  };

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
