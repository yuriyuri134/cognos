/* Use only with the isolated fixture server, never with production data. */
const { chromium } = require(process.env.COGNOS_PLAYWRIGHT_MODULE || "playwright");
const assert = require("node:assert/strict");
(async () => {
  const url = new URL(process.argv[2]);
  const project = url.searchParams.get("project");
  const browser = await chromium.launch({headless: true, executablePath: "C:/Program Files/Google/Chrome/Application/chrome.exe"});
  const page = await browser.newPage();
  const errors = []; page.on("pageerror", error => errors.push(error.message));
  const post = async (path, body) => {
    const response = await page.request.post(new URL(path, url).href, {data: body});
    assert.equal(response.ok(), true, await response.text()); return response.json();
  };
  try {
    const source = (await post(`/api/projects/${project}/activities`, {title: "源构造"})).activity;
    await post(`/api/activities/${source.id}/changes`, {revision: 1, operations: [
      {action: "add_document", id: "number", title: "输入七", format: "json", body: 7},
      {action: "add_document", id: "method", title: "三倍构造", format: "json", body: {
        op: "calculate", operator: "*", left: {op: "read", document: "number"}, right: {op: "literal", value: 3}}}
    ]});
    const target = (await post(`/api/projects/${project}/activities`, {title: "新活动"})).activity;
    url.searchParams.set("activity", target.id);
    await page.goto(url.href);
    await page.locator("#import-activity-material").click();
    await page.locator("#transfer-dialog").waitFor({state: "visible"});
    await page.locator("#transfer-document").selectOption("method");
    await page.locator("#transfer-kind").selectOption("construction");
    await page.locator("#transfer-note").fill("只在此数值例子中试过，仍需检查适用范围。");
    await page.locator("#transfer-submit").click();
    await page.locator("#transfer-dialog").waitFor({state: "hidden"});
    await page.waitForFunction(() => document.querySelector("#document-title").textContent === "三倍构造");
    let detail = await (await page.request.get(new URL(`/api/activities/${target.id}`, url).href)).json();
    assert.equal(detail.activity.state.adopted, null);
    assert.equal(Object.keys(detail.activity.state.documents).length, 3);
    await page.locator("#adopt-document").click();
    await page.waitForFunction(() => document.querySelector("#preview").textContent.trim() === "21");
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({status: "passed", imported_documents: 3, adopted_value: 21, page_errors: errors}));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
