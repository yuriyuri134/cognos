<?php
/** @var array $_ */
?>
<div id="app-content">
<main id="project-workspace" data-project-id="<?php p($_['projectId']); ?>" data-location-id="<?php p($_['locationId']); ?>">
    <header class="pw-header">
        <a href="<?php p(\OCP\Util::linkToRoute('cognos.home.index')); ?>">Cognos</a>
        <a id="pw-all-projects" href="<?php p(\OCP\Util::linkToRoute('cognos.project.page')); ?>">所有项目</a>
        <h1 id="pw-title">项目</h1>
    </header>
    <p id="pw-notice" role="status" aria-live="polite"></p>
    <section id="pw-list-view">
        <form id="pw-create-form" class="pw-inline">
            <label for="pw-project-name">新项目</label>
            <input id="pw-project-name" maxlength="200" required placeholder="给这件事起个名字">
            <button type="submit">确立项目</button>
        </form>
        <ul id="pw-projects" class="pw-names"></ul>
    </section>
    <section id="pw-work-view" hidden>
        <aside class="pw-structure">
            <h2>项目中的位置</h2>
            <nav id="pw-locations" aria-label="项目结构"></nav>
            <form id="pw-location-form">
                <label for="pw-location-name">从这里展开</label>
                <input id="pw-location-name" required maxlength="200" placeholder="新的部分或方向">
                <button type="submit">建立位置</button>
            </form>
        </aside>
        <div class="pw-main">
            <section class="pw-content">
                <div class="pw-section-title"><h2>当前内容</h2><span id="pw-revision"></span></div>
                <p class="pw-hint">可以从一句话开始，也可以留空，在下面展开讨论。</p>
                <form id="pw-content-form">
                    <label class="pw-sr" for="pw-content">当前位置的内容</label>
                    <textarea id="pw-content" rows="10" maxlength="100000" placeholder="正在研究的问题、已经形成的设计，或者要继续修改的成果……"></textarea>
                    <div class="pw-inline"><button type="submit" id="pw-save">保存当前内容</button><span id="pw-dirty" aria-live="polite"></span></div>
                </form>
                <div id="pw-conflict" hidden>
                    <p>保存期间已有新版本。你的文本还在上面，先比较，再继续。</p>
                    <pre id="pw-conflict-content"></pre>
                    <button type="button" id="pw-rebase">我已比较，以新版为基础保存上面的文本</button>
                </div>
                <p id="pw-content-sources" class="pw-hint"></p>
                <details id="pw-history"><summary>查看修改经过</summary><div id="pw-history-list"></div><pre id="pw-history-content"></pre></details>
            </section>
            <section class="pw-materials">
                <h2>在这里使用的材料</h2>
                <ul id="pw-usages"></ul>
                <details><summary>关联已有会话</summary>
                    <form id="pw-usage-form">
                        <label for="pw-source">已有会话</label><select id="pw-source" required></select>
                        <label for="pw-purpose">在这里怎样用（可留空）</label>
                        <input id="pw-purpose" maxlength="2000" placeholder="例如：比较另一种解释">
                        <button type="submit">关联到这里</button>
                    </form>
                </details>
            </section>
            <section class="pw-discussion">
                <div class="pw-section-title"><h2>在这里讨论</h2><a id="pw-original-chat">展开原会话</a></div>
                <div id="pw-runs" aria-live="polite"></div>
                <div id="pw-messages" role="log" aria-label="当前位置的讨论"></div>
                <form id="pw-message-form">
                    <label class="pw-sr" for="pw-message">发给 AI 的消息</label>
                    <textarea id="pw-message" rows="3" required placeholder="从当前位置继续，或提出另一个问题……"></textarea>
                    <button id="pw-send" type="submit">发送</button>
                </form>
            </section>
        </div>
    </section>
</main>
</div>
