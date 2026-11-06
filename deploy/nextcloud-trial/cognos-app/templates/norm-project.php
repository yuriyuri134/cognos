<?php /** @var array $_ */ ?>
<div id="app-content">
<div id="cg-space" class="cg-v2 cg-with-aside" data-project-id="<?php p($_['projectId']); ?>">
    <nav class="cg-nav" id="cg-nav" aria-label="空间"></nav>
    <main class="cg-main">
        <header class="cg-main-head">
            <h1 id="cg-space-title">加载中…</h1>
            <span class="cg-meta" id="cg-space-meta"></span>
        </header>
        <div id="cg-space-top"></div>
        <section class="cg-chat-wrap" aria-labelledby="cg-space-chat-title">
            <h2 id="cg-space-chat-title" class="cg-visually-hidden">和这个空间的 agent 说</h2>
            <div id="cg-space-chat"></div>
        </section>
    </main>
    <aside class="cg-aside" id="cg-space-parts" aria-label="这个空间现在的样子"></aside>
    <div class="cg-overlay" id="cg-map-overlay" hidden>
        <div class="cg-overlay-box" role="dialog" aria-modal="true" aria-labelledby="cg-map-title">
            <header><h2 id="cg-map-title">关系</h2><button type="button" class="cg-btn cg-btn-small" id="cg-map-close">收起</button></header>
            <div class="cg-map" id="cg-map-full"></div>
        </div>
    </div>
</div>
</div>
