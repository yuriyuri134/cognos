/* 让 Norm 里一个空间真的读一轮自己的原话、写历史（读到头的那一轮再重写总观、写自述）。cognos-admin，经本机桥叫 Claude Code，会写数据（都只追加）：
 *   node deploy/nextcloud-trial/check_history_reading.cjs
 *       开一个「验证：历史实测」空间，连一段三天的 Initium 对话（第二天转了向、放下了一条路，第三天定了一件事），读一轮（2 次运行），最后撤掉这个空间
 *   HISTORY_SPACE=<空间 id> node deploy/nextcloud-trial/check_history_reading.cjs
 *       读这个空间的下一轮（块数 + 1 次运行；一轮最多 5 块）。真空间先在页面上看清要几次运行，额度是你的
 * 等它读完（一块的约几分钟；5 块并排读再合一次，最多约 45 分钟），看：读了几块几句、记下几条、摘句和编号对上多少、分了几段、在哪转的、
 * 总观写了什么；再登录打开空间页：总观、历史，展开一句原话，桌面和 390px 各截一张。
 * 报告 evidence/history-reading.json（各段、总观全文、对原话的数）；截图 evidence/history-reading-overview.png、history-reading-history.png、
 * history-reading-phone-overview.png、history-reading-phone-history.png。
 * （原来的 check_self_reading.cjs：读自己现在就是写历史，那个脚本并进这里了。）
 */
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), os = require('node:os');
const {execFileSync} = require('node:child_process');
const live = require('./lib/live.cjs');
const CONTAINER = process.env.COGNOS_APP_CONTAINER || 'cognos-nextcloud-trial-app-1';

const D = 86400;
// [多少秒以前, 谁, 原话]：三天，第二天番茄放下、改种叶菜，第三天定下只种叶菜
const INITIUM = [
  [3 * D, 'user', '我想在阳台种点菜，先从番茄开始。阳台朝北，一天大概只有两个小时太阳。'],
  [3 * D - 120, 'assistant', '朝北的阳台光照不够。番茄要每天六小时以上的直射光，光不够结果会很少。可以先考虑耐阴的叶菜，比如生菜、小葱、薄荷。'],
  [3 * D - 600, 'user', '我还是想试试番茄，买了两棵苗，再买一个补光灯。'],
  [3 * D - 720, 'assistant', '补光灯能补一部分。选全光谱的，每天开十二小时左右，灯离植株三十厘米。'],
  [2 * D, 'user', '番茄苗种了一周，茎越长越细，叶子发黄，补光灯好像也不太够。'],
  [2 * D - 180, 'assistant', '这是徒长，光还是不够。要么再加灯，要么换作物。'],
  [2 * D - 900, 'user', '算了，番茄放下吧。改种生菜和小葱，不折腾补光灯了。'],
  [2 * D - 1020, 'assistant', '好。生菜可以撒播，十天左右就能间苗；小葱可以把买来的葱根直接插进土里。'],
  [D, 'user', '生菜发芽了，小葱也冒新叶了。我定了：以后只种叶菜，每周六浇一次营养液。'],
  [D - 120, 'assistant', '可以。每周记一下长势，看浇营养液的间隔要不要调。'],
];
const OWN = ['这个空间以后就记阳台种菜的事，每周六我来说一下长势。'];
// 在容器里开验证空间和那段对话：直接用服务写，不经过 AI；Initium 那几句的时间往前挪成三天
const SETUP = String.raw`<?php
define('OC_CONSOLE', 1); $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/console.php'; require '/var/www/html/lib/base.php';
$in = json_decode(base64_decode('PAYLOAD'), true);
$uid = 'cognos-admin';
\OC::$server->get(\OCP\IUserSession::class)->setUser(\OC::$server->get(\OCP\IUserManager::class)->get($uid));
$norm = \OC::$server->get(\OCA\Cognos\Service\NormService::class);
$creations = \OC::$server->get(\OCA\Cognos\Service\CreationSpaceService::class);
$db = \OC::$server->get(\OCP\IDBConnection::class);
$stamp = date('Y-m-d H:i:s');
$space = $norm->createSpace($uid, '验证：历史实测 ' . $stamp, '项目', [], ['progress' => '叶菜长起来，每周记一次长势'], '阳台种菜');
$conv = $norm->spaceConversation($uid, $space);
$cr = $creations->create($uid, '验证：历史实测原话 ' . $stamp);
$cid = (int)$cr['id'];
$norm->link($uid, $space, $cid, null);
$now = time();
foreach ($in['initium'] as [$ago, $role, $text]) {
    $m = $creations->addMessage($cid, $uid, $role, $text, null, $role === 'assistant' ? 'Claude' : null);
    $q = $db->getQueryBuilder();
    $q->update('cognos_creation_messages')->set('created_at', $q->createNamedParameter($now - (int)$ago, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
        ->where($q->expr()->eq('id', $q->createNamedParameter((int)$m['id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeStatement();
}
foreach ($in['own'] as $text) { $creations->addMessage($conv, $uid, 'user', $text); }
echo json_encode(['space' => $space, 'creation' => $cid, 'conversation' => $conv]);
`;

