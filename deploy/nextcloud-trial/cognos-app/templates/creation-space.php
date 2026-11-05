<?php
\OCP\Util::addScript('cognos', 'creation-space');
\OCP\Util::addStyle('cognos', 'creation-space');
?>
<div id="cognos-creation-space" class="cognos-creation-space">
  <header class="cognos-creation-header">
    <div>
      <a href="/index.php/apps/cognos/" class="cognos-back-link">← Cognos</a>
      <h1>Initium</h1>
      <p>写下什么都行。写下就进对话，agent 会自己读，接着展开。</p>
    </div>
    <a href="/index.php/apps/cognos/norm" class="cognos-secondary">Norm →</a>
  </header>

  <section id="cognos-new-creation-form" class="cognos-new-creation-form">
    <form id="cognos-creation-form">
      <label for="cognos-creation-message" class="cognos-sr">写下的</label>
      <textarea id="cognos-creation-message" maxlength="8000" rows="4" required placeholder="想到的、读到的、想试试的……"></textarea>
      <div class="cognos-form-actions">
        <button class="cognos-primary" type="submit">写下</button>
      </div>
      <span id="cognos-creation-status" role="status"></span>
    </form>
  </section>

  <section class="cognos-creations-list" aria-label="创立列表">
    <div id="cognos-creations-loading" class="cognos-loading">加载中...</div>
    <div id="cognos-creations-empty" class="cognos-empty" hidden>
      <p>还没有创立</p>
      <p>在上面写下第一句</p>
    </div>
    <div id="cognos-creations-grid" class="cognos-creations-grid"></div>
  </section>
</div>
