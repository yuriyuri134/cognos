(() => {
  'use strict';
  const form = document.querySelector('#cognos-space-form');
  const typeInput = document.querySelector('#cognos-space-type');
  const status = document.querySelector('#cognos-space-status');
  const name = document.querySelector('#cognos-space-name');
  const description = document.querySelector('#cognos-space-description');

  document.querySelector('#cognos-create-space')?.addEventListener('click', () => {
    form.hidden = !form.hidden;
    if (!form.hidden) name.focus();
  });

  document.querySelectorAll('[data-space-type]').forEach((button) => {
    button.addEventListener('click', () => {
      document.querySelectorAll('[data-space-type]').forEach((item) => item.classList.remove('selected'));
      button.classList.add('selected');
      typeInput.value = button.dataset.spaceType || '';
    });
  });

  document.querySelector('#cognos-create-space-form')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!typeInput.value) {
      status.textContent = '请先选择空间类型。';
      return;
    }
    const button = event.currentTarget.querySelector('button[type="submit"]');
    button.disabled = true;
    status.textContent = '正在建立空间…';
    try {
      // 第一版先把笔记型空间落到现有 Notes 原生空间；其他类型保留明确入口。
      if (typeInput.value !== 'notes') {
        status.textContent = '这个类型的空间入口已保留，具体成员/项目对象将在对应底座中建立。';
        button.disabled = false;
        return;
      }
      const response = await fetch('/index.php/apps/cognos/notes/spaces', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: window.OC?.requestToken || '' },
        body: JSON.stringify({ title: name.value.trim(), description: description.value.trim() }),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || data.message || `建立失败（${response.status}）`);
      status.textContent = '空间已建立，正在打开笔记空间…';
      window.location.href = '/index.php/apps/cognos/notes';
    } catch (error) {
      status.textContent = error.message;
      button.disabled = false;
    }
  });
})();
