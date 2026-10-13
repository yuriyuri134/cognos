function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, (char) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
  })[char]);
}

const stream = document.querySelector("#message-stream");
const memberPage = document.querySelector("#member-page");
const memberPageBody = document.querySelector("#member-page-body");
const socialPage = document.querySelector("#social-page");
const socialPageBody = document.querySelector("#social-page-body");
const socialDirectoryBtn = document.querySelector("#social-directory-btn");
const socialInvitationsBtn = document.querySelector("#social-invitations-btn");
const socialBack = document.querySelector("#social-back");
const invitationCount = document.querySelector("#invitation-count");
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
const directBack = document.querySelector("#direct-back");
const scopeBar = document.querySelector("#scope-bar");
const scopeLabel = document.querySelector("#scope-label");
const projectTabs = document.querySelector("#project-tabs");
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
const activityOpenBtn = document.querySelector("#activity-open-btn");
let latestActivityId = null;
activityOpenBtn?.addEventListener("click", () => {
  if (!currentView.projectId) return;
  const activityPart = latestActivityId ? `&activity=${encodeURIComponent(latestActivityId)}` : "";
  window.location.href = `/activities?project=${encodeURIComponent(currentView.projectId)}${activityPart}`;
});

let sending = false;
let activeTraceElement = null;
let activeParticipants = [];
let currentTargetId = "gemini";
let stagedQuote = null;
let isHandoffMode = false;
let messagesMap = new Map();
let orgData = null;
let currentUser = "";
let currentAccount = null;
async function refreshAuth() {
  currentAccount = null;
  try {
    const r = await fetch("/api/auth/me");
    if (r.ok) currentAccount = (await r.json()).account || null;
  } catch { /* 暂时无法核实会话时要求重新登录。 */ }
  currentUser = currentAccount?.display_name || "";
  const lab = document.querySelector("#current-user-label");
  if (lab) lab.textContent = currentAccount ? `${currentUser} (@${currentAccount.username})` : "未登录";
  if (authLogoutBtn) authLogoutBtn.hidden = !currentAccount;
  if (authOpenBtn) authOpenBtn.hidden = !!currentAccount;
  return currentAccount;
}

let currentView = { type: "field" };
let previousView = { type: "field" };
let socialMode = "directory";
let currentMembers = [];
let projectChannels = [];
let projectChannelsProjectId = null;
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

