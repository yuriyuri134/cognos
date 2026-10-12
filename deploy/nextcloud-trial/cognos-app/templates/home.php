<?php
\OCP\Util::addScript('cognos', 'home');
\OCP\Util::addStyle('cognos', 'home');
?>
<div id="cognos-home" class="cognos-home">
  <header class="cognos-home-header">
    <div>
      <p class="cognos-eyebrow">Cognos</p>
      <h1>你的工作空间</h1>
      <p>把材料、协作、项目行动和学习放在同一个连续空间里。每个空间都可以继续和 Agent 对话。</p>
    </div>
    <button id="cognos-create-space" class="cognos-primary" type="button">＋ 新建空间</button>
  </header>

  <section id="cognos-space-form" class="cognos-space-form" hidden>
    <h2>新建一个空间</h2>
    <p>先选择它要承载的活动，之后可以再连接其他空间。</p>
    <div class="cognos-space-types" role="group" aria-label="空间类型">
      <button type="button" data-space-type="notes"><strong>笔记空间</strong><span>保存材料、思考和来源</span></button>
      <button type="button" data-space-type="organization"><strong>组织空间</strong><span>成员、群聊和共同协作</span></button>
      <button type="button" data-space-type="project"><strong>项目空间</strong><span>任务、文件、决定和进展</span></button>
      <button type="button" data-space-type="learning"><strong>学习空间</strong><span>围绕材料持续理解和练习</span></button>
    </div>
    <form id="cognos-create-space-form">
      <label>名称<input id="cognos-space-name" required maxlength="255" placeholder="例如：Cognos 第一轮实验"></label>
      <label>说明<textarea id="cognos-space-description" maxlength="4000" placeholder="这个空间现在要承载什么？"></textarea></label>
      <input type="hidden" id="cognos-space-type" value="">
      <button class="cognos-primary" type="submit">建立空间</button>
      <span id="cognos-space-status" role="status"></span>
    </form>
  </section>

  <section class="cognos-space-grid" aria-label="空间类型">
    <a class="cognos-space-card" href="/index.php/apps/cognos/notes">
      <span class="cognos-space-icon">▤</span><h2>笔记</h2>
      <p>材料、Obsidian 来源、链接和版本。打开一份材料后，可以选择 Agent 围绕它继续。</p>
      <span class="cognos-space-action">打开笔记 →</span>
    </a>
    <a class="cognos-space-card" href="/index.php/apps/talk/">
      <span class="cognos-space-icon">◌</span><h2>组织</h2>
      <p>成员、群聊和共同活动。组织空间连接 Talk，对话中可以明确召唤 Agent。</p>
      <span class="cognos-space-action">打开组织协作 →</span>
    </a>
    <a class="cognos-space-card" href="/index.php/apps/deck/">
      <span class="cognos-space-icon">▦</span><h2>项目</h2>
      <p>任务、文件、决定和项目动态。Deck 卡片可以建立 Activity，保留任务来源快照。</p>
      <span class="cognos-space-action">打开项目 →</span>
    </a>
    <a class="cognos-space-card" href="/index.php/apps/cognos/activities">
      <span class="cognos-space-icon">↻</span><h2>学习</h2>
      <p>把笔记、项目经历和 Agent 对话连接起来，形成可以继续的学习活动。</p>
      <span class="cognos-space-action">打开学习活动 →</span>
    </a>
  </section>

  <section class="cognos-agent-strip">
    <div><strong>Agent 在哪里？</strong><p>笔记页面、Activity 页面和组织群聊都使用同一个 Hermes Agent 边界。Agent 只有在你选择或召唤后才参与。</p></div>
    <a class="cognos-secondary" href="/index.php/apps/cognos/activities">查看 Agent 活动 →</a>
  </section>
</div>
