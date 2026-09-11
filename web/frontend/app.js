const stream = document.querySelector("#message-stream");
const memberPage = document.querySelector("#member-page");
const memberPageBody = document.querySelector("#member-page-body");
const memberBack = document.querySelector("#member-back");
const emptyState = document.querySelector("#empty-state");
const composer = document.querySelector("#composer");
const input = document.querySelector("#message-input");
const sendButton = document.querySelector("#send-button");
const searchToggle = document.querySelector("#search-toggle");
const searchQueryInput = document.querySelector("#search-query");
const messageTemplate = document.querySelector("#message-template");
const pendingTemplate = document.querySelector("#pending-template");
const errorBanner = document.querySelector("#error-banner");
const runtimePill = document.querySelector("#runtime-pill");
const targetPillsContainer = document.querySelector("#target-pills");
const participantsList = document.querySelector("#participants-list");

const stagedQuoteEl = document.querySelector("#staged-quote");
const stagedAuthorEl = document.querySelector("#staged-author");
const stagedIdEl = document.querySelector("#staged-id");
const stagedPreviewEl = document.querySelector("#staged-preview");
const stagedCloseBtn = document.querySelector("#staged-close");

const fieldEntry = document.querySelector("#field-entry");
const fieldLive = document.querySelector("#field-live");
const orgNav = document.querySelector("#org-nav");
const orgCreateForm = document.querySelector("#org-create-form");
const orgNameInput = document.querySelector("#org-name-input");
const orgCreateBtn = document.querySelector("#org-create-btn");
const orgCreateToggle = document.querySelector("#org-create-toggle");
const membersBlock = document.querySelector("#members-block");
const membersList = document.querySelector("#members-list");
const memberAddToggle = document.querySelector("#member-add-toggle");
const memberAddForm = document.querySelector("#member-add-form");
const memberKind = document.querySelector("#member-kind");
const memberName = document.querySelector("#member-name");
const memberParticipant = document.querySelector("#member-participant");
const memberAddBtn = document.querySelector("#member-add-btn");
const viewTitle = document.querySelector("#view-title");
const viewNote = document.querySelector("#view-note");
const scopeBar = document.querySelector("#scope-bar");
const scopeLabel = document.querySelector("#scope-label");
const emptyTitle = document.querySelector("#empty-title");
const emptySub = document.querySelector("#empty-sub");
const mentionPopup = document.querySelector("#mention-popup");
const situationOverlay = document.querySelector("#situation-overlay");
const situationContent = document.querySelector("#situation-content");
const situationClose = document.querySelector("#situation-close");

const inquirySection = document.querySelector("#inquiry-section");
const inquiryNav = document.querySelector("#inquiry-nav");
const inquiryCreateBtn = document.querySelector("#inquiry-create-btn");
const inquiryPage = document.querySelector("#inquiry-page");
const inquiryPageBody = document.querySelector("#inquiry-page-body");
const inquiryBack = document.querySelector("#inquiry-back");
const inquiryRetry = document.querySelector("#inquiry-retry");
document.querySelector("#activity-open-btn")?.addEventListener("click", () => {
  if (currentView.projectId) window.location.href = `/activities?project=${encodeURIComponent(currentView.projectId)}`;
});

let sending = false;
let activeTraceElement = null;
let activeParticipants = [];
let currentTargetId = "gemini";
let stagedQuote = null;
let isHandoffMode = false;
let messagesMap = new Map();
let orgData = null;
let currentView = { type: "field" };
let previousView = { type: "field" };
let currentMembers = [];
let mentionOpen = false;
let mentionItems = [];
let mentionIndex = 0;
let mentionStart = -1;
const stagedBadgeEl = document.querySelector("#staged-badge");

function shortId(value, fallback) {
  if (!value) return fallback;
  if (value.length <= 28) return value;
  return `${value.slice(0, 14)}…${value.slice(-10)}`;
}

