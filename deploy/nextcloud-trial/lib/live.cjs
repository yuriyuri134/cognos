/* Shared helpers for live checks against the local trial (8780): authenticated API calls and a headless browser.
 * Credentials are read from deploy/nextcloud-trial/.secrets here and never printed or returned.
 * Usage (see .agents/skills/cognos-live-check/SKILL.md):
 *   const live = require('./lib/live.cjs');
 *   const {req, api} = live.client();            // cognos-admin
 *   const b = await live.browser(); await b.login(); await b.go(live.APP + '/projects'); ... await b.close();
 */
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), assert = require('node:assert/strict');
const {spawn} = require('node:child_process');

const ROOT = path.resolve(__dirname, '..');
const ORIGIN = 'http://127.0.0.1:8780';
const APP = ORIGIN + '/index.php/apps/cognos';
const OCS = ORIGIN + '/ocs/v2.php/apps/cognos/api/v1.0';
const EVIDENCE = path.join(ROOT, 'evidence');
// .secrets is gitignored and only exists in the main checkout; COGNOS_TRIAL_SECRETS points elsewhere if needed
const SECRETS = process.env.COGNOS_TRIAL_SECRETS || path.join(ROOT, '.secrets');
const secret = name => fs.readFileSync(path.join(SECRETS, name), 'utf8').trim();
const admin = () => ({user: 'cognos-admin', password: secret('admin-password')});
const outsider = () => JSON.parse(secret('task-journey.json')).outsider;
const sleep = ms => new Promise(r => setTimeout(r, ms));
const id = () => crypto.randomUUID();

/** API calls as one account. req() hits the app routes, api() the OCS agent API; both assert the status. */
function client(actor = admin()) {
  async function call(url, method, data, expected) {
    const r = await fetch(url, {method, redirect: 'manual', headers: {
      Authorization: 'Basic ' + Buffer.from(actor.user + ':' + actor.password).toString('base64'),
      'OCS-APIRequest': 'true', Accept: 'application/json', ...(data ? {'Content-Type': 'application/json'} : {}),
    }, body: data ? JSON.stringify(data) : undefined, signal: AbortSignal.timeout(60000)});
    let body; try { body = await r.json(); } catch { throw Error(method + ' ' + url + ': non-JSON HTTP ' + r.status); }
    const payload = body.ocs ? body.ocs.data : body;
    assert.equal(r.status, expected, method + ' ' + url + ': ' + (payload.message || 'HTTP ' + r.status));
    return payload;
  }
  return {
    req: (route, method = 'GET', data, expected = 200) => call(APP + route, method, data, expected),
    api: (route, method = 'GET', data, expected = 200) => call(OCS + route + (route.includes('?') ? '&' : '?') + 'format=json', method, data, expected),
  };
}

