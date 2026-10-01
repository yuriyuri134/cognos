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
let activityView = null;

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
  if (node.type === "external_retrieval" && Array.isArray(node.searches)) {
    return node.searches.map((item, index) => {
      const sources = (item.sources || []).map(source => {
        const excerpt = (source.page_text || source.snippet || "").trim().slice(0, 360);
        const fingerprint = source.raw_content_sha256 ? `\n    响应指纹：${source.raw_content_sha256.slice(0, 16)}…` : "";
        return `  - ${source.title || source.url}\n    ${source.url}${source.extraction_status ? `\n    正文：${source.extraction_status}` : ""}${excerpt ? `\n    摘录：${excerpt}` : ""}${fingerprint}`;
      }).join("\n");
      return `${index + 1}. 查询：${item.query}\n   状态：${item.status}${item.axis ? ` · 方向：${item.axis}` : ""}${sources ? `\n${sources}` : "\n   没有可展示来源"}`;
    }).join("\n\n");
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
  activityView = payload.activity_view;
  const query = new URL(location.href); query.searchParams.set("activity", id); history.replaceState(null, "", query);
  if (changedActivity) { selectedDocument = null; editorDirty = false; focusDirty = false; pendingRequest = null; pendingAcquisition = null; pendingAnswer = null; el("situation-edit").hidden = true; }
  if (!focusDirty) { el("focus").value = activity.state.focus; focusOriginal = activity.state.focus; focusRevision = activity.revision; }
  else if (activity.state.focus === focusOriginal) focusRevision = activity.revision;
  renderDocuments(); renderArrangement(); renderMessages(payload.messages);
  activityMoves = payload.moves; renderReturnPoint(payload); renderSituation(); renderMoves(); renderWaiting();
  if (!editorDirty) renderEditor();
  else if (activity.state.documents[selectedDocument]?.versions.length === editorVersion) editorRevision = activity.revision;
  const adopted = activity.state.adopted;
  el("adopted-description").textContent = adopted ? `正在采用「${documentLabel(adopted.document)}」第 ${adopted.version} 版。修改后需再次采用才会改变运行。` : "尚未采用特定组织，当前展开全部材料。";
  el("release").disabled = !adopted || busy;
  const last = payload.runs.at(-1);
  const statuses = { paused: "本次已暂停，可以继续展开。", move_proposed: "已提出一次活动提议，尚未执行；可以继续展开。", budget_reached: "本次已到调用上限；已执行结果保留，可以继续。", failed: "本次未完成，已保存的内容保留。", protocol_error: "参与者回复未符合操作格式，原文已保存。", conflict: "期间出现了新修改，请依据当前内容继续。", interrupted: "上次执行已经中断，已保存内容保留；不会自动重复执行。", running: "该活动仍在服务中继续。", waiting_user: "参与者在等你的真实回答，回答后才继续。" };
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
  await refreshContexts();
}

function clipped(value, limit = 220) {
  const text = String(value || "").trim();
  return text.length <= limit ? text : `${text.slice(0, limit)}…`;
}

function renderReturnPoint(payload) {
  const container = el("return-summary");
  const section = el("return-point");
  container.replaceChildren();
  const rows = [];
  const waiting = activityMoves.findLast(move => move.state.status === "waiting_user");
  const openTensions = activityView?.tensions?.open || [];
  const activeFormations = activityView?.formations?.active || [];
  const latestMove = activityMoves.at(-1);
  const latestResult = latestMove?.state?.results?.at(-1);
  const focus = activity.state.focus?.trim();

  if (waiting?.state?.waiting?.question) rows.push(["等你回答", waiting.state.waiting.question]);
  if (openTensions.length) rows.push(["还没安顿", openTensions.at(-1).text]);
  if (activeFormations.length) rows.push(["正在形成", activeFormations.at(-1).text]);
  if (latestResult) rows.push(["最近实际得到", summarizeResult(latestResult)]);
  if (focus) rows.push(["此刻关注", focus]);

  const hasEarlierWork = (payload.runs || []).length > 0 || activity.revision > 1;
  section.hidden = !hasEarlierWork || rows.length === 0;
  if (section.hidden) return;
  for (const [label, value] of rows.slice(0, 4)) {
    const row = document.createElement("p");
    const strong = document.createElement("strong"); strong.textContent = `${label}：`;
    row.append(strong, document.createTextNode(clipped(value)));
    container.append(row);
  }
}