async function refreshActivityEntry(projectId) {
  if (!activityOpenBtn) return;
  latestActivityId = null;
  activityOpenBtn.textContent = "共同展开 · 新活动";
  activityOpenBtn.title = "进入这个项目的活动工作台";
  try {
    const payload = await requestJson(`/api/projects/${projectId}/activities`);
    const latest = (payload.activities || [])[0];
    if (!latest) return;
    latestActivityId = latest.id;
    const title = latest.title.length > 22 ? `${latest.title.slice(0, 22)}…` : latest.title;
    activityOpenBtn.textContent = `继续活动 · ${title}`;
    activityOpenBtn.title = `最近活动更新于 ${formatTime(latest.created_at)}；直接回到上次继续点`;
  } catch (error) {
    console.warn("活动入口加载失败", error);
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
  const isAI = !!record.author_participant_id || !!record.runtime_ref;
  const isUser = !isAI && (record.author_account_id ? currentAccount?.id === record.author_account_id : currentUser && record.author === currentUser);
  const authorName = record.author || "未知参与者";
  
  node.classList.toggle("user", isUser);
  node.classList.toggle("pending", pending);
  if (isAI) node.classList.add(`author-${String(record.author_participant_id || authorName).toLowerCase().replace(/[^a-z0-9_-]/g, "-")}`);
  
  node.dataset.messageId = record.id || "pending";
  
  const authorEl = node.querySelector(".author");
  if (isUser && record.target) {
    authorEl.textContent = `${currentUser} → @${record.target}`;
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
      <strong class="qp-author">@${escapeHtml(qAuthor)}</strong>
      <code class="qp-id">${escapeHtml(shortId(record.quoted_occurrence_id, ""))}</code>
      ${shortText ? `<span class="qp-text">${escapeHtml(shortText)}</span>` : ""}
    `;
  }
  
  const reqPreview = node.querySelector(".handoff-request-preview");
  if (reqPreview && record.handoff_request) {
    reqPreview.hidden = false;
    reqPreview.innerHTML = `
      <div class="handoff-box" style="margin-top:12px; padding:12px; border-left:3px solid var(--accent); background:rgba(255,255,255,0.05); border-radius:4px;">
        <strong style="color:var(--accent);">⚡ 外部交接请求</strong>
        <div style="font-size:0.9em; margin-top:4px;">
          <span>目标: <code>${escapeHtml(record.handoff_request.target_agent || "未知")}</code></span>
          <span style="margin-left:8px;">上下文: <code>${escapeHtml((record.handoff_request.source_refs || []).map(id => shortId(id, "")).join(", "))}</code></span>
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
          <span>变更文件: <code>${(record.handoff_result.changed_files || []).length}</code></span>
          <span style="margin-left:8px;">运行命令: <code>${(record.handoff_result.commands_run || []).length}</code></span>
        </div>
      </div>
    `;
  }

  const body = node.querySelector(".message-body");
  renderContent(body, record.content, !isAI);

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

  const activityBtn = node.querySelector(".activity-button");
  if (activityBtn) {
    if (pending || !record.id || !currentView.projectId) {
      activityBtn.hidden = true;
    } else {
      activityBtn.addEventListener("click", async () => {
        activityBtn.disabled = true;
        try {
          const created = await requestJson(`/api/projects/${encodeURIComponent(currentView.projectId)}/activities/from-occurrence`, {
            method: "POST",
            body: JSON.stringify({ occurrence_id: record.id, title: `从「${(record.content || "").slice(0, 24)}」开始` }),
          });
          window.location.href = `/activities?project=${encodeURIComponent(currentView.projectId)}&activity=${encodeURIComponent(created.activity.id)}`;
        } catch (error) {
          activityBtn.disabled = false;
          showError(error.message || "无法从这条发生开始活动");
        }
      });
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
  fallback.innerHTML = `<div class="message-meta"><span class="author">${escapeHtml(targetName || "AI")}</span><time>正在回应</time></div><div class="message-body">正在回应…</div>`;
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
    btn.innerHTML = `<span class="pill-dot"></span>@${escapeHtml(p.name)}`;
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
    btn.innerHTML = `<span>@${escapeHtml(item.label)}</span><span class="mention-kind">${escapeHtml(item.kind)}</span>`;
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
        <strong class="p-name">@${escapeHtml(p.name)}</strong>
        <span class="p-model">${escapeHtml(p.model_name || "")}</span>
      </div>
      <div class="p-thread">
        <span class="p-label">Thread:</span>
        <code title="${escapeHtml(p.thread_id || "")}">${escapeHtml(shortId(p.thread_id, "尚未建立"))}</code>
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
    chip.title = `打开 ${m.name} 的成员主页`;
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

function setProjectTabsVisible(visible, activeTab) {
  if (!projectTabs) return;
  projectTabs.hidden = !visible;
  if (activeTab) setActiveTab(activeTab);
}

function setActiveTab(tab) {
  if (!projectTabs) return;
  projectTabs.querySelectorAll(".view-tab").forEach((btn) => {
    btn.classList.toggle("active", btn.dataset.tab === tab);
  });
}

async function loadProjectChannels(projectId) {
  try {
    const payload = await requestJson(`/api/projects/${projectId}/conversations`);
    projectChannels = payload.conversations || [];
    projectChannelsProjectId = projectId;
  } catch (error) {
    projectChannels = [];
    projectChannelsProjectId = null;
    console.warn("频道加载失败", error);
  }
  renderOrgNav();
}

function buildChannelList(org, project) {
  const wrap = document.createElement("div");
  wrap.className = "project-channels";
  projectChannels.forEach((channel) => {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "channel-item";
    const isActive =
      (currentView.type === "conversation" && currentView.conversationId === channel.id) ||
      (channel.is_default && currentView.type === "project" && currentView.projectId === project.id);
    if (isActive) btn.classList.add("active");
    const glyph = document.createElement("span");
    glyph.className = "channel-glyph";
    glyph.textContent = "#";
    const title = document.createElement("span");
    title.textContent = channel.title;
    btn.append(glyph, title);
    btn.title = channel.is_default ? "项目默认频道" : channel.title;
    btn.addEventListener("click", () => {
      if (channel.is_default) {
        switchToProject(org.id, project.id);
      } else {
        openConversation(org.id, project.id, channel);
      }
    });
    wrap.append(btn);
  });

  const addBtn = document.createElement("button");
  addBtn.type = "button";
  addBtn.className = "channel-add";
  addBtn.textContent = "＋ 新频道";
  addBtn.addEventListener("click", () => createChannelFor(org, project));
  wrap.append(addBtn);
  return wrap;
}

async function createChannelFor(org, project) {
  const title = window.prompt(`在「${project.title}」中新建频道，名称：`);
  if (!title || !title.trim()) return;
  try {
    const res = await requestJson(`/api/projects/${project.id}/conversations`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ title: title.trim(), kind: "channel" }),
    });
    await loadProjectChannels(project.id);
    await openConversation(org.id, project.id, res.conversation);
  } catch (error) {
    showError(error.message || "创建频道失败");
  }
}

async function openConversation(orgId, projectId, conversation) {
  currentView = { type: "conversation", orgId, projectId, conversationId: conversation.id, title: conversation.title };
  setFieldActive(false);
  showConversationPane();
  setProjectTabsVisible(true, "discussion");
  if (directBack) directBack.hidden = true;
  if (inquirySection) inquirySection.hidden = false;
  try {
    const [projectPayload, payload] = await Promise.all([
      requestJson(`/api/projects/${projectId}/messages`),
      requestJson(`/api/conversations/${conversation.id}/messages`),
    ]);
    const projectTitle = projectPayload.project ? projectPayload.project.title : "";
    if (viewTitle) viewTitle.textContent = `${projectTitle} · #${conversation.title}`;
    if (viewNote) viewNote.textContent = `${payload.messages.length} 条发生 · 频道内沟通`;
    setScope(`正在发生于：项目「${projectTitle}」· 频道 #${conversation.title}`);
    setEmptyState("这个频道还没有消息。", "在这里发出第一条，它将写入该频道。");
    if (payload.quoted) {
      Object.values(payload.quoted).forEach((record) => {
        if (record.id) messagesMap.set(record.id, record);
      });
    }
    renderMessages(payload.messages || []);
    renderState(projectPayload);
    renderMembers(projectPayload.members || []);
    renderOrgNav();
    document.querySelector("#runtime-status").textContent = "频道沟通就绪";
  } catch (error) {
    showError(error.message || "无法读取频道发生");
    switchToProject(orgId, projectId);
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
      if (currentView.projectId === project.id) {
        btn.classList.add("active");
      }
      btn.innerHTML = `<span class="project-glyph">◌</span><span class="project-title">${escapeHtml(project.title)}</span>`;
      btn.title = project.description || project.title;
      btn.addEventListener("click", () => switchToProject(org.id, project.id));
      group.append(btn);
      if (currentView.projectId === project.id && projectChannelsProjectId === project.id) {
        group.append(buildChannelList(org, project));
      }
    });
    orgNav.append(group);
  });
}

