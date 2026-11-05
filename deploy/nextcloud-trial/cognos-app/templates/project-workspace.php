<?php
/** @var array $_ */
?>
<div id="app-content">
<main id="project-workspace" data-project-id="<?php p($_['projectId']); ?>" data-location-id="<?php p($_['locationId']); ?>">
    <header class="pw-header">
        <a href="/index.php/apps/cognos/home">Cognos</a>
        <a id="pw-all-projects" href="/index.php/apps/cognos/projects">所有项目</a>
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
        <p class="pw-hint"><a href="/index.php/apps/cognos/initium">Initium</a>：还没成为项目的想法和会话，在那里也可以确立项目。</p>
    </section>
    <section id="pw-work-view" hidden>
        <aside class="pw-structure">
            <h2>项目中的位置</h2>
            <a id="pw-overview-link" class="pw-overview-link">项目总览</a>
            <nav id="pw-locations" aria-label="项目结构"></nav>
            <form id="pw-location-form">
                <label for="pw-location-name">从这里展开</label>
                <input id="pw-location-name" required maxlength="200" placeholder="新的部分或方向">
                <button type="submit">建立位置</button>
            </form>
            <details id="pw-restructure" data-location-only>
                <summary>调整这一处</summary>
                <label for="pw-move-parent">移到哪一处下面</label>
                <select id="pw-move-parent"></select>
                <div class="pw-inline">
                    <button type="button" id="pw-preview-move">预览调整</button>
                    <button type="button" id="pw-preview-independent">预览独立成项目</button>
                </div>
            </details>
            <details id="pw-merge" hidden>
                <summary>合并到另一个项目</summary>
                <label for="pw-merge-project">目标项目</label>
                <select id="pw-merge-project"></select>
                <label for="pw-merge-parent">并到那里的哪一处下面</label>
                <select id="pw-merge-parent"></select>
                <button type="button" id="pw-preview-merge">预览合并</button>
            </details>
            <ul id="pw-location-history" class="pw-reach" data-location-only></ul>
        </aside>
        <div class="pw-main">
            <section id="pw-restructure-preview" hidden tabindex="-1" aria-labelledby="pw-restructure-title">
                <h2 id="pw-restructure-title">确认前先看后果</h2>
                <ul id="pw-restructure-lines" class="pw-reach"></ul>
                <div class="pw-inline"><button type="button" id="pw-restructure-apply">确认</button><button type="button" id="pw-restructure-cancel">算了</button></div>
            </section>
            <section class="pw-decisions" aria-labelledby="pw-decisions-title">
                <div class="pw-section-title"><h2 id="pw-decisions-title">在生效的决定</h2></div>
                <p class="pw-hint">你本人说过、现在对这个项目里所有参与者都有效的话。每次有人进来或开始做事，入口最前面就是对那一处适用的这几条。</p>
                <ul id="pw-decisions" class="pw-decision-list"></ul>
                <details id="pw-decision-add">
                    <summary>登记一条决定</summary>
                    <form id="pw-decision-form">
                        <label for="pw-decision-content">你的原话</label>
                        <textarea id="pw-decision-content" rows="3" maxlength="4000" required placeholder="照你说的原样写，不要改写成规则"></textarea>
                        <label for="pw-decision-note">在哪说的（可留空）</label>
                        <input id="pw-decision-note" maxlength="1000" placeholder="例如：11/03 在项目对话里">
                        <fieldset class="pw-scope">
                            <legend>适用范围</legend>
                            <label><input type="radio" name="pw-decision-scope" value="project" checked> 整个项目</label>
                            <label data-location-only><input type="radio" name="pw-decision-scope" value="here"> 只是这一处和它下面</label>
                        </fieldset>
                        <p id="pw-decision-mode" class="pw-hint"></p>
                        <div class="pw-inline"><button type="submit">登记</button><button type="button" id="pw-decision-reset">清空</button></div>
                    </form>
                </details>
                <details id="pw-decisions-ended-box">
                    <summary>已撤回、被替代的</summary>
                    <ul id="pw-decisions-ended" class="pw-decision-list"></ul>
                </details>
                <details id="pw-handoff" data-location-only>
                    <summary>给叫不醒的端（网页会话）</summary>
                    <p class="pw-hint">Cognos 叫不醒网页上的会话，也看不到它读没读过。要它知道，就把下面这段复制过去；页面上它仍显示「尚无证据」。</p>
                    <label class="pw-sr" for="pw-handoff-text">可复制的入口文字</label>
                    <textarea id="pw-handoff-text" rows="8" readonly></textarea>
                    <button type="button" id="pw-handoff-copy">复制</button>
                </details>
            </section>
            <section aria-labelledby="pw-lifecycle-title">
                <h2 id="pw-lifecycle-title">项目状态</h2>
                <p id="pw-lifecycle-state" role="status"></p>
                <p id="pw-explore-entry" hidden><a href="/index.php/apps/cognos/initium">到 Initium 另起一段探索</a>，原项目和讨论保留。</p>
                <details><summary>调整推进安排</summary>
                    <form id="pw-lifecycle-form">
                        <label for="pw-lifecycle-action">操作</label>
                        <select id="pw-lifecycle-action"></select>
                        <label for="pw-lifecycle-note">这次为什么调整（可留空）</label>
                        <textarea id="pw-lifecycle-note" rows="2" maxlength="4000"></textarea>
                        <label for="pw-lifecycle-reopen">再开条件或未完成内容（可留空）</label>
                        <textarea id="pw-lifecycle-reopen" rows="2" maxlength="2000"></textarea>
                        <button type="submit">保存推进安排</button>
                    </form>
                </details>
                <details><summary>状态变化记录</summary><ul id="pw-lifecycle-history"></ul></details>
            </section>
            <section class="pw-who" aria-labelledby="pw-who-title">
                <h2 id="pw-who-title">谁在动什么</h2>
                <div id="pw-who" aria-live="polite"></div>
            </section>
            <section id="pw-followups-section" hidden aria-labelledby="pw-followups-title">
                <h2 id="pw-followups-title">接续安排</h2>
                <p class="pw-hint">这里只记录一次接续选择，不代表 AI 已完成。未知项先核对外部运行；关闭本次接续不会重放它，也不会把实际运行标成完成。</p>
                <ul id="pw-followups" class="pw-decision-list"></ul>
            </section>
            <section id="pw-dispatch-section" hidden aria-labelledby="pw-dispatch-title">
                <h2 id="pw-dispatch-title">排队与派发</h2>
                <p class="pw-hint">保留原消息或反对的引用。已派出不等于已完成；未知项先核对外部工作。关闭记录不重放，也不改变实际运行状态。</p>
                <ul id="pw-dispatch-queue" class="pw-decision-list"></ul>
            </section>
            <p id="pw-merged-banner" class="pw-run pw-warn" hidden></p>
            <section id="pw-origin" aria-labelledby="pw-origin-title" hidden>
                <h2 id="pw-origin-title">来路</h2>
                <p class="pw-hint">这个项目是从哪段会话里的哪几句确立的。原会话没有搬动，还能接着聊。</p>
                <ul id="pw-origin-list" class="pw-decision-list"></ul>
            </section>
            <section id="pw-overview" aria-labelledby="pw-states-title" hidden>
                <h2 id="pw-states-title">各位置现在怎样</h2>
                <ul id="pw-location-states" class="pw-decision-list"></ul>
                <div id="pw-project-history-box" hidden>
                    <h2>结构的经过</h2>
                    <ul id="pw-project-history" class="pw-reach"></ul>
                </div>
                <h2>最近交回的</h2>
                <p class="pw-hint">每次交回时做事的那位上报了依据什么；没上报就照写「未上报依据」，不等于它没有依据。</p>
                <ul id="pw-recent" class="pw-reach"></ul>
            </section>
            <section id="pw-attempts-section" aria-labelledby="pw-attempts-title">
                <div class="pw-section-title"><h2 id="pw-attempts-title">尝试</h2><button type="button" id="at-refresh">刷新尝试</button></div>
                <p class="pw-hint">研究、推演、试写或现实活动都可以从一句话开始。简介说明现在在做什么；阶段记录保留当时的认识，与执行工作的完成分开。</p>
                <p id="at-notice" role="status" aria-live="polite"></p>
                <details id="at-create-box" data-location-only><summary>留下一项尝试</summary><form id="at-create"></form></details>
                <ul id="at-items" class="pw-decision-list"></ul>
            </section>
            <section id="pw-work-items-section" aria-labelledby="pw-work-items-title">
                <div class="pw-section-title"><h2 id="pw-work-items-title">执行工作</h2><button type="button" id="wi-refresh">刷新工作</button></div>
                <p class="pw-hint">有明确交付安排时再登记；讨论和自由探索可以继续在原处。工作是否完成与 AI 是否返回分别记录。</p>
                <p id="wi-notice" role="status" aria-live="polite"></p>
                <details data-location-only><summary>登记一份工作</summary>
                    <form id="wi-create">
                        <label for="wi-description">这份工作的范围</label><textarea id="wi-description" required maxlength="4000" rows="2"></textarea>
                        <label for="wi-owner">负责人（可以暂不指定）</label><input id="wi-owner" maxlength="200">
                        <label for="wi-deliverable">交付什么</label><textarea id="wi-deliverable" maxlength="4000" rows="2"></textarea>
                        <label for="wi-acceptance">怎样验收</label><textarea id="wi-acceptance" maxlength="4000" rows="2"></textarea>
                        <button type="submit">登记工作</button>
                    </form>
                </details>
                <ul id="wi-items" class="pw-decision-list"></ul>
            </section>
            <section class="pw-delegate" data-location-only aria-labelledby="pw-delegate-title">
                <h2 id="pw-delegate-title">交出去</h2>
                <p class="pw-hint">把这一处的一件事交给本机的 Claude Code，你可以走开。结果回到这里的讨论；允许改当前内容时，它写的版本署它的名字，交出去以后你改过这里，就不覆盖。</p>
                <form id="pw-delegate-form">
                    <p id="pw-delegate-work-label" class="pw-hint"></p>
                    <button type="button" id="pw-delegate-work-clear" hidden>不关联这份工作</button>
                    <label for="pw-delegate-ask">要做的事（一句话就行）</label>
                    <textarea id="pw-delegate-ask" rows="2" maxlength="2000" required placeholder="例如：把当前稿里两种入口方案各写三条优缺点放回当前稿"></textarea>
                    <details>
                        <summary>更多（都可以不填）</summary>
                        <label for="pw-delegate-goal">要做成什么</label>
                        <input id="pw-delegate-goal" maxlength="2000">
                        <label for="pw-delegate-means">用什么</label>
                        <input id="pw-delegate-means" maxlength="2000" placeholder="例如：只看当前稿和关联的材料">
                        <label for="pw-delegate-forbidden">不能碰什么</label>
                        <input id="pw-delegate-forbidden" maxlength="2000">
                        <label for="pw-delegate-return">什么时候回来</label>
                        <select id="pw-delegate-return">
                            <option value="10">10 分钟内</option>
                            <option value="30" selected>30 分钟内</option>
                            <option value="60">1 小时内</option>
                            <option value="120">2 小时内</option>
                            <option value="240">4 小时内</option>
                            <option value="1440">一天内</option>
                        </select>
                    </details>
                    <label class="pw-check"><input type="checkbox" id="pw-delegate-edit"> 可以改这一处的当前内容</label>
                    <label class="pw-check"><input type="checkbox" id="pw-delegate-manage-work" disabled> 可以维护关联工作的安排（改范围、记录结果、拆分；子项另行授权）</label>
                    <label class="pw-check"><input type="checkbox" id="pw-delegate-adopt"> 可以在这一处采用（回答或当前内容的某一版）</label>
                    <button type="submit">交出去</button>
                </form>
                <ul id="pw-delegations" class="pw-decision-list" aria-live="polite"></ul>
            </section>
            <section class="pw-content" data-location-only>
                <div class="pw-section-title"><h2>当前内容</h2><span id="pw-revision"></span></div>
                <p class="pw-hint">可以从一句话开始，也可以留空，在下面展开讨论。</p>
                <form id="pw-content-form">
                    <label class="pw-sr" for="pw-content">当前位置的内容</label>
                    <textarea id="pw-content" rows="10" maxlength="100000" placeholder="正在研究的问题、已经形成的设计，或者要继续修改的成果……"></textarea>
                    <div class="pw-inline"><button type="submit" id="pw-save">保存当前内容</button><button type="button" id="pw-adopt-content">在这里采用当前这一版</button><span id="pw-dirty" aria-live="polite"></span></div>
                </form>
                <div id="pw-conflict" hidden>
                    <p>保存期间已有新版本。你的文本还在上面，先比较，再继续。</p>
                    <div id="pw-conflict-content"></div>
                    <button type="button" id="pw-rebase">我已比较，以新版为基础保存上面的文本</button>
                </div>
                <p id="pw-content-sources" class="pw-hint"></p>
                <details id="pw-history"><summary>查看修改经过</summary><div id="pw-history-list"></div><pre id="pw-history-content"></pre></details>
            </section>
            <section class="pw-adoptions" data-location-only aria-labelledby="pw-adoptions-title">
                <h2 id="pw-adoptions-title">在这里采用的</h2>
                <p class="pw-hint">这一处正式采用了哪一样东西的哪一版。只管这一处：在这里撤回，别处的采用不变。被纠正时，采用了那一版的进待复核，只是相关的只提示。</p>
                <ul id="pw-impacts" class="pw-reach" aria-live="polite"></ul>
                <div id="pw-proposals-box" hidden>
                    <h3>AI 提议采用的</h3>
                    <p class="pw-hint">它没有在这里采用的权限，只能提出；采用不采用由你定。</p>
                    <ul id="pw-proposals" class="pw-decision-list"></ul>
                </div>
                <ul id="pw-adoption-list" class="pw-decision-list"></ul>
                <details id="pw-adoptions-ended-box" hidden>
                    <summary>已撤回、被替代的</summary>
                    <ul id="pw-adoptions-ended" class="pw-decision-list"></ul>
                </details>
            </section>
            <section class="pw-materials" data-location-only>
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
            <section class="pw-discussion" data-location-only>
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
