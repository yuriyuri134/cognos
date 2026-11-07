/* 让 Initium 里一段对话真的被读一次、让 Norm 里的空间来认领（cognos-admin，经本机桥叫 Claude Code，会写数据）：
 *   node deploy/nextcloud-trial/check_claim_reading.cjs
 * 先在容器里开一个「验证：读一本书 <时间>」空间（学习，带第 1 版自述）和一段「验证：认领实测 <时间>」Initium 对话（只写原话，不叫 AI），
 * 再按「现在就读」：定性的读者一次、Norm 里的空间各认领一次（主项目和这个验证空间）、最后比一次。
 * 等它读完（最多约 12 分钟），看各空间说是不是自己的、放进了哪里、认领的空间有没有照它说的改自述；截 Initium 右边那栏（桌面、390px）。
 * 最后撤回这次放置（改过的自述退回去），撤掉验证空间；那段 Initium 对话留着，和别的「验证：」对话一样。
 * 这段讲的是读一本书，和 Cognos 无关：主项目应当说不是它的，不会被放进去，也不会改它的自述。
 * 报告 evidence/claim-reading.json；截图 evidence/claim-reading-desktop.png、claim-reading-placed.png、claim-reading-phone.png、claim-reading-phone-claims.png。
 */
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), os = require('node:os');
const {execFileSync} = require('node:child_process');
const live = require('./lib/live.cjs');
const CONTAINER = process.env.COGNOS_APP_CONTAINER || 'cognos-nextcloud-trial-app-1';

const SELF = '我在维持：把《思考，快与慢》一章一章读透，每读完一章留下一张卡片，能讲给没读过的人听。\n'
  + '怎样算往前走：一张卡片写完，能回答「这一章改变了我对自己哪一点的看法」，还带着我自己生活里的例子；只摘抄书里的话、没有自己的例子，就算在原地。\n'
  + '我变过什么：刚开始读，还没变过。\n'
  + '最放不下的：系统1、系统2这些词我能复述，但还说不出自己身上的例子。';
const MESSAGES = [
  ['user', '我在读《思考，快与慢》，第一章讲系统1和系统2。我想每读完一章，就用自己的话写一张卡片，讲给一个没读过的人听。第一章我是这么理解的：系统1是自动的、快的，系统2是费力的、慢的，大部分时候系统2在偷懒，只是给系统1的判断盖章。'],
  ['assistant', '你抓到了主线。可以再补一点：系统2偷懒不全是坏事，它的注意力有限，一直开着反而做不了事。卡片上可以用书里「球拍和球」那道题做例子，它正好让人看见系统1抢答。'],
  ['user', '球拍和球那道题我第一次也答错了，答的是 10 分。我想卡片上就写这个：我自己的系统1是怎么抢答的。第二章讲注意力和努力，说心算时瞳孔会变大，我还没太懂这和系统2有什么关系。'],
  ['assistant', '瞳孔那个实验是在说：系统2的努力是能看得见、量得出的，而且有上限。题一难，瞳孔放大；超过上限，人就放弃或者出错。所以系统2偷懒，很多时候是在省这份有限的力气。'],
  ['user', '那我先把第一章的卡片写出来，第二章读完再回来对一遍。每张卡片都要能回答一个问题：这一章改变了我对自己哪一点的看法。'],
];
// 在容器里开验证空间和那段对话：直接用服务写，不经过 AI
const SETUP = String.raw`<?php
define('OC_CONSOLE', 1); $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/console.php'; require '/var/www/html/lib/base.php';
$in = json_decode(base64_decode('PAYLOAD'), true);
$uid = 'cognos-admin';
\OC::$server->get(\OCP\IUserSession::class)->setUser(\OC::$server->get(\OCP\IUserManager::class)->get($uid));
$norm = \OC::$server->get(\OCA\Cognos\Service\NormService::class);
$creations = \OC::$server->get(\OCA\Cognos\Service\CreationSpaceService::class);
$stamp = date('Y-m-d H:i:s');
$space = $norm->createSpace($uid, '验证：读一本书 ' . $stamp, '学习', [], ['progress' => '每章留下一张能讲给别人听的卡片'], '把《思考，快与慢》一章一章读透');
$self = $norm->writeSelf($uid, $space, $in['self'], '第一次写', '第一次写', '第一次写', '验证');
$cr = $creations->create($uid, '验证：认领实测 ' . $stamp);
foreach ($in['messages'] as [$role, $text]) { $creations->addMessage((int)$cr['id'], $uid, $role, $text); }
echo json_encode(['space' => $space, 'self_id' => $self['id'], 'creation' => (int)$cr['id']]);
`;