function renderDocuments() {
  el("documents").replaceChildren();
  for (const [id, doc] of Object.entries(activity.state.documents)) {
    const button = document.createElement("button"); button.className = `document-card${id === selectedDocument ? " selected" : ""}`;
    const title = document.createElement("span"), note = document.createElement("small");
    const computed = doc.versions.at(-1)?.source_author === "Cognos · 程序计算";
    const transferred = doc.versions.at(-1)?.source_author === "Cognos · 跨活动带入";
    title.textContent = doc.title; note.textContent = `${computed ? "程序结果 · 不可改写" : transferred ? (doc.immutable ? "带入快照" : "带入构造 · 可修订") : doc.immutable ? "原文保留" : "可改写"} · 第 ${doc.versions.length} 版`;
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
  el("publish-document").hidden = !doc;
  if (!doc) return;
  editorRevision = activity.revision;
  editorVersion = doc.versions.length;
  el("document-title").textContent = doc.title;
  el("document-status").textContent = `第 ${doc.versions.length} 版`;
  const computed = doc.versions.at(-1)?.source_author === "Cognos · 程序计算";
  el("document-description").textContent = computed
    ? "这是程序实际计算得到的值，保留了计算来源。可读取或用于新的组合；修改输入不会改写这份旧结果。"
    : doc.immutable ? "这是保留的原文。可另起内容来解释或改写它。" : "保存会形成新版本，旧版本保留。";
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
      try { const reply = JSON.parse(item.raw_activity_reply); body.textContent = reply.message || reply.answer || "提出了活动操作，执行情况已保留。"; } catch { /* 不可解析的原文仍展示。 */ }
    }
    article.append(author, body); el("messages").append(article);
  }
}

