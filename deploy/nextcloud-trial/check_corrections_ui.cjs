// 空间页「被纠正」一栏：登录后打开一个空间，点「被纠正」，看到收、窄各出现在哪几天和每一次；桌面和 390px 都不溢出、没有页面错误。
// 用法：SPACE=<空间 id> node deploy/nextcloud-trial/check_corrections_ui.cjs（默认 cognos 空间）
const assert = require('node:assert/strict');
const live = require('./lib/live.cjs');
const SPACE = process.env.SPACE || 'bdfdb509fbd1248c8d7487a9b1d4385f';

live.run('check-corrections-ui', async (checks) => {
  const {req} = live.client();
  const d = await req('/norm/projects/' + SPACE + '/data', 'GET');
  const n = (d.project.corrections || []).length;
  assert(n > 0, '这个空间还没有纠正');
  checks.push('接口给出 ' + n + ' 次纠正');
  const b = await live.browser();
  try {
    await b.login();
    await b.go(live.APP + '/norm/projects/' + SPACE);
    await b.until('!!document.querySelector("#cg-space-views [data-view=corrections]")');
    await b.click('#cg-space-views [data-view=corrections]');
    await b.until('!!document.querySelector("#cg-view-corrections .cg-corr") && !document.querySelector("#cg-view-corrections").hidden');
    const shown = await b.evaluate('document.querySelectorAll("#cg-view-corrections .cg-corr").length');
    assert.equal(shown, n);
    const sum = await b.evaluate('document.querySelector(".cg-corr-sum").innerText');
    assert(/收 \d+ 次/.test(sum) && /窄 \d+ 次/.test(sum));
    checks.push('页面列出 ' + shown + ' 次，上面是：' + sum.split('\n').slice(0, 2).join(' / '));
    await b.shoot('corrections-desktop.png', '#cg-space-parts');
    await b.phone();
    await b.until('!!document.querySelector("#cg-view-corrections .cg-corr")');
    assert(await b.noOverflow());
    await b.shoot('corrections-phone.png', '#cg-space-parts');
    checks.push('390px 不溢出');
    assert.deepEqual(b.errors, []);
    checks.push('没有页面错误');
  } finally { b.close(); }
});
