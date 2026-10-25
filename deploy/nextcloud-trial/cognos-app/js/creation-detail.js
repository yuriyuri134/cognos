(function() {
  'use strict';

  const state = {
    creationId: null,
    creation: null,
    messages: [],
    creations: [],
    loading: false,
    sending: false,
  };

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
      const button = e.target.closest('.space-keep-btn');
      if (!button) return;
      const message = state.messages.find(m => String(m.id) === button.dataset.messageId);
      if (message) window.CognosSpacePanel?.keep(message.content);
    });
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

      renderCreation();
      renderMessages();
    } catch (error) {
      console.error('Load error:', error);
      elements.chatTitle.textContent = '加载失败';
    } finally {
      state.loading = false;
    }
  }

  function renderCreation() {
    if (!state.creation) return;
    elements.chatTitle.textContent = state.creation.title;
  }

  function renderMessages() {
    if (!elements.messagesContainer) return;

    if (state.messages.length === 0) {
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
      .map(message => createMessageBubble(message))
      .join('');

    // Scroll to bottom
    requestAnimationFrame(() => {
      const container = document.getElementById('messages-container');
      if (container) {
        container.scrollTop = container.scrollHeight;
      }
    });
  }

  function createMessageBubble(message) {
    const role = message.role === 'user' ? 'user' : 'agent';
    const timestamp = new Date(message.created_at * 1000).toLocaleTimeString('zh-CN', {
      hour: '2-digit',
      minute: '2-digit',
    });

    return `
      <div class="cognos-message cognos-message-${role}">
        <div class="cognos-message-header">
          <span class="cognos-message-sender">${role === 'user' ? '你' : 'Agent'}</span>
          <span class="cognos-message-time">${timestamp}</span>
        </div>
        <div class="cognos-message-content">${role === 'user' ? formatMessageContent(message.content) : renderMarkdown(message.content)}</div>
        ${role === 'user' ? `<button type="button" class="space-keep-btn" data-message-id="${message.id}" title="把这句原话留在空间里，作为意图或事实">留在空间里</button>` : ''}
      </div>
    `;
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

    state.sending = true;
    elements.sendBtn.disabled = true;
    elements.sendBtn.style.display = 'none';
    elements.stopBtn.style.display = 'flex';

    try {
      const response = await fetch(`/index.php/apps/cognos/creations/${state.creationId}/messages`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'requesttoken': OC.requestToken,
        },
        body: JSON.stringify({ message }),
      });

      if (!response.ok) {
        throw new Error('Failed to send message');
      }

      const data = await response.json();
      state.messages.push(...data.messages);

      elements.chatInput.value = '';
      handleInputChange();
      renderMessages();
      // 被叫进来的 agent 可能在这片空间里留下了尝试或读法
      window.CognosSpacePanel?.reload();
    } catch (error) {
      console.error('Send message error:', error);
      alert('发送失败，请重试');
    } finally {
      state.sending = false;
      elements.sendBtn.disabled = false;
      elements.sendBtn.style.display = 'flex';
      elements.stopBtn.style.display = 'none';
      elements.chatInput.focus();
    }
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