function formatTime(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "时间未知";
  
  const now = new Date();
  const isToday = date.toDateString() === now.toDateString();
  const yesterday = new Date(now);
  yesterday.setDate(yesterday.getDate() - 1);
  const isYesterday = date.toDateString() === yesterday.toDateString();

  const timeStr = new Intl.DateTimeFormat("zh-CN", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(date);

  if (isToday) {
    return `今天 ${timeStr}`;
  } else if (isYesterday) {
    return `昨天 ${timeStr}`;
  } else {
    const monthDay = new Intl.DateTimeFormat("zh-CN", {
      month: "2-digit",
      day: "2-digit",
    }).format(date);
    return `${monthDay} ${timeStr}`;
  }
}

function renderContent(element, content, isUser) {
  if (isUser) {
    element.textContent = content;
  } else {
    if (typeof marked !== "undefined" && typeof DOMPurify !== "undefined") {
      try {
        const rawHtml = marked.parse(content, { breaks: true, gfm: true });
        element.innerHTML = DOMPurify.sanitize(rawHtml);
      } catch (err) {
        console.warn("Markdown 解析失败，降级为纯文本", err);
        element.textContent = content;
      }
    } else {
      element.textContent = content;
    }
  }
}

function inspectTrace(runtimeRef, buttonNode) {
  if (!runtimeRef) return;
  
  if (activeTraceElement) {
    activeTraceElement.classList.remove("active-trace");
  }
  activeTraceElement = buttonNode;
  if (activeTraceElement) {
    activeTraceElement.classList.add("active-trace");
  }

  const turnEl = document.querySelector("#turn-id");
  if (turnEl) {
    turnEl.textContent = `${runtimeRef.runtime || "ai"} · ${shortId(runtimeRef.turn_id, "未知 turn")}`;
    turnEl.title = `runtime: ${runtimeRef.runtime}\nthread: ${runtimeRef.thread_id}\nturn: ${runtimeRef.turn_id}`;
  }
}

function stageHandoff(record) {
  isHandoffMode = true;
  stagedQuote = record;
  if (stagedQuoteEl) {
    stagedBadgeEl.textContent = "准备交接此发生";
    stagedBadgeEl.classList.add("handoff-badge");
    stagedAuthorEl.textContent = `@${record.author || "参与者"}`;
    stagedIdEl.textContent = `· ${shortId(record.id, "")}`;
    const preview = record.content.replace(/\s+/g, " ").trim();
    stagedPreviewEl.textContent = preview.length > 50 ? `${preview.slice(0, 50)}…` : preview;
    stagedQuoteEl.hidden = false;
  }
  input.placeholder = "输入交给外部 Agent (如 Antigravity) 的指令...";
  input.focus();
}

function stageQuote(record) {
  isHandoffMode = false;
  stagedQuote = record;
  if (stagedQuoteEl) {
    stagedBadgeEl.textContent = "引用已有发生";
    stagedBadgeEl.classList.remove("handoff-badge");
    stagedAuthorEl.textContent = `@${record.author || "参与者"}`;
    stagedIdEl.textContent = `· ${shortId(record.id, "")}`;
    const preview = record.content.replace(/\s+/g, " ").trim();
    stagedPreviewEl.textContent = preview.length > 50 ? `${preview.slice(0, 50)}…` : preview;
    stagedQuoteEl.hidden = false;
  }

  // 智能切换受话方：如果引用的是 Gemini，默认发给 Codex；如果引用 Codex，默认发给 Gemini
  const authorLower = (record.author || "").toLowerCase();
  if (authorLower.includes("gemini") && activeParticipants.some(p => p.id === "codex")) {
    currentTargetId = "codex";
  } else if (authorLower.includes("codex") && activeParticipants.some(p => p.id === "gemini")) {
    currentTargetId = "gemini";
  }
  renderTargetPills();
  input.focus();
}

function clearStagedQuote() {
  stagedQuote = null;
  isHandoffMode = false;
  input.placeholder = "写下此刻要继续的东西…（可输入 @Codex 或 @Gemini 定向邀请）";
  if (stagedQuoteEl) {
    stagedQuoteEl.hidden = true;
  }
}

if (stagedCloseBtn) {
  stagedCloseBtn.addEventListener("click", clearStagedQuote);
}

function createMessage(record, { pending = false } = {}) {
  const node = messageTemplate.content.firstElementChild.cloneNode(true);
  const isUser = record.author === "小林";
  const authorName = record.author || "未知参与者";
  
  node.classList.toggle("user", isUser);
  node.classList.toggle("pending", pending);
  if (!isUser) {
    node.classList.add(`author-${authorName.toLowerCase()}`);
  }
  
  node.dataset.messageId = record.id || "pending";
  
  const authorEl = node.querySelector(".author");
  if (isUser && record.target) {
    authorEl.textContent = `小林 → @${record.target}`;
  } else {
    authorEl.textContent = authorName;
  }
  
  node.querySelector("time").textContent = pending ? "正在送达" : formatTime(record.occurred_at);

  // 渲染被引用的发生预览
  const quotePreview = node.querySelector(".quote-preview");
  if (quotePreview && record.quoted_occurrence_id) {
    const quoted = messagesMap.get(record.quoted_occurrence_id);
    const qAuthor = quoted ? quoted.author : "发生";
    const qText = quoted ? quoted.content.replace(/\s+/g, " ").trim() : "";
    const shortText = qText.length > 60 ? `${qText.slice(0, 60)}…` : qText;
    
    quotePreview.hidden = false;
    quotePreview.innerHTML = `
      <span class="qp-badge">面对已有发生</span>
      <strong class="qp-author">@${qAuthor}</strong>
      <code class="qp-id">${shortId(record.quoted_occurrence_id, "")}</code>
      ${shortText ? `<span class="qp-text">${shortText}</span>` : ""}
    `;
  }
  
  const reqPreview = node.querySelector(".handoff-request-preview");
  if (reqPreview && record.handoff_request) {
    reqPreview.hidden = false;
    reqPreview.innerHTML = `
      <div class="handoff-box" style="margin-top:12px; padding:12px; border-left:3px solid var(--accent); background:rgba(255,255,255,0.05); border-radius:4px;">
        <strong style="color:var(--accent);">⚡ 外部交接请求</strong>
        <div style="font-size:0.9em; margin-top:4px;">
          <span>目标: <code>${record.handoff_request.target_agent || "未知"}</code></span>
          <span style="margin-left:8px;">上下文: <code>${record.handoff_request.source_refs.map(id => shortId(id, "")).join(", ")}</code></span>
        </div>
      </div>
    `;
  }

  const resPreview = node.querySelector(".handoff-result-preview");
  if (resPreview && record.handoff_result) {
    resPreview.hidden = false;
    resPreview.innerHTML = `
      <div class="handoff-box result-box" style="margin-top:12px; padding:12px; border-left:3px solid var(--success); background:rgba(255,255,255,0.05); border-radius:4px;">
        <strong style="color:var(--success);">✅ 交接结果</strong>
        <div style="font-size:0.9em; margin-top:4px;">
          <span>变更文件: <code>${record.handoff_result.changed_files.length}</code></span>
          <span style="margin-left:8px;">运行命令: <code>${record.handoff_result.commands_run.length}</code></span>
        </div>
      </div>
    `;
  }

  const body = node.querySelector(".message-body");
  renderContent(body, record.content, isUser);

  // 联网检索来源（Search Grounding）回显
  if (record.sources && record.sources.length) {
    const wrap = document.createElement("div");
    wrap.className = "sources-block";
    const label = document.createElement("span");
    label.className = "sources-label";
    label.textContent = "检索来源";
    wrap.append(label);
    record.sources.slice(0, 5).forEach((s) => {
      const a = document.createElement("a");
      a.href = s.uri || "#";
      a.target = "_blank";
      a.rel = "noopener noreferrer";
      a.textContent = s.title || s.uri;
      a.title = s.uri;
      a.className = "source-link";
      wrap.append(a);
    });
    body.after(wrap);
  }

  // 应用层联网检索：来源/失败提示回显
  if (record.search_error) {
    const note = document.createElement("div");
    note.className = "search-note";
    note.textContent = `⚠ 联网检索未完成（${record.search_error}），已照常发送`;
    body.after(note);
  }
  if (record.search_sources && record.search_sources.length) {
    const wrap = document.createElement("div");
    wrap.className = "sources-block";
    const label = document.createElement("span");
    label.className = "sources-label";
    label.textContent = "联网检索来源";
    wrap.append(label);
    record.search_sources.slice(0, 5).forEach((s) => {
      const a = document.createElement("a");
      a.href = s.url || "#";
      a.target = "_blank";
      a.rel = "noopener noreferrer";
      a.textContent = s.title || s.url;
      a.title = s.url;
      a.className = "source-link";
      wrap.append(a);
    });
    body.after(wrap);
  }

  // 引用按钮
  const quoteBtn = node.querySelector(".quote-button");
  if (quoteBtn) {
    if (pending) {
      quoteBtn.hidden = true;
    } else {
      quoteBtn.addEventListener("click", () => stageQuote(record));
    }
  }

  // Handoff 按钮
  const handoffBtn = node.querySelector(".handoff-button");
  if (handoffBtn) {
    if (pending) {
      handoffBtn.hidden = true;
    } else {
      handoffBtn.addEventListener("click", () => stageHandoff(record));
    }
  }

  // Trace 按钮
  const trace = node.querySelector(".trace-button");
  if (record.runtime_ref) {
    trace.hidden = false;
    const model = record.runtime_ref.model ? `${record.runtime_ref.model} · ` : "";
    trace.textContent = `${record.runtime_ref.runtime || "ai"} · ${model}${shortId(record.runtime_ref.turn_id, "未知")}`;
    trace.title = `点击关联右栏 Trace\nruntime: ${record.runtime_ref.runtime}\nthread: ${record.runtime_ref.thread_id}\nturn: ${record.runtime_ref.turn_id}\nactual model: ${record.runtime_ref.model || "未记录"}`;
    trace.addEventListener("click", () => inspectTrace(record.runtime_ref, trace));
  }

  // 处境按钮：查看当时 Situation Projection
  const situationBtn = node.querySelector(".situation-button");
  if (situationBtn) {
    if (record.situation_ref) {
      situationBtn.hidden = false;
      situationBtn.addEventListener("click", () => openSituation(record.situation_ref));
    }
  }

  // orientation 状态标识
  if (record.orientation && record.orientation.status) {
    const meta = node.querySelector(".message-meta");
    if (meta) {
      const chip = document.createElement("span");
      if (record.orientation.status === "clarification_required") {
        chip.className = "orientation-chip chip-clarify";
        chip.textContent = "需要澄清";
        chip.title = (record.orientation.missing || []).join("；") || "关键信息缺失";
      } else if (record.orientation.status === "assumption") {
        chip.className = "orientation-chip chip-assume";
        chip.textContent = "带假设";
        chip.title = (record.orientation.assumptions || []).join("；") || "部分假设";
      }
      if (chip.textContent) meta.append(chip);
    }
  }
  return node;
}

async function openSituation(situationId) {
  if (!situationOverlay || !situationContent) return;
  try {
    const situation = await requestJson(`/api/situations/${situationId}`);
    situationContent.textContent = JSON.stringify(situation, null, 2);
    situationOverlay.hidden = false;
  } catch (error) {
    showError(error.message || "无法读取处境投影");
  }
}

if (situationClose) {
  situationClose.addEventListener("click", () => {
    if (situationOverlay) situationOverlay.hidden = true;
  });
}

function createPendingReply(targetName) {
  if (pendingTemplate) {
    const node = pendingTemplate.content.firstElementChild.cloneNode(true);
    node.querySelector(".author").textContent = targetName || "AI";
    return node;
  }
  const fallback = document.createElement("article");
  fallback.className = "message pending-reply";
  fallback.innerHTML = `<div class="message-meta"><span class="author">${targetName || "AI"}</span><time>正在回应</time></div><div class="message-body">正在回应…</div>`;
  return fallback;
}

function renderMessages(messages) {
  messagesMap.clear();
  messages.forEach(m => {
    if (m.id) messagesMap.set(m.id, m);
  });

  stream.replaceChildren();
  if (!messages.length) {
    stream.append(emptyState);
  } else {
    messages.forEach((record) => stream.append(createMessage(record)));
  }
  document.querySelector("#history-count").textContent = String(messages.length);
  stream.scrollTop = stream.scrollHeight;
}

function renderTargetPills() {
  if (!targetPillsContainer) return;
  targetPillsContainer.replaceChildren();

  activeParticipants.forEach((p) => {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = `target-pill ${p.id === currentTargetId ? "active" : ""}`;
    btn.dataset.targetId = p.id;
    btn.innerHTML = `<span class="pill-dot"></span>@${p.name}`;
    btn.title = `定向邀请 ${p.name} (${p.model_name})`;
    btn.addEventListener("click", () => {
      currentTargetId = p.id;
      renderTargetPills();
      input.focus();
    });
    targetPillsContainer.append(btn);
  });
}

// ---------- @ 提及补全 ----------

function mentionCandidates() {
  const seen = new Set();
  const list = [];
  activeParticipants.forEach((p) => {
    if (seen.has(p.id)) return;
    seen.add(p.id);
    list.push({ key: p.id, label: p.name, kind: "AI", participantId: p.id });
  });
  currentMembers.forEach((m) => {
    const key = m.participant_id || `m-${m.id}`;
    if (seen.has(key)) return;
    seen.add(key);
    list.push({
      key,
      label: m.name,
      kind: m.kind === "ai" ? "AI" : "人",
      participantId: m.kind === "ai" ? m.participant_id : null,
    });
  });
  return list;
}

function mentionTriggerPos() {
  const pos = input.selectionStart;
  const text = input.value.slice(0, pos);
  const match = text.match(/(^|\s)@([^\s@]*)$/u);
  if (!match) return null;
  return {
    start: pos - match[0].length + match[1].length,
    query: match[2] || "",
  };
}

function renderMentionPopup() {
  if (!mentionPopup) return;
  mentionPopup.replaceChildren();
  mentionItems.forEach((item, idx) => {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = `mention-item${idx === mentionIndex ? " selected" : ""}`;
    btn.innerHTML = `<span>@${item.label}</span><span class="mention-kind">${item.kind}</span>`;
    btn.addEventListener("mousedown", (event) => {
      event.preventDefault();
      selectMention(idx);
    });
    mentionPopup.append(btn);
  });
  mentionPopup.hidden = false;
}

function closeMention() {
  mentionOpen = false;
  mentionItems = [];
  mentionIndex = 0;
  if (mentionPopup) mentionPopup.hidden = true;
}

function openMention(start, query) {
  const candidates = mentionCandidates().filter((item) =>
    item.label.toLowerCase().includes(query.toLowerCase())
  );
  if (!candidates.length) {
    closeMention();
    return;
  }
  mentionOpen = true;
  mentionStart = start;
  mentionItems = candidates;
  mentionIndex = 0;
  renderMentionPopup();
}

function updateMention() {
  if (!mentionPopup) return;
  const trig = mentionTriggerPos();
  if (trig) {
    openMention(trig.start, trig.query);
  } else {
    closeMention();
  }
}

function selectMention(idx) {
  const item = mentionItems[idx];
  if (!item) return;
  const before = input.value.slice(0, mentionStart);
  const after = input.value.slice(input.selectionStart);
  const insert = `@${item.label} `;
  input.value = before + insert + after;
  const caret = before.length + insert.length;
  input.setSelectionRange(caret, caret);
  closeMention();
  resizeInput();
  // 选中 AI 参与者时同步设为受话方
  if (item.participantId && activeParticipants.some((p) => p.id === item.participantId)) {
    currentTargetId = item.participantId;
    renderTargetPills();
  }
  input.focus();
}

function renderParticipants(participants) {
  if (!participantsList) return;
  participantsList.replaceChildren();

  participants.forEach((p) => {
    const card = document.createElement("div");
    card.className = `participant-card ${p.id === currentTargetId ? "focused" : ""}`;
    card.innerHTML = `
      <div class="p-header">
        <strong class="p-name">@${p.name}</strong>
        <span class="p-model">${p.model_name || ""}</span>
      </div>
      <div class="p-thread">
        <span class="p-label">Thread:</span>
        <code title="${p.thread_id || ''}">${shortId(p.thread_id, "尚未建立")}</code>
      </div>
    `;
    participantsList.append(card);
  });
}

function renderState(payload) {
  activeParticipants = payload.participants || [];
  if (payload.default_target && !activeParticipants.some(p => p.id === currentTargetId)) {
    currentTargetId = payload.default_target;
  }
  renderTargetPills();
  renderParticipants(activeParticipants);

  const state = payload.state || {};
  const latest = state.latest_runtime_ref || {};
  const turnEl = document.querySelector("#turn-id");
  if (turnEl) {
    turnEl.textContent = latest.turn_id ? `${latest.runtime || "ai"} · ${shortId(latest.turn_id, "")}` : "尚无 turn";
    turnEl.title = latest.turn_id || "";
  }
  document.querySelector("#persistence").textContent = state.persistence || "Obsidian Vault";
  document.querySelector("#history-count").textContent = String(state.history_count || 0);
}

function renderMembers(members) {
  if (!membersBlock || !membersList) return;
  currentMembers = members || [];
  membersList.replaceChildren();
  members.forEach((m) => {
    const chip = document.createElement("button");
    chip.type = "button";
    chip.className = `member-chip member-${m.kind}`;
    chip.title = `进入 ${m.name} 的主体空间`;
    chip.textContent = `${m.kind === "ai" ? "AI" : "人"} · ${m.name}`;
    chip.addEventListener("click", () => openMember(m.id));
    membersList.append(chip);
  });
  membersBlock.hidden = false;
  populateMemberParticipant();
}

function populateMemberParticipant() {
  if (!memberParticipant) return;
  const current = memberParticipant.value;
  memberParticipant.replaceChildren();
  activeParticipants.forEach((p) => {
    const opt = document.createElement("option");
    opt.value = p.id;
    opt.textContent = `${p.name} (${p.id})`;
    memberParticipant.append(opt);
  });
  if (current) memberParticipant.value = current;
}

async function addProjectMember() {
  if (!currentView || currentView.type !== "project") return;
  const name = (memberName.value || "").trim();
  if (!name) {
    memberName.focus();
    return;
  }
  const kind = memberKind.value;
  let participantId = null;
  if (kind === "ai") {
    participantId = memberParticipant.value;
    if (!activeParticipants.some(p => p.id === participantId)) {
      showError("AI 参与者不在当前注册表中");
      return;
    }
  }
  try {
    const orgRes = await requestJson(`/api/orgs/${currentView.orgId}/members`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ kind, name, participant_id: participantId }),
    });
    await requestJson(`/api/projects/${currentView.projectId}/members`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ org_member_id: orgRes.member.id }),
    });
    memberName.value = "";
    memberAddForm.hidden = true;
    await switchToProject(currentView.orgId, currentView.projectId);
  } catch (error) {
    showError(error.message || "添加成员失败");
  }
}

