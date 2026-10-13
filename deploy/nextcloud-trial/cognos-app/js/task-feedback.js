/* Cognos trial adapter for Deck 1.19. Recovery storage holds only a digest and request ID. */
(() => {
  'use strict';
  if (!/\/apps\/deck(?:\/|$)/.test(location.pathname) || window.__cognosTaskFeedback) return;
  window.__cognosTaskFeedback = true;
  const metadata = new WeakMap();
  const layouts = new WeakMap();
  const originalOpen = XMLHttpRequest.prototype.open;
  const originalSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function(method, url, ...args) {
    let target = false;
    let destination = url;
    try {
      const parsed = new URL(url, location.href);
      target = parsed.origin === location.origin && method.toUpperCase() === 'POST'
        && /^\/ocs\/v2\.php\/apps\/deck\/api\/v1\.[01]\/cards$/.test(parsed.pathname);
      if (target) {
        parsed.pathname = '/ocs/v2.php/apps/cognos/api/v1.0/cards';
        destination = parsed.href;
      }
    } catch {}
    metadata.set(this, {target});
    return originalOpen.call(this, method, destination, ...args);
  };
  XMLHttpRequest.prototype.send = function(body) {
    if (!metadata.get(this)?.target || typeof body !== 'string') return originalSend.call(this, body);
    let payload;
    try { payload = JSON.parse(body); } catch { return originalSend.call(this, body); }
    const title = payload.title;
    const input = Array.from(document.querySelectorAll('form input[placeholder="卡片名"]'))
      .find(element => element.value === title && element.getBoundingClientRect().width > 0);
    const form = input?.closest('form');
    const submit = form?.querySelector('input[type="submit"],button[type="submit"]');
    const wasDisabled = submit?.disabled;
    let notice = form?.querySelector('[data-cognos-task-feedback]');
    if (form && !notice) {
      layouts.set(form, form.style.flexWrap);
      notice = document.createElement('p');
      notice.dataset.cognosTaskFeedback = 'true';
      notice.style.cssText = 'flex:0 0 100%;box-sizing:border-box;margin:8px 0;white-space:normal;line-height:1.5;font-size:14px;';
      form.append(notice);
    }
    if (form) {
      form.style.flexWrap = 'wrap';
      notice.setAttribute('role', 'status');
      notice.setAttribute('aria-live', 'polite');
      notice.textContent = '正在创建任务…';
      form.setAttribute('aria-busy', 'true');
    }
    if (submit) submit.disabled = true;
    let recoveryKey, requestId;
    const finish = () => {
      form?.removeAttribute('aria-busy');
      if (submit) submit.disabled = wasDisabled;
      let success = false;
      try {
        const response = JSON.parse(this.responseText);
        success = this.status >= 200 && this.status < 300
          && [100, 200].includes(Number(response.ocs?.meta?.statuscode))
          && Number.isInteger(response.ocs?.data?.id);
      } catch {}
      if (success) {
        window.dispatchEvent(new CustomEvent('cognos:task-changed'));
        if (recoveryKey && sessionStorage.getItem(recoveryKey) === requestId) sessionStorage.removeItem(recoveryKey);
        if (form) { notice.remove(); form.style.flexWrap = layouts.get(form) || ''; layouts.delete(form); }
        return;
      }
      if (!notice) return;
      notice.setAttribute('role', 'alert');
      notice.setAttribute('aria-live', 'assertive');
      notice.textContent = this.status === 403
        ? '你当前无权创建这项任务。输入已保留。'
        : '创建结果尚未确认。输入已保留；恢复连接后重试同一次操作会核对原结果。';
    };
    this.addEventListener('loadend', finish, {once: true});
    // Include the account in the digest; never persist the title, body or credentials.
    const current = window.OC?.getCurrentUser?.()?.uid || window.OC?.currentUser || '';
    const actor = typeof current === 'string' ? current : '';
    const canonical = value => {
      if (Array.isArray(value)) return value.map(canonical);
      if (value && typeof value === 'object') return Object.fromEntries(Object.keys(value).sort().map(k => [k, canonical(value[k])]));
      return value;
    };
    Promise.resolve().then(async () => {
      const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(JSON.stringify([actor, canonical(payload)])));
      recoveryKey = 'cognos.task.create.' + Array.from(new Uint8Array(digest), n => n.toString(16).padStart(2, '0')).join('');
      requestId = sessionStorage.getItem(recoveryKey) || crypto.randomUUID();
      sessionStorage.setItem(recoveryKey, requestId);
      return originalSend.call(this, JSON.stringify({...payload, requestId}));
    }).catch(() => {
      this.dispatchEvent(new ProgressEvent('error'));
      this.dispatchEvent(new ProgressEvent('loadend'));
    });
  };
})();
