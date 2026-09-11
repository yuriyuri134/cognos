"use strict";

const el = id => document.getElementById(id);
const projectId = new URLSearchParams(location.search).get("project");
let activity = null, selectedDocument = null, editorRevision = null;
let editorVersion = null, focusRevision = null, focusOriginal = "";
let editorDirty = false, focusDirty = false, busy = false, pendingRequest = null;
let arrangement = [], preview = null;
let importable = [];
let pendingAcquisition = null, sourcePreview = null;
let activityMoves = [], pendingAnswer = null;

function notify(message = "") { el("notice").textContent = message; el("notice").hidden = !message; }
async function request(path, options = {}) {
  const response = await fetch(path, { ...options, headers: { "Content-Type": "application/json", ...options.headers } });
  const data = await response.json();
  if (!response.ok) throw new Error(typeof data.detail === "string" ? data.detail : `请求未完成（${response.status}）`);
  return data;
}
function action(fn) { return async event => { if (event) event.preventDefault(); try { notify(); await fn(); } catch (error) { notify(error.message); } }; }
function asText(value) { return typeof value === "string" ? value : JSON.stringify(value, null, 2); }
function documentLabel(id) { return activity.state.documents[id]?.title || id; }
function describe(node, depth = 0) {
  if (!node || typeof node !== "object" || depth > 6) return "结构化内容";
  if (node.provider === "project_history" && Array.isArray(node.records)) {
    return `取得 ${node.returned_count} 条记录，匹配 ${node.matched_count} 条。\n\n` + node.records.map(item => `${item.author}\n${item.content}${item.content_truncated ? "\n（节选，完整发生仍保留）" : ""}`).join("\n\n——\n\n");
  }
  const next = item => describe(item, depth + 1);
  switch (node.op) {
    case "read": return `读取「${documentLabel(node.document)}」${node.version ? `第 ${node.version} 版` : "的当前版本"}`;
    case "call": return `使用「${documentLabel(node.document)}」的组合`;
    case "join": return (node.inputs || []).map(next).join("\n然后并置：");
    case "replace": return `${next(node.input)}\n将「${node.old}」替换为「${node.new}」`;
    case "slice": return `${next(node.input)}\n取其中 ${node.start || 0} 至 ${node.end ?? "末尾"} 的范围`;
    case "select": return `${next(node.input)}\n选择 ${(node.path || []).join(" → ")}`;
    case "calculate": return `计算：${next(node.left)} ${node.operator} ${next(node.right)}`;
    case "literal": return asText(node.value);
    case "list": return (node.inputs || []).map(next).join("\n");
    case "object": return Object.entries(node.fields || {}).map(([key, val]) => `${key}：${next(val)}`).join("\n");
    default: return "结构化材料，可展开查看内容。";
  }
}

async function listActivities(selectedId) {
  const payload = await request(`/api/projects/${encodeURIComponent(projectId)}/activities`);
  el("activities").replaceChildren();
  for (const item of payload.activities) {
    const option = document.createElement("option"); option.value = item.id; option.textContent = item.title;
    el("activities").append(option);
  }
  if (selectedId && payload.activities.some(item => item.id === selectedId)) el("activities").value = selectedId;
  const id = el("activities").value;
  el("no-activity").hidden = Boolean(id);
  el("activity-workspace").hidden = !id;
  el("rename-activity").disabled = !id;
  if (id) await loadActivity(id);
}

