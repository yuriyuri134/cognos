<?php
\OCP\Util::addStyle('cognos', 'norm');
/** @var array $_ */
$self = $_['self_space'] ?? null;
?>
<div id="app-content">
<main class="cg-home">
    <?php if ($self !== null): ?>
    <a class="cg-door cg-door-self" href="/index.php/apps/cognos/norm/projects/<?php p($self['id']); ?>">
        <h2>Cognos 自己</h2>
        <p>和 Cognos 自己说。它带着自己的经历、总观和还没想完的事，读得到自己的代码；你不在时，它从挂着的事接着走。</p>
    </a>
    <?php endif; ?>
    <a class="cg-door" href="/index.php/apps/cognos/creations">
        <h2>Initium</h2>
        <p>还没成形的东西放这里。写下什么，agent 自己读、接着展开；读者读完，看它是什么、该去哪。</p>
    </a>
    <a class="cg-door" href="/index.php/apps/cognos/norm">
        <h2>Norm</h2>
        <p>成了形的放这里：项目，和带着自己做法的空间。你不在时 agent 照各自的做法接着做。</p>
    </a>
</main>
</div>
