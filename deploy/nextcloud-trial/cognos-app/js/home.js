(() => {
  'use strict';

  // ===== 加载最近的创立 =====
  async function loadRecentCreations() {
    const container = document.getElementById('recent-creations');
    if (!container) return;

    try {
      const response = await fetch('/index.php/apps/cognos/creations/data', {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'requesttoken': window.OC?.requestToken || ''
        }
      });

      if (!response.ok) {
        throw new Error(`加载失败: ${response.status}`);
      }

      const data = await response.json();
      const creations = data.creations || [];

      if (creations.length === 0) {
        container.innerHTML = `
          <div class="loading-placeholder">
            <p style="color: var(--text-secondary);">还没有创立，点击上方卡片开启第一个</p>
          </div>
        `;
        return;
      }

      // 只显示最近 3 个
      const recent = creations.slice(0, 3);
      container.innerHTML = recent.map(creation => {
        const createdAt = new Date(creation.created_at * 1000);
        const timeAgo = formatTimeAgo(createdAt);
        const messageCount = creation.message_count || 0;

        return `
          <a href="/index.php/apps/cognos/creations/${creation.id}" class="creation-item">
            <h4 class="creation-item-title">${escapeHtml(creation.title)}</h4>
            <div class="creation-item-meta">
              <span>⏱ ${timeAgo}</span>
              <span>💬 ${messageCount} 条消息</span>
            </div>
          </a>
        `;
      }).join('');

    } catch (error) {
      console.error('加载最近创立失败:', error);
      container.innerHTML = `
        <div class="loading-placeholder">
          <p style="color: var(--text-tertiary);">加载失败，请稍后再试</p>
        </div>
      `;
    }
  }

  // ===== 工具函数 =====
  function formatTimeAgo(date) {
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return '刚刚';
    if (diffMins < 60) return `${diffMins} 分钟前`;
    if (diffHours < 24) return `${diffHours} 小时前`;
    if (diffDays < 7) return `${diffDays} 天前`;
    return date.toLocaleDateString('zh-CN', { month: 'short', day: 'numeric' });
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // ===== 初始化 =====
  document.addEventListener('DOMContentLoaded', () => {
    loadRecentCreations();
  });
})();
