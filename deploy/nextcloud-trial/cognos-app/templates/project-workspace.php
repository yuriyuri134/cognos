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
                            <label><input type="radio" name="pw-decision-scope" value="here"> 只是这一处和它下面</label>
                        </fieldset>
                        <p id="pw-decision-mode" class="pw-hint"></p>
                        <div class="pw-inline"><button type="submit">登记</button><button type="button" id="pw-decision-reset">清空</button></div>
                    </form>
                </details>
                <details id="pw-decisions-ended-box">
                    <summary>已撤回、被替代的</summary>
                    <ul id="pw-decisions-ended" class="pw-decision-list"></ul>
                </details>
                <details id="pw-handoff">
                    <summary>给叫不醒的端（网页会话）</summary>
                    <p class="pw-hint">Cognos 叫不醒网页上的会话，也看不到它读没读过。要它知道，就把下面这段复制过去；页面上它仍显示「尚无证据」。</p>
                    <label class="pw-sr" for="pw-handoff-text">可复制的入口文字</label>
                    <textarea id="pw-handoff-text" rows="8" readonly></textarea>
                    <button type="button" id="pw-handoff-copy">复制</button>
                </details>
            </section>
            <section class="pw-who" aria-labelledby="pw-who-title">
                <h2 id="pw-who-title">谁在动什么</h2>
                <div id="pw-who" aria-live="polite"></div>
            </section>
            <section class="pw-delegate" aria-labelledby="pw-delegate-title">
                <h2 id="pw-delegate-title">交出去</h2>
                <p class="pw-hint">把这一处的一件事交给本机的 Claude Code，你可以走开。结果回到这里的讨论；允许改当前内容时，它写的版本署它的名字，交出去以后你改过这里，就不覆盖。</p>
                <form id="pw-delegate-form">
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
                    <button type="submit">交出去</button>
                </form>
                <ul id="pw-delegations" class="pw-decision-list" aria-live="polite"></ul>
            </section>
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
