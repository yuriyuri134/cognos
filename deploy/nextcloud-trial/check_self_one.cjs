// 验证「并成一个 Cognos 自己」（2026-11-10）：
// 首页有「Cognos 自己」这扇门，点进去是 Norm 里 cognos 空间的页面，对话就是被认作 Cognos 自己的那段（#88 那个问题在里面）；
// 桌面和 390px 都能看，没有页面错误。只看，不发消息、不叫 agent。
const assert = require('node:assert/strict');
const live = require('./lib/live.cjs');

live.run('check_self_one', async (checks) => {
  const b = await live.browser();
  try {
    await b.login();
    await b.go(live.APP + '/');
    await b.until('!!document.querySelector(".cg-door-self")');
    const href = await b.evaluate('document.querySelector(".cg-door-self").getAttribute("href")');
    const m = /\/norm\/projects\/([a-f0-9]{32})$/.exec(href);
    assert(m, '门指向一个 Norm 空间：' + href);
    checks.push('首页有「Cognos 自己」的门，指向 Norm 空间 ' + m[1]);
    await b.shoot('self-one-home.png', '.cg-home');
    await b.phone();
    assert(await b.noOverflow());
    await b.shoot('self-one-home-phone.png', '.cg-home');
    await b.desktop();

    await b.go(live.ORIGIN + href);
    await b.until('document.querySelector("#cg-space-chat") && document.querySelector("#cg-space-chat").innerText.includes("你觉得cognos该怎么构建")');
    const title = await b.evaluate('document.querySelector("#cg-space-title").innerText');
    checks.push('点进去是空间「' + title + '」，对话里有 10/27 那句「你觉得cognos该怎么构建？」和后面的回答');
    const tail = await b.evaluate('document.querySelector("#cg-space-chat").innerText.includes("所以在于判断，没有自主判断")');
    assert(tail, '对话里有 10/28 那句');
    await b.shoot('self-one-space.png');
    await b.phone();
    assert(await b.noOverflow());
    await b.shoot('self-one-space-phone.png');
    assert.deepEqual(b.errors, []);
    checks.push('桌面和 390px 都能看，没有页面错误');
  } finally { b.close(); }
});