async function loadActivity(id) {
  const changedActivity = activity?.id !== id;
  const payload = await request(`/api/activities/${id}`);
  activity = payload.activity;
  const query = new URL(location.href); query.searchParams.set("activity", id); history.replaceState(null, "", query);
  if (changedActivity) { selectedDocument = null; editorDirty = false; focusDirty = false; pendingRequest = null; pendingAcquisition = null; pendingAnswer = null; el("situation-edit").hidden = true; }
  if (!focusDirty) { el("focus").value = activity.state.focus; focusOriginal = activity.state.focus; focusRevision = activity.revision; }
  else if (activity.state.focus === focusOriginal) focusRevision = activity.revision;
  renderDocuments(); renderArrangement(); renderMessages(payload.messages);
  activityMoves = payload.moves; renderSituation(); renderMoves(); renderWaiting();
  if (!editorDirty) renderEditor();
  else if (activity.state.documents[selectedDocument]?.versions.length === editorVersion) editorRevision = activity.revision;
  const adopted = activity.state.adopted;
  el("adopted-description").textContent = adopted ? `正在采用「${documentLabel(adopted.document)}」第 ${adopted.version} 版。修改后需再次采用才会改变运行。` : "尚未采用特定组织，当前展开全部材料。";
  el("release").disabled = !adopted || busy;
  const last = payload.runs.at(-1);
  const statuses = { paused: "本次已暂停，可以继续展开。", budget_reached: "本次已到调用上限；已执行结果保留，可以继续。", failed: "本次未完成，已保存的内容保留。", protocol_error: "参与者回复未符合操作格式，原文已保存。", conflict: "期间出现了新修改，请依据当前内容继续。", interrupted: "上次执行已经中断，已保存内容保留；不会自动重复执行。", running: "该活动仍在服务中继续。", waiting_user: "参与者在等你的真实回答，回答后才继续。" };
  el("run-status").textContent = last ? (last.status === "acquired" ? "本次取材结果已保留，可以继续面对。" : (statuses[last.status] || last.status)) : "";
  el("revision-history").replaceChildren();
  for (const revision of payload.history.toReversed()) {
    const item = document.createElement("li"), button = document.createElement("button");
    button.textContent = `修订 ${revision.revision} · ${revision.actor}`;
    button.addEventListener("click", action(async () => {
      const result = await request(`/api/activities/${id}/preview?revision=${revision.revision}`);
      el("history-title").textContent = `修订 ${revision.revision} 当时的展开`;
      el("history-preview").textContent = result.status === "ok" ? result.text : result.error;
      el("history-dialog").showModal();
    })); item.append(button); el("revision-history").append(item);
  }
  await refreshPreview();
  await refreshSource();
}

function renderDocuments() {
  el("documents").replaceChildren();
  for (const [id, doc] of Object.entries(activity.state.documents)) {
    const button = document.createElement("button"); button.className = `document-card${id === selectedDocument ? " selected" : ""}`;
    const title = document.createElement("span"), note = document.createElement("small");
    title.textContent = doc.title; note.textContent = `${doc.immutable ? "原文保留" : "可改写"} · 第 ${doc.versions.length} 版`;
    button.append(title, note);
    button.addEventListener("click", () => {
      if (editorDirty) { notify("当前内容尚未保存。请先保存，或重新载入活动放弃这次编辑。"); return; }
      selectedDocument = id; renderDocuments(); renderEditor();
    }); el("documents").append(button);
  }
}

function renderEditor() {
  const doc = activity.state.documents[selectedDocument];
  el("editor-section").hidden = !doc;
  if (!doc) return;
  editorRevision = activity.revision;
  editorVersion = doc.versions.length;
  el("document-title").textContent = doc.title;
  el("document-status").textContent = `第 ${doc.versions.length} 版`;
  el("document-description").textContent = doc.immutable ? "这是保留的原文。可另起内容来解释或改写它。" : "保存会形成新版本，旧版本保留。";
  const body = doc.versions.at(-1).body;
  el("document-editor").hidden = doc.format !== "text";
  el("definition-details").hidden = doc.format !== "json";
  el("structured-summary").hidden = doc.format !== "json";
  el("document-editor").value = asText(body); el("document-editor").readOnly = doc.immutable;
  el("json-editor").value = asText(body); el("json-editor").readOnly = doc.immutable;
  el("structured-summary").textContent = describe(body);
  el("save-document").hidden = doc.immutable;
  el("adopt-document").hidden = doc.format !== "json" || !body?.op;
  el("adopt-source-document").hidden = doc.format !== "json" || !body?.op;
}

function renderArrangement() {
  arrangement = Object.keys(activity.state.documents).map(id => ({ id, selected: false }));
  const adopted = activity.state.adopted;
  if (adopted) {
    const body = activity.state.documents[adopted.document].versions[adopted.version - 1].body;
    if (body?.op === "join" && body.inputs?.every(item => item.op === "read")) {
      const chosen = body.inputs.map(item => ({ id: item.document, selected: true }));
      arrangement = [...chosen, ...arrangement.filter(item => !chosen.some(other => other.id === item.id))];
    }
  }
  drawArrangement();
}
function drawArrangement() {
  el("arrangement-list").replaceChildren();
  arrangement.forEach((item, index) => {
    const row = document.createElement("div"), label = document.createElement("label"), check = document.createElement("input");
    row.className = "arrangement-row"; check.type = "checkbox"; check.checked = item.selected;
    check.addEventListener("change", () => { item.selected = check.checked; });
    label.append(check, document.createTextNode(` ${documentLabel(item.id)}`)); row.append(label);
    for (const [caption, delta] of [["上移", -1], ["下移", 1]]) {
      const button = document.createElement("button"); button.textContent = caption;
      button.disabled = index + delta < 0 || index + delta >= arrangement.length || busy;
      button.addEventListener("click", () => { [arrangement[index], arrangement[index + delta]] = [arrangement[index + delta], arrangement[index]]; drawArrangement(); }); row.append(button);
    }
    el("arrangement-list").append(row);
  });
}

