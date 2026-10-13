// Isolated real Chrome checks; no third-party browser package is required.
const { spawn } = require("node:child_process");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const assert = require("node:assert/strict");
const http = require("node:http");
const net = require("node:net");
const crypto = require("node:crypto");
const { EventEmitter } = require("node:events");

class LoopbackWebSocket extends EventEmitter {
  constructor(address) {
    super();
    const url = new URL(address);
    this.buffer = Buffer.alloc(0); this.ready = false; this.fragments = [];
    this.socket = net.connect(Number(url.port), url.hostname, () => {
      const key = crypto.randomBytes(16).toString("base64");
      this.socket.write(`GET ${url.pathname} HTTP/1.1\r\nHost: ${url.host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ${key}\r\nSec-WebSocket-Version: 13\r\n\r\n`);
    });
    this.socket.on("error", error => this.emit("error", error));
    this.socket.on("data", data => {
      this.buffer = Buffer.concat([this.buffer, data]);
      if (!this.ready) {
        const end = this.buffer.indexOf("\r\n\r\n"); if (end < 0) return;
        if (!this.buffer.subarray(0, end).toString().startsWith("HTTP/1.1 101")) { this.emit("error", new Error("Chrome WebSocket handshake failed")); return; }
        this.buffer = this.buffer.subarray(end + 4); this.ready = true; this.emit("open");
      }
      while (this.buffer.length >= 2) {
        const first = this.buffer[0], opcode = first & 15;
        let length = this.buffer[1] & 127, offset = 2;
        if (length === 126) { if (this.buffer.length < 4) return; length = this.buffer.readUInt16BE(2); offset = 4; }
        if (length === 127) { if (this.buffer.length < 10) return; length = Number(this.buffer.readBigUInt64BE(2)); offset = 10; }
        if (this.buffer.length < offset + length) return;
        const payload = this.buffer.subarray(offset, offset + length); this.buffer = this.buffer.subarray(offset + length);
        if (opcode === 8) { this.socket.end(); return; }
        if (opcode === 9) { this.frame(payload, 10); continue; }
        if (opcode === 1 || opcode === 0) {
          this.fragments.push(payload);
          if (first & 128) { const value = Buffer.concat(this.fragments).toString(); this.fragments = []; this.emit("message", { data: value }); }
        }
      }
    });
  }
  addEventListener(name, callback, options = {}) { options.once ? this.once(name, callback) : this.on(name, callback); }
  frame(payload, opcode = 1) {
    const length = payload.length, extra = length < 126 ? 0 : length < 65536 ? 2 : 8;
    const header = Buffer.alloc(2 + extra + 4); header[0] = 128 | opcode; header[1] = 128 | (extra === 0 ? length : extra === 2 ? 126 : 127);
    if (extra === 2) header.writeUInt16BE(length, 2); if (extra === 8) header.writeBigUInt64BE(BigInt(length), 2);
    const mask = crypto.randomBytes(4); mask.copy(header, 2 + extra);
    const data = Buffer.from(payload); for (let i = 0; i < length; i++) data[i] ^= mask[i % 4];
    this.socket.write(Buffer.concat([header, data]));
  }
  send(value) { this.frame(Buffer.from(value)); }
  close() { this.socket.destroy(); }
}
const root = path.resolve(__dirname, "..");
const port = 8891, debugPort = 9400 + Math.floor(Math.random() * 1000);
const base = `http://127.0.0.1:${port}`;
const profile = fs.mkdtempSync(path.join(os.tmpdir(), "cognos-chrome-"));
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
function loopbackFetch(url, options = {}) {
  return new Promise((resolve, reject) => {
    const req = http.request(url, {method: options.method || "GET", timeout: 2000}, response => {
      let body = ""; response.setEncoding("utf8"); response.on("data", data => { body += data; });
      response.on("end", () => resolve({ ok: response.statusCode < 400, json: async () => JSON.parse(body) }));
    });
    req.on("timeout", () => req.destroy(new Error("Loopback request timed out")));
    req.on("error", reject); req.end();
  });
}
const report = { fixture: "isolated scripted runtime; no model inference", checks: [], pageErrors: [] };
const backend = spawn(path.join(root, "phase0/.venv/Scripts/python.exe"), ["-u", path.join(__dirname, "productization_ui_fixture.py"), String(port)], { cwd: root, windowsHide: true });
let backendText = "", backendErrors = "", chrome;
backend.stdout.on("data", data => { backendText += data; });
backend.stderr.on("data", data => { backendErrors += data; });

