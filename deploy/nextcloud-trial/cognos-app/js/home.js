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
      // 创立空间：直接跳转到创立空间列表页
      if (typeInput.value === 'creation') {
        window.location.href = '/index.php/apps/cognos/creations';
        return;
      }

      // 笔记空间：调用现有的笔记空间创建接口
      if (typeInput.value === 'notes') {
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
        return;
      }

      // 其他类型空间保留入口
      status.textContent = '这个类型的空间入口已保留，具体成员/项目对象将在对应底座中建立。';
      button.disabled = false;
    } catch (error) {
      status.textContent = error.message;
      button.disabled = false;
    }
  });
})();