if (memberAddToggle) {
  memberAddToggle.addEventListener("click", () => {
    const show = memberAddForm.hidden;
    memberAddForm.hidden = !show;
    if (memberParticipant) memberParticipant.hidden = memberKind.value !== "ai";
    if (show) memberName.focus();
  });
}
if (memberKind) {
  memberKind.addEventListener("change", () => {
    if (memberParticipant) memberParticipant.hidden = memberKind.value !== "ai";
  });
}
if (memberAddBtn) memberAddBtn.addEventListener("click", addProjectMember);
if (memberName) {
  memberName.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      addProjectMember();
    }
  });
}

function setFieldActive(active) {
  if (!fieldEntry || !fieldLive) return;
  fieldEntry.classList.toggle("active-field", active);
  fieldLive.style.display = active ? "" : "none";
}

async function loadOrgData() {
  try {
    const payload = await requestJson("/api/orgs");
    orgData = payload.organizations || [];
    renderOrgNav();
  } catch (error) {
    console.warn("组织数据加载失败", error);
  }
}

function setScope(text) {
  if (scopeLabel) scopeLabel.textContent = text || "";
}

function setEmptyState(title, sub) {
  if (emptyTitle) emptyTitle.textContent = title;
  if (emptySub) emptySub.textContent = sub;
}