/** A fresh headless Chrome over the DevTools protocol. Dialogs are accepted; uncaught page errors collect in b.errors. */
async function browser() {
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cognos-live-'));
  const proc = spawn(process.env.COGNOS_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    ['--headless=new', '--disable-gpu', '--no-first-run', '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank'],
    {windowsHide: true, stdio: 'ignore'});
  let port;
  for (let i = 0; i < 100 && !port; i++) {
    try { port = Number(fs.readFileSync(path.join(profile, 'DevToolsActivePort'), 'utf8').split('\n')[0]); } catch {}
    if (!port) await sleep(100);
  }
  if (!port) { proc.kill(); throw Error('headless Chrome did not start'); }
  const targets = await (await fetch('http://127.0.0.1:' + port + '/json')).json();
  const ws = new WebSocket(targets.find(t => t.type === 'page').webSocketDebuggerUrl);
  await new Promise((r, j) => { ws.addEventListener('open', r, {once: true}); ws.addEventListener('error', j, {once: true}); });
  let counter = 0; const pending = new Map(); const errors = [];
  const send = (method, params = {}) => new Promise((resolve, reject) => {
    const n = ++counter;
    const timer = setTimeout(() => { pending.delete(n); reject(Error('browser timeout: ' + method)); }, 20000);
    pending.set(n, {resolve: x => { clearTimeout(timer); resolve(x); }, reject: e => { clearTimeout(timer); reject(e); }});
    ws.send(JSON.stringify({id: n, method, params}));
  });
  ws.addEventListener('message', e => {
    const d = JSON.parse(e.data);
    if (d.method === 'Runtime.exceptionThrown') errors.push(d.params.exceptionDetails.text);
    if (d.method === 'Page.javascriptDialogOpening') send('Page.handleJavaScriptDialog', {accept: true}).catch(() => {});
    if (d.id) { const p = pending.get(d.id); if (!p) return; pending.delete(d.id); d.error ? p.reject(Error(d.error.message)) : p.resolve(d.result); }
  });
  await send('Page.enable'); await send('Runtime.enable');
  const b = {
    errors, send,
    async evaluate(expression) {
      const r = await send('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true});
      if (r.exceptionDetails) throw Error('evaluation failed: ' + (r.exceptionDetails.exception?.description?.split('\n')[0] || r.exceptionDetails.text));
      return r.result.value;
    },
    /** Wait until a page expression is truthy (about 30 s by default). */
    async until(expression, tries = 150) {
      for (let i = 0; i < tries; i++) { try { if (await b.evaluate(expression)) return; } catch {} await sleep(200); }
      throw Error('condition did not become true: ' + expression.slice(0, 120));
    },
    go: url => send('Page.navigate', {url}),
    async click(selector) {
      const point = await b.evaluate('(() => {const e=document.querySelector(' + JSON.stringify(selector) + ');e.scrollIntoView({block:"center"});const r=e.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2}})()');
      await send('Input.dispatchMouseEvent', {type: 'mousePressed', button: 'left', clickCount: 1, ...point});
      await send('Input.dispatchMouseEvent', {type: 'mouseReleased', button: 'left', clickCount: 1, ...point});
    },
    /** Replace the text of an input/textarea the way a person types. */
    async fill(selector, text) {
      await b.evaluate('document.querySelector(' + JSON.stringify(selector) + ').focus()');
      await send('Input.dispatchKeyEvent', {type: 'rawKeyDown', key: 'a', code: 'KeyA', modifiers: 2, windowsVirtualKeyCode: 65});
      await send('Input.dispatchKeyEvent', {type: 'keyUp', key: 'a', code: 'KeyA', modifiers: 2, windowsVirtualKeyCode: 65});
      await send('Input.insertText', {text});
    },
    viewport: (width, height) => send('Emulation.setDeviceMetricsOverride', {width, height, deviceScaleFactor: 1, mobile: false}),
    desktop: () => b.viewport(1360, 1000),
    phone: () => b.viewport(390, 844),
    noOverflow: () => b.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'),
    /** Screenshot into evidence/<name>, scrolled to selector if given. Look at it before reporting it. */
    async shoot(name, selector) {
      if (selector) { await b.evaluate('document.querySelector(' + JSON.stringify(selector) + ').scrollIntoView({block:"start"})'); await sleep(300); }
      const shot = await send('Page.captureScreenshot');
      fs.mkdirSync(EVIDENCE, {recursive: true});
      fs.writeFileSync(path.join(EVIDENCE, name), Buffer.from(shot.data, 'base64'));
    },
    /** Log in as cognos-admin. The password goes straight into the page, never into output. */
    async login(actor = admin()) {
      await b.desktop();
      await b.go(ORIGIN + '/index.php/login');
      await b.until('!!document.querySelector("#password")');
      await b.evaluate('(() => {for (const [s,v] of ' + JSON.stringify([['#user', actor.user], ['#password', actor.password]]) +
        ') {const e=document.querySelector(s);e.value=v;e.dispatchEvent(new Event("input",{bubbles:true}));}document.querySelector("#password").closest("form").requestSubmit();})()');
      await b.until('!location.pathname.includes("/login") && !!document.querySelector("#app-content")');
    },
    close() { try { ws.close(); } catch {} proc.kill(); },
  };
  return b;
}

/** Run a check: collects named checks, writes evidence/<name>.json with pass/fail, prints it, sets the exit code. */
async function run(name, body) {
  const report = {at: new Date().toISOString(), checks: []};
  try { await body(report.checks, report); report.result = 'pass'; }
  catch (e) { report.result = 'fail'; report.error = e.message; process.exitCode = 1; }
  fs.mkdirSync(EVIDENCE, {recursive: true});
  fs.writeFileSync(path.join(EVIDENCE, name + '.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
  return report;
}

module.exports = {ROOT, ORIGIN, APP, OCS, EVIDENCE, admin, outsider, client, browser, run, sleep, id};