async function until(fn, timeout = 15000) {
  const end = Date.now() + timeout;
  while (Date.now() < end) { try { const value = await fn(); if (value) return value; } catch {} await delay(100); }
  throw new Error("UI check timed out");
}

async function main() {
  const fixture = await until(() => { const line = backendText.split(/\r?\n/).find(x => x.startsWith("{")); return line && JSON.parse(line); });
  console.log("Fixture ready; checking loopback health");
  await until(async () => (await loopbackFetch(`${base}/api/health`)).ok);
  chrome = spawn("C:/Program Files/Google/Chrome/Application/chrome.exe", ["--headless=new", "--no-sandbox", "--disable-gpu", "--no-first-run", "--no-default-browser-check", `--user-data-dir=${profile}`, `--remote-debugging-port=${debugPort}`, "about:blank"], { windowsHide: true, stdio: "ignore" });
  const target = await until(async () => (await (await loopbackFetch(`http://127.0.0.1:${debugPort}/json/list`)).json()).find(item => item.type === "page"));
  console.log("Chrome debugging endpoint ready");
  const ws = new LoopbackWebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { const timer = setTimeout(() => reject(new Error("Chrome WebSocket connection timed out")), 10000); ws.addEventListener("open", () => { clearTimeout(timer); resolve(); }, { once: true }); ws.addEventListener("error", reject, { once: true }); });
  let counter = 0;
  const pending = new Map();
  ws.addEventListener("message", event => {
    const data = JSON.parse(event.data);
    if (data.id) { const callback = pending.get(data.id); pending.delete(data.id); if (data.error) callback.reject(new Error(data.error.message)); else callback.resolve(data.result); }
    if (data.method === "Runtime.exceptionThrown") report.pageErrors.push(data.params.exceptionDetails.exception?.description || data.params.exceptionDetails.text);
  });
  function call(method, params = {}) { return new Promise((resolve, reject) => { const id = ++counter; const timer = setTimeout(() => { pending.delete(id); reject(new Error(`Chrome ${method} timed out`)); }, 10000); pending.set(id, { resolve: x => { clearTimeout(timer); resolve(x); }, reject: x => { clearTimeout(timer); reject(x); } }); ws.send(JSON.stringify({ id, method, params })); }); }
  async function evaluate(expression) {
    const result = await call("Runtime.evaluate", { expression, awaitPromise: true, returnByValue: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
  }
  async function click(selector) { await evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`); }
  async function screenshot(name) { const data = await call("Page.captureScreenshot"); fs.writeFileSync(path.join(__dirname, name), Buffer.from(data.data, "base64")); }
  await call("Runtime.enable"); await call("Page.enable");
  await call("Page.navigate", { url: base });
  await until(() => evaluate('typeof currentAccount !== "undefined" && document.querySelector("#runtime-status").textContent.includes("就绪")'));
  assert.equal(await evaluate('getComputedStyle(document.querySelector("#auth-overlay")).display'), "none");
  report.checks.push("Anonymous page loads with hidden login overlay and no startup ReferenceError");
  await click("#auth-open-btn");
  await evaluate('document.querySelector("#auth-username").value="alice"; document.querySelector("#auth-password").value="fixture123"');
  await click("#auth-login-btn");
  await until(() => evaluate('currentAccount?.username === "alice" && document.querySelector("#auth-overlay").hidden'));
  assert.equal(await evaluate('document.cookie.includes("cognos_session")'), false);
  assert.equal(await evaluate('document.querySelector("#auth-password").value'), "");
  report.checks.push("Login succeeds; session cookie is inaccessible to JavaScript; password field cleared");
  await evaluate(`switchToProject(${JSON.stringify(fixture.org)}, ${JSON.stringify(fixture.project)})`);
  await call("Emulation.setDeviceMetricsOverride", { width: 720, height: 900, deviceScaleFactor: 1, mobile: false });
  await click('[data-tab="members"]');
  await until(() => evaluate('!!document.querySelector(".wb-member-card") && !document.querySelector("#project-page").hidden'));
  assert.equal(await evaluate('getComputedStyle(document.querySelector(".right-rail")).display'), "none");
  await screenshot("productization-members-mobile.png");
  await click(".wb-member-card");
  await until(() => evaluate('!document.querySelector("#member-page").hidden'));
  assert.equal(await evaluate('document.querySelector("#project-page").hidden'), true);
  assert.match(await evaluate('document.querySelector("#member-page-body").textContent'), /同学 A/);
  await click("#member-back");
  await until(() => evaluate('!document.querySelector("#project-page").hidden && !!document.querySelector(".wb-member-card")'));
  report.checks.push("Project Members tab and member homepage work with right rail hidden; Back restores tab");
  await until(() => evaluate('Array.from(document.querySelector(".wb-form select").options).some(x => x.textContent.includes("同学 B"))'));
  await evaluate('const s=document.querySelector(".wb-form select"); s.value=Array.from(s.options).find(x=>x.textContent.includes("同学 B")).value; s.closest("form").requestSubmit()');
  await until(() => evaluate('document.querySelectorAll(".wb-member-card").length === 2'));
  report.checks.push("Registered second account can be added to project from central Members tab");
  await click('[data-tab="discussion"]');
  await until(() => evaluate('!document.querySelector("#composer").hidden'));
  await evaluate('document.querySelector("#message-input").value="真实浏览器发言"; document.querySelector("#composer").requestSubmit()');
  await until(() => evaluate('!sending && Array.from(document.querySelectorAll(".message .author")).some(x=>x.textContent.includes("同学 A"))'));
  report.checks.push("Human display name containing spaces renders and posts without DOMTokenList error");
  await call("Page.navigate", { url: `${base}/activities?project=${fixture.project}` });
  await until(() => evaluate('document.querySelector("#account-label")?.textContent.includes("同学 A")'));
  await click("#account-logout");
  await until(() => evaluate('document.querySelector("#account-label").textContent === "未登录"'));
  await click("#new-activity");
  await until(() => evaluate('document.querySelector("#account-dialog").open'));
  await evaluate('document.querySelector("#account-username").value="bob"; document.querySelector("#account-password").value="fixture123"');
  await click("#account-login");
  await until(() => evaluate('document.querySelector("#account-label").textContent.includes("同学 B") && !document.querySelector("#account-dialog").open'));
  await click("#new-activity");
  await until(() => evaluate('!document.querySelector("#activity-workspace").hidden'));
  report.checks.push("Activity shares login cookie; anonymous write opens login; second account logs in and creates activity");
  await screenshot("productization-activity-login.png");
  assert.deepEqual(report.pageErrors, []);
  await call("Browser.close");
  ws.close();
}

(async () => {
  try { await main(); report.status = "passed"; }
  catch (error) { report.status = "failed"; report.error = error.message; process.exitCode = 1; }
  finally {
    try { await loopbackFetch(`${base}/__test__/shutdown`, { method: "POST" }); } catch {}
    await delay(1000);
    if (backend.exitCode === null) backend.kill();
    if (chrome && chrome.exitCode === null) chrome.kill();
    fs.writeFileSync(path.join(__dirname, "productization-ui-report.json"), JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report, null, 2));
    if (report.status === "failed" && backendErrors) console.error(backendErrors.slice(-2000));
  }
})();