async function createProject(org) {
  const title = window.prompt(`在「${org.name}」中新建项目，标题：`);
  if (!title || !title.trim()) return null;
  try {
    const res = await requestJson(`/api/orgs/${org.id}/projects`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ title: title.trim(), description: "" }),
    });
    await loadOrgData();
    return res.project;
  } catch (error) {
    showError(error.message || "创建项目失败");
    return null;
  }
}

function renderOrgNav() {
  if (!orgNav) return;
  orgNav.replaceChildren();
  const hasOrgs = orgData && orgData.length > 0;
  if (orgCreateToggle) orgCreateToggle.hidden = hasOrgs;
  if (orgCreateForm) orgCreateForm.hidden = hasOrgs;

  (orgData || []).forEach((org) => {
    const group = document.createElement("div");
    group.className = "org-group";

    const head = document.createElement("div");
    head.className = "org-head";
    const label = document.createElement("strong");
    label.textContent = org.name;
    const count = document.createElement("small");
    count.textContent = `${org.project_count || 0} 项目`;
    head.append(label, count);
    group.append(head);

    const addBtn = document.createElement("button");
    addBtn.type = "button";
    addBtn.className = "nav-mini-btn project-add";
    addBtn.textContent = "＋ 新建项目";
    addBtn.addEventListener("click", async () => {
      const project = await createProject(org);
      if (project && project.id) await switchToProject(org.id, project.id);
    });
    group.append(addBtn);

    const projects = org.projects || [];
    if (!projects.length) {
      const empty = document.createElement("p");
      empty.className = "org-empty";
      empty.textContent = "还没有项目";
      group.append(empty);
    }
    projects.forEach((project) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "project-item";
      if (currentView.type === "project" && currentView.projectId === project.id) {
        btn.classList.add("active");
      }
      btn.innerHTML = `<span class="project-glyph">◌</span><span class="project-title">${project.title}</span>`;
      btn.title = project.description || project.title;
      btn.addEventListener("click", () => switchToProject(org.id, project.id));
      group.append(btn);
    });
    orgNav.append(group);
  });
}