function renderMessages(messages) {
  el("messages").replaceChildren();
  for (const item of messages.filter(item => !item.activity_operation && !item.activity_change_request)) {
    const article = document.createElement("article"), author = document.createElement("strong"), body = document.createElement("p");
    article.className = "message"; author.textContent = item.author; body.textContent = item.content;
    if (item.raw_activity_reply) {
      try { const reply = JSON.parse(item.raw_activity_reply); body.textContent = reply.answer || "提出了活动操作，执行情况已保留。"; } catch { /* 不可解析的原文仍展示。 */ }
    }
    article.append(author, body); el("messages").append(article);
  }
}

function renderSituation() {
  const situation = activity.state.situation || { text: "", refs: [] };
  el("situation-revision").textContent = `第 ${activity.revision} 版`;
  el("situation-text").textContent = situation.text || "（尚未写下理解；可以从材料、问题或没有问题的片段开始。）";
  el("situation-refs").replaceChildren();
  for (const ref of situation.refs) {
    const chip = document.createElement("span"); chip.className = "ref-chip";
    chip.textContent = `${documentLabel(ref.document)} @${ref.version}${ref.note ? ` · ${ref.note}` : ""}`;
    if (ref.excerpt) chip.title = ref.excerpt;
    el("situation-refs").append(chip);
  }
}

function summarizeResult(result) {
  if (result.kind === "user_answer") return `回答：${result.content}`;
  if (result.acquisition) return `取材：取得 ${result.acquisition.returned_count} 条记录 → 「${documentLabel(result.document)}」`;
  if (typeof result.document === "string") return `生成内容 → 「${documentLabel(result.document)}」`;
  if (result.document?.body !== undefined) return `读取「${result.document.title}」第 ${result.document.version} 版`;
  if (result.evaluation) return `计算：${String(result.evaluation.text ?? "").slice(0, 90)}`;
  if (result.revision) return `修改完成：现在是修订 ${result.revision}`;
  if (result.error) return `失败：${result.error}`;
  if (result.question) return `等待回答：${result.question}`;
  return "已执行";
}

function renderMoves() {
  el("moves").replaceChildren();
  if (!activityMoves.length) {
    el("moves").textContent = "还没有活动提议。邀请参与者后，它会写下准备做什么；服务实际执行并回真实结果。";
    return;
  }
  for (const move of activityMoves) {
    const card = document.createElement("article"); card.className = "move-card";
    const head = document.createElement("div"); head.className = "move-head";
    const who = document.createElement("strong"); who.textContent = move.proposer;
    const badge = document.createElement("span"); badge.className = `badge badge-${move.state.status}`;
    badge.textContent = { proposed: "已提议", executing: "执行中", done: "已完成", failed: "有失败", waiting_user: "等待回答" }[move.state.status] || move.state.status;
    head.append(who, badge);
    const intent = document.createElement("p"); intent.className = "move-intent";
    intent.textContent = move.intent || "（未写明意图）";
    card.append(head, intent);
    const results = document.createElement("details"); results.className = "move-results";
    const summary = document.createElement("summary");
    summary.textContent = `实际结果（${(move.state.results || []).length} 项）`;
    results.append(summary);
    for (const result of move.state.results || []) {
      const row = document.createElement("div"); row.className = "result-row";
      const status = document.createElement("span"); status.className = "badge";
      status.textContent = result.status === "ok" ? "ok" : (result.status === "waiting_user" ? "等回答" : result.status);
      const text = document.createElement("span"); text.className = "result-text"; text.textContent = summarizeResult(result);
      row.append(status, document.createTextNode(" "), text);
      results.append(row);
    }
    card.append(results);
    el("moves").append(card);
  }
}