function setup() {
  const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-claim-')), 'claim_setup.php');
  fs.writeFileSync(file, SETUP.replace('PAYLOAD', Buffer.from(JSON.stringify({self: SELF, messages: MESSAGES})).toString('base64')));
  execFileSync('docker', ['cp', file, CONTAINER + ':/tmp/claim_setup.php']);
  const out = execFileSync('docker', ['exec', '-u', 'www-data', CONTAINER, 'php', '/tmp/claim_setup.php'], {encoding: 'utf8'});
  return JSON.parse(out.trim().split('\n').pop());
}

live.run('claim-reading', async (checks, report) => {
  const {req} = live.client();
  const ids = setup();
  report.setup = ids;
  checks.push('开了验证空间（学习，第 1 版自述）和一段只有原话的 Initium 对话 #' + ids.creation);
  let reading = null;
  try {
    const started = await req('/creations/' + ids.creation + '/readers', 'POST', {}, 202);
    assert(started.started && started.reading && started.reading.state === 'reading', '没开始读：' + (started.reading ? started.reading.error : started.why));
    const plan = started.reading.result.plan;
    report.plan = plan.map(p => ({role: p.role, who: p.space_title || p.label, self_version: p.self_version, chars: p.chars}));
    assert(plan[0].role === 'reader' && plan.some(p => p.role === 'claim' && p.space_id === ids.space), '验证空间没被请来认领');
    checks.push('按了「现在就读」：' + plan.map(p => p.role === 'claim' ? '「' + p.space_title + '」来认领' : '定性的读者').join('、') + '，最后再比一次');

    let r;
    for (let i = 0; i < 144; i++) { // 12 分钟
      await live.sleep(5000);
      r = await req('/creations/' + ids.creation + '/readers');
      if (r.latest && r.latest.state !== 'reading') break;
    }
    reading = r.latest;
    assert(reading && reading.id === started.reading.id && reading.state === 'done', '没读完：' + (reading ? reading.state + ' ' + (reading.error || '') : '没有记录'));
    const res = reading.result || {}, a = reading.applied || {};
    const claims = (res.readings || []).filter(x => x.role === 'claim');
    const owner = (res.readings || []).find(x => x.role === 'reader') || {};
    report.reader = {what: owner.what, where: owner.where, misfit: owner.misfit, missed: owner.missed};
    report.claims = claims.map(x => ({space: x.space_title, claim: x.claim, why: x.why, mine: x.mine, not_mine: x.not_mine,
      change: x.change && x.change.text ? {changed: x.change.changed, why: x.change.why, unchanged: x.change.unchanged} : null, disagree: x.disagree, missed: x.missed}));
    report.compare = {differ: res.compare && res.compare.differ, needs: res.compare && res.compare.needs};
    report.applied = a;
    assert(!owner.missed && owner.what, '定性的读者没读出东西');
    assert(!/读者\s*\d/.test(JSON.stringify([res.compare && res.compare.differ, res.compare && res.compare.needs, a.why])), '比较的那位写的还是「读者几」，页面上对不上卡片');
    assert(claims.length === plan.filter(p => p.role === 'claim').length && claims.every(x => !x.missed), '有空间的认领没读出来：' + JSON.stringify(claims.map(x => [x.space_title, x.raw || ''])));
    checks.push('都回来了：' + claims.map(x => '「' + x.space_title + '」' + ({mine: '说是它的', part: '说部分是它的', not: '说不是它的'})[x.claim]).join('，')
      + '；放法：' + a.action + (a.space_title ? '「' + a.space_title + '」' : ''));

    const test = claims.find(x => x.space_id === ids.space);
    const others = claims.filter(x => x.space_id !== ids.space);
    assert(test.claim !== 'not', '验证空间说这段不是它的');
    assert(others.every(x => x.claim === 'not'), '和读书无关的空间也说是它的：' + others.filter(x => x.claim !== 'not').map(x => x.space_title).join('、'));
    assert(a.action === 'existing' && a.space_id === ids.space && a.claim === test.claim, '没放进认领它的验证空间');
    if (test.change.text) {
      assert(a.self && a.self.version === 2 && a.self.prev_version === 1, '验证空间说要改自述，放进去以后没改');
      const space = (await req('/norm/projects/' + ids.space + '/data')).project;
      assert(space.self.version === 2 && space.self.text === test.change.text && space.self.source.includes('接下 Initium 里的'), '自述第 2 版不是它认领时说的那份');
      checks.push('放进了认领它的验证空间，它照认领时说的改了自述（第 2 版：' + space.self.changed + '）；主项目说不是，没被动');
    } else {
      checks.push('放进了认领它的验证空间，它说接下也不用改自述；主项目说不是，没被动');
    }

    const b = await live.browser();
    try {
      await b.login();
      const panel = '(p => !!p && p.querySelectorAll(".cg-claim").length === ' + claims.length + ' && p.querySelector(".cg-placed").textContent.includes("验证：读一本书"))(document.querySelector("#space-panel-body .cg-readers"))';
      await b.go(live.APP + '/creations/' + ids.creation);
      await b.until(panel);
      await live.sleep(800);
      await b.shoot('claim-reading-desktop.png');
      await b.shoot('claim-reading-placed.png', '#space-panel-body .cg-placed');
      await b.phone();
      await b.go(live.APP + '/creations/' + ids.creation);
      await b.until('!!document.querySelector("#space-panel-toggle")');
      if (await b.evaluate('document.querySelector(".cognos-chat-app").classList.contains("space-panel-collapsed")')) await b.click('#space-panel-toggle');
      await b.until(panel);
      assert(await b.noOverflow(), 'Initium 读者那栏在 390px 横向溢出');
      // 读者那栏的收起按钮不被 Nextcloud 顶栏盖住，点得到
      assert(await b.evaluate('(c => { const r = c.getBoundingClientRect(); const e = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return !!e && c.contains(e); })(document.querySelector("#space-panel-close"))'),
        '手机上读者那栏的收起按钮被顶栏盖住了');
      await b.shoot('claim-reading-phone.png');
      await b.shoot('claim-reading-phone-claims.png', '#space-panel-body .cg-claim');
      assert.deepEqual(b.errors, [], '页面没有未捕获的错误');
      checks.push('登录后 Initium 右边那栏：定性的读者、' + claims.length + ' 张认领卡、放进了哪里和它改了自述，桌面和 390px 都显示，不溢出，手机上收起按钮点得到，没有页面错误');
    } finally { b.close(); }
  } finally {
    // 收拾：撤回这次放置（改过的自述退回去），撤掉验证空间
    if (reading && reading.state === 'done' && !reading.undone_at && reading.applied && ['existing', 'new'].includes(reading.applied.action)) {
      const u = (await req('/readers/' + reading.id + '/undo', 'POST', {})).reading;
      const after = await req('/creations/' + ids.creation + '/readers');
      const space = (await req('/norm/projects/' + ids.space + '/data')).project;
      report.undo = {undone_at: u.undone_at, linked: after.linked, self_version: space.self && space.self.version};
      assert(u.undone_at && after.linked === null, '撤回以后还连着');
      if (reading.applied.self && reading.applied.self.version) {
        assert(space.self.version === 3 && space.self.text === SELF, '撤回以后自述没退回第 1 版');
        checks.push('撤回以后这段回到 Initium，验证空间的自述退回去（追加第 3 版，内容是第 1 版）');
      } else checks.push('撤回以后这段回到 Initium');
    }
    await req('/norm/projects/' + ids.space + '/withdraw', 'POST', {});
  }
});