function setup() {
  const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-history-')), 'history_setup.php');
  fs.writeFileSync(file, SETUP.replace('PAYLOAD', Buffer.from(JSON.stringify({initium: INITIUM, own: OWN})).toString('base64')));
  execFileSync('docker', ['cp', file, CONTAINER + ':/tmp/history_setup.php']);
  const out = execFileSync('docker', ['exec', '-u', 'www-data', CONTAINER, 'php', '/tmp/history_setup.php'], {encoding: 'utf8'});
  return JSON.parse(out.trim().split('\n').pop());
}

live.run('history-reading', async (checks, report) => {
  const {req} = live.client();
  const test = !process.env.HISTORY_SPACE;
  const ids = test ? setup() : null;
  const spaceId = test ? ids.space : process.env.HISTORY_SPACE;
  if (test) {
    report.setup = ids;
    checks.push('开了验证空间「验证：历史实测」：连着一段三天的 Initium 对话（10 句，第二天放下番茄、改种叶菜，第三天定下只种叶菜），这里的对话 1 句');
  }
  try {
    const before = await req('/norm/projects/' + spaceId + '/data');
    const p0 = before.project, u = before.unread;
    assert(u && u.items > 0, '没有没读的原话：' + JSON.stringify(u));
    report.before = {title: p0.title, unread: u, history: p0.history_meta, overview: p0.overview ? p0.overview.version : 0, self: p0.self ? p0.self.version : 0};
    checks.push('「' + p0.title + '」还有 ' + u.items + ' 句没读（约 ' + u.chars + ' 字），' + u.chunks + ' 块，读完要 ' + u.runs + ' 次运行'
      + (u.rounds > 1 ? '，分 ' + u.rounds + ' 轮；这次读第一轮' : ''));

    const started = await req('/norm/projects/' + spaceId + '/read', 'POST', {}, 202);
    assert(started.started && started.reading && started.reading.state === 'reading', '没开始读：' + (started.reading ? started.reading.error : started.why));
    const res0 = started.reading.result;
    report.plan = res0.plan.map(x => ({label: x.label, role: x.role, chars: x.chars, items: x.items}));
    report.run = res0.run;
    checks.push('开始读：' + res0.plan.length + ' 块（' + res0.plan.map(x => x.chars + ' 字').join('、') + '）' + (res0.final ? '，读到头' : '，这一轮读不到头'));

    let data;
    for (let i = 0; i < 540; i++) { // 45 分钟
      await live.sleep(5000);
      data = await req('/norm/projects/' + spaceId + '/data');
      if (!data.drift || data.drift.state !== 'reading') break;
    }
    const d = data.drift;
    assert(d && d.id === started.reading.id && d.state === 'done', '没读完：' + (d ? d.state + ' ' + (d.error || '') : '没有记录'));
    const r = d.result, c = r.compare || {}, st = r.stats || {};
    report.stats = st; report.readings = (r.readings || []).map(x => ({label: x.label, what: x.what, open: x.open, entries: x.entries, missed: x.missed, stats: x.stats, raw: x.raw}));
    report.merge = {what: c.what, stages: c.stages, overview: c.overview, merge_error: c.merge_error, raw: r.compare_raw ? r.compare_raw.slice(0, 4000) : null};
    report.error = d.error;
    assert(st.chunks_ok === st.chunks && st.entries > 0, '有块没读出东西：' + JSON.stringify(report.readings.filter(x => x.missed)));
    assert(!c.merge_error, '合起来那一步：' + c.merge_error);
    checks.push('读完：' + st.chunks + ' 块都读出来了，记下 ' + st.entries + ' 条；摘的原话 ' + st.quotes + ' 句，对上 ' + st.quotes_ok + ' 句；指的编号 ' + st.refs + ' 个，对上 '
      + st.refs_ok + ' 个；' + st.no_source + ' 条没指到原话');
    assert(st.quotes_ok > 0 && st.no_source <= st.entries / 2, '对得上原话的太少');

    const full = await req('/norm/projects/' + spaceId + '/data?history=1');
    const h = full.history, pj = full.project;
    report.history = {version: h.version, changed: h.changed, notes: h.notes, unplaced: h.unplaced.length, skipped: h.skipped.length,
      stages: h.stages.map(s => ({title: s.title, from: s.from, to: s.to, text: s.text, turn: s.turn, turn_by: s.turn_by, kept: s.kept,
        dropped: (s.dropped || []).map(x => x.text), entries: (s.entries || []).map(id => h.entries[id] ? h.entries[id].kind + '｜' + h.entries[id].title : id)}))};
    assert(h.stages.length > 0, '历史没分段');
    if (test) assert.equal(h.unplaced.length, 0, '有条目没归进段');
    else if (h.unplaced.length) checks.push('有 ' + h.unplaced.length + ' 条没归进段（页面上可以只合一次）');
    const turned = h.stages.filter(s => s.turn);
    checks.push('历史第 ' + h.version + ' 版：' + h.stages.length + ' 段（' + h.stages.map(s => '「' + s.title + '」').join(' → ') + '）'
      + (turned.length ? '，转在：' + turned.map(s => s.turn).join('；') : '，没写转向'));
    if (test) assert(turned.length > 0, '番茄放下、改种叶菜，历史里却没有一处转向');
    if (res0.final) {
      const ov = pj.overview;
      report.overview = ov; report.self = pj.self;
      assert(ov && ov.now && ov.lines.length > 0, '读到头了，总观没写或者没有线');
      assert(pj.self && c.self && c.self.version === pj.self.version, '读到头了，自述没写');
      checks.push('总观第 ' + ov.version + ' 版：' + ov.lines.length + ' 条线、' + ov.yours.length + ' 件只有你能定的；自述第 ' + pj.self.version + ' 版'
        + (c.drifted ? '；它觉得现在的样子不太合身' : ''));
    }
    if (test) {
      assert.equal(full.unread.items, 0, '读完了还有没读的');
      checks.push('读过的记下了，再算没读的是 0');
    }

    const b = await live.browser();
    try {
      await b.login();
      const url = live.APP + '/norm/projects/' + spaceId;
      const first = res0.final || pj.overview ? 'overview' : 'conversation';
      for (const size of ['desktop', 'phone']) {
        await (size === 'desktop' ? b.desktop() : b.phone());
        await b.go(url);
        await b.until('document.querySelector("#cg-space-views [data-view=' + first + ']") && document.querySelector("#cg-space-views [data-view=' + first + ']").getAttribute("aria-pressed")==="true"');
        if (first === 'overview') {
          await b.until('!!document.querySelector("#cg-view-overview .cg-ov-now")');
          assert(await b.noOverflow(), '总观在 ' + size + ' 横向溢出');
          await b.shoot('history-reading-' + (size === 'phone' ? 'phone-' : '') + 'overview.png');
        }
        await b.click('#cg-space-views [data-view="history"]');
        await b.until('(s => !!s && !s.hidden && s.querySelectorAll(".cg-stage").length >= ' + h.stages.length + ')(document.querySelector("#cg-view-history"))');
        // 以前的段默认收着：都打开，展开第一句原话
        await b.evaluate('document.querySelectorAll("#cg-view-history .cg-stage details").forEach(x => { x.open = true; })');
        await b.click('#cg-view-history .cg-stages .cg-orig-btn');
        await b.until('(x => !!x && !x.textContent.includes("正在拿原话"))(document.querySelector("#cg-view-history .cg-stages .cg-orig-box"))');
        assert(await b.evaluate('!/拿不到|已经不在了/.test(document.querySelector("#cg-view-history .cg-stages .cg-orig-box").textContent)'), '原话拿不到');
        assert(await b.noOverflow(), '历史在 ' + size + ' 横向溢出');
        await b.shoot('history-reading-' + (size === 'phone' ? 'phone-' : '') + 'history.png', '#cg-view-history .cg-stages');
      }
      assert.deepEqual(b.errors, [], '页面没有未捕获的错误');
      checks.push('登录后的空间页：' + (first === 'overview' ? '打开就是总观，' : '') + '历史一段段显示，原话展开得了，桌面和 390px 都不溢出，没有页面错误');
    } finally { b.close(); }
  } finally {
    if (test) await req('/norm/projects/' + spaceId + '/withdraw', 'POST', {});
  }
});