async function switchToField() {
  currentView = { type: "field" };
  setFieldActive(true);
  if (viewTitle) viewTitle.textContent = "第一发生场";
  if (viewNote) viewNote.textContent = "同一发生场内多参与者共在；原文保存在本地 Vault";
  if (membersBlock) membersBlock.hidden = true;
  if (inquirySection) inquirySection.hidden = true;
  setScope("正在发生于：第一发生场（全局）");
  setEmptyState("从正在牵引你的地方开始。", "无需选择类型，也无需先把它整理成任务。");
  showConversationPane();
  await loadOrgData();
  await bootstrap();
}

function showConversationPane() {
  if (memberPage) memberPage.hidden = true;
  if (inquiryPage) inquiryPage.hidden = true;
  if (stream) stream.hidden = false;
  if (composer) composer.hidden = false;
  if (scopeBar) scopeBar.hidden = false;
}

async function loadInquiries(projectId) {
  if (!inquiryNav) return;
  try {
    const payload = await requestJson(`/api/projects/${projectId}/inquiries`);
    inquiryNav.replaceChildren();
    (payload.inquiries || []).forEach((inquiry) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "project-item";
      if (currentView.type === "inquiry" && currentView.inquiryId === inquiry.id) {
        btn.classList.add("active");
      }
      btn.innerHTML = `<span class="project-glyph">◎</span><span class="project-title">${inquiry.question.slice(0, 40)}</span>`;
      btn.title = `尝试 ${inquiry.attempt_count || 0} 次 · ${inquiry.status}`;
      btn.addEventListener("click", () => openInquiry(inquiry.id));
      inquiryNav.append(btn);
    });
    if (!(payload.inquiries || []).length) {
      const p = document.createElement("p");
      p.className = "org-empty";
      p.textContent = "还没有探究";
      inquiryNav.append(p);
    }
  } catch (error) {
    console.warn("探究列表加载失败", error);
  }
}

async function createInquiry() {
  if (currentView.type !== "project") return;
  const question = window.prompt("新探究的问题（原话，后续可以在这里多次尝试）：");
  if (!question || !question.trim()) return;
  try {
    const res = await requestJson(`/api/projects/${currentView.projectId}/inquiries`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ question: question.trim() }),
    });
    await openInquiry(res.inquiry.id);
  } catch (error) {
    showError(error.message || "创建探究失败");
  }
}

async function openInquiry(inquiryId) {
  previousView = { ...currentView };
  currentView = { type: "inquiry", orgId: previousView.orgId, projectId: previousView.projectId, inquiryId };
  try {
    const payload = await requestJson(`/api/inquiries/${inquiryId}`);
    renderInquiryPage(payload);
    if (stream) stream.hidden = true;
    if (composer) composer.hidden = false;
    if (scopeBar) scopeBar.hidden = false;
    setScope(`探究中：${payload.inquiry.question.slice(0, 40)} · 每次发送都是一次尝试`);
    if (memberPage) memberPage.hidden = true;
    if (inquiryPage) inquiryPage.hidden = false;
    if (viewTitle) viewTitle.textContent = "探究";
    if (viewNote) viewNote.textContent = `第 ${payload.attempts.length} 次尝试 · 状态 ${payload.inquiry.status}`;
  } catch (error) {
    showError(error.message || "无法读取探究");
    currentView = previousView;
    showConversationPane();
  }
}

