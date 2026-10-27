'use strict';

(function() {
    const state = {
        creations: [],
        loading: false
    };

    const elements = {
        creationsList: null
    };

    function init() {
        elements.creationsList = document.getElementById('recent-creations-list');
        if (!elements.creationsList) return;

        loadRecentCreations();
    }

    async function loadRecentCreations() {
        if (state.loading) return;
        
        state.loading = true;
        
        try {
            const response = await fetch('/index.php/apps/cognos/creations/data', {
                headers: { 'requesttoken': OC.requestToken },
            });
            if (!response.ok) {
                throw new Error('Failed to fetch creations');
            }
            
            const data = await response.json();
            state.creations = data.creations || [];
            
            renderCreations();
        } catch (error) {
            console.error('Error loading creations:', error);
            renderError();
        } finally {
            state.loading = false;
        }
    }

    function renderCreations() {
        if (!elements.creationsList) return;

        if (state.creations.length === 0) {
            elements.creationsList.innerHTML = `
                <div class="empty-state">
                    <p>还没有创立</p>
                    <p class="empty-hint">点击上方按钮开启第一个创立</p>
                </div>
            `;
            return;
        }

        const recentCreations = state.creations.slice(0, 4);
        
        elements.creationsList.innerHTML = recentCreations.map(creation => {
            const time = formatTimeAgo(creation.updated_at || creation.created_at);
            const messageCount = creation.message_count || 0;
            
            return `
                <a href="/index.php/apps/cognos/creations/${creation.id}" class="creation-card">
                    <h4 class="creation-title">${escapeHtml(creation.title)}</h4>
                    <div class="creation-meta">
                        <span class="creation-time">${time}</span>
                        ${messageCount > 0 ? `<span class="creation-messages">${messageCount} 条对话</span>` : ''}
                    </div>
                </a>
            `;
        }).join('');
    }

    function renderError() {
        if (!elements.creationsList) return;
        
        elements.creationsList.innerHTML = `
            <div class="error-state">
                <p>加载失败</p>
                <button onclick="location.reload()">重试</button>
            </div>
        `;
    }

    function formatTimeAgo(timestamp) {
        if (!timestamp) return '';
        
        // 后端给的是 Unix 秒
        const date = new Date(timestamp * 1000);
        const now = new Date();
        const seconds = Math.floor((now - date) / 1000);
        
        if (seconds < 60) return '刚刚';
        if (seconds < 3600) return `${Math.floor(seconds / 60)} 分钟前`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)} 小时前`;
        if (seconds < 604800) return `${Math.floor(seconds / 86400)} 天前`;
        
        return date.toLocaleDateString('zh-CN', { month: 'short', day: 'numeric' });
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