async function switchToField() {
  currentView = { type: "field" };
  setFieldActive(true);
  setProjectTabsVisible(false);
  projectChannels = [];
  projectChannelsProjectId = null;
  if (viewTitle) viewTitle.textContent = "第一发生场";
  if (viewNote) viewNote.textContent = "同一发生场内多参与者共在；原文保存在本地 Vault";
  if (membersBlock) membersBlock.hidden = true;
  if (directBack) directBack.hidden = true;
  if (inquirySection) inquirySection.hidden = true;
  setScope("正在发生于：第一发生场（全局）");
  setEmptyState("从正在牵引你的地方开始。", "无需选择类型，也无需先把它整理成任务。");
  showConversationPane();
  document.dispatchEvent(new CustomEvent("cognos:scope-field"));
  await loadOrgData();
  await bootstrap();
}

function showConversationPane() {
  if (memberPage) memberPage.hidden = true;
  if (socialPage) socialPage.hidden = true;
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
      btn.innerHTML = `<span class="project-glyph">◎</span><span class="project-title">${escapeHtml(inquiry.question.slice(0, 40))}</span>`;
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
    setProjectTabsVisible(false);
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

async function openSocial(mode = "directory") {
  if (!currentAccount) { openAuth(); return; }
  previousView = { ...currentView };
  currentView = { type: "social", mode };
  socialMode = mode;
  if (stream) stream.hidden = true;
  if (composer) composer.hidden = true;
  if (scopeBar) scopeBar.hidden = true;
  if (memberPage) memberPage.hidden = true;
  if (inquiryPage) inquiryPage.hidden = true;
  if (socialPage) socialPage.hidden = false;
  setProjectTabsVisible(false);
  if (viewTitle) viewTitle.textContent = mode === "invitations" ? "待处理邀请" : "成员目录与私信";
  if (viewNote) viewNote.textContent = "搜索成员、加入组织，或继续一对一沟通。";
  try {
    const [accountsPayload, invitationsPayload, directPayload] = await Promise.all([
      requestJson("/api/auth/accounts"),
      requestJson("/api/invitations"),
      requestJson("/api/direct-conversations"),
    ]);
    const pending = (invitationsPayload.invitations || []).filter((item) => item.status === "pending");
    if (invitationCount) invitationCount.textContent = pending.length ? `(${pending.length})` : "";
    renderSocialPage(accountsPayload.accounts || [], invitationsPayload.invitations || [], directPayload.conversations || []);
  } catch (error) { showError(error.message || "协作入口读取失败"); }
}

function renderSocialPage(accounts, invitations, directConversations) {
  if (!socialPageBody) return;
  socialPageBody.replaceChildren();
  const directory = document.createElement("section"); directory.className = "wb-section";
  directory.innerHTML = '<div class="wb-section-head"><h2>成员目录</h2><span class="wb-hint">按用户名或显示名搜索</span></div>';
  const search = document.createElement("input"); search.className = "wb-search"; search.placeholder = "搜索成员…"; search.type = "search";
  const list = document.createElement("div"); list.className = "wb-list";
  const renderAccounts = (items) => {
    list.replaceChildren();
    items.filter((item) => item.id !== currentAccount?.id).forEach((item) => {
      const row = document.createElement("div"); row.className = "wb-item social-account-row";
      const info = document.createElement("div"); info.innerHTML = `<strong>${escapeHtml(item.display_name)}</strong><span class="wb-hint">@${escapeHtml(item.username)}</span>`;
      const actions = document.createElement("div"); actions.className = "wb-item-controls";
      const inviteOrg = document.createElement("select"); inviteOrg.className = "wb-compact-select";
      const orgs = orgData || [];
      orgs.forEach((org) => { const option = document.createElement("option"); option.value = org.id; option.textContent = org.name; inviteOrg.append(option); });
      const invite = document.createElement("button"); invite.className = "wb-action"; invite.textContent = "邀请入组织"; invite.disabled = !orgs.length;
      invite.addEventListener("click", async () => {
        if (!inviteOrg.value) { showError("请先创建组织"); return; }
        try {
          await requestJson(`/api/orgs/${inviteOrg.value}/invitations`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ invitee_account_id: item.id }) });
          invite.textContent = "已发送"; invite.disabled = true;
        } catch (error) { showError(error.message || "邀请失败"); }
      });
      const dm = document.createElement("button"); dm.className = "wb-primary"; dm.textContent = "发起私信";
      dm.addEventListener("click", async () => {
        try {
          const result = await requestJson("/api/direct-conversations", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ account_id: item.id }) });
          await openDirectConversation(result.conversation, item);
        } catch (error) { showError(error.message || "私信创建失败"); }
      });
      actions.append(inviteOrg, invite, dm); row.append(info, actions); list.append(row);
    });
    if (!list.children.length) list.textContent = "没有匹配的成员。";
  };
  renderAccounts(accounts);
  search.addEventListener("input", () => { const q = search.value.trim().toLowerCase(); renderAccounts(accounts.filter((item) => `${item.username} ${item.display_name}`.toLowerCase().includes(q))); });
  directory.append(search, list); socialPageBody.append(directory);

  const invites = document.createElement("section"); invites.className = "wb-section"; invites.innerHTML = '<div class="wb-section-head"><h2>我的组织邀请</h2></div>';
  invitations.filter((item) => item.status === "pending").forEach((item) => {
    const row = document.createElement("div"); row.className = "wb-item"; row.innerHTML = `<strong>${escapeHtml(item.org_name)}</strong><span class="wb-hint">邀请人：${escapeHtml(item.inviter_account_id)}</span>`;
    const accept = document.createElement("button"); accept.className = "wb-primary"; accept.textContent = "接受加入";
    accept.addEventListener("click", async () => { await requestJson(`/api/invitations/${item.id}/respond`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ accept: true }) }); await openSocial("invitations"); });
    const decline = document.createElement("button"); decline.className = "wb-action"; decline.textContent = "拒绝";
    decline.addEventListener("click", async () => { await requestJson(`/api/invitations/${item.id}/respond`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ accept: false }) }); await openSocial("invitations"); });
    row.append(accept, decline); invites.append(row);
  });
  if (!invites.querySelector(".wb-item")) invites.append(document.createTextNode("暂无待处理邀请。"));
  socialPageBody.append(invites);

  const dms = document.createElement("section"); dms.className = "wb-section"; dms.innerHTML = '<div class="wb-section-head"><h2>最近私信</h2></div>';
  directConversations.forEach((conversation) => { const button = document.createElement("button"); button.className = "wb-item social-dm"; button.textContent = conversation.other_account?.display_name || "成员"; button.addEventListener("click", () => openDirectConversation(conversation, conversation.other_account)); dms.append(button); });
  if (!directConversations.length) dms.append(document.createTextNode("还没有私信。先从成员目录搜索一个人。"));
  socialPageBody.append(dms);
}

