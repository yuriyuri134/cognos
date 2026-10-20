<?php
\OCP\Util::addScript('cognos', 'home');
\OCP\Util::addStyle('cognos', 'home');
?>

<div id="app">
    <div id="app-content">
        <div class="cognos-home-modern">
            <!-- 创立空间 - 独立区域 -->
            <section class="creation-space-section">
                <div class="section-header">
                    <div class="section-title-group">
                        <h2 class="section-title">创立空间</h2>
                        <p class="section-subtitle">与 Agent 深度对话，探索认识的生长</p>
                    </div>
                </div>

                <div class="creation-space-content">
                    <!-- 新建创立卡片 -->
                    <a href="<?php p(\OC::$server->getURLGenerator()->linkToRoute('cognos.creationSpace.index')); ?>"
                       class="new-creation-card">
                        <div class="card-icon-container">
                            <div class="card-icon">✨</div>
                        </div>
                        <div class="card-body">
                            <h3 class="card-title">开启新的创立</h3>
                            <p class="card-desc">提出问题，与 Agent 展开对话</p>
                        </div>
                        <div class="card-arrow">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M7 4l6 6-6 6" stroke="currentColor" stroke-width="2"
                                      stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                            </svg>
                        </div>
                    </a>

                    <!-- 最近的创立 -->
                    <div id="recent-creations" class="recent-creations-grid">
                        <div class="loading-placeholder">
                            <div class="spinner"></div>
                            <p>加载最近的创立...</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 视觉分隔 -->
            <div class="section-divider">
                <div class="divider-line"></div>
            </div>

            <!-- 示范空间 - 独立区域 -->
            <section class="demo-space-section">
                <div class="section-header">
                    <div class="section-title-group">
                        <h2 class="section-title">示范空间</h2>
                        <p class="section-subtitle">探索 Cognos 在不同场景中的应用</p>
                    </div>
                </div>

                <div class="demo-spaces-grid">
                    <a href="/index.php/apps/cognos/notes" class="demo-space-card" data-space="notes">
                        <div class="space-icon-wrapper">
                            <div class="space-icon">📝</div>
                        </div>
                        <div class="space-content">
                            <h3 class="space-title">笔记</h3>
                            <p class="space-desc">在笔记中组织思考，与 Agent 讨论</p>
                        </div>
                        <div class="space-overlay">
                            <span class="overlay-text">打开笔记空间</span>
                        </div>
                    </a>

                    <a href="/index.php/apps/deck/" class="demo-space-card" data-space="projects">
                        <div class="space-icon-wrapper">
                            <div class="space-icon">📋</div>
                        </div>
                        <div class="space-content">
                            <h3 class="space-title">项目</h3>
                            <p class="space-desc">项目管理与协作，获得智能建议</p>
                        </div>
                        <div class="space-overlay">
                            <span class="overlay-text">打开项目空间</span>
                        </div>
                    </a>

                    <a href="/index.php/apps/talk/" class="demo-space-card" data-space="orgs">
                        <div class="space-icon-wrapper">
                            <div class="space-icon">🏢</div>
                        </div>
                        <div class="space-content">
                            <h3 class="space-title">组织</h3>
                            <p class="space-desc">组织知识，建立共识</p>
                        </div>
                        <div class="space-overlay">
                            <span class="overlay-text">打开组织空间</span>
                        </div>
                    </a>

                    <a href="/index.php/apps/cognos/activities" class="demo-space-card" data-space="learning">
                        <div class="space-icon-wrapper">
                            <div class="space-icon">📚</div>
                        </div>
                        <div class="space-content">
                            <h3 class="space-title">学习</h3>
                            <p class="space-desc">个性化学习路径，深度理解</p>
                        </div>
                        <div class="space-overlay">
                            <span class="overlay-text">打开学习空间</span>
                        </div>
                    </a>
                </div>
            </section>

            <!-- Agent 提示条 -->
            <section class="agent-info-strip">
                <div class="info-icon">🤖</div>
                <div class="info-content">
                    <strong>Agent 在哪里？</strong>
                    <p>所有空间共享同一个 Hermes Agent。它只在你召唤时参与对话。</p>
                </div>
                <a href="/index.php/apps/cognos/activities" class="info-link">
                    查看 Agent 活动 →
                </a>
            </section>
        </div>
    </div>
</div>
