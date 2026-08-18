const stream = document.querySelector("#message-stream");
const emptyState = document.querySelector("#empty-state");
const composer = document.querySelector("#composer");
const input = document.querySelector("#message-input");
const sendButton = document.querySelector("#send-button");
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

let sending = false;
let activeTraceElement = null;
let activeParticipants = [];
let currentTargetId = "gemini";
let stagedQuote = null;
let isHandoffMode = false;
let messagesMap = new Map();
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
    trace.textContent = `${record.runtime_ref.runtime || "ai"} · ${shortId(record.runtime_ref.turn_id, "未知")}`;
    trace.title = `点击关联右栏 Trace\nruntime: ${record.runtime_ref.runtime}\nthread: ${record.runtime_ref.thread_id}\nturn: ${record.runtime_ref.turn_id}`;
    trace.addEventListener("click", () => inspectTrace(record.runtime_ref, trace));
  }
  return node;
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

async function sendCurrentMessage() {
  if (sending || !input.value.trim()) return;

  const content = input.value;
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
      await requestJson("/api/messages", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          content,
          target: targetId,
          quoted_occurrence_id: quoteId,
        }),
      });
    }
    await bootstrap();
  } catch (error) {
    showError(error.message || "消息没有完成送达");
    await bootstrap();
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
input.addEventListener("keydown", (event) => {
  if (event.key === "Enter" && !event.shiftKey && !event.isComposing) {
    event.preventDefault();
    sendCurrentMessage();
  }
});

bootstrap();
input.focus();

