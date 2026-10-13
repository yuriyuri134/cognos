/**
 * 项目工作台（Organization Slice 1）
 *
 * 把一条真实工作流放在一个页面里：消息 → 决定 → 工作项 → 交接 → 结果 → 时间线。
 * 这些是普通软件层对象：Task 不等于 Attempt，Channel 不等于 Space。
 * 真相源仍是 Vault 第一发生场.jsonl；本模块只调用既有 API 做投影与 CRUD。
 */
(() => {
  const page = document.querySelector("#project-page");
  const body = document.querySelector("#project-page-body");
  const backBtn = document.querySelector("#project-back");
  const refreshBtn = document.querySelector("#workbench-refresh");
  const stream = document.querySelector("#message-stream");
  const composer = document.querySelector("#composer");
  const scopeBar = document.querySelector("#scope-bar");

  if (!page || !body) return;

  const STATUS_LABELS = {
    open: "待开始",
    in_progress: "进行中",
    blocked: "受阻",
    done: "完成",
  };

  const KIND_LABELS = {
    message: "发生",
    decision: "决定",
    work_item: "工作项",
    handoff: "交接",
    result: "结果",
  };

  const state = {
    orgId: null,
    projectId: null,
    project: null,
    members: [],
    messages: [],
    participants: [],
    defaultTarget: null,
    decisions: [],
    workItems: [],
    timeline: [],
    open: false,
    activeTab: "work_items",
    notice: "",
    error: "",
  };

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }

  function option(label, value) {
    const node = document.createElement("option");
    node.textContent = label;
    node.value = value;
    return node;
  }

  function shortId(value, fallback) {
    if (!value) return fallback || "";
    return value.length <= 28 ? value : `${value.slice(0, 12)}…${value.slice(-8)}`;
  }

  function formatTime(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "时间未知";
    const now = new Date();
    const sameDay = date.toDateString() === now.toDateString();
    const time = new Intl.DateTimeFormat("zh-CN", {
      hour: "2-digit",
      minute: "2-digit",
      hour12: false,
    }).format(date);
    if (sameDay) return `今天 ${time}`;
    const md = new Intl.DateTimeFormat("zh-CN", { month: "2-digit", day: "2-digit" }).format(date);
    return `${md} ${time}`;
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
      if (response.status === 401) document.dispatchEvent(new CustomEvent("cognos:login-required"));
      const detail = Array.isArray(payload.detail) ? payload.detail.map((item) => item.msg).join("；") : payload.detail;
      throw new Error(detail || `请求失败（${response.status}）`);
    }
    return payload;
  }

  function postJson(url, payload, method) {
    return requestJson(url, {
      method: method || "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
  }

  async function fetchData() {
    if (!state.projectId) return;
    const projectId = state.projectId;
    const [messages, decisions, workItems, timeline] = await Promise.all([
      requestJson(`/api/projects/${projectId}/messages`),
      requestJson(`/api/projects/${projectId}/decisions`),
      requestJson(`/api/projects/${projectId}/work-items`),
      requestJson(`/api/projects/${projectId}/timeline`),
    ]);
    if (state.projectId !== projectId) return; // 期间已切走
    state.project = messages.project || state.project;
    state.members = messages.members || [];
    state.messages = messages.messages || [];
    state.participants = messages.participants || [];
    state.defaultTarget = messages.default_target || state.participants[0]?.id || null;
    state.decisions = decisions.decisions || [];
    state.workItems = workItems.work_items || [];
    state.timeline = timeline.events || [];
  }

  async function refresh({ keepNotice = false } = {}) {
    try {
      await fetchData();
      if (!keepNotice) state.notice = "";
      state.error = "";
      if (state.open) render();
    } catch (error) {
      state.error = error.message || "工作台数据读取失败";
      if (state.open) render();
    }
  }

  function openPage() {
    state.open = true;
    state.notice = "";
    state.error = "";
    page.hidden = false;
    if (stream) stream.hidden = true;
    if (composer) composer.hidden = true;
    if (scopeBar) scopeBar.hidden = true;
    render();
  }

  function closePage() {
    state.open = false;
    page.hidden = true;
    if (stream) stream.hidden = false;
    if (composer) composer.hidden = false;
    if (scopeBar) scopeBar.hidden = false;
  }

  // ---------- 渲染 ----------

  function render() {
    body.replaceChildren();
    if (state.notice) body.append(el("div", "wb-notice", state.notice));
    if (state.error) body.append(el("div", "wb-error", state.error));
    if (!state.projectId) {
      body.append(el("p", "wb-empty", "尚未进入项目。"));
      return;
    }

    const summary = el("div", "wb-summary");
    summary.append(el("strong", null, state.project ? state.project.title : "项目"));
    summary.append(
      el(
        "span",
        "wb-count",
        `${state.workItems.length} 个工作项 · ${state.decisions.length} 个决定 · ${state.timeline.length} 条时间线事件`
      )
    );
    body.append(summary);

    if (state.activeTab === "discussion") {
      body.append(renderDiscussion());
    } else if (state.activeTab === "members") {
      body.append(renderProjectMembers());
    } else if (state.activeTab === "decisions") {
      body.append(renderDecisions());
    } else if (state.activeTab === "timeline") {
      body.append(renderTimeline());
    } else {
      body.append(renderWorkItems());
    }
  }

  function renderDiscussion() {
    const section = el("section", "wb-section");
    const head = el("div", "wb-section-head");
    head.append(el("h2", null, `团队讨论（${state.messages.length}）`));
    head.append(el("span", "wb-hint", "选择 Agent 后发送，Agent 才进入项目讨论"));
    section.append(head);
    const list = el("div", "wb-discussion-list");
    state.messages.forEach((message) => {
      const row = el("article", "wb-discussion-message");
      row.append(el("strong", null, message.author || "未知参与者"));
      row.append(el("time", "wb-hint", formatTime(message.occurred_at)));
      row.append(el("p", null, message.content || ""));
      list.append(row);
    });
    if (!state.messages.length) list.append(el("p", "wb-empty", "讨论还没有发生。可以先选择一个 Agent 发起。"));
    section.append(list);
    const form = el("form", "wb-form");
    const target = el("select");
    state.participants.forEach((participant) => target.append(option(`${participant.name} · ${participant.model_name}`, participant.id)));
    if (state.defaultTarget) target.value = state.defaultTarget;
    const input = el("textarea"); input.rows = 3; input.placeholder = "在团队讨论中 @ Agent，继续当前项目……"; input.required = true;
    const send = el("button", "wb-primary", "发送讨论"); send.type = "submit";
    form.append(target, input, send);
    form.addEventListener("submit", async (event) => {
      event.preventDefault(); if (!input.value.trim()) return;
      send.disabled = true;
      try { await postJson(`/api/projects/${state.projectId}/messages`, { content: input.value.trim(), target: target.value }); input.value = ""; state.notice = "讨论已发出，Agent 回复已回流。"; await refresh(); }
      catch (error) { state.error = error.message || "讨论发送失败"; render(); }
      finally { send.disabled = false; }
    });
    section.append(form);
    return section;
  }

  function renderProjectMembers() {
    const section = el("section", "wb-section");
    const head = el("div", "wb-section-head");
    head.append(el("h2", null, `项目成员（${state.members.length}）`));
    head.append(el("span", "wb-hint", "打开主页查看职责、资料与发言"));
    section.append(head);
    const list = el("div", "wb-member-list");
    state.members.forEach((member) => {
      const card = el("button", "wb-member-card");
      card.type = "button";
      card.dataset.memberId = member.id;
      card.append(el("strong", null, member.name));
      card.append(el("span", "wb-hint", `${member.kind === "ai" ? "AI 参与者" : "人类成员"} · ${member.position || member.org_role || "尚未填写职责"}`));
      card.append(el("span", "wb-member-link", "打开成员主页 →"));
      card.addEventListener("click", () => document.dispatchEvent(new CustomEvent("cognos:open-member", { detail: { memberId: member.id } })));
      list.append(card);
    });
    if (!state.members.length) list.append(el("p", "wb-empty", "还没有项目成员。可以添加已注册同学或组织成员。"));
    section.append(list);
    const form = el("form", "wb-form");
    const select = el("select");
    select.setAttribute("aria-label", "选择要加入项目的成员");
    select.append(option("选择组织成员或已注册账号", ""));
    const add = el("button", "wb-primary", "加入项目");
    add.type = "submit";
    const hint = el("span", "wb-hint", "登录后可添加成员；同学首次在项目发言时也会加入。");
    form.append(select, add, hint);
    section.append(form);
    Promise.all([
      requestJson(`/api/orgs/${state.orgId}`),
      currentAccount ? requestJson("/api/auth/accounts").catch(() => ({ accounts: [] })) : Promise.resolve({ accounts: [] }),
    ]).then(([org, accounts]) => {
      const present = new Set(state.members.map((member) => member.id));
      const linked = new Set((org.members || []).map((member) => member.account_id).filter(Boolean));
      (org.members || []).filter((member) => !present.has(member.id)).forEach((member) => {
        select.append(option(`${member.name} · 组织成员`, `member:${member.id}`));
      });
      (accounts.accounts || []).filter((account) => !linked.has(account.id)).forEach((account) => {
        select.append(option(`${account.display_name} (@${account.username}) · 账号`, `account:${account.id}`));
      });
    }).catch((error) => { hint.textContent = error.message || "成员目录读取失败"; });
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!select.value) return;
      add.disabled = true;
      try {
        const [kind, id] = select.value.split(":");
        let memberId = id;
        if (kind === "account") {
          const payload = await postJson(`/api/orgs/${state.orgId}/members`, { kind: "human", account_id: id });
          memberId = payload.member.id;
        }
        await postJson(`/api/projects/${state.projectId}/members`, { org_member_id: memberId });
        await refresh();
      } catch (error) { hint.textContent = error.message || "加入项目失败"; }
      finally { add.disabled = false; }
    });
    return section;
  }

  function renderWorkItems() {
    const section = el("section", "wb-section");
    const head = el("div", "wb-section-head");
    head.append(el("h2", null, `工作项（${state.workItems.length}）`));
    head.append(el("span", "wb-hint", "从讨论到执行到结果"));
    section.append(head);

    const form = el("form", "wb-form");
    const titleInput = el("input");
    titleInput.placeholder = "工作项标题";
    titleInput.maxLength = 200;
    titleInput.required = true;
    const descInput = el("textarea");
    descInput.rows = 2;
    descInput.placeholder = "描述（可选）";
    const row = el("div", "wb-row");
    const assignee = el("select");
    assignee.append(option("未指派", ""));
    state.members.forEach((member) => {
      assignee.append(option(`${member.name}（${member.kind === "ai" ? "AI" : "人"}）`, member.id));
    });
    const decisionSelect = el("select");
    decisionSelect.append(option("不关联决定", ""));
    state.decisions.forEach((decision) => {
      decisionSelect.append(option(decision.content.slice(0, 48), decision.id));
    });
    row.append(assignee, decisionSelect);
    const submit = el("button", "wb-primary", "＋ 新建工作项");
    submit.type = "submit";
    form.append(titleInput, descInput, row, submit);
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!titleInput.value.trim()) return;
      try {
        await postJson(`/api/projects/${state.projectId}/work-items`, {
          title: titleInput.value.trim(),
          description: descInput.value,
          assignee_member_id: assignee.value || null,
          decision_id: decisionSelect.value || null,
        });
        titleInput.value = "";
        descInput.value = "";
        state.notice = "工作项已创建。";
        await refresh();
      } catch (error) {
        state.error = error.message || "创建工作项失败";
        render();
      }
    });
    section.append(form);

    const list = el("div", "wb-list");
    if (!state.workItems.length) {
      list.append(
        el("p", "wb-empty", "还没有工作项。可以先在右侧任意一条消息上点「决定」，再把决定落成工作项。")
      );
    }
    state.workItems.forEach((item) => list.append(renderWorkItem(item)));
    section.append(list);
    return section;
  }

  function renderWorkItem(item) {
    const node = el("div", "wb-item");
    const head = el("div", "wb-item-head");
    head.append(el("strong", "wb-item-title", item.title));
    const badge = el("span", `wb-status ${item.status}`, STATUS_LABELS[item.status] || item.status);
    head.append(badge);
    node.append(head);

    const meta = [];
    meta.push(`创建 ${formatTime(item.created_at)}`);
    if (item.decision_id) meta.push("关联决定");
    if (item.source_refs && item.source_refs.length) meta.push(`来源发生 ${item.source_refs.length} 条`);
    node.append(el("div", "wb-item-meta", meta.join(" · ")));

    if (item.description) node.append(el("div", "wb-item-desc", item.description));

    const controls = el("div", "wb-item-controls");
    const statusSelect = el("select");
    Object.entries(STATUS_LABELS).forEach(([value, label]) => statusSelect.append(option(label, value)));
    statusSelect.value = item.status;
    statusSelect.addEventListener("change", async () => {
      try {
        await postJson(`/api/work-items/${item.id}`, { status: statusSelect.value }, "PATCH");
        state.notice = `「${item.title}」状态已改为 ${STATUS_LABELS[statusSelect.value] || statusSelect.value}。`;
        await refresh();
      } catch (error) {
        state.error = error.message || "更新状态失败";
        render();
      }
    });

    const assigneeSelect = el("select");
    assigneeSelect.append(option("未指派", ""));
    state.members.forEach((member) => {
      assigneeSelect.append(option(`${member.name}`, member.id));
    });
    assigneeSelect.value = item.assignee_member_id || "";
    assigneeSelect.addEventListener("change", async () => {
      try {
        await postJson(
          `/api/work-items/${item.id}`,
          { assignee_member_id: assigneeSelect.value || null },
          "PATCH"
        );
        state.notice = "负责人已更新。";
        await refresh();
      } catch (error) {
        state.error = error.message || "更新负责人失败";
        render();
      }
    });

    const handoffBtn = el("button", "wb-action", "交接执行");
    handoffBtn.type = "button";
    handoffBtn.title = "生成交接（envelope），由外部 DSH 执行；结果回流到该项目";
    handoffBtn.addEventListener("click", async () => {
      if (!window.confirm(`把「${item.title}」交出去执行？\n将生成 handoff envelope，等待外部执行者运行。`)) {
        return;
      }
      try {
        const instruction = item.description
          ? `执行工作项：${item.title}\n\n${item.description}`
          : `执行工作项：${item.title}`;
        const result = await postJson("/api/handoff/create", {
          instruction,
          target_agent: "DSH",
          project_id: state.projectId,
          work_item_id: item.id,
          occurrence_refs: item.source_refs || [],
        });
        state.notice = `已生成交接 ${shortId(result.record.id, "")}，等待执行结果回流。`;
        await refresh();
      } catch (error) {
        state.error = error.message || "交接创建失败";
        render();
      }
    });

    controls.append(statusSelect, assigneeSelect, handoffBtn);
    node.append(controls);
    return node;
  }

  function renderDecisions() {
    const section = el("section", "wb-section");
    const head = el("div", "wb-section-head");
    head.append(el("h2", null, `决定（${state.decisions.length}）`));
    head.append(el("span", "wb-hint", "保留来源，不替换原消息"));
    section.append(head);

    const list = el("div", "wb-list");
    if (!state.decisions.length) {
      list.append(el("p", "wb-empty", "还没有正式决定。可以在任意消息上点「决定」，或在下方直接记录。"));
    }
    state.decisions.forEach((decision) => {
      const node = el("div", "wb-item");
      const headNode = el("div", "wb-item-head");
      headNode.append(el("strong", "wb-item-title", decision.content.slice(0, 80)));
      headNode.append(el("span", "wb-status", decision.status));
      node.append(headNode);
      const refs = (decision.source_refs || []).map((item) => shortId(item, "")).join(", ");
      node.append(
        el(
          "div",
          "wb-item-meta",
          `${decision.decided_by || "未记录"} · ${formatTime(decision.decided_at)}${refs ? ` · 来源 ${refs}` : ""}`
        )
      );
      if (decision.reopen_condition) {
        node.append(el("div", "wb-item-meta", `重开条件：${decision.reopen_condition}`));
      }
      list.append(node);
    });
    section.append(list);
    return section;
  }

  function renderTimeline() {
    const section = el("section", "wb-section");
    const head = el("div", "wb-section-head");
    head.append(el("h2", null, "项目时间线"));
    head.append(el("span", "wb-hint", "只读聚合：发生 / 决定 / 工作项 / 交接 / 结果"));
    section.append(head);

    const list = el("div", "wb-list");
    if (!state.timeline.length) {
      list.append(el("p", "wb-empty", "时间线还是空的。"));
    }
    state.timeline.forEach((event) => {
      const node = el("div", "wb-event");
      node.append(el("time", null, formatTime(event.at)));
      node.append(el("span", `wb-event-kind ${event.kind}`, KIND_LABELS[event.kind] || event.kind));
      const text = event.author ? `${event.author}：${event.summary}` : event.summary;
      node.append(el("span", "wb-event-text", text));
      list.append(node);
    });
    section.append(list);
    return section;
  }

  // ---------- 从消息创建决定 ----------

  const dialog = el("div", "wb-overlay");
  dialog.hidden = true;
  const card = el("div", "wb-dialog");
  const dialogTitle = el("h3", null, "把这条发生记为项目决定");
  const dialogSource = el("p", "wb-item-meta", "");
  const dialogLabel = el("label", null, "决定内容（可以编辑成正式表述）");
  const dialogText = el("textarea");
  dialogText.maxLength = 100000;
  const dialogFoot = el("div", "wb-dialog-foot");
  const cancelBtn = el("button", null, "取消");
  const saveBtn = el("button", "primary", "记入决定");
  dialogFoot.append(cancelBtn, saveBtn);
  card.append(dialogTitle, dialogSource, dialogLabel, dialogText, dialogFoot);
  dialog.append(card);
  document.body.append(dialog);

  let pendingDecisionSource = null;

  function openDecisionDialog(record) {
    pendingDecisionSource = record;
    dialogText.value = record.content || "";
    dialogSource.textContent = `来源：${record.author || "未知"} · ${shortId(record.id, "")}`;
    dialog.hidden = false;
    dialogText.focus();
  }

  function closeDecisionDialog() {
    pendingDecisionSource = null;
    dialog.hidden = true;
  }

  cancelBtn.addEventListener("click", closeDecisionDialog);
  dialog.addEventListener("click", (event) => {
    if (event.target === dialog) closeDecisionDialog();
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !dialog.hidden) closeDecisionDialog();
  });

  saveBtn.addEventListener("click", async () => {
    if (!pendingDecisionSource || !state.projectId) return;
    const content = dialogText.value.trim();
    if (!content) return;
    try {
      await postJson(`/api/projects/${state.projectId}/decisions`, {
        content,
        source_refs: [pendingDecisionSource.id],
      });
      closeDecisionDialog();
      state.notice = "已记为项目决定（来源发生保留）。";
      await refresh();
      if (state.open) render();
    } catch (error) {
      state.error = error.message || "记录决定失败";
      closeDecisionDialog();
      render();
    }
  });

  if (stream) {
    stream.addEventListener("click", (event) => {
      const button = event.target.closest(".decision-button");
      if (!button || !state.projectId) return;
      const messageNode = button.closest(".message");
      const messageId = messageNode && messageNode.dataset.messageId;
      const record = state.messages.find((item) => item.id === messageId);
      if (record) openDecisionDialog(record);
    });
  }

  // ---------- 与主页面联动 ----------

  document.addEventListener("cognos:project-changed", async (event) => {
    const detail = event.detail || {};
    state.orgId = detail.orgId || null;
    state.projectId = detail.projectId || null;
    state.project = detail.project || null;
    state.members = detail.members || [];
    state.notice = "";
    state.error = "";
    document.body.classList.add("in-project");
    closePage();
    try {
      await fetchData();
    } catch (error) {
      state.error = error.message || "工作台数据读取失败";
    }
  });

  document.addEventListener("cognos:scope-field", () => {
    state.projectId = null;
    state.project = null;
    state.messages = [];
    state.decisions = [];
    state.workItems = [];
    state.timeline = [];
    document.body.classList.remove("in-project");
    closePage();
  });

  document.addEventListener("cognos:workbench-tab", async (event) => {
    const tab = (event.detail && event.detail.tab) || "work_items";
    if (!state.projectId) return;
    state.activeTab = tab;
    try {
      await fetchData();
      state.error = "";
    } catch (error) {
      state.error = error.message || "工作台数据读取失败";
    }
    openPage();
  });

  document.addEventListener("cognos:show-discussion", () => closePage());
  document.addEventListener("cognos:hide-workbench", () => { state.open = false; page.hidden = true; });

  if (backBtn) {
    backBtn.addEventListener("click", () => {
      closePage();
      document.dispatchEvent(new CustomEvent("cognos:discussion-tab"));
    });
  }

  if (refreshBtn) {
    refreshBtn.addEventListener("click", async () => {
      try {
        await fetchData();
        state.error = "";
        state.notice = "";
        render();
      } catch (error) {
        state.error = error.message || "刷新失败";
        render();
      }
    });
  }
})();
