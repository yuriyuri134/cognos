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
        <div class="cognos-message-content">${formatMessageContent(message.content)}</div>
      </div>
    `;
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
