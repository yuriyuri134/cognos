(function() {
  'use strict';

  const state = {
    creations: [],
    loading: false,
    agent: 'claude',
    filter: 'regular',
    query: '',
  };

  const elements = {};

  function isVerifyTitle(title) {
    return /^(?:验证：|检查\s*\d{4}-\d{2}-\d{2}|试一下：agent)/.test(String(title || '').trim());
  }

  function init() {
    cacheElements();
    attachEventListeners();
    loadCreations();
  }

  function cacheElements() {
    elements.newCreationBtn = document.getElementById('cognos-new-creation-btn');
    elements.newCreationForm = document.getElementById('cognos-new-creation-form');
    elements.creationForm = document.getElementById('cognos-creation-form');
    elements.cancelBtn = document.getElementById('cognos-cancel-creation');
    elements.messageInput = document.getElementById('cognos-creation-message');
    elements.status = document.getElementById('cognos-creation-status');
    elements.loading = document.getElementById('cognos-creations-loading');
    elements.empty = document.getElementById('cognos-creations-empty');
    elements.grid = document.getElementById('cognos-creations-grid');
    elements.searchInput = document.getElementById('cognos-creation-search');
    elements.agentButtons = Array.from(document.querySelectorAll('.cognos-pill[data-agent]'));
    elements.filterTabs = Array.from(document.querySelectorAll('.cognos-tab[data-filter]'));
  }

  function attachEventListeners() {
    elements.newCreationBtn?.addEventListener('click', showNewCreationForm);
    elements.cancelBtn?.addEventListener('click', hideNewCreationForm);
    elements.creationForm?.addEventListener('submit', handleCreateCreation);
    elements.agentButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        state.agent = btn.dataset.agent || 'claude';
        elements.agentButtons.forEach((b) => b.classList.toggle('is-active', b === btn));
      });
    });
    elements.filterTabs.forEach((tab) => {
      tab.addEventListener('click', () => {
        state.filter = tab.dataset.filter || 'regular';
        elements.filterTabs.forEach((t) => t.classList.toggle('is-active', t === tab));
        renderCreations();
      });
    });
    elements.searchInput?.addEventListener('input', () => {
      state.query = elements.searchInput.value.trim().toLowerCase();
      renderCreations();
    });
  }

  function showNewCreationForm() {
    elements.newCreationForm.hidden = false;
    elements.messageInput.focus();
  }

  function hideNewCreationForm() {
    elements.newCreationForm.hidden = true;
    elements.creationForm.reset();
    elements.status.textContent = '';
  }

  async function handleCreateCreation(e) {
    e.preventDefault();

    // 不用起标题：拿写下的第一行当名字
    const initialMessage = elements.messageInput.value.trim();
    if (!initialMessage) return;
    const firstLine = initialMessage.split('\n')[0].trim();
    const title = [...firstLine].length > 40 ? [...firstLine].slice(0, 40).join('') + '…' : firstLine;

    try {
      elements.status.textContent = '写下了，正在进对话……';
      const response = await fetch('/index.php/apps/cognos/creations/data', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'requesttoken': OC.requestToken,
        },
        body: JSON.stringify({ title, initialMessage, agent: state.agent }),
      });

      if (!response.ok) {
        throw new Error('创建失败');
      }

      const data = await response.json();
      const creationId = data.creation.id;

      window.location.href = `/index.php/apps/cognos/creations/${creationId}`;
    } catch (error) {
      console.error('Create creation error:', error);
      elements.status.textContent = '没存上，再试一次';
    }
  }

  async function loadCreations() {
    if (state.loading) return;
    state.loading = true;

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
      updateTabCounts();
      renderCreations();
    } catch (error) {
      console.error('Load creations error:', error);
      elements.loading.textContent = '加载失败';
    } finally {
      state.loading = false;
      elements.loading.hidden = true;
    }
  }

  function updateTabCounts() {
    const total = state.creations.length;
    const verifyCount = state.creations.filter(c => isVerifyTitle(c.title)).length;
    const regularCount = total - verifyCount;
    const labels = {
      regular: `日常对话 (${regularCount})`,
      all: `全部 (${total})`,
      verify: `验证与脚本 (${verifyCount})`,
    };
    elements.filterTabs.forEach((tab) => {
      const k = tab.dataset.filter;
      if (labels[k]) tab.textContent = labels[k];
    });
  }

  function renderCreations() {
    const list = state.creations.filter((c) => {
      const verify = isVerifyTitle(c.title);
      if (state.filter === 'regular' && verify) return false;
      if (state.filter === 'verify' && !verify) return false;
      if (state.query && !String(c.title || '').toLowerCase().includes(state.query)) return false;
      return true;
    });

    if (list.length === 0) {
      elements.empty.hidden = false;
      elements.grid.innerHTML = '';
      return;
    }

    elements.empty.hidden = true;
    elements.grid.innerHTML = list
      .map(creation => createCreationCard(creation))
      .join('');
  }

  function createCreationCard(creation) {
    const createdDate = new Date(creation.created_at * 1000).toLocaleDateString('zh-CN');
    const updatedDate = new Date(creation.updated_at * 1000).toLocaleDateString('zh-CN');
    const count = Number(creation.message_count || 0);

    return `
      <a href="/index.php/apps/cognos/creations/${creation.id}" class="cognos-creation-card">
        <h3>${escapeHtml(creation.title)}</h3>
        <div class="cognos-creation-meta">
          <span>${count} 条消息 · 最近更新 ${updatedDate}</span>
          <span>创建于 ${createdDate}</span>
        </div>
      </a>
    `;
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
