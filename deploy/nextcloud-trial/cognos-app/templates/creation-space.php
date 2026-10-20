<?php
\OCP\Util::addScript('cognos', 'creation-space');
\OCP\Util::addStyle('cognos', 'creation-space');
?>
<div id="cognos-creation-space" class="cognos-creation-space">
  <header class="cognos-creation-header">
    <div>
      <a href="/index.php/apps/cognos/home" class="cognos-back-link">← 返回主页</a>
      <h1>创立空间</h1>
      <p>抛出想法、与 Agent 对话、让对象展开</p>
    </div>
    <button id="cognos-new-creation-btn" class="cognos-primary" type="button">+ 新建创立</button>
  </header>

  <section id="cognos-new-creation-form" class="cognos-new-creation-form" hidden>
    <h2>新建创立</h2>
    <form id="cognos-creation-form">
      <label>
        标题
        <input id="cognos-creation-title" required maxlength="255" placeholder="这个创立是关于什么的？">
      </label>
      <label>
        初始想法（可选）
        <textarea id="cognos-creation-message" maxlength="4000" placeholder="想要尝试什么？Agent 会基于这个初始想法开始回应..."></textarea>
      </label>
      <div class="cognos-form-actions">
        <button class="cognos-primary" type="submit">创建并开始</button>
        <button class="cognos-secondary" type="button" id="cognos-cancel-creation">取消</button>
      </div>
      <span id="cognos-creation-status" role="status"></span>
    </form>
  </section>

  <section class="cognos-creations-list" aria-label="创立列表">
    <div id="cognos-creations-loading" class="cognos-loading">加载中...</div>
    <div id="cognos-creations-empty" class="cognos-empty" hidden>
      <p>还没有创立</p>
      <p>点击上方"新建创立"开始第一个尝试</p>
    </div>
    <div id="cognos-creations-grid" class="cognos-creations-grid"></div>
  </section>
</div>
