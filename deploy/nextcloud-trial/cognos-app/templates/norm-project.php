<?php /** @var array $_ */ ?>
<div id="app-content">
<main id="cg-project" class="cg-page" data-project-id="<?php p($_['projectId']); ?>">
    <header class="cg-header">
        <a href="/index.php/apps/cognos/norm">Norm</a>
        <h1 id="cg-project-title">项目</h1>
    </header>
    <nav class="cg-tabs" role="tablist">
        <button type="button" role="tab" data-tab="stages" aria-selected="true">历史阶段</button>
        <button type="button" role="tab" data-tab="branches" aria-selected="false">分支和部分拆解</button>
        <button type="button" role="tab" data-tab="map" aria-selected="false">关系图</button>
    </nav>
    <section id="cg-tab-stages" class="cg-tab"><ol id="cg-stages" class="cg-stages"></ol></section>
    <section id="cg-tab-branches" class="cg-tab" hidden><div id="cg-branches" class="cg-branches"></div></section>
    <section id="cg-tab-map" class="cg-tab" hidden><div id="cg-map" class="cg-map"></div></section>
</main>
</div>
