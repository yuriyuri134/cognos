<?php /** @var array $_ */ ?>
<div id="app-content">
<div id="cg-space" class="cg-v2 cg-project" data-project-id="<?php p($_['projectId']); ?>">
    <nav class="cg-nav" id="cg-nav" aria-label="空间"></nav>
    <main class="cg-main">
        <header class="cg-main-head">
            <h1 id="cg-space-title">加载中…</h1>
            <span class="cg-meta" id="cg-space-meta"></span>
        </header>
        <div id="cg-space-top"></div>
        <nav id="cg-space-views" class="cg-space-views" aria-label="项目工作区"></nav>
        <section id="cg-space-conversation" class="cg-chat-wrap" aria-labelledby="cg-space-chat-title">
            <h2 id="cg-space-chat-title" class="cg-visually-hidden">和这个空间的 agent 说</h2>
            <div id="cg-space-chat"></div>
        </section>
        <div id="cg-space-parts" class="cg-space-parts"></div>
    </main>
    <dialog class="cg-overlay" id="cg-map-overlay" aria-labelledby="cg-map-title">
        <div class="cg-overlay-box">
            <header><h2 id="cg-map-title">关系</h2><button type="button" class="cg-btn cg-btn-small" id="cg-map-close">收起</button></header>
            <div class="cg-map" id="cg-map-full"></div>
        </div>
    </dialog>
</div>
</div>
