/* 让 Norm 里一个空间真的读一次自己的经历（cognos-admin，经本机桥叫 Claude Code，会写数据：新一版自述只追加，旧的不动）：
 *   node deploy/nextcloud-trial/check_self_reading.cjs                    读主项目（没有主项目就读第一个空间）
 *   SELF_SPACE=<空间 id> node deploy/nextcloud-trial/check_self_reading.cjs
 * 等它读完（最多约 12 分钟），看新一版自述写下了、旁观的那位说了什么，再在登录后的页面上打开「自述」一栏，桌面和 390px 各截一张。
 * 读一次是 2 次运行（它自己读一次，旁观的看一次）。
 * 报告 evidence/self-reading.json（自述全文、改了什么、合不合身、旁观者说的）；截图 evidence/self-reading-desktop.png、self-reading-phone.png。
 */
const assert = require('node:assert/strict');
const live = require('./lib/live.cjs');

live.run('self-reading', async (checks, report) => {
  const {req} = live.client();
  const spaces = (await req('/norm/data')).projects || [];
  const space = process.env.SELF_SPACE ? spaces.find(s => s.id === process.env.SELF_SPACE) : (spaces.find(s => s.is_main) || spaces[0]);
  assert(space, '没有可以读的空间');
  const before = (await req('/norm/projects/' + space.id + '/data')).project;
  const had = before.self ? before.self.version : 0;
  const started = await req('/norm/projects/' + space.id + '/read', 'POST', {}, 202);
  assert(started.started && started.reading && started.reading.state === 'reading', '没开始读：' + (started.reading ? started.reading.error : started.why));
  const plan = started.reading.result.plan;
  report.space = {id: space.id, title: space.title, had, chars: plan[0].chars};
  checks.push('「' + space.title + '」开始读自己的经历（之前有 ' + had + ' 版自述，这次读 ' + plan[0].chars + ' 字），读的记录 #' + started.reading.id);

  let data;
  for (let i = 0; i < 144; i++) { // 12 分钟
    await live.sleep(5000);
    data = await req('/norm/projects/' + space.id + '/data');
    if (!data.drift || data.drift.state !== 'reading') break;
  }
  const d = data.drift;
  assert(d && d.id === started.reading.id && d.state === 'done', '没读完：' + (d ? d.state + ' ' + (d.error || '') : '没有记录'));
  const c = d.result.compare;
  const self = data.project.self;
  report.self = self; report.form = {fits: !c.drifted, then: c.then, now: c.now, why: c.why, options: (c.options || []).map(o => o.label)};
  report.bystander = c.bystander; report.error = d.error;
  assert(c.self_reading && self && c.self.id === self.id && self.text.length > 150, '读完没写成自述');
  // 和上一版一字不差时不另记（它说「没改」）；第一次读一定是新的一版
  if (c.self.new) assert(self.version === had + 1 && self.source === '重读自己的经历', '新一版的版本号或来源不对');
  else assert(had > 0, '第一次读却没写下第一版');
  checks.push((c.self.new ? '读完写下第 ' + self.version + ' 版自述（' + self.text.length + ' 字）' : '读完觉得自述不用改，还是第 ' + self.version + ' 版') + '，'
    + (c.drifted ? '它觉得现在的样子不太合身，给了 ' + c.options.length + ' 种办法' : '它觉得现在的样子还合身'));
  checks.push(c.bystander ? '旁观的读者看了这一版：' + (c.bystander.note || '') + '（挑出 ' + (c.bystander.empty || []).length + ' 处空话）' : '旁观的读者没回来：' + (d.error || '不知道为什么'));

  const b = await live.browser();
  try {
    await b.login();
    for (const [size, set] of [['desktop', b.desktop], ['phone', b.phone]]) {
      await set();
      await b.go(live.APP + '/norm/projects/' + space.id);
      await b.until('!!document.querySelector("#cg-space-views [data-view=self]")');
      await b.click('#cg-space-views [data-view="self"]');
      await b.until('(s => !!s && !s.hidden && s.textContent.includes("第 ' + self.version + ' 版") && !!s.querySelector(".cg-self-text"))(document.querySelector("#cg-view-self"))');
      assert(await b.noOverflow(), '自述一栏在 ' + size + ' 横向溢出');
      await b.shoot('self-reading-' + size + '.png');
    }
    assert.deepEqual(b.errors, [], '页面没有未捕获的错误');
    checks.push('登录后的空间页「自述」一栏显示第 ' + self.version + ' 版，桌面和 390px 都不溢出，没有页面错误');
  } finally { b.close(); }
});