function renderSituation() {
  const situation = activityView?.independent_situation || { text: "", refs: [] };
  const active = activityView?.formations?.active || [], dormant = activityView?.formations?.dormant || [];
  function renderFormation(container, item) {
    const card = document.createElement("article"); card.className = "formation-card";
    const source = document.createElement("p"); source.className = "hint";
    source.textContent = `${item.participant?.name || "参与者"} · ${item.status === "dormant" ? "暂缓" : "暂定读法"}`;
    const body = document.createElement("pre"); body.className = "situation-text"; body.textContent = item.text || "（尚未写出内容）";
    card.append(source, body);
    for (const ref of item.refs || []) {
      if (!ref || typeof ref !== "object" || !ref.document) continue;
      const button = document.createElement("button"); button.className = "ref-chip";
      button.textContent = `${documentLabel(ref.document)}${ref.version ? ` @${ref.version}` : ""}`;
      button.addEventListener("click", action(async () => {
        const saved = await request(`/api/activities/${activity.id}/documents/${encodeURIComponent(ref.document)}${ref.version ? `?version=${ref.version}` : ""}`);
        el("history-title").textContent = `${saved.title} · 第 ${saved.version} 版`;
        el("history-preview").textContent = asText(saved.body); el("history-dialog").showModal();
      }));
      card.append(button);
    }
    const details = document.createElement("details"), summary = document.createElement("summary");
    const events = (activityView?.formation_history || []).filter(event => event.id === item.id);
    summary.textContent = `形成与修订记录（${events.length}）`; details.append(summary);
    for (const event of events) {
      const row = document.createElement("p"); row.className = "formation-revision";
      const status = event.status === "dormant" ? "暂缓" : event.status === "active" ? "启用" : "记录或修订";
      row.textContent = `${event.participant?.name || "参与者"} · 第 ${event.call_index + 1} 次调用 · ${status}\n${event.text ?? "（保留原内容，仅更新状态）"}`;
      if (event.context_snapshot_id) row.title = `输入快照：${event.context_snapshot_id}`;
      details.append(row);
    }
    const controls = document.createElement("div"); controls.className = "inline";
    const toggle = document.createElement("button");
    toggle.textContent = item.status === "dormant" ? "重新启用" : "暂缓";
    toggle.addEventListener("click", action(async () => {
      await request(`/api/activities/${activity.id}/formations`, {method: "POST", body: JSON.stringify({
        revision: activity.revision, request_id: crypto.randomUUID(), formation_id: item.id,
        status: item.status === "dormant" ? "active" : "dormant"
      })});
      await loadActivity(activity.id);
    }));
    const revise = document.createElement("button"); revise.textContent = "修订文字";
    revise.addEventListener("click", action(async () => {
      const text = window.prompt("修订这项暂定读法。旧版本仍会保留。", item.text || "");
      if (text === null) return;
      await request(`/api/activities/${activity.id}/formations`, {method: "POST", body: JSON.stringify({
        revision: activity.revision, request_id: crypto.randomUUID(), formation_id: item.id,
        status: item.status === "dormant" ? "dormant" : "changed", text
      })});
      await loadActivity(activity.id);
    }));
    controls.append(toggle, revise);
    card.append(details, controls); container.append(card);
  }
  el("formation-active").replaceChildren(); el("formation-dormant").replaceChildren();
  active.forEach(item => renderFormation(el("formation-active"), item));
  dormant.forEach(item => renderFormation(el("formation-dormant"), item));
  el("formation-dormant-details").hidden = dormant.length === 0;
  el("formation-dormant-summary").textContent = `暂缓的读法（${dormant.length}）`;
  el("situation-text").hidden = false;
  el("situation-refs").hidden = false;
  el("situation-label").textContent = situation.text ? "独立写下的理解 / 旧活动记录"
    : active.length ? "也可以写下你自己的理解。" : "尚未形成明确理解。";
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
  if (result.history_browse) return `浏览原话：本页 ${result.history_browse.returned_count} 条，另有 ${result.history_browse.older_count} 条更早内容；未按当前取材条件过滤。`;
  if (result.kind === "user_answer") return `回答：${result.content}`;
  if (result.manifest && result.document) return `从另一活动带入${result.manifest.kind === "construction" ? "构造与依赖" : "材料快照"} → 「${documentLabel(result.document)}」`;
  if (result.evaluation && typeof result.document === "string") return `程序计算结果已保存 → 「${documentLabel(result.document)}」`;
  if (result.acquisition?.provider === "project_occurrence") {
    const read = result.acquisition;
    return `回读原话 ${read.excerpt_start}–${read.excerpt_end} / ${read.total_characters} 字符${read.next_start !== null ? "，后面仍有未读内容" : ""} → 「${documentLabel(result.document)}」`;
  }
  if (typeof result.successful_queries === "number") {
    const failed = result.failed_queries || 0;
    return `外部检索：${result.successful_queries}/${result.queries} 个查询取得材料${failed ? `，${failed} 个失败` : ""} → 「${documentLabel(result.document)}」`;
  }
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
function contextSummary(snapshot) {
  const context = snapshot.context || {};
  const runtime = context.runtime || {};
  const model = runtime.actual_model || runtime.model || "未标明模型";
  const formations = Array.isArray(context.formations) ? context.formations.length : 0;
  const tensions = Array.isArray(context.tensions) ? context.tensions.length : 0;
  const active = context.active_materials || {};
  const materials = Array.isArray(active) ? active.length : (Array.isArray(active.entries) ? active.entries.length : 0);
  const encounters = Array.isArray(context.recent_encounters) ? context.recent_encounters.length : 0;
  const materialLabel = active.selection?.body_policy === "compiled_result_with_readable_sources" ? "来源引用" : "材料";
  return `第 ${(snapshot.call_index ?? 0) + 1} 次调用 · ${model} · ${materialLabel} ${materials} · 正在形成 ${formations} · 未安顿 ${tensions} · 最近遭遇 ${encounters}`;
}
function renderContextDetail(container, snapshot) {
  const context = snapshot.context || {};
  const visible = { ...context };
  delete visible.rendered_prompt;
  const summary = document.createElement("p"); summary.className = "hint";
  summary.textContent = `快照 ${snapshot.id} · ${snapshot.rendered_prompt_hash || "无提示哈希"}`;
  const pre = document.createElement("pre"); pre.className = "context-json";
  pre.textContent = JSON.stringify(visible, null, 2);
  const boundary = document.createElement("p"); boundary.className = "hint";
  boundary.textContent = context.runtime?.input_scope === "assembled_activity_only"
    ? "本轮不附带聊天历史。发送记录包含固定指令和请求正文；服务提供方内部的处理不在记录范围内。"
    : context.runtime?.input_scope === "fresh_thread_runtime_context_not_captured"
    ? "本轮使用新的临时 Codex 会话，不续接普通聊天。记录包含提交的内容；运行时额外指令和工具读取未完整记录。"
    : "此快照只记录活动提交的提示；运行时可能附带其他历史。";
  const button = document.createElement("button"); button.type = "button"; button.textContent = "查看发送内容";
  button.addEventListener("click", action(async () => {
    const detail = await request(`/api/activities/${activity.id}/contexts/${encodeURIComponent(snapshot.id)}`);
    pre.textContent = detail.runtime_requests?.length
      ? JSON.stringify(detail.runtime_requests, null, 2)
      : (detail.snapshot.rendered_prompt || "本次没有保存渲染提示。");
    button.hidden = true;
  }));
  container.append(summary, boundary, pre, button);
  if (context.active_materials?.selection?.body_policy === "compiled_result_with_readable_sources") {
    const selection = document.createElement("p"); selection.className = "hint";
    selection.textContent = "本轮按采用的组织方式展开材料。来源引用不表示已附带全文；原文仍可重新读取，用户输入和实际结果也会独立进入。";
    container.insertBefore(selection, pre);
  }
}
async function refreshContexts() {
  const container = el("context-snapshots");
  if (!container || !activity) return;
  const payload = await request(`/api/activities/${activity.id}/contexts?limit=12`);
  container.replaceChildren();
  if (!payload.snapshots.length) {
    const empty = document.createElement("p"); empty.className = "hint"; empty.textContent = "尚未有模型调用。"; container.append(empty); return;
  }
  for (const snapshot of payload.snapshots.toReversed()) {
    const details = document.createElement("details"); details.className = "context-card";
    const summary = document.createElement("summary"); summary.textContent = contextSummary(snapshot);
    details.append(summary);
    renderContextDetail(details, snapshot);
    container.append(details);
  }
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
let transferSource = null, transferRevision = null, pendingTransfer = null;
let transferCandidateMode = false, experienceCandidates = [];
function selectedExperienceCandidate() {
  if (!transferCandidateMode || !transferSource) return null;
  return experienceCandidates.find(item => item.source_activity === transferSource.id && item.document === el("transfer-document").value) || null;
}
function describeExperienceCandidate() {
  const candidate = selectedExperienceCandidate();
  if (!candidate) return;
  el("transfer-kind").value = candidate.kind;
  const traces = candidate.evidence.map(item => item.type).filter(Boolean).join("、") || "实际使用痕迹";
  el("transfer-source-note").textContent = `候选来自“${candidate.source_title}”第 ${candidate.source_revision} 版，痕迹：${traces}。这里只说明它过去被实际使用过，不说明它现在适用；带入后也不会自动采用。`;
}
async function loadTransferSource() {
  transferSource = null;
  el("transfer-submit").disabled = true;
  el("transfer-document").replaceChildren();
  const ident = el("transfer-activity").value;
  if (!ident) return;
  const payload = await request(`/api/activities/${encodeURIComponent(ident)}`);
  if (ident !== el("transfer-activity").value) return;
  transferSource = payload.activity;
  for (const [id, doc] of Object.entries(transferSource.state.documents)) {
    const candidate = transferCandidateMode ? experienceCandidates.find(item => item.source_activity === ident && item.document === id) : null;
    if (transferCandidateMode && !candidate) continue;
    const option = document.createElement("option"); option.value = id;
    option.textContent = `${doc.title} · 第 ${candidate?.version || doc.versions.length} 版${candidate ? " · 有实际使用痕迹" : ""}`;
    el("transfer-document").append(option);
  }
  if (transferCandidateMode) describeExperienceCandidate();
  else el("transfer-source-note").textContent = `来源活动第 ${transferSource.revision} 版。带入保留来源，不自动采用；构造依赖会固定版本。这里的适用说明仍是判断。`;
  el("transfer-submit").disabled = !el("transfer-document").value;
}
el("import-activity-material").addEventListener("click", action(async () => {
  if (busy) return;
  transferCandidateMode = false; experienceCandidates = [];
  transferRevision = activity.revision; pendingTransfer = null;
  el("transfer-note").value = ""; el("transfer-kind").value = "material";
  el("transfer-activity").replaceChildren();
  const payload = await request(`/api/projects/${activity.project_id}/activities`);
  for (const item of payload.activities.filter(item => item.id !== activity.id)) {
    const option = document.createElement("option"); option.value = item.id; option.textContent = item.title;
    el("transfer-activity").append(option);
  }
  if (!el("transfer-activity").value) { notify("当前项目还没有其他活动可供带入。"); return; }
  await loadTransferSource(); el("transfer-dialog").showModal();
}));
el("browse-experience").addEventListener("click", action(async () => {
  if (busy) return;
  const payload = await request(`/api/activities/${activity.id}/experience-candidates?limit=50`);
  experienceCandidates = payload.candidates || [];
  if (!experienceCandidates.length) { notify("当前项目还没有可发现的已使用候选。普通草稿不会仅因存在就被推荐。"); return; }
  transferCandidateMode = true; transferRevision = activity.revision; pendingTransfer = null;
  el("transfer-note").value = "";
  el("transfer-activity").replaceChildren();
  const seen = new Set();
  for (const item of experienceCandidates) {
    if (seen.has(item.source_activity)) continue;
    seen.add(item.source_activity);
    const option = document.createElement("option"); option.value = item.source_activity; option.textContent = item.source_title;
    el("transfer-activity").append(option);
  }
  await loadTransferSource(); el("transfer-dialog").showModal();
}));
el("transfer-activity").addEventListener("change", action(loadTransferSource));
el("transfer-document").addEventListener("change", describeExperienceCandidate);
el("transfer-cancel").addEventListener("click", () => el("transfer-dialog").close());
el("transfer-form").addEventListener("submit", action(async () => {
  if (!transferSource || !el("transfer-document").value) return;
  const documentId = el("transfer-document").value;
  const candidate = selectedExperienceCandidate();
  const body = {revision: transferRevision, source_activity: transferSource.id, source_revision: candidate?.source_revision || transferSource.revision,
    document: documentId, version: candidate?.version || transferSource.state.documents[documentId].versions.length,
    kind: el("transfer-kind").value, note: el("transfer-note").value};
  const signature = JSON.stringify(body);
  if (pendingTransfer?.signature !== signature) pendingTransfer = {signature, request_id: crypto.randomUUID()};
  el("transfer-submit").disabled = true;
  try {
    const payload = await request(`/api/activities/${activity.id}/imports/from-activity`, {
      method: "POST", body: JSON.stringify({...body, request_id: pendingTransfer.request_id})});
    if (payload.run.status !== "imported") throw new Error(payload.run.error || "本次未能带入，请重新打开并核对当前版本。");
    selectedDocument = payload.run.events[0].results[0].document;
    el("transfer-dialog").close(); pendingTransfer = null; transferCandidateMode = false; experienceCandidates = [];
    await loadActivity(activity.id); notify("已带入，来源说明同时保留。可先查看，再决定如何使用。");
  } finally { el("transfer-submit").disabled = false; }
}));

let importBefore = null;
async function browseImportPage(before = null) {
  const payload = await request(`/api/activities/${activity.id}/history-browse${before ? `?before=${encodeURIComponent(before)}` : ""}`);
  importable = payload.records.map(item => ({...item, id: item.occurrence_id}));
  importBefore = payload.next_before;
  el("import-older").disabled = !importBefore;
  el("import-coverage").textContent = `本页 ${payload.returned_count} 条，还有 ${payload.older_count} 条更早内容；每项最多展示 500 字。`;
  el("import-selection").replaceChildren();
  for (const item of importable) {
    const option = document.createElement("option"); option.value = item.id;
    const filter = item.matches_current_filters === false ? " · 未命中当前筛选" : item.matches_current_filters === true ? " · 命中当前筛选" : "";
    option.textContent = `${item.author} · ${item.content.slice(0, 45)}${filter}`;
    el("import-selection").append(option);
  }
  el("confirm-import").disabled = !importable.length;
  showImportPreview();
}
el("import-occurrence").addEventListener("click", action(async () => {
  await browseImportPage(); el("import-dialog").showModal();
}));
el("import-older").addEventListener("click", action(async () => { if (importBefore) await browseImportPage(importBefore); }));
el("import-latest").addEventListener("click", action(() => browseImportPage()));
function showImportPreview() {
  const item = importable.find(item => item.id === el("import-selection").value);
  el("import-preview").textContent = item ? `${item.author}\n\n${item.content}${item.content_truncated ? `\n\n（当前仅展示开头 500 字，原文共 ${item.total_characters} 字；带入会保留全文。）` : ""}` : "本项目暂时没有可引入的发言或执行结果。";
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
el("publish-document").addEventListener("click", () => {
  const doc = activity.state.documents[selectedDocument];
  if (!doc) return;
  el("publish-kind").value = "message";
  el("publish-title").value = doc.title;
  el("publish-content").value = asText(doc.versions.at(-1).body);
  el("publish-dialog").showModal();
});
el("cancel-publish").addEventListener("click", () => el("publish-dialog").close());
el("publish-form").addEventListener("submit", action(async () => {
  if (!selectedDocument) throw new Error("请先选择要返回项目的材料。");
  const doc = activity.state.documents[selectedDocument];
  const result = await request(`/api/activities/${activity.id}/publish`, { method: "POST", body: JSON.stringify({
    request_id: crypto.randomUUID(), kind: el("publish-kind").value,
    title: el("publish-title").value, content: el("publish-content").value,
    source_document_id: selectedDocument, source_document_version: doc.versions.length,
  }) });
  el("publish-dialog").close();
  notify({ message: "项目消息", work_item: "工作项", decision: "提议中的决定" }[result.published.kind] + "已返回项目；来源与活动保持关联。" );
  await loadActivity(activity.id);
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
  const situation = activityView?.independent_situation || { text: "", refs: [] };
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