async function openDirectConversation(conversation, otherAccount) {
  previousView = { ...currentView }; currentView = { type: "direct", conversationId: conversation.id, title: otherAccount?.display_name || "私信" };
  if (socialPage) socialPage.hidden = true; showConversationPane();
  if (directBack) directBack.hidden = false;
  if (viewTitle) viewTitle.textContent = `私信 · ${otherAccount?.display_name || "成员"}`;
  if (viewNote) viewNote.textContent = "一对一沟通；消息会保留在原始发生记录中。";
  try { const payload = await requestJson(`/api/direct-conversations/${conversation.id}/messages`); renderMessages(payload.messages || []); setEmptyState("还没有私信", "在下方发出第一条消息。"); } catch (error) { showError(error.message || "私信读取失败"); }
}

if (socialDirectoryBtn) socialDirectoryBtn.addEventListener("click", () => openSocial("directory"));
if (socialInvitationsBtn) socialInvitationsBtn.addEventListener("click", () => openSocial("invitations"));
if (socialBack) socialBack.addEventListener("click", () => { currentView = previousView || { type: "field" }; showConversationPane(); bootstrap(); });
if (directBack) directBack.addEventListener("click", () => openSocial("directory"));

async function openMember(memberId) {
  if (currentView.type !== "member") previousView = { ...currentView };
  currentView = { type: "member", memberId };
  try {
    const payload = await requestJson(`/api/members/${memberId}`);
    renderMemberPage(payload);
    document.dispatchEvent(new CustomEvent("cognos:hide-workbench"));
    if (stream) stream.hidden = true;
    if (composer) composer.hidden = true;
    if (scopeBar) scopeBar.hidden = true;
    setProjectTabsVisible(false);
    if (memberPage) memberPage.hidden = false;
    const member = payload.member;
    if (viewTitle) viewTitle.textContent = `成员：${member.name}`;
    if (viewNote) viewNote.textContent = `${member.org_name || "组织"} · ${member.kind === "ai" ? "AI 参与者" : "人类成员"}`;
  } catch (error) {
    showError(error.message || "无法读取成员主页");
    currentView = previousView;
    showConversationPane();
  }
}

