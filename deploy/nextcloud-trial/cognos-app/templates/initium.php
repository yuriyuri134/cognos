<?php
/** @var array $_ */
?>
<div id="app-content">
<div id="project-workspace"><main id="initium" class="pw-initium">
    <header class="pw-header">
        <a href="/index.php/apps/cognos/home">Cognos</a>
        <a href="/index.php/apps/cognos/projects">所有项目</a>
        <h1>Initium</h1>
    </header>
    <p id="in-notice" role="status" aria-live="polite"></p>
    <section aria-labelledby="in-capture-title">
        <h2 id="in-capture-title">留下一句</h2>
        <p class="pw-hint">还不用想它是不是项目。一句话、一个链接都行；之后可以接着聊、让 AI 先试，值得持续时再确立。</p>
        <form id="in-capture-form">
            <label class="pw-sr" for="in-text">一句话或一个链接</label>
            <textarea id="in-text" rows="3" maxlength="20000" required placeholder="突然想到的、读到的、想试试的……"></textarea>
            <label class="pw-check"><input type="checkbox" id="in-try"> 留下后让 AI 先试一下</label>
            <button type="submit">留下</button>
        </form>
    </section>
    <section aria-labelledby="in-list-title">
        <h2 id="in-list-title">还没成为项目的</h2>
        <p class="pw-hint">每段会话都还能接着聊。确立以后，它作为项目的来路留在原处，不会搬走。</p>
        <ul id="in-items" class="pw-decision-list"></ul>
    </section>
</main></div>
</div>
