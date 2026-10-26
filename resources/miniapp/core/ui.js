import { h } from './dom.js';
import { getTg, isNative, haptic } from './telegram.js';

// ---- Toast ----
export function toast(message, type = 'info', ms = 3200) {
  const box = document.getElementById('toasts');
  if (!box) return;
  const el = h('div', { class: `toast toast-${type}` }, message);
  box.append(el);
  if (type === 'error') haptic('error');
  else if (type === 'success') haptic('success');
  setTimeout(() => el.remove(), ms);
}

// ---- Konfirmasi: popup Telegram, fallback dialog DOM ----
export function showConfirm({ title = 'Konfirmasi', message, okText = 'Ya', cancelText = 'Batal', destructive = false }) {
  const tg = getTg();
  if (isNative() && typeof tg.showPopup === 'function') {
    return new Promise((resolve) => {
      try {
        tg.showPopup({
          title,
          message,
          buttons: [
            { id: 'ok', type: destructive ? 'destructive' : 'default', text: okText },
            { id: 'cancel', type: 'cancel' },
          ],
        }, (id) => resolve(id === 'ok'));
      } catch {
        tg.showConfirm?.(message, (ok) => resolve(!!ok));
      }
    });
  }
  return new Promise((resolve) => {
    const done = (v) => { overlay.remove(); resolve(v); };
    const overlay = h('div', { class: 'modal-overlay', role: 'dialog', 'aria-modal': 'true' },
      h('div', { class: 'modal' },
        h('h2', {}, title),
        h('p', {}, message),
        h('div', { class: 'modal-actions' },
          h('button', { class: 'btn', type: 'button', onclick: () => done(false) }, cancelText),
          h('button', { class: `btn ${destructive ? 'btn-danger' : 'btn-primary'}`, type: 'button', onclick: () => done(true) }, okText))));
    document.body.append(overlay);
  });
}

// ---- MainButton (native) / bilah bawah (dev & non-Telegram) ----
let mainHandler = null;

function bar() {
  return { wrap: document.getElementById('mainbar'), btn: document.getElementById('mainbtn') };
}

export function setMainButton({ text, onClick, disabled = false, loading = false }) {
  hideMainButton();
  mainHandler = onClick;
  if (isNative()) {
    const mb = getTg().MainButton;
    mb.setParams?.({ text, is_visible: true, is_active: !disabled });
    mb.onClick(mainHandler);
    loading ? mb.showProgress?.() : mb.hideProgress?.();
    return;
  }
  const { wrap, btn } = bar();
  btn.textContent = loading ? 'Memproses...' : text;
  btn.disabled = disabled || loading;
  btn.onclick = () => mainHandler?.();
  wrap.hidden = false;
  document.body.classList.add('has-mainbar');
}

export function setMainButtonLoading(loading, text) {
  if (isNative()) {
    const mb = getTg().MainButton;
    loading ? mb.showProgress?.(false) : mb.hideProgress?.();
    loading ? mb.disable?.() : mb.enable?.();
    return;
  }
  const { btn } = bar();
  btn.disabled = loading;
  if (loading) { btn.dataset.text = btn.textContent; btn.textContent = 'Memproses...'; }
  else btn.textContent = text || btn.dataset.text || btn.textContent;
}

export function hideMainButton() {
  if (isNative()) {
    const mb = getTg().MainButton;
    if (mainHandler) mb.offClick?.(mainHandler);
    mb.hideProgress?.();
    mb.hide?.();
  } else {
    const { wrap, btn } = bar();
    if (wrap) { wrap.hidden = true; btn.onclick = null; }
    document.body.classList.remove('has-mainbar');
  }
  mainHandler = null;
}
