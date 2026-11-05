/* 登录后的浏览器检查共用的那一段：起无头 Chrome、登录 8780、点击、填写、截图、390px 不横向溢出、收集页面未捕获的异常。
 * 以前每个 check_*_browser.cjs 都把这段复制一遍，新检查改用这里；旧脚本不用改。
 *
 * 用法：
 *   const {open} = require('./browser-check.cjs');
 *   const b = await open({name: 'my-check'});          // 起浏览器，登录 cognos-admin
 *   await b.go(b.base + '/projects/locations/' + id);
 *   await b.until('!!document.querySelector("#pw-who")');
 *   b.pass('能看到谁在做什么');                         // 记一条通过的检查
 *   await b.shoot('my-check-desktop.png', '#pw-who-title');
 *   await b.narrow('my-check-mobile.png', '#pw-who-title'); // 换成 390px，断言不横向溢出，再截图
 *   await b.finish();                                  // 断言没有未捕获异常，写 evidence/<name>.json
 *
 * 出错时用 b.fail(error)。密码只从本机 .secrets/admin-password 读，不打印、不进报告。
 */
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), assert = require('node:assert/strict');
const {spawn} = require('node:child_process');

const root = __dirname;
const evidenceDir = process.env.COGNOS_TRIAL_EVIDENCE_DIR || path.join(root, 'evidence');
const sleep = ms => new Promise(r => setTimeout(r, ms));

