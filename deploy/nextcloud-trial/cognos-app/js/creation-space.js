(function() {
  'use strict';

  const state = {
    creations: [],
    loading: false,
  };

  const elements = {};

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
    elements.titleInput = document.getElementById('cognos-creation-title');
    elements.messageInput = document.getElementById('cognos-creation-message');
    elements.status = document.getElementById('cognos-creation-status');
    elements.loading = document.getElementById('cognos-creations-loading');
    elements.empty = document.getElementById('cognos-creations-empty');
    elements.grid = document.getElementById('cognos-creations-grid');
  }

  function attachEventListeners() {
    elements.newCreationBtn?.addEventListener('click', showNewCreationForm);
    elements.cancelBtn?.addEventListener('click', hideNewCreationForm);
    elements.creationForm?.addEventListener('submit', handleCreateCreation);
  }

  function showNewCreationForm() {
    elements.newCreationForm.hidden = false;
    elements.titleInput.focus();
  }

  function hideNewCreationForm() {
    elements.newCreationForm.hidden = true;
    elements.creationForm.reset();
    elements.status.textContent = '';
  }

  async function handleCreateCreation(e) {
    e.preventDefault();

    const title = elements.titleInput.value.trim();
    const initialMessage = elements.messageInput.value.trim();

    if (!title) {
      elements.status.textContent = '请输入标题';
      return;
    }

    try {
      elements.status.textContent = '创建中...';
      const response = await fetch('/index.php/apps/cognos/creations/data', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'requesttoken': OC.requestToken,
        },
        body: JSON.stringify({ title, initialMessage }),
      });

      if (!response.ok) {
        throw new Error('创建失败');
      }

      const data = await response.json();
      const creationId = data.creation.id;

      // Redirect to detail page
      window.location.href = `/index.php/apps/cognos/creations/${creationId}`;
    } catch (error) {
      console.error('Create creation error:', error);
      elements.status.textContent = '创建失败，请重试';
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
      renderCreations();
    } catch (error) {
      console.error('Load creations error:', error);
      elements.loading.textContent = '加载失败';
    } finally {
      state.loading = false;
      elements.loading.hidden = true;
    }
  }

  function renderCreations() {
    if (state.creations.length === 0) {
      elements.empty.hidden = false;
      elements.grid.innerHTML = '';
      return;
    }

    elements.empty.hidden = true;
    elements.grid.innerHTML = state.creations
      .map(creation => createCreationCard(creation))
      .join('');
  }

  function createCreationCard(creation) {
    const createdDate = new Date(creation.created_at * 1000).toLocaleDateString('zh-CN');
    const updatedDate = new Date(creation.updated_at * 1000).toLocaleDateString('zh-CN');

    return `
      <a href="/index.php/apps/cognos/creations/${creation.id}" class="cognos-creation-card">
        <h3>${escapeHtml(creation.title)}</h3>
        <div class="cognos-creation-meta">
          <span>创建于 ${createdDate}</span>
          <span>最近更新 ${updatedDate}</span>
        </div>
      </a>
    `;
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