async function backFromMember() {
  const prev = previousView || { type: "field" };
  currentView = prev;
  if (prev.type === "project") {
    await switchToProject(prev.orgId, prev.projectId);
    if (prev.tab && prev.tab !== "discussion") {
      setActiveTab(prev.tab);
      document.dispatchEvent(new CustomEvent("cognos:workbench-tab", { detail: { tab: prev.tab } }));
    }
  } else if (prev.type === "conversation") {
    await openConversation(prev.orgId, prev.projectId, { id: prev.conversationId, title: prev.title });
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
  buf.push(`<div class="mp-grid"><span>名称</span><strong>${escapeHtml(member.name)}</strong>`);
  buf.push(`<span>类型</span><strong>${member.kind === "ai" ? "AI 参与者" : "人类成员"}</strong>`);
  buf.push(`<span>模型</span><strong>${escapeHtml(participant ? participant.model_name + " · " + participant.id : (member.participant_id || "—"))}</strong>`);
  buf.push(`<span>所属组织</span><strong>${escapeHtml(member.org_name || "—")}</strong></div></div>`);

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

}

// ---------- 登录 / 注册 ----------
const authOverlay = document.querySelector("#auth-overlay");
const authOpenBtn = document.querySelector("#auth-open-btn");
const authLogoutBtn = document.querySelector("#auth-logout-btn");
const authCloseBtn = document.querySelector("#auth-close-btn");
const authError = document.querySelector("#auth-error");
const authDisplay = document.querySelector("#auth-display");
const authUsername = document.querySelector("#auth-username");
const authPassword = document.querySelector("#auth-password");

function openAuth() {
  if (authOverlay) authOverlay.hidden = false;
  if (authUsername) authUsername.focus();
}
function closeAuth() {
  if (authOverlay) authOverlay.hidden = true;
  if (authPassword) authPassword.value = "";
  if (authError) { authError.hidden = true; authError.textContent = ""; }
}
function showAuthError(msg) {
  if (!authError) return;
  authError.textContent = msg;
  authError.hidden = false;
}
async function submitAuth(kind) {
  const username = (authUsername && authUsername.value || "").trim();
  const password = authPassword ? authPassword.value : "";
  const display = (authDisplay && authDisplay.value || "").trim();
  if (!username || !password) { showAuthError("请输入用户名和密码"); return; }
  try {
    await requestJson(`/api/auth/${kind}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ username, password, display_name: display }),
    });
    await refreshAuth();
    closeAuth();
    await reloadCurrentView();
  } catch (error) {
    showAuthError(error.message || (kind === "login" ? "登录失败" : "注册失败"));
  }
}
if (authOpenBtn) authOpenBtn.addEventListener("click", openAuth);
if (authCloseBtn) authCloseBtn.addEventListener("click", closeAuth);
if (authOverlay) authOverlay.addEventListener("click", (event) => { if (event.target === authOverlay) closeAuth(); });
if (authUsername) authUsername.addEventListener("keydown", (event) => { if (event.key === "Enter") submitAuth("login"); });
if (authPassword) authPassword.addEventListener("keydown", (event) => { if (event.key === "Enter") submitAuth("login"); });
if (document.querySelector("#auth-login-btn")) document.querySelector("#auth-login-btn").addEventListener("click", () => submitAuth("login"));
if (document.querySelector("#auth-register-btn")) document.querySelector("#auth-register-btn").addEventListener("click", () => submitAuth("register"));
if (authLogoutBtn) authLogoutBtn.addEventListener("click", async () => {
  try {
    await requestJson("/api/auth/logout", { method: "POST" });
    await refreshAuth();
    await reloadCurrentView();
  } catch (error) { showError(error.message || "登出失败"); }
});

async function reloadCurrentView() {
  const view = { ...currentView };
  if (view.type === "project") {
    await switchToProject(view.orgId, view.projectId);
    if (view.tab && view.tab !== "discussion") {
      setActiveTab(view.tab);
      document.dispatchEvent(new CustomEvent("cognos:workbench-tab", { detail: { tab: view.tab } }));
    }
  } else if (view.type === "conversation") {
    await openConversation(view.orgId, view.projectId, { id: view.conversationId, title: view.title });
  } else if (view.type === "inquiry") await openInquiry(view.inquiryId);
  else if (view.type === "member") await openMember(view.memberId);
  else await bootstrap();
}

document.addEventListener("cognos:login-required", () => { refreshAuth(); openAuth(); });
document.addEventListener("cognos:open-member", (event) => openMember(event.detail.memberId));
document.addEventListener("keydown", (event) => { if (event.key === "Escape") closeAuth(); });

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
  if (directBack) directBack.hidden = true;
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
    setProjectTabsVisible(true, "discussion");
    await refreshActivityEntry(projectId);
    await loadProjectChannels(projectId);
    renderOrgNav();
    await loadInquiries(projectId);
    document.querySelector("#runtime-status").textContent = "项目沟通就绪";
    document.dispatchEvent(new CustomEvent("cognos:project-changed", {
      detail: { orgId, projectId, project: payload.project, members: payload.members || [] },
    }));
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
    if (response.status === 401 && !url.startsWith("/api/auth/")) {
      document.dispatchEvent(new CustomEvent("cognos:login-required"));
    }
    const detail = Array.isArray(payload.detail) ? payload.detail.map((item) => item.msg).join("；") : payload.detail;
    throw new Error(detail || `请求失败（${response.status}）`);
  }
  return payload;
}

async function bootstrap() {
  await refreshAuth();
  if (authLogoutBtn) authLogoutBtn.hidden = currentAccount ? false : true;
  try {
    const payload = await requestJson("/api/bootstrap");
    window.__cognosParticipants = payload.participants || [];
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
  if (currentView.type === "conversation") {
    return `/api/conversations/${currentView.conversationId}/messages`;
  }
  if (currentView.type === "direct") {
    return `/api/direct-conversations/${currentView.conversationId}/messages`;
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
  if (!currentAccount) { openAuth(); return; }

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
    author: currentUser,
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
    } else if (currentView.type === "conversation") {
      await openConversation(currentView.orgId, currentView.projectId, { id: currentView.conversationId, title: currentView.title });
    } else if (currentView.type === "inquiry") {
      await openInquiry(currentView.inquiryId);
    } else if (currentView.type === "direct") {
      const payload = await requestJson(`/api/direct-conversations/${currentView.conversationId}/messages`);
      renderMessages(payload.messages || []);
    } else {
      await bootstrap();
    }
  } catch (error) {
    showError(error.message || "消息没有完成送达");
    if (currentView.type === "project") {
      await switchToProject(currentView.orgId, currentView.projectId);
    } else if (currentView.type === "conversation") {
      await openConversation(currentView.orgId, currentView.projectId, { id: currentView.conversationId, title: currentView.title });
    } else if (currentView.type === "inquiry") {
      await openInquiry(currentView.inquiryId);
    } else if (currentView.type === "direct") {
      const payload = await requestJson(`/api/direct-conversations/${currentView.conversationId}/messages`);
      renderMessages(payload.messages || []);
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

if (projectTabs) {
  projectTabs.addEventListener("click", (event) => {
    const btn = event.target.closest(".view-tab");
    if (!btn) return;
    const tab = btn.dataset.tab;
    currentView.tab = tab;
    if (tab === "discussion") {
      setActiveTab("discussion");
      document.dispatchEvent(new CustomEvent("cognos:show-discussion"));
      if (currentView.type === "conversation") {
        openConversation(currentView.orgId, currentView.projectId, {
          id: currentView.conversationId,
          title: currentView.title,
        });
      } else if (currentView.type === "project") {
        switchToProject(currentView.orgId, currentView.projectId);
      } else {
        showConversationPane();
      }
    } else {
      setActiveTab(tab);
      document.dispatchEvent(new CustomEvent("cognos:workbench-tab", { detail: { tab } }));
    }
  });
}

document.addEventListener("cognos:discussion-tab", () => { currentView.tab = "discussion"; setActiveTab("discussion"); });

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