async function open({name, origin = process.env.COGNOS_ORIGIN || 'http://127.0.0.1:8780', user = 'cognos-admin',
  login = true, width = 1360, height = 1000} = {}) {
  if (!name || !/^[\w-]+$/.test(name)) throw Error('open() 需要 name（只用字母、数字、- 和 _），报告写到 evidence/<name>.json');
  fs.mkdirSync(evidenceDir, {recursive: true});
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-' + name + '-'));
  const chrome = process.env.COGNOS_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
  const browser = spawn(chrome, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-sandbox',
    '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank'], {windowsHide: true, stdio: 'ignore'});
  let ws, counter = 0, closed = false;
  const pending = new Map(), errors = [], checks = [];
  const report = {name, at: new Date().toISOString(), checks, javascript_errors: errors};

  function call(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = ++counter;
      const timer = setTimeout(() => {pending.delete(id); reject(Error('浏览器超时：' + method));}, 20000);
      pending.set(id, {resolve: x => {clearTimeout(timer); resolve(x);}, reject: e => {clearTimeout(timer); reject(e);}});
      ws.send(JSON.stringify({id, method, params}));
    });
  }
  async function evaluate(expression) {
    const r = await call('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true});
    if (r.exceptionDetails) {
      throw Error('页面里执行出错：' + (r.exceptionDetails.exception?.description?.split('\n')[0] || r.exceptionDetails.text));
    }
    return r.result.value;
  }
  async function until(expression, tries = 150) {
    for (let i = 0; i < tries; i++) {
      try { if (await evaluate(expression)) return; } catch {}
      await sleep(200);
    }
    throw Error('等不到这个条件成立：' + expression.slice(0, 120));
  }
  const go = url => call('Page.navigate', {url});
  async function click(selector) {
    const point = await evaluate('(() => {const e=document.querySelector(' + JSON.stringify(selector) +
      ');if(!e)throw Error("找不到 "+' + JSON.stringify(selector) +
      ');e.scrollIntoView({block:"center"});const r=e.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2}})()');
    await call('Input.dispatchMouseEvent', {type: 'mousePressed', button: 'left', clickCount: 1, ...point});
    await call('Input.dispatchMouseEvent', {type: 'mouseReleased', button: 'left', clickCount: 1, ...point});
  }
  async function fill(selector, text) {
    await evaluate('document.querySelector(' + JSON.stringify(selector) + ').focus()');
    await call('Input.dispatchKeyEvent', {type: 'rawKeyDown', key: 'a', code: 'KeyA', modifiers: 2, windowsVirtualKeyCode: 65});
    await call('Input.dispatchKeyEvent', {type: 'keyUp', key: 'a', code: 'KeyA', modifiers: 2, windowsVirtualKeyCode: 65});
    await call('Input.insertText', {text});
  }
  async function viewport(w, h = 844) {
    await call('Emulation.setDeviceMetricsOverride', {width: w, height: h, deviceScaleFactor: 1, mobile: false});
    await sleep(400);
  }
  async function shoot(file, selector) {
    if (selector) await evaluate('document.querySelector(' + JSON.stringify(selector) + ')?.scrollIntoView({block:"start"})');
    await sleep(300);
    const shot = await call('Page.captureScreenshot');
    fs.writeFileSync(path.join(evidenceDir, file), Buffer.from(shot.data, 'base64'));
    return path.join(evidenceDir, file);
  }
  async function narrow(file, selector) {
    await viewport(390);
    assert(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), '390px 下有横向溢出');
    checks.push('390px 没有横向溢出');
    if (file) await shoot(file, selector);
  }
  const pass = what => { checks.push(what); };
  function close() {
    if (closed) return;
    closed = true;
    try { ws?.close(); } catch {}
    browser.kill();
  }
  function write() {
    fs.writeFileSync(path.join(evidenceDir, name + '.json'), JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report, null, 2));
  }
  async function finish() {
    try {
      assert.equal(errors.length, 0, '页面有未捕获的异常：' + errors.join(' | '));
      report.result = 'pass';
    } catch (e) {
      report.result = 'fail'; report.error = e.message; process.exitCode = 1;
    }
    write(); close();
  }
  function fail(e) {
    report.result = 'fail'; report.error = e?.message || String(e); process.exitCode = 1;
    write(); close();
  }

  let port;
  for (let i = 0; i < 100 && !port; i++) {
    try { port = Number(fs.readFileSync(path.join(profile, 'DevToolsActivePort'), 'utf8').split('\n')[0]); } catch {}
    if (!port) await sleep(100);
  }
  if (!port) { browser.kill(); throw Error('无头浏览器没起来；Chrome 路径不对就设 COGNOS_CHROME'); }
  const targets = await (await fetch('http://127.0.0.1:' + port + '/json')).json();
  ws = new WebSocket(targets.find(t => t.type === 'page').webSocketDebuggerUrl);
  await new Promise((r, j) => { ws.addEventListener('open', r, {once: true}); ws.addEventListener('error', j, {once: true}); });
  ws.addEventListener('message', e => {
    const d = JSON.parse(e.data);
    if (d.method === 'Runtime.exceptionThrown') errors.push(d.params.exceptionDetails.exception?.description?.split('\n')[0] || d.params.exceptionDetails.text);
    // 离开有未保存修改的页面时的确认框一律点确定
    if (d.method === 'Page.javascriptDialogOpening') call('Page.handleJavaScriptDialog', {accept: true}).catch(() => {});
    if (d.id) {
      const p = pending.get(d.id); if (!p) return; pending.delete(d.id);
      d.error ? p.reject(Error(d.error.message)) : p.resolve(d.result);
    }
  });
  await call('Page.enable'); await call('Runtime.enable');
  await viewport(width, height);

  const b = {origin, base: origin + '/index.php/apps/cognos', evidenceDir, report, call, evaluate, until, go, click, fill,
    viewport, shoot, narrow, pass, finish, fail, close};
  if (login) {
    const password = fs.readFileSync(path.join(root, '.secrets/admin-password'), 'utf8').trim();
    await go(origin + '/index.php/login');
    await until('!!document.querySelector("#password")');
    await evaluate('(() => {for (const [s,v] of ' + JSON.stringify([['#user', user], ['#password', password]]) +
      ') {const e=document.querySelector(s);e.value=v;e.dispatchEvent(new Event("input",{bubbles:true}));}' +
      'document.querySelector("#password").closest("form").requestSubmit();})()');
    await until('!location.pathname.includes("/login")');
    pass('已登录 ' + user);
  }
  return b;
}

module.exports = {open, sleep, evidenceDir};