function renderWaiting() {
  const waiting = activityMoves.findLast(move => move.state.status === "waiting_user");
  el("waiting-box").hidden = !waiting;
  if (!waiting) return;
  el("waiting-text").textContent = `等待你的真实回答：${waiting.state.waiting.question}`;
  el("submit-answer").disabled = busy;
}

async function refreshPreview() {
  preview = await request(`/api/activities/${activity.id}/preview`);
  el("preview").textContent = preview.status === "ok" ? (preview.text || "还没有材料，可以直接开始。") : `展开未完成：${preview.error}`;
  el("save-preview").disabled = preview.status !== "ok" || !preview.text || busy;
}
async function change(operations, reason, revision = activity.revision) {
  const result = await request(`/api/activities/${activity.id}/changes`, { method: "POST", body: JSON.stringify({ revision, operations, reason }) });
  activity = result.activity;
  await loadActivity(activity.id);
}
async function refreshSource() {
  sourcePreview = await request(`/api/activities/${activity.id}/acquisition`);
  const adopted = activity.state.source_adopted;
  el("source-description").textContent = adopted ? `正在采用「${documentLabel(adopted.document)}」第 ${adopted.version} 版；执行时读取当前项目的真实记录。` : "尚未采用取材方式。已有材料仍可继续使用。";
  const params = sourcePreview.request;
  el("source-preview").textContent = params ? `范围：当前项目\n包含：${params.contains || "不限"}\n原作者：${params.author || "不限"}\n类型：${{any:"发言与执行结果",message:"发言",handoff_result:"执行结果"}[params.kind]}\n最多 ${params.limit} 条，按最新发生优先` : (sourcePreview.error || "");
  el("release-source").disabled = !adopted || busy;
  el("acquire-material").disabled = (!pendingAcquisition && sourcePreview.status !== "ready") || busy;
  el("acquire-material").textContent = pendingAcquisition ? "重试本次取材" : "按当前方式取得材料";
}
function setBusy(value) {
  busy = value;
  for (const button of document.querySelectorAll("button")) button.disabled = value;
  el("activities").disabled = value; el("participant").disabled = value || Boolean(pendingRequest);
  el("message").readOnly = value || Boolean(pendingRequest);
  el("continue-button").textContent = value ? "正在共同展开…" : (pendingRequest ? "重试同一次请求" : "共同继续");
  if (!value && activity) {
    el("release").disabled = !activity.state.adopted;
    el("release-source").disabled = !activity.state.source_adopted;
    el("acquire-material").disabled = !pendingAcquisition && sourcePreview?.status !== "ready";
    drawArrangement();
  }
}

el("new-activity").addEventListener("click", action(async () => {
  if (editorDirty || focusDirty) throw new Error("请先保存当前编辑，再新建活动。");
  const result = await request(`/api/projects/${encodeURIComponent(projectId)}/activities`, { method: "POST", body: "{}" });
  await listActivities(result.activity.id);
}));
el("activities").addEventListener("change", action(async () => {
  if (editorDirty || focusDirty) { el("activities").value = activity.id; throw new Error("请先保存当前编辑，再切换活动。"); }
  await loadActivity(el("activities").value);
}));
el("rename-activity").addEventListener("click", () => { if (activity) { el("activity-title").value = activity.state.title; el("rename-dialog").showModal(); } });
el("cancel-rename").addEventListener("click", () => el("rename-dialog").close());
el("rename-form").addEventListener("submit", action(async () => {
  await change([{ action: "rename", title: el("activity-title").value }], "调整活动名称");
  el("activities").selectedOptions[0].textContent = activity.state.title;
  el("rename-dialog").close();
}));
el("import-occurrence").addEventListener("click", action(async () => {
  const payload = await request(`/api/projects/${encodeURIComponent(projectId)}/messages`);
  importable = payload.messages.filter(item => !item.activity_operation && !item.activity_change_request).toReversed();
  el("import-selection").replaceChildren();
  for (const item of importable) {
    const option = document.createElement("option"); option.value = item.id;
    option.textContent = `${item.author} · ${item.content.slice(0, 45)}`;
    el("import-selection").append(option);
  }
  el("confirm-import").disabled = !importable.length;
  showImportPreview(); el("import-dialog").showModal();
}));
function showImportPreview() {
  const item = importable.find(item => item.id === el("import-selection").value);
  el("import-preview").textContent = item ? `${item.author}\n\n${item.content}` : "本项目暂时没有可引入的发言或执行结果。";
}
el("import-selection").addEventListener("change", showImportPreview);
el("cancel-import").addEventListener("click", () => el("import-dialog").close());
el("import-form").addEventListener("submit", action(async () => {
  const item = importable.find(item => item.id === el("import-selection").value);
  if (!item) return;
  await request(`/api/activities/${activity.id}/imports`, { method: "POST", body: JSON.stringify({ revision: activity.revision, occurrence_id: item.id, title: `${item.author} · ${item.content.slice(0, 30)}` }) });
  await loadActivity(activity.id); el("import-dialog").close();
}));
el("save-focus").addEventListener("click", action(async () => {
  const value = el("focus").value;
  await change([{ action: "set_focus", text: value }], "调整当前关注", focusRevision);
  focusDirty = false; focusOriginal = value; focusRevision = activity.revision;
}));
el("focus").addEventListener("input", () => { focusDirty = true; });
for (const id of ["document-editor", "json-editor"]) el(id).addEventListener("input", () => { editorDirty = true; });
el("save-document").addEventListener("click", action(async () => {
  const doc = activity.state.documents[selectedDocument];
  const body = doc.format === "text" ? el("document-editor").value : JSON.parse(el("json-editor").value);
  const result = await request(`/api/activities/${activity.id}/changes`, { method: "POST", body: JSON.stringify({ revision: editorRevision, operations: [{ action: "revise_document", document: selectedDocument, body }], reason: "修改内容" }) });
  editorDirty = false; activity = result.activity; await loadActivity(activity.id);
}));
el("adopt-document").addEventListener("click", action(async () => {
  if (editorDirty) throw new Error("先保存这份定义，再采用其新版本。");
  await change([{ action: "adopt", document: selectedDocument }], "采用当前组织定义");
}));
el("release").addEventListener("click", action(() => change([{ action: "release" }], "停用当前组织，保留全部材料")));
el("save-source").addEventListener("click", action(async () => {
  if (editorDirty || focusDirty) throw new Error("请先保存当前编辑，再采用取材方式。");
  const id = crypto.randomUUID();
  await change([{ action: "add_document", id, title: "项目取材方式", format: "json", body: { op: "literal", value: { provider: "project_history", contains: el("source-contains").value, author: el("source-author").value, kind: el("source-kind").value, limit: 10 } } }, { action: "adopt_source", document: id }], "采用取材方式");
}));
el("adopt-source-document").addEventListener("click", action(async () => {
  if (editorDirty) throw new Error("先保存这份定义，再采用其新版本。");
  await change([{ action: "adopt_source", document: selectedDocument }], "采用当前取材定义");
}));
el("release-source").addEventListener("click", action(() => change([{ action: "release_source" }], "停用取材方式，保留此前取得的材料")));
el("acquire-material").addEventListener("click", action(async () => {
  if (busy) return;
  if (editorDirty || focusDirty) throw new Error("请先保存当前编辑，再取材。");
  pendingAcquisition ||= { revision: activity.revision, request_id: crypto.randomUUID() };
  setBusy(true);
  try {
    const payload = await request(`/api/activities/${activity.id}/acquire`, { method: "POST", body: JSON.stringify(pendingAcquisition) });
    pendingAcquisition = null;
    if (payload.run.status === "acquired") selectedDocument = payload.run.events[0].results[0].document;
    await loadActivity(activity.id);
    if (payload.run.status !== "acquired") notify(payload.run.error || "取材未完成，已取得的记录仍保留。");
  } catch (error) { throw new Error(`本次取材返回尚未确认，可重试同一请求。${error.message}`); }
  finally { setBusy(false); await refreshSource(); }
}));
el("adopt-arrangement").addEventListener("click", action(async () => {
  const chosen = arrangement.filter(item => item.selected);
  if (!chosen.length) throw new Error("请先选择要并置的材料。");
  const id = crypto.randomUUID();
  await change([{ action: "add_document", id, title: "材料编排", format: "json", body: { op: "join", inputs: chosen.map(item => ({ op: "read", document: item.id })) } }, { action: "adopt", document: id }], "采用新的材料并置方式");
}));
el("save-preview").addEventListener("click", action(() => change([{ action: "add_document", title: "从当前展开形成的草稿", format: "text", body: preview.text }], "保留当前展开结果为可改写内容", preview.revision)));
el("add-document").addEventListener("click", () => { el("add-form").reset(); el("add-dialog").showModal(); });
el("copy-document").addEventListener("click", () => {
  const doc = activity.state.documents[selectedDocument]; el("add-form").reset();
  el("new-title").value = `${doc.title} · 继续`; el("new-kind").value = "draft";
  el("new-json").checked = doc.format === "json"; el("new-body").value = asText(doc.versions.at(-1).body); el("add-dialog").showModal();
});
el("cancel-add").addEventListener("click", () => el("add-dialog").close());
el("add-form").addEventListener("submit", action(async () => {
  const isJson = el("new-json").checked;
  const body = isJson ? JSON.parse(el("new-body").value) : el("new-body").value;
  await change([{ action: "add_document", title: el("new-title").value || "未命名内容", format: isJson ? "json" : "text", body, immutable: el("new-kind").value === "source" }], "加入材料或可改写内容");
  el("add-dialog").close();
}));
el("close-history").addEventListener("click", () => el("history-dialog").close());
el("toggle-situation-edit").addEventListener("click", () => {
  const situation = activity.state.situation || { text: "", refs: [] };
  el("situation-editor").value = situation.text;
  el("situation-refs-editor").value = situation.refs
    .map(ref => `${ref.document}@${ref.version}${ref.note ? ` # ${ref.note}` : ""}`).join("\n");
  el("situation-edit").hidden = false;
});
el("cancel-situation").addEventListener("click", () => { el("situation-edit").hidden = true; });
el("save-situation").addEventListener("click", action(async () => {
  const refs = el("situation-refs-editor").value.split("\n").map(line => line.trim()).filter(Boolean).map(line => {
    const [main, ...noteParts] = line.split("#");
    const idVersion = main.trim().split(/\s+/)[0] || "";
    const at = idVersion.lastIndexOf("@");
    return {
      document: at > 0 ? idVersion.slice(0, at) : idVersion,
      version: at > 0 ? Number(idVersion.slice(at + 1)) : undefined,
      note: noteParts.join("#").trim(),
    };
  });
  await change([{ action: "set_situation", text: el("situation-editor").value, refs }], "修订当前理解");
  el("situation-edit").hidden = true;
}));
el("submit-answer").addEventListener("click", action(async () => {
  const waiting = activityMoves.findLast(move => move.state.status === "waiting_user");
  if (!waiting) throw new Error("当前没有等待回答的问题。");
  const answer = el("answer-input").value;
  if (!answer.trim() && !pendingAnswer) throw new Error("请写下真实回答。");
  pendingAnswer ||= { moveId: waiting.id, revision: activity.revision, request_id: crypto.randomUUID(), answer };
  setBusy(true);
  try {
    await request(`/api/activities/${activity.id}/moves/${pendingAnswer.moveId}/answer`, {
      method: "POST",
      body: JSON.stringify({ revision: pendingAnswer.revision, request_id: pendingAnswer.request_id, answer: pendingAnswer.answer }),
    });
    pendingAnswer = null; el("answer-input").value = "";
    await loadActivity(activity.id);
  } catch (error) {
    throw new Error(`回答提交尚未确认，重试会使用同一次回答。${error.message}`);
  } finally { setBusy(false); }
}));
el("continue-form").addEventListener("submit", action(async () => {
  if (busy) return;
  if (editorDirty || focusDirty) throw new Error("请先保存当前编辑，再邀请参与者继续。");
  pendingRequest ||= { revision: activity.revision, request_id: crypto.randomUUID(), content: el("message").value, target: el("participant").value };
  setBusy(true);
  try {
    const payload = await request(`/api/activities/${activity.id}/continue`, { method: "POST", body: JSON.stringify(pendingRequest) });
    pendingRequest = null; el("message").value = "";
    await loadActivity(activity.id);
    if (["failed", "protocol_error", "conflict", "interrupted"].includes(payload.run.status)) notify(payload.run.error || el("run-status").textContent);
  } catch (error) {
    throw new Error(`本次返回尚未确认，重试会查询同一次执行。${error.message}`);
  } finally { setBusy(false); await refreshPreview(); }
}));

action(async () => {
  if (!projectId) throw new Error("请从项目中的“共同展开”入口进入。");
  const organizations = await request("/api/orgs");
  const project = organizations.organizations.flatMap(org => org.projects || []).find(item => item.id === projectId);
  if (project) el("project-title").textContent = project.title;
  const models = await request("/api/models");
  for (const participant of models.participants) { const option = document.createElement("option"); option.value = participant.id; option.textContent = participant.name; el("participant").append(option); }
  await listActivities(new URLSearchParams(location.search).get("activity"));
})();
