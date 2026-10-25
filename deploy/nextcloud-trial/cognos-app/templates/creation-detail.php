<?php
\OCP\Util::addScript('cognos', 'space-panel');
\OCP\Util::addScript('cognos', 'creation-detail');
\OCP\Util::addStyle('cognos', 'creation-detail');
?>

<div class="cognos-chat-app" data-creation-id="<?php p($_['creationId']); ?>">
  <!-- 左侧边栏 -->
  <aside class="chat-sidebar" id="chat-sidebar">
    <div class="sidebar-header">
      <button class="sidebar-toggle" id="sidebar-toggle" aria-label="折叠侧边栏">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
          <path d="M3 5h14M3 10h14M3 15h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </button>
      <h2 class="sidebar-title">创立空间</h2>
    </div>

    <button class="new-creation-btn" id="new-creation-btn">
      <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
        <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      </svg>
      <span>新创立</span>
    </button>

    <div class="creations-list" id="creations-list">
      <div class="loading-state">
        <div class="spinner-small"></div>
        <span>加载中...</span>
      </div>
    </div>

    <div class="sidebar-footer">
      <a href="/index.php/apps/cognos/home" class="footer-link">
        <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
          <path d="M10 3L2 10h2v7h5v-5h2v5h5v-7h2L10 3z"/>
        </svg>
        返回主页
      </a>
    </div>
  </aside>

  <!-- 主对话区 -->
  <main class="chat-main">
    <header class="chat-header">
      <div class="header-left">
        <button class="mobile-menu-btn" id="mobile-menu-btn" aria-label="打开菜单">
          <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
            <path d="M3 5h14M3 10h14M3 15h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </button>
        <h1 class="chat-title" id="chat-title" contenteditable="true" spellcheck="false">加载中...</h1>
      </div>
      <div class="header-actions">
        <button class="space-panel-toggle" id="space-panel-toggle" aria-label="显示或收起「这里」" title="这片空间里有什么" aria-pressed="true">
          这里
        </button>
        <button class="icon-btn" id="share-btn" aria-label="分享" title="分享">
          <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
            <path d="M15 6a2 2 0 100-4 2 2 0 000 4zM15 18a2 2 0 100-4 2 2 0 000 4zM5 12a2 2 0 100-4 2 2 0 000 4z" stroke="currentColor" stroke-width="2"/>
            <path d="M7 11l6-3M7 11l6 3" stroke="currentColor" stroke-width="2"/>
          </svg>
        </button>
        <button class="icon-btn" id="settings-btn" aria-label="设置" title="设置">
          <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor">
            <path d="M10 6a4 4 0 100 8 4 4 0 000-8zm7.7 3.4l-1.8-.5a6 6 0 00-.8-2l1.1-1.5-1.4-1.4-1.5 1.1a6 6 0 00-2-.8l-.5-1.8h-2l-.5 1.8a6 6 0 00-2 .8L4.8 3.6 3.4 5l1.1 1.5a6 6 0 00-.8 2l-1.8.5v2l1.8.5a6 6 0 00.8 2l-1.1 1.5 1.4 1.4 1.5-1.1a6 6 0 002 .8l.5 1.8h2l.5-1.8a6 6 0 002-.8l1.5 1.1 1.4-1.4-1.1-1.5a6 6 0 00.8-2l1.8-.5v-2z"/>
          </svg>
        </button>
      </div>
    </header>

    <div class="chat-messages-container" id="messages-container">
      <div class="messages-inner" id="messages-inner">
        <!-- 消息将在这里渲染 -->
        <div class="empty-state" id="empty-state">
          <div class="empty-icon">💭</div>
          <h3>开始对话</h3>
          <p>在下方输入框中提出你的问题或想法</p>
        </div>
      </div>
    </div>

    <footer class="chat-footer">
      <form class="chat-input-form" id="chat-input-form">
        <div class="input-wrapper">
          <textarea
            class="chat-input"
            id="chat-input"
            placeholder="在这里输入消息... (Shift+Enter 换行, Enter 发送)"
            rows="1"
            maxlength="8000"
          ></textarea>
          <div class="input-actions">
            <button type="button" class="icon-btn-small" id="attach-btn" aria-label="附件" title="附件">
              <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                <path d="M14 7l-7 7a2 2 0 01-3-3l7-7a3.5 3.5 0 015 5l-7 7a5 5 0 01-7-7l7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
              </svg>
            </button>
            <button type="submit" class="send-btn" id="send-btn" aria-label="发送">
              <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor">
                <path d="M2 10l16-8-8 16-2-8-6-0z"/>
              </svg>
            </button>
            <button type="button" class="stop-btn" id="stop-btn" aria-label="停止生成" style="display: none;">
              <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor">
                <rect x="5" y="5" width="10" height="10" rx="1"/>
              </svg>
            </button>
          </div>
        </div>
        <div class="input-footer">
          <span class="char-count" id="char-count">0 / 8000</span>
          <span class="input-hint">Shift+Enter 换行</span>
        </div>
      </form>
    </footer>
  </main>

  <!-- 右侧「这里」：这片空间的入口，和被叫进来的 agent 看到的是同一份 -->
  <aside class="space-panel" id="space-panel" aria-label="这里">
    <div class="space-panel-header">
      <h2 class="space-panel-title">这里</h2>
      <button class="icon-btn" id="space-panel-close" aria-label="收起">
        <svg width="18" height="18" viewBox="0 0 20 20" fill="none">
          <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </button>
    </div>
    <div class="space-panel-body" id="space-panel-body">
      <div class="loading-state">
        <div class="spinner-small"></div>
        <span>加载中...</span>
      </div>
    </div>
  </aside>
</div>
