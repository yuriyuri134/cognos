<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <link rel="stylesheet" href="/custom_apps/cognos/css/notes-page.css">
</head>
<body>
    <div id="app-content" data-cognos-notes-root>
        <header class="cognos-notes-header">
            <div>
                <p class="cognos-eyebrow">Cognos</p>
                <h1>材料起点</h1>
                <p>材料属于当前 Nextcloud 账号；从这里开始的 Activity 会保留不可变来源快照。</p>
            </div>
            <nav><a href="/index.php/apps/cognos/activities">Activity</a></nav>
        </header>
        <section class="cognos-notes-toolbar">
            <button id="new-space" type="button">建立材料空间</button>
            <button id="search-notes" type="button">搜索</button>
        </section>
        <form id="space-form" hidden>
            <label>空间名称<input id="space-title" required maxlength="255"></label>
            <label>说明<textarea id="space-description" maxlength="4000"></textarea></label>
            <button type="submit">建立</button>
        </form>
        <div id="search-box" hidden><input type="search" id="search-input" placeholder="搜索材料"></div>
        <p id="notes-notice" class="cognos-notice" hidden></p>
        <section id="spaces-list"><h2>我的材料空间</h2><div id="spaces-container"></div></section>
        <section id="notes-list" hidden><button id="back-to-spaces" type="button">返回空间</button><h2 id="space-title-label"></h2><div id="notes-container"></div></section>
        <section id="search-results" hidden><h2>搜索结果</h2><div id="results-container"></div></section>
    </div>
</body>
</html>