async function backFromInquiry() {
  const prev = previousView || { type: "field" };
  currentView = prev;
  if (prev.type === "project") {
    await switchToProject(prev.orgId, prev.projectId);
  } else {
    await switchToField();
  }
}

async function retryInquiry() {
  if (sending || currentView.type !== "inquiry") return;
  sending = true;
  sendButton.disabled = true;
  clearError();
  try {
    await requestJson(`/api/inquiries/${currentView.inquiryId}/attempts`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ content: "", target: currentTargetId, quoted_occurrence_id: null }),
    });
    await openInquiry(currentView.inquiryId);
  } catch (error) {
    showError(error.message || "尝试未完成");
  } finally {
    sending = false;
    sendButton.disabled = false;
  }
}

function renderInquiryPage(payload) {
  if (!inquiryPageBody) return;
  const inquiry = payload.inquiry;
  const attempts = payload.attempts || [];
  const messages = payload.messages || [];
  const byId = new Map(messages.filter((m) => m.id).map((m) => [m.id, m]));
  const buf = [];

  buf.push(`<div class="mp-section"><span class="fact-label">问题</span>`);
  buf.push(`<p class="inquiry-question"></p></div>`);

  buf.push(`<div class="mp-section"><span class="fact-label">尝试链（${attempts.length} 次）</span><div class="mp-projects" id="inq-attempts"></div></div>`);

  inquiryPageBody.innerHTML = buf.join("");
  const questionEl = inquiryPageBody.querySelector(".inquiry-question");
  if (questionEl) questionEl.textContent = inquiry.question;

  const attemptsNode = document.querySelector("#inq-attempts");
  if (attemptsNode) {
    if (!attempts.length) {
      const p = document.createElement("p");
      p.className = "mp-empty";
      p.textContent = "还没有尝试。在下方输入补充指令，或直接点「再次尝试」。";
      attemptsNode.append(p);
    }
    attempts.forEach((attempt) => {
      attemptsNode.append(makeAttemptCard(attempt, byId.get(attempt.occurrence_id)));
    });
  }
}

function makeAttemptCard(attempt, occurrence) {
  const card = document.createElement("div");
  card.className = "attempt-card";
  const head = document.createElement("div");
  head.className = "attempt-head";
  const title = document.createElement("strong");
  title.textContent = `尝试 ${attempt.attempt_no} · ${attempt.participant_id}`;
  const time = document.createElement("time");
  time.textContent = formatTime(attempt.created_at);
  head.append(title, time);
  card.append(head);

  const report = attempt.working_report || {};
  if (Object.keys(report).length) {
    const grid = document.createElement("div");
    grid.className = "wr-grid";
    [
      ["触及", report.touched],
      ["改变", report.changed],
      ["未展开", report.still_open],
      ["下一次", report.next],
    ].forEach(([label, value]) => {
      if (!value) return;
      const row = document.createElement("div");
      const lab = document.createElement("span");
      lab.textContent = label;
      const val = document.createElement("div");
      val.textContent = String(value);
      row.append(lab, val);
      grid.append(row);
    });
    card.append(grid);
  }

  if (occurrence && occurrence.content) {
    const preview = document.createElement("div");
    preview.className = "attempt-answer";
    preview.textContent = occurrence.content.slice(0, 220) + (occurrence.content.length > 220 ? "…" : "");
    preview.title = "完整回答见第一发生场（occurrence: " + occurrence.id + "）";
    card.append(preview);
  }
  return card;
}

if (inquiryCreateBtn) inquiryCreateBtn.addEventListener("click", createInquiry);
if (inquiryBack) inquiryBack.addEventListener("click", backFromInquiry);
if (inquiryRetry) inquiryRetry.addEventListener("click", retryInquiry);

async function openMember(memberId) {
  previousView = { ...currentView };
  currentView = { type: "member", memberId };
  try {
    const payload = await requestJson(`/api/members/${memberId}`);
    renderMemberPage(payload);
    if (stream) stream.hidden = true;
    if (composer) composer.hidden = true;
    if (scopeBar) scopeBar.hidden = true;
    if (memberPage) memberPage.hidden = false;
    const member = payload.member;
    if (viewTitle) viewTitle.textContent = `成员：${member.name}`;
    if (viewNote) viewNote.textContent = `${member.org_name || "组织"} · ${member.kind === "ai" ? "AI 参与者" : "人类成员"}`;
  } catch (error) {
    showError(error.message || "无法读取成员空间");
    currentView = previousView;
    showConversationPane();
  }
}

async function backFromMember() {
  const prev = previousView || { type: "field" };
  currentView = prev;
  if (prev.type === "project") {
    await switchToProject(prev.orgId, prev.projectId);
  } else {
    await switchToField();
  }
}

function occLine(record) {
  const author = record.author || "?";
  const date = formatTime(record.occurred_at);
  const text = (record.content || "").replace(/\s+/g, " ").trim();
  return `${author} · ${date} · ${text.length > 72 ? text.slice(0, 72) + "…" : text}`;
}

