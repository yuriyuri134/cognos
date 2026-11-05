---
name: cognos-live-check
description: 在本机 8780 上真实验证 Cognos 的改动：用 cognos-admin 调接口、在登录后的无头浏览器里点页面、看 390px、必要时真实叫本机 Claude Code。写 deploy/nextcloud-trial/check_*.cjs、或要说「验证过了」之前用。
---

# 在 8780 上真实验证

工程检查通过只说明接口和页面按约定工作，不说明想得对。汇报时把「跑过、通过」「没跑」「推断」分开写。

## 用公用的 `lib/live.cjs`，不要再复制一份浏览器代码

```js
const assert = require('node:assert/strict');
const live = require('./lib/live.cjs');

live.run('my-check', async (checks, report) => {      // 写 evidence/my-check.json，失败时退出码 1
  const {req, api} = live.client();                     // cognos-admin；req 走 app 路由，api 走 OCS agent 接口
  const p = await req('/projects/data', 'POST', {title: '验证：xxx ' + Date.now(), request_id: live.id()});
  await req('/projects/' + p.project_id + '/overview', 'GET', null, 404, live.outsider());   // 另一个试用账号应当看不到
  checks.push('一句话说清这一步证明了什么');

  const b = await live.browser();
  try {
    await b.login();                                    // 密码只进页面，不进输出
    await b.go(live.APP + '/projects/' + p.project_id);
    await b.until('!!document.querySelector("#pw-overview")');
    await b.phone(); assert(await b.noOverflow());      // 390px
    await b.shoot('my-check-phone.png', '#pw-overview');
    assert.deepEqual(b.errors, []);                     // 没有未捕获的页面错误
  } finally { b.close(); }
});
```

- `.secrets` 不进 git，只在主工作目录里。在别的目录跑时设 `COGNOS_TRIAL_SECRETS=<主目录>/deploy/nextcloud-trial/.secrets`。
- 部署以后先跑 `node deploy/nextcloud-trial/check_smoke.cjs`：接口通、主要页面登录后在桌面和 390px 能打开、没报错。
- 证据（json、截图）写进 `deploy/nextcloud-trial/evidence/`，不进 git。**截图要自己看一眼**再说页面没问题。

## 写检查的规矩

- 用 cognos-admin 和试用的 outsider 账号，**不用小林的账号**。新建的数据起 `验证：...<时间>` 这样的名字，不删，留作证据。
- 断言写具体：哪个 id、哪个版本、哪句话在不在。不要只断言「没报错」。
- 重跑要能过：不要假设版本号是 2、列表只有 3 条、顺序固定；用内容找，用正则配版本号。
- 真实叫本机 Claude Code 的步骤（交出去、在位置里发消息）一次要 1 到 5 分钟。只在要证明「真实 agent 走得通」时叫，一块改动一两次就够；纯接口逻辑用接口直接测。叫的时候用后台运行，别干等。
- 在 Git Bash 里用 Python 读证据时加 `PYTHONIOENCODING=utf-8`，不然中文显示成乱码（文件本身没坏）。

## 已有的检查

`deploy/nextcloud-trial/check_*.cjs` / `check_*.py`，每个文件开头写了它验证什么。改到哪一块，就重跑那一块原有的检查，作回归。
