<?php
\OCP\Util::addScript('cognos', 'creation-space');
\OCP\Util::addStyle('cognos', 'creation-space');
?>
<div id="cognos-creation-space" class="cognos-creation-space">
  <header class="cognos-creation-header">
    <div>
      <a href="/index.php/apps/cognos/" class="cognos-back-link">← Cognos</a>
      <h1>Initium</h1>
      <p>写下什么都行。写下就进对话，agent 会自己读，接着展开；也可以直接从 ChatGPT 聊天端导入接着聊。</p>
    </div>
    <a href="/index.php/apps/cognos/norm" class="cognos-secondary">Norm →</a>
  </header>

  <section id="cognos-new-creation-form" class="cognos-new-creation-form">
    <form id="cognos-creation-form">
      <label for="cognos-creation-message" class="cognos-sr">写下的</label>
      <textarea id="cognos-creation-message" maxlength="8000" rows="4" required placeholder="想到的、读到的、想试试的……"></textarea>
      <div class="cognos-form-actions">
        <div class="cognos-agent-choice" role="group" aria-label="默认谁来回">
          <span class="cognos-agent-choice-label">先由谁读：</span>
          <button type="button" class="cognos-pill is-active" data-agent="claude">Claude</button>
          <button type="button" class="cognos-pill" data-agent="chatgpt">GPT（聊天端）</button>
          <button type="button" class="cognos-pill" data-agent="gpt">Codex</button>
        </div>
        <button class="cognos-primary" type="submit">写下</button>
      </div>
      <span id="cognos-creation-status" role="status"></span>
    </form>
  </section>

  <section class="cognos-creations-list" aria-label="创立列表">
    <div class="cognos-list-toolbar">
      <div class="cognos-filter-tabs" role="tablist" aria-label="对话筛选">
        <button type="button" class="cognos-tab is-active" data-filter="regular" id="cognos-tab-regular">日常对话</button>
        <button type="button" class="cognos-tab" data-filter="all" id="cognos-tab-all">全部</button>
        <button type="button" class="cognos-tab" data-filter="verify" id="cognos-tab-verify">验证与脚本</button>
      </div>
      <input type="search" id="cognos-creation-search" class="cognos-search-input" placeholder="搜索对话标题…" aria-label="搜索对话标题">
    </div>
    <div id="cognos-creations-loading" class="cognos-loading">加载中...</div>
    <div id="cognos-creations-empty" class="cognos-empty" hidden>
      <p>还没有匹配的对话</p>
      <p>在上面写下第一句，或切换筛选查看全部</p>
    </div>
    <div id="cognos-creations-grid" class="cognos-creations-grid"></div>
  </section>
</div>
