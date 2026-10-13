<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>材料 - Cognos</title>
</head>
<body>
    <div id="app-content" class="cognos-note-view">
        <header class="cognos-notes-header">
            <div><p class="cognos-eyebrow">Cognos · 材料</p><h1 id="note-title">正在读取…</h1><p id="note-meta"></p></div>
            <a href="/index.php/apps/cognos/notes">返回材料</a>
        </header>
        <article id="note-content" class="cognos-note-content"></article>
            <section class="cognos-note-actions">
            <label for="participant-select">让谁面对这份材料</label>
            <select id="participant-select" required><option value="">正在读取参与者…</option></select>
            <button id="discuss-button" type="button">围绕此材料继续</button>
            <span id="discuss-status" role="status"></span>
        </section>
        <section><h2>来源 Activity</h2><div id="references-list"></div></section>
        <input type="hidden" id="note-id" value="<?php p($_['noteId']); ?>">
    </div>
</body>
</html>
