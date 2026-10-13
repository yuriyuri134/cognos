(() => {
  'use strict';

  const apiBase = '/index.php/apps/cognos';
  const node = (tag, text) => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    return element;
  };
  const root = () => document.querySelector('#cognos-activities-page') || document.body;

  async function api(path, options = {}) {
    const response = await fetch(apiBase + path, {
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        requesttoken: window.OC?.requestToken || '',
        ...(options.headers || {}),
      },
      ...options,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(data.message || `请求失败 ${response.status}`);
      error.status = response.status;
      error.data = data;
      throw error;
    }
    return data;
  }

  function layout() {
    const page = root();
    page.replaceChildren();
    page.id = 'cognos-activities-page';
    return page;
  }

  const participantSelect = (participants, selected = '') => {
    const select = document.createElement('select');
    select.required = true;
    select.className = 'cognos-participant-select';
    const empty = node('option', '选择一个参与者（必选）');
    empty.value = '';
    select.append(empty);
    for (const participant of participants || []) {
      const available = participant.runtime_available === true;
      const routeModel = participant.runtime_model ? ` · ${participant.runtime_model}` : ' · runtime 未接通';
      const option = node('option', `${participant.display_name} · ${participant.description}${routeModel}`);
      option.value = participant.id;
      option.selected = participant.id === selected;
      select.append(option);
    }
    return select;
  };

  async function loadParticipants() {
    const data = await api('/participants');
    return data.participants || [];
  }

  function addRetryButton(messageElement, activityId, messageId, messages) {
    if (!messageId || messageElement.querySelector('[data-cognos-retry]')) return;
    const button = node('button', '重试上一条');
    button.type = 'button';
    button.dataset.cognosRetry = 'true';
    button.addEventListener('click', async () => {
      button.disabled = true;
      button.textContent = '正在重试…';
      try {
        await api(`/activities/${encodeURIComponent(activityId)}/messages/${encodeURIComponent(messageId)}/retry`, { method: 'POST' });
        await loadMessages(activityId, messages);
      } catch (error) {
        button.disabled = false;
        button.textContent = '重试上一条';
        const status = node('span', error.message);
        status.className = 'cognos-retry-status';
        messageElement.append(status);
      }
    });
    messageElement.append(button);
  }

  function addMessage(container, role, content, scroll = true, meta = null, defaultParticipant = '') {
    const message = node('div');
    message.className = `cognos-message cognos-message-${role}`;
    if (meta?.state) message.dataset.state = meta.state;
    if (meta?.id) message.dataset.messageId = meta.id;

    const participantName = meta?.participant_name || (role === 'assistant' ? defaultParticipant : '');
    const avatar = node('div');
    avatar.className = 'message-avatar';
    avatar.textContent = role === 'user' ? '你' : role === 'assistant' ? (participantName[0] || 'A') : '●';
    const body = node('div');
    body.className = 'message-body';
    if (participantName) {
      const author = node('strong', participantName);
      author.className = 'message-author';
      body.append(author);
    }
    body.append(node('div', content));
    message.append(avatar, body);
    container.append(message);
    if (scroll) message.scrollIntoView({ behavior: 'smooth', block: 'end' });
    return message;
  }

  async function loadMessages(activityId, container, participantName = '') {
    try {
      const data = await api(`/activities/${encodeURIComponent(activityId)}/messages`);
      container.replaceChildren();
      for (const item of data.messages || []) {
        const message = addMessage(container, item.role, item.content, false, item, participantName);
        if (item.role === 'user' && item.state === 'failed') {
          const notice = node('p', '消息已保存，但参与者没有返回；可以明确重试。');
          notice.className = 'cognos-failed-notice';
          message.append(notice);
          addRetryButton(message, activityId, item.id, container);
        }
      }
    } catch (error) {
      container.replaceChildren(node('p', error.message));
    }
  }

  async function renderList() {
    const page = layout();
    const header = node('header');
    header.className = 'cognos-activity-header';
    header.append(node('h1', 'Cognos Activity'));
    header.append(node('p', 'Activity 是一段可持续推进的工作上下文。Agent 只有被你明确选择后，才会进入这段工作。'));
    page.append(header);

    const create = node('section');
    create.className = 'cognos-create-activity';
    create.append(node('h2', '新建 Activity'));
    create.append(node('p', '先写下尚未形成的问题，再选择这次要面对它的参与者。选择不会让 Agent 自动发言。'));
    const title = document.createElement('input');
    title.placeholder = '例如：梳理这次组织实验';
    title.required = true;
    const context = document.createElement('textarea');
    context.rows = 5;
    context.placeholder = '把问题、材料或当前困惑写在这里……';
    context.required = true;
    const chooser = node('div');
    chooser.className = 'cognos-participant-chooser';
    chooser.append(node('label', '当前参与者'));
    const selectHost = document.createElement('div');
    selectHost.textContent = '正在读取参与者…';
    chooser.append(selectHost);
    const button = node('button', '创建并进入 Activity');
    button.type = 'button';
    const status = node('p');
    status.className = 'cognos-create-status';

    try {
      selectHost.replaceWith(participantSelect(await loadParticipants()));
    } catch (error) {
      status.textContent = error.message;
      button.disabled = true;
    }

    button.addEventListener('click', async () => {
      const participantId = chooser.querySelector('select')?.value || '';
      button.disabled = true;
      status.textContent = '正在创建 Activity…';
      try {
        const data = await api('/activities/manual', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ title: title.value, context: context.value, participant_id: participantId }),
        });
        window.location.href = `${apiBase}/activities/${encodeURIComponent(data.activity.id)}`;
      } catch (error) {
        status.textContent = error.message;
        button.disabled = false;
      }
    });
    create.append(title, context, chooser, button, status);
    page.append(create);

    const existing = node('section');
    existing.className = 'cognos-existing-activities';
    existing.append(node('h2', '已有 Activity'));
    const list = node('div', '正在读取…');
    existing.append(list);
    page.append(existing);
    try {
      const data = await api('/activities/data');
      list.replaceChildren();
      if (!data.activities?.length) {
        list.append(node('p', '还没有 Activity。上面创建第一段。'));
        return;
      }
      for (const activity of data.activities) {
        const item = node('article');
        const link = node('a', activity.title || '未命名 Activity');
        link.href = `${apiBase}/activities/${encodeURIComponent(activity.id)}`;
        item.append(link, node('p', `${activity.participant_name || '未选择参与者'} · ${activity.status || '进行中'} · ${new Date(Number(activity.created_at) * 1000).toLocaleString('zh-CN')}`));
        list.append(item);
      }
    } catch (error) {
      list.textContent = error.message;
    }
  }

  async function renderActivity(id) {
    const page = layout();
    const back = node('a', '← Activity 列表');
    back.href = `${apiBase}/activities`;
    page.append(back);
    const heading = node('h1', '正在读取 Activity…');
    page.append(heading);

    try {
      const data = await api(`/activities/${encodeURIComponent(id)}/data`);
      const activity = data.activity;
      let participantName = activity.participant_name || '';
      heading.textContent = activity.title || '未命名 Activity';

      const participantBar = node('section');
      participantBar.className = 'cognos-current-participant';
      const participantLabel = node('strong', `当前参与者：${participantName || '未选择'}`);
      const participantHint = node('p', '这是当前被你召唤的参与者，不是匿名助手。输出默认是候选，不会自动成为任务、决定或事实。');
      const switcher = participantSelect(await loadParticipants(), activity.participant_id || '');
      const switchButton = node('button', '切换参与者');
      switchButton.type = 'button';
      const switchStatus = node('span');
      switchButton.addEventListener('click', async () => {
        if (!switcher.value) {
          switchStatus.textContent = '请先选择一个参与者。';
          return;
        }
        switchButton.disabled = true;
        try {
          const result = await api(`/activities/${encodeURIComponent(id)}/participant`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ participant_id: switcher.value }),
          });
          participantName = result.activity.participant_name;
          participantLabel.textContent = `当前参与者：${participantName}`;
          switchStatus.textContent = '已记录；下一条消息将由该参与者继续。';
          input.placeholder = `写给 ${participantName}……`;
        } catch (error) {
          switchStatus.textContent = error.message;
        } finally {
          switchButton.disabled = false;
        }
      });
      participantBar.append(participantLabel, participantHint, switcher, switchButton, switchStatus);
      page.append(participantBar);

      const source = node('details');
      source.append(node('summary', `上下文来源 · ${activity.source_type || 'manual_activity'}`));
      source.append(node('pre', JSON.stringify(activity.source_snapshot, null, 2)));
      page.append(source);

      const chat = node('section');
      chat.className = 'cognos-chat';
      const messages = node('div');
      messages.className = 'cognos-messages';
      chat.append(messages);
      await loadMessages(id, messages, participantName);

      const form = document.createElement('form');
      form.className = 'cognos-chat-input';
      const input = document.createElement('textarea');
      input.rows = 3;
      input.required = true;
      input.placeholder = `写给 ${participantName || '当前参与者'}……`;
      const send = node('button', '发送给当前参与者');
      send.type = 'submit';
      form.append(input, send);
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const message = input.value.trim();
        if (!message) return;
        send.disabled = true;
        input.disabled = true;
        const optimistic = addMessage(messages, 'user', message, true, { id: `local-${Date.now()}`, state: 'pending' });
        const waiting = addMessage(messages, 'assistant', `${participantName || '参与者'} 正在回应…`, true, { participant_name: participantName }, participantName);
        input.value = '';
        try {
          const requestId = window.crypto?.randomUUID
            ? window.crypto.randomUUID()
            : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
          await api(`/activities/${encodeURIComponent(id)}/chat`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message, request_id: requestId }),
          });
          optimistic.remove();
          waiting.remove();
          await loadMessages(id, messages, participantName);
        } catch (error) {
          optimistic.remove();
          waiting.remove();
          await loadMessages(id, messages, participantName);
          if (error.data?.message_id) {
            const failed = messages.querySelector(`[data-message-id="${CSS.escape(error.data.message_id)}"]`);
            if (failed) addRetryButton(failed, id, error.data.message_id, messages);
          } else {
            messages.append(node('p', error.message));
          }
        } finally {
          send.disabled = false;
          input.disabled = false;
          input.focus();
        }
      });
      chat.append(form);
      page.append(chat);
    } catch (error) {
      heading.textContent = error.message;
    }
  }

  const match = location.pathname.match(/\/apps\/cognos\/activities\/([^/]+)/);
  if (match) renderActivity(decodeURIComponent(match[1]));
  else renderList();
})();