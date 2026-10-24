<?php
\OCP\Util::addScript('cognos', 'home');
\OCP\Util::addStyle('cognos', 'home');
?>

<div id="app">
    <div id="app-content">
        <div id="cognos-home">
            <!-- 创立空间区域 -->
            <section class="cognos-section creation-section">
                <div class="section-header">
                    <h2>创立空间</h2>
                    <p>与 Agent 深度对话，探索认识的生长</p>
                </div>

                <a href="/index.php/apps/cognos/creations" class="new-creation-btn">
                    <span class="btn-icon">✨</span>
                    <span class="btn-text">
                        <strong>开启新的创立</strong>
                        <small>提出问题，与 Agent 展开对话</small>
                    </span>
                    <span class="btn-arrow">→</span>
                </a>

                <div class="recent-creations">
                    <div class="recent-header">
                        <h3>最近的创立</h3>
                        <a href="/index.php/apps/cognos/creations" class="view-all">查看全部 →</a>
                    </div>
                    <div id="recent-creations-list" class="creations-grid">
                        <div class="loading-state">
                            <div class="spinner"></div>
                            <p>加载中...</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 示范空间区域 -->
            <section class="cognos-section demo-section">
                <div class="section-header">
                    <h2>示范空间</h2>
                    <p>探索 Cognos 在不同场景中的应用</p>
                </div>

                <div class="demo-spaces-grid">
                    <a href="/index.php/apps/cognos/notes" class="demo-space-card">
                        <div class="space-icon">📝</div>
                        <h3>笔记</h3>
                        <p>在笔记中组织思考，与 Agent 讨论</p>
                    </a>

                    <a href="/index.php/apps/talk/" class="demo-space-card">
                        <div class="space-icon">🏢</div>
                        <h3>组织</h3>
                        <p>组织知识，建立共识</p>
                    </a>

                    <a href="/index.php/apps/deck/" class="demo-space-card">
                        <div class="space-icon">📋</div>
                        <h3>项目</h3>
                        <p>项目管理与协作，获得智能建议</p>
                    </a>

                    <a href="/index.php/apps/cognos/activities" class="demo-space-card">
                        <div class="space-icon">📚</div>
                        <h3>学习</h3>
                        <p>个性化学习路径，深度理解</p>
                    </a>

                    <a href="/index.php/apps/cognos/readings" class="demo-space-card">
                        <div class="space-icon">🧭</div>
                        <h3>主体空间（试验）</h3>
                        <p>读法引擎：原话不改，读法可重组</p>
                    </a>
                </div>
            </section>

            <!-- Agent 信息条 -->
            <section class="agent-info">
                <div class="info-icon">🤖</div>
                <div class="info-text">
                    <strong>关于 Hermes Agent</strong>
                    <p>所有空间共享同一个 Agent。它只在你召唤时参与对话。</p>
                </div>
                <a href="/index.php/apps/cognos/activities" class="info-link">查看活动记录 →</a>
            </section>
        </div>
    </div>
</div>