function renderMemberPage(payload) {
  if (!memberPageBody) return;
  const member = payload.member;
  const participant = activeParticipants.find((p) => p.id === member.participant_id);
  const buf = [];

  // 身份与职责
  buf.push(`<div class="mp-section"><span class="fact-label">身份</span>`);
  buf.push(`<div class="mp-grid"><span>名称</span><strong>${member.name}</strong>`);
  buf.push(`<span>类型</span><strong>${member.kind === "ai" ? "AI 参与者" : "人类成员"}</strong>`);
  buf.push(`<span>模型</span><strong>${participant ? participant.model_name + " · " + participant.id : (member.participant_id || "—")}</strong>`);
  buf.push(`<span>所属组织</span><strong>${member.org_name || "—"}</strong></div></div>`);

  // 组织职位/职责
  buf.push(`<div class="mp-section"><span class="fact-label">组织职位 / 职责</span>`);
  buf.push(`<div class="mp-edit-row"><input id="mp-org-role" value="" maxlength="120"><button class="mp-save" id="mp-org-role-save" type="button">保存职位</button></div></div>`);

  // 专属资料
  buf.push(`<div class="mp-section"><span class="fact-label">专属资料</span>`);
  buf.push(`<textarea id="mp-materials" rows="4"></textarea>`);
  buf.push(`<div class="mp-edit-row"><button class="mp-save" id="mp-materials-save" type="button">保存资料</button></div></div>`);

  // 所在项目与负责位置
  buf.push(`<div class="mp-section"><span class="fact-label">所在项目与负责位置</span><div class="mp-projects" id="mp-projects"></div></div>`);

  // 连续性
  buf.push(`<div class="mp-section"><span class="fact-label">连续性（runtime thread）</span><div id="mp-thread" class="mp-thread"></div></div>`);

  // 它的发生
  buf.push(`<div class="mp-section"><span class="fact-label">它的发生（${payload.authored.length}）</span><div class="mp-list" id="mp-authored"></div></div>`);
  // 被指向/面对的
  buf.push(`<div class="mp-section"><span class="fact-label">被指向 / 面对的（${payload.addressed.length}）</span><div class="mp-list" id="mp-addressed"></div></div>`);
  // 引用过的
  buf.push(`<div class="mp-section"><span class="fact-label">引用过的发生（${payload.referenced.length}）</span><div class="mp-list" id="mp-referenced"></div></div>`);

  memberPageBody.innerHTML = buf.join("");

  const roleInput = document.querySelector("#mp-org-role");
  const materialsInput = document.querySelector("#mp-materials");
  if (roleInput) roleInput.value = member.org_role || "";
  if (materialsInput) materialsInput.value = member.materials || "";

  // 项目位置
  const projectsNode = document.querySelector("#mp-projects");
  if (projectsNode) {
    (member.projects || []).forEach((proj) => {
      projectsNode.append(makePositionRow(proj, member.id));
    });
    if (!(member.projects || []).length) {
      const p = document.createElement("p");
      p.className = "mp-empty";
      p.textContent = "尚未被加入任何项目";
      projectsNode.append(p);
    }
  }

  const threadNode = document.querySelector("#mp-thread");
  if (threadNode) {
    if (payload.thread) {
      threadNode.textContent = `文件 ${payload.thread.file} · thread_id ${shortId(payload.thread.thread_id, "未建")} · ${payload.thread.turns ?? 0} turns · ${payload.thread.size ?? 0} B`;
    } else {
      threadNode.textContent = "该成员尚未建立独立 runtime 连续性（或非 AI 成员）";
    }
  }

  fillOccList("#mp-authored", payload.authored);
  fillOccList("#mp-addressed", payload.addressed);
  fillOccList("#mp-referenced", payload.referenced);

  const roleSave = document.querySelector("#mp-org-role-save");
  if (roleSave) {
    roleSave.addEventListener("click", async () => {
      try {
        await requestJson(`/api/orgs/${member.org_id}/members/${member.id}`, {
          method: "PATCH",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ org_role: roleInput.value }),
        });
        await openMember(member.id);
      } catch (error) {
        showError(error.message || "保存职位失败");
      }
    });
  }
  const materialSave = document.querySelector("#mp-materials-save");
  if (materialSave) {
    materialSave.addEventListener("click", async () => {
      try {
        await requestJson(`/api/orgs/${member.org_id}/members/${member.id}`, {
          method: "PATCH",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ materials: materialsInput.value }),
        });
        await openMember(member.id);
      } catch (error) {
        showError(error.message || "保存资料失败");
      }
    });
  }

  if (memberBack) memberBack.addEventListener("click", backFromMember);
}

