<?php
\OCP\Util::addScript('cognos', 'creation-space');
\OCP\Util::addStyle('cognos', 'creation-space');
?>
<div id="cognos-creation-space" class="cognos-creation-space">
  <header class="cognos-creation-header">
    <div>
      <a href="/index.php/apps/cognos/" class="cognos-back-link">← Cognos</a>
      <h1>Initium</h1>
      <p>在这里找回以前的会话，或开始新的讨论。完整对话在会话页内阅读和继续。</p>
    </div>
    <a href="/index.php/apps/cognos/norm" class="cognos-secondary">Norm →</a>
  </header>

  <section class="cognos-creation-launcher" aria-label="开始新会话">
    <button class="cognos-primary" id="cognos-start-conversation" type="button">新建对话</button>
    <span id="cognos-creation-status" role="status" aria-live="polite"></span>
  </section>

  <section class="cognos-creations-list" aria-label="创立列表">
    <div class="cognos-creations-heading">
      <h2>历史会话</h2><div class="cognos-filter-tabs" role="group" aria-label="对话筛选"><button type="button" class="cognos-tab is-active" data-filter="regular">日常对话</button><button type="button" class="cognos-tab" data-filter="all">全部</button><button type="button" class="cognos-tab" data-filter="verify">验证与脚本</button></div>
      <input id="cognos-creation-search" type="search" placeholder="按标题查找会话" aria-label="查找会话">
    </div>
    <p class="cognos-creations-note">这里显示 Initium 的会话。Norm 空间自己的对话请在 Norm 查看。</p>
    <div id="cognos-creations-loading" class="cognos-loading">加载中...</div>
    <div id="cognos-creations-empty" class="cognos-empty" hidden>
      <p>还没有创立</p>
      <p>点击「新建对话」开始</p>
    </div>
    <div id="cognos-creations-grid" class="cognos-creations-grid"></div>
  </section>
</div>
