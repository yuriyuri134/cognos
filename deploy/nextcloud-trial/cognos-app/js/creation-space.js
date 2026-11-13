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
    elements.startBtn = document.getElementById('cognos-start-conversation');
    elements.status = document.getElementById('cognos-creation-status');
    elements.search = document.getElementById('cognos-creation-search');
    elements.loading = document.getElementById('cognos-creations-loading');
    elements.empty = document.getElementById('cognos-creations-empty');
    elements.grid = document.getElementById('cognos-creations-grid');
  }

  function attachEventListeners() {
    elements.startBtn?.addEventListener('click', startConversation);
    elements.search?.addEventListener('input', renderCreations);
    document.querySelectorAll('[data-filter]').forEach(button => button.addEventListener('click', () => {
      document.querySelectorAll('[data-filter]').forEach(item => item.classList.toggle('is-active', item === button));
      renderCreations();
    }));
  }

  // 先创建空会话，再在真正的对话框里输入第一句；不再写两次。
  async function startConversation() {
    if (elements.startBtn.disabled) return;
    elements.startBtn.disabled = true;
    elements.status.textContent = '正在打开新对话…';
    try {
      const response = await fetch('/index.php/apps/cognos/creations/data', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
        body: JSON.stringify({ title: '新对话', initialMessage: '' }),
      });
      if (!response.ok) throw new Error('创建失败');
      const data = await response.json();
      if (!data.creation?.id) throw new Error('没有收到会话编号');
      window.location.href = '/index.php/apps/cognos/creations/' + data.creation.id;
    } catch (error) {
      console.error('Start conversation error:', error);
      elements.status.textContent = '没打开：' + error.message + '，请重试';
      elements.startBtn.disabled = false;
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
    const query = elements.search?.value.trim().toLocaleLowerCase() || '';
    const filter = document.querySelector('[data-filter].is-active')?.dataset.filter || 'regular';
    const visible = state.creations.filter(creation =>
      String(creation.title || '').toLocaleLowerCase().includes(query) && (filter === 'all' || (filter === 'verify') === /^(验证[：:]|脚本[：:]|test[：:])/i.test(String(creation.title || ''))));
    if (visible.length === 0) {
      elements.empty.hidden = false;
      elements.empty.querySelector('p').textContent = state.creations.length === 0
        ? '还没有会话' : '没有找到匹配的会话';
      elements.grid.innerHTML = '';
      return;
    }

    elements.empty.hidden = true;
    elements.grid.innerHTML = visible.map(creation => createCreationCard(creation)).join('');
  }

  function createCreationCard(creation) {
    const createdDate = new Date(creation.created_at * 1000).toLocaleDateString('zh-CN');
    const updatedDate = new Date(creation.updated_at * 1000).toLocaleDateString('zh-CN');

    return `
      <a href="/index.php/apps/cognos/creations/${creation.id}" class="cognos-creation-card">
        <h3>${escapeHtml(creation.title)}</h3>
        <div class="cognos-creation-meta">
          <span>创建于 ${createdDate}</span>
          <span>最近更新 ${updatedDate} · ${creation.message_count || 0} 条消息</span>
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
