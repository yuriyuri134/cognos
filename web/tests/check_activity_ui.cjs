/* 浏览器贯通检查使用独立 fixture 服务，不向真实模型发送消息。 */
const { chromium } = require(process.env.COGNOS_PLAYWRIGHT_MODULE || "playwright");
const assert = require("node:assert/strict");
const fs = require("node:fs/promises");
const path = require("node:path");

(async () => {
  const target = process.argv[2];
  if (!target) throw new Error("需要 fixture 活动页面地址");
  const output = path.resolve(process.env.COGNOS_UI_OUTPUT || "web/.state/ui-check-results");
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, executablePath: process.env.COGNOS_BROWSER_PATH || "C:/Program Files/Google/Chrome/Application/chrome.exe" });
  const page = await browser.newPage({ viewport: { width: 1512, height: 1100 } });
  const errors = [];
  page.on("pageerror", error => errors.push(error.message));
  async function settled() { await page.waitForFunction(() => document.getElementById("activity-workspace").hidden === false && document.getElementById("preview").textContent.length > 0); }
  async function detail() { const id = new URL(page.url()).searchParams.get("activity"); return (await page.request.get(new URL(`/api/activities/${id}`, target).href)).json(); }
  async function waitRevision(old) { await page.waitForFunction(revision => document.querySelectorAll("#revision-history li").length > revision, old); }
  async function add(title, body, kind = "source") {
    const old = (await detail()).activity.revision;
    await page.locator("#add-document").click(); await page.locator("#new-title").fill(title);
    await page.locator("#new-kind").selectOption(kind); await page.locator("#new-body").fill(body);
    await page.locator("#add-form button[type=submit]").click();
    await page.locator("#add-dialog").waitFor({ state: "hidden" }); await waitRevision(old);
  }
  try {
    await page.goto(target); await page.locator("#new-activity").click(); await settled();
    assert.equal(await page.locator("#focus").inputValue(), "");
    await page.locator("#rename-activity").click();
    await page.locator("#activity-title").fill("没有定题的创作");
    await page.locator("#rename-form button[type=submit]").click();
    await page.locator("#rename-dialog").waitFor({ state: "hidden" });
    assert.equal((await detail()).activity.state.title, "没有定题的创作");
    await page.locator("#import-occurrence").click();
    await page.locator("#import-dialog").waitFor();
    assert.match(await page.locator("#import-preview").textContent(), /来源检查替身/);
    await page.locator("#confirm-import").click();
    await page.locator("#import-dialog").waitFor({ state: "hidden" });
    const imported = Object.values((await detail()).activity.state.documents).find(doc => doc.title.startsWith("来源检查替身"));
    assert.equal(imported.immutable, true);
    assert.equal(imported.versions[0].source_author, "来源检查替身");
    assert.ok(imported.versions[0].occurrence_id);
    await page.locator("#source-builder > summary").click();
    await page.locator("#source-contains").fill("执行结果");
    await page.locator("#source-author").fill("来源检查替身");
    await page.locator("#save-source").click();
    await page.waitForFunction(() => document.getElementById("source-preview").textContent.includes("原作者：来源检查替身"));
    const beforeAcquire = (await detail()).activity.revision;
    await page.locator("#acquire-material").click();
    await waitRevision(beforeAcquire);
    await page.waitForFunction(() => document.getElementById("acquire-material").disabled === false);
    assert.match(await page.locator("#structured-summary").textContent(), /取得 1 条记录/);
    const sourceCount = Object.keys((await detail()).activity.state.documents).length;
    await page.route("**/acquire", async route => { await route.fetch(); await route.abort("failed"); }, { times: 1 });
    await page.locator("#acquire-material").click();
    await page.getByRole("button", { name: "重试本次取材", exact: true }).waitFor();
    await page.locator("#acquire-material").click();
    await page.getByRole("button", { name: "按当前方式取得材料", exact: true }).waitFor();
    assert.equal(Object.keys((await detail()).activity.state.documents).length, sourceCount + 1);
    await page.locator("#release-source").click();
    await page.waitForFunction(() => document.getElementById("acquire-material").disabled);
    await add("创作片段", "窗还亮着。\n窗还亮着。");
    await add("未选材料", "这个描述还没有被整理成一个问题。");
    await page.getByRole("button", { name: "创作片段 原文保留 · 第 1 版", exact: true }).click();
    assert.equal(await page.locator("#document-editor").evaluate(node => node.readOnly), true);
    await page.locator("#copy-document").click(); await page.locator("#new-title").fill("工作草稿");
    await page.locator("#add-form button[type=submit]").click(); await page.locator("#add-dialog").waitFor({ state: "hidden" });
    await page.getByRole("button", { name: "工作草稿 可改写 · 第 1 版", exact: true }).click();
    await page.locator("#document-editor").fill("还没有定好的开头。");
    await page.locator("#focus").fill("看看留白怎样改变节奏");
    let old = (await detail()).activity.revision;
    await page.locator("#save-focus").click(); await waitRevision(old);
    old = (await detail()).activity.revision;
    await page.locator("#save-document").click(); await waitRevision(old);
    assert.equal(await page.locator("#document-editor").inputValue(), "还没有定好的开头。");
    await page.locator("#arrangement-builder > summary").click();
    await page.locator("#arrangement-list label").filter({ hasText: "创作片段" }).locator("input").check();
    await page.locator("#adopt-arrangement").click();
    await page.waitForFunction(() => document.getElementById("preview").textContent === "窗还亮着。\n窗还亮着。");
    let data = await detail();
    const sourceId = Object.keys(data.activity.state.documents).find(id => data.activity.state.documents[id].title === "创作片段");
    await page.getByRole("button", { name: "材料编排 可改写 · 第 1 版", exact: true }).click();
    await page.locator("#definition-details > summary").click();
    await page.locator("#json-editor").fill(JSON.stringify({ op: "replace", input: { op: "read", document: sourceId }, old: "窗", new: "灯" }));
    old = data.activity.revision;
    await page.locator("#save-document").click(); await waitRevision(old);
    assert.equal(await page.locator("#preview").textContent(), "窗还亮着。\n窗还亮着。");
    await page.locator("#adopt-document").click();
    await page.waitForFunction(() => document.getElementById("preview").textContent === "灯还亮着。\n灯还亮着。");
    old = (await detail()).activity.revision;
    await page.locator("#save-preview").click(); await waitRevision(old);
    await page.reload(); await settled();
    await page.waitForFunction(() => document.getElementById("preview").textContent === "灯还亮着。\n灯还亮着。");
    assert.equal(await page.locator("#focus").inputValue(), "看看留白怎样改变节奏");
    await page.screenshot({ path: path.join(output, "activity-desktop.png"), fullPage: true });

    await page.locator("#message").fill("验证修改并接续"); await page.locator("#continue-button").click();
    await page.waitForFunction(() => document.getElementById("run-status").textContent.includes("本次已暂停") && document.getElementById("continue-button").disabled === false);
    assert.equal(await page.locator("#preview").textContent(), "窗还亮着。");
    data = await detail();
    assert.equal(data.runs.at(-1).events.length, 2);
    assert.equal(data.activity.state.documents[sourceId].versions[0].body, "窗还亮着。\n窗还亮着。");

    const runsBefore = data.runs.length;
    await page.route("**/continue", async route => { await route.fetch(); await route.abort("failed"); }, { times: 1 });
    await page.locator("#message").fill("验证同一次请求的重试"); await page.locator("#continue-button").click();
    await page.getByRole("button", { name: "重试同一次请求", exact: true }).waitFor();
    assert.equal(await page.locator("#message").evaluate(node => node.readOnly), true);
    await page.locator("#continue-button").click();
    await page.getByRole("button", { name: "共同继续", exact: true }).waitFor();
    assert.equal((await detail()).runs.length, runsBefore + 1);

    // 连续链：理解修订 → 活动提议 → 等待真实回答 → 回答成为结果
    await page.locator("#toggle-situation-edit").click();
    await page.locator("#situation-editor").fill("还没有明确的理解。");
    old = (await detail()).activity.revision;
    await page.locator("#save-situation").click(); await waitRevision(old);
    assert.equal((await detail()).activity.state.situation.text, "还没有明确的理解。");
    assert.match(await page.locator("#situation-text").textContent(), /还没有明确的理解/);
    await page.locator("#message").fill("验证理解与活动提议"); await page.locator("#continue-button").click();
    await page.waitForFunction(() => document.getElementById("waiting-box").hidden === false && document.getElementById("continue-button").disabled === false);
    assert.match(await page.locator("#waiting-text").textContent(), /哪个方向继续/);
    data = await detail();
    const askingMove = data.moves.at(-1);
    assert.equal(askingMove.state.status, "waiting_user");
    assert.equal(askingMove.basis_revision, data.activity.revision);
    assert.equal(data.activity.state.situation.text, "还没有明确的理解。");
    assert.match(await page.locator("#formation-active").textContent(), /留白和重复是同一组动作/);
    await page.locator("#answer-input").fill("往声音的方向"); await page.locator("#submit-answer").click();
    await page.waitForFunction(() => document.getElementById("waiting-box").hidden === true && document.getElementById("continue-button").disabled === false);
    data = await detail();
    const answered = data.moves.at(-1);
    assert.equal(answered.state.status, "done");
    const saved = answered.state.results.at(-1);
    assert.equal(saved.kind, "user_answer");
    assert.equal(saved.content, "往声音的方向");
    assert.equal(saved.author, "小林");
    assert.match(await page.locator("#moves").textContent(), /往声音的方向/);

    await page.locator("#message").fill("验证并存读法"); await page.locator("#continue-button").click();
    await page.waitForFunction(() => document.querySelectorAll("#formation-active .formation-card").length === 3);
    await page.locator("#message").fill("验证暂缓读法"); await page.locator("#continue-button").click();
    await page.waitForFunction(() => document.querySelectorAll("#formation-active .formation-card").length === 2 &&
      document.querySelectorAll("#formation-dormant .formation-card").length === 1);
    assert.match(await page.locator("#formation-active").textContent(), /停顿也许改变了节奏/);
    await page.locator("#formation-dormant-summary").click();
    assert.match(await page.locator("#formation-dormant").textContent(), /可能是重复/);
    const reading = page.locator("#formation-active .formation-card").filter({hasText: "停顿也许改变了节奏"});
    await reading.locator("summary").click();
    assert.match(await reading.textContent(), /可能是停顿/);
    await page.reload(); await settled();
    assert.equal(await page.locator("#formation-active .formation-card").count(), 2);
    assert.equal(await page.locator("#formation-dormant .formation-card").count(), 1);
    assert.equal((await detail()).activity.state.situation.text, "还没有明确的理解。");
    assert.equal(await page.locator("#return-point").isHidden(), false);
    assert.match(await page.locator("#return-summary").textContent(), /正在形成/);
    assert.match(await page.locator("#return-summary").textContent(), /停顿也许改变了节奏/);
    const returningActivityId = new URL(page.url()).searchParams.get("activity");
    await page.goto(target); await settled();
    assert.equal(new URL(page.url()).searchParams.get("activity"), returningActivityId);
    assert.equal(await page.locator("#return-point").isHidden(), false);
    assert.match(await page.locator("#return-summary").textContent(), /停顿也许改变了节奏/);

    await page.locator("#release").click();
    await page.waitForFunction(() => document.getElementById("release").disabled && document.getElementById("preview").textContent.includes("这个描述还没有"));
    await page.locator("#continue-button").click();
    await page.waitForFunction(() => document.getElementById("continue-button").disabled === false);
    assert.equal(await page.locator("#release").isDisabled(), true);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: path.join(output, "activity-mobile.png"), fullPage: true });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth), false);
    assert.deepEqual(errors, []);
    const report = { status: "passed", model: "scripted fixture, no real model inference", checks: ["material without a question", "activity rename", "existing result import with original author", "adopted query acquires actual project records", "acquisition retry without duplicate material", "release acquisition", "immutable original", "editing and independent focus change", "adopt versus revise", "actual transformed preview", "restart continuity", "project-only revisit opens latest activity and return point", "model commands and returned result", "network retry without replay", "release preserves materials", "mobile layout", "no page errors"] };
    await fs.writeFile(path.join(output, "report.json"), JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report));
  } finally { await browser.close(); await fetch(new URL("/__test__/shutdown", target), { method: "POST" }).catch(() => {}); }
})().catch(error => { console.error(error); process.exitCode = 1; });
