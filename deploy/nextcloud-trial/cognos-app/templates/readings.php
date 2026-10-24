<?php
\OCP\Util::addScript('cognos', 'readings');
\OCP\Util::addStyle('cognos', 'readings');
?>
<div id="cognos-readings" class="cognos-readings">
  <header class="rd-header">
    <a href="/index.php/apps/cognos/home" class="rd-back">← 返回主页</a>
    <h1>主体空间 · <span id="rd-subject">…</span></h1>
    <p class="rd-lede">原始发生永远不改，读法可以换。异常攒够了，读法就重组；新旧读法并存，由后来的发生裁决。</p>
    <p class="rd-meta" id="rd-meta"></p>
  </header>

  <section class="rd-section">
    <h2>对照检验：谁更能预料你的下一句话</h2>
    <p class="rd-note">同一批 <em>训练截止之后</em> 的真实对话里，每次 agent 说完话，三种记忆各自预测你会怎么回应；评审在不知道来源的情况下对照你的真实回复打分（0–3）。</p>
    <div id="rd-eval" class="rd-eval"><div class="rd-loading">加载中…</div></div>
  </section>

  <section class="rd-section">
    <h2>当前读法</h2>
    <div id="rd-current" class="rd-cards"></div>
  </section>

  <section class="rd-section">
    <h2>重组史</h2>
    <p class="rd-note">读法撑不住时发生的变化：旧切法 → 新切法，以及后来的裁决。</p>
    <div id="rd-reorg" class="rd-reorg"></div>
  </section>

  <section class="rd-section">
    <h2>生长过程</h2>
    <div id="rd-timeline" class="rd-timeline"></div>
  </section>

  <section class="rd-section">
    <h2>预测样例</h2>
    <p class="rd-note">A 和 B 得分不同的测试发生。</p>
    <div id="rd-examples" class="rd-examples"></div>
  </section>

  <section class="rd-section">
    <h2>已退出的读法</h2>
    <div id="rd-retired" class="rd-cards rd-cards-retired"></div>
  </section>
</div>