function makePositionRow(proj, memberId) {
  const row = document.createElement("div");
  row.className = "mp-position-row";
  const title = document.createElement("strong");
  title.textContent = proj.title;
  const input = document.createElement("input");
  input.value = proj.position || "";
  input.placeholder = "项目内负责位置（如：发生层负责人）";
  const save = document.createElement("button");
  save.type = "button";
  save.className = "mp-save";
  save.textContent = "保存位置";
  save.addEventListener("click", async () => {
    try {
      await requestJson(`/api/projects/${proj.id}/members/${memberId}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ position: input.value }),
      });
      save.textContent = "已保存";
    } catch (error) {
      showError(error.message || "保存位置失败");
    }
  });
  row.append(title, input, save);
  return row;
}

function fillOccList(selector, records) {
  const node = document.querySelector(selector);
  if (!node) return;
  if (!records.length) {
    const p = document.createElement("p");
    p.className = "mp-empty";
    p.textContent = "无";
    node.append(p);
    return;
  }
  records.slice().reverse().slice(0, 40).forEach((record) => {
    const line = document.createElement("div");
    line.className = "mp-occurrence";
    line.title = `id: ${record.id}`;
    line.textContent = occLine(record);
    node.append(line);
  });
}

if (memberBack) memberBack.addEventListener("click", backFromMember);

async function switchToProject(orgId, projectId) {
  currentView = { type: "project", orgId, projectId };
  setFieldActive(false);
  showConversationPane();
  if (inquirySection) inquirySection.hidden = false;
  try {
    const payload = await requestJson(`/api/projects/${projectId}/messages`);
    if (viewTitle) viewTitle.textContent = payload.project.title;
    if (viewNote) viewNote.textContent = payload.project.description || `${payload.project.status} · 项目内沟通`;
    setScope(`正在发生于：项目「${payload.project.title}」· 消息写入该项目`);
    setEmptyState("这个项目还没有讨论。", "在这里发出第一条，它将写入该项目。");
    if (payload.quoted) {
      Object.values(payload.quoted).forEach((record) => {
        if (record.id) messagesMap.set(record.id, record);
      });
    }
    renderMessages(payload.messages || []);
    renderState(payload);
    renderMembers(payload.members || []);
    renderOrgNav();
    await loadInquiries(projectId);
    document.querySelector("#runtime-status").textContent = "项目沟通就绪";
  } catch (error) {
    showError(error.message || "无法读取项目发生");
    switchToField();
  }
}

async function createOrg() {
  const name = orgNameInput ? orgNameInput.value.trim() : "";
  if (!name) return;
  try {
    await requestJson("/api/orgs", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ name, description: "" }),
    });
    if (orgNameInput) orgNameInput.value = "";
    await loadOrgData();
  } catch (error) {
    showError(error.message || "创建组织失败");
  }
}

if (fieldEntry) fieldEntry.addEventListener("click", switchToField);
if (orgCreateBtn) orgCreateBtn.addEventListener("click", createOrg);
if (orgNameInput) {
  orgNameInput.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      createOrg();
    }
  });
}
if (orgCreateToggle) {
  orgCreateToggle.addEventListener("click", () => {
    orgCreateToggle.hidden = true;
    orgCreateForm.hidden = false;
    orgNameInput.focus();
  });
}

function showError(message) {
  errorBanner.textContent = message;
  errorBanner.hidden = false;
  runtimePill.classList.add("error");
  document.querySelector("#runtime-status").textContent = "本次连接未完成";
}

function clearError() {
  errorBanner.hidden = true;
  errorBanner.textContent = "";
  runtimePill.classList.remove("error");
}

async function requestJson(url, options) {
  const response = await fetch(url, options);
  let payload = {};
  try {
    payload = await response.json();
  } catch {
    // 非 JSON 响应
  }
  if (!response.ok) {
    throw new Error(payload.detail || `请求失败（${response.status}）`);
  }
  return payload;
}

async function bootstrap() {
  try {
    const payload = await requestJson("/api/bootstrap");
    renderMessages(payload.messages || []);
    renderState(payload);
    document.querySelector("#runtime-status").textContent = "多参与者场已就绪";
  } catch (error) {
    showError(error.message || "无法读取当前发生场");
  }
  await loadOrgData();
}

function currentMessagesUrl() {
  if (currentView.type === "project") {
    return `/api/projects/${currentView.projectId}/messages`;
  }
  if (currentView.type === "inquiry") {
    return `/api/inquiries/${currentView.inquiryId}/attempts`;
  }
  return "/api/messages";
}

function resizeInput() {
  input.style.height = "auto";
  input.style.height = `${Math.min(input.scrollHeight, 190)}px`;

  // 监听输入开头的 @ 触发自动切换
  const text = input.value.trim().toLowerCase();
  for (const p of activeParticipants) {
    if (text.startsWith(`@${p.name.toLowerCase()}`) || text.startsWith(`@${p.id.toLowerCase()}`)) {
      if (currentTargetId !== p.id) {
        currentTargetId = p.id;
        renderTargetPills();
      }
      break;
    }
  }
}

async function sendCurrentMessage(forcedContent) {
  const contentValue = forcedContent !== undefined ? forcedContent : input.value;
  if (sending || !contentValue.trim()) return;

  const content = contentValue;
  const targetId = currentTargetId;
  const targetObj = activeParticipants.find(p => p.id === targetId);
  const targetName = targetObj ? targetObj.name : "AI";
  const quoteId = stagedQuote ? stagedQuote.id : null;

  sending = true;
  sendButton.disabled = true;
  clearError();

  if (emptyState.isConnected) emptyState.remove();
  
  // 渲染乐观的用户发送卡片
  const optimisticUser = {
    id: "pending-user",
    author: "小林",
    target: targetName,
    quoted_occurrence_id: quoteId,
    content,
    occurred_at: new Date().toISOString(),
  };
  stream.append(createMessage(optimisticUser, { pending: true }));
  
  // 插入指定参与者正在回应的加载占位
  const pendingReplyNode = createPendingReply(targetName);
  stream.append(pendingReplyNode);
  stream.scrollTop = stream.scrollHeight;
  
  input.value = "";
  clearStagedQuote();
  resizeInput();

  try {
    if (isHandoffMode) {
      await requestJson("/api/handoff/create", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          instruction: content,
          target_agent: "Antigravity",
          working_directory: null,
          source_refs: quoteId ? [quoteId] : []
        }),
      });
    } else {
      await requestJson(currentMessagesUrl(), {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          content,
          target: targetId,
          quoted_occurrence_id: quoteId,
          search: searchToggle ? searchToggle.checked : false,
          search_query: searchQueryInput ? searchQueryInput.value.trim() || undefined : undefined,
        }),
      });
    }
    if (currentView.type === "project") {
      await switchToProject(currentView.orgId, currentView.projectId);
    } else if (currentView.type === "inquiry") {
      await openInquiry(currentView.inquiryId);
    } else {
      await bootstrap();
    }
  } catch (error) {
    showError(error.message || "消息没有完成送达");
    if (currentView.type === "project") {
      await switchToProject(currentView.orgId, currentView.projectId);
    } else if (currentView.type === "inquiry") {
      await openInquiry(currentView.inquiryId);
    } else {
      await bootstrap();
    }
  } finally {
    sending = false;
    sendButton.disabled = false;
    input.focus();
  }
}

composer.addEventListener("submit", (event) => {
  event.preventDefault();
  sendCurrentMessage();
});

input.addEventListener("input", resizeInput);
input.addEventListener("input", updateMention);
input.addEventListener("keydown", (event) => {
  if (mentionOpen && !event.isComposing) {
    if (event.key === "ArrowDown") {
      event.preventDefault();
      mentionIndex = (mentionIndex + 1) % mentionItems.length;
      renderMentionPopup();
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      mentionIndex = (mentionIndex - 1 + mentionItems.length) % mentionItems.length;
      renderMentionPopup();
    } else if (event.key === "Enter") {
      event.preventDefault();
      selectMention(mentionIndex);
    } else if (event.key === "Escape") {
      event.preventDefault();
      closeMention();
    }
    return;
  }
  if (event.key === "Enter" && !event.shiftKey && !event.isComposing) {
    event.preventDefault();
    sendCurrentMessage();
  }
});

setFieldActive(true);
bootstrap();
input.focus();

