import '../css/miniapp.css';
import { h, clear } from './core/dom.js';
import { getTg, isNative } from './core/telegram.js';
import { initTheme } from './core/theme.js';
import { login } from './core/auth.js';
import { onAuthRequired, flushQueue } from './core/api.js';
import { route, startRouter, navigate, parseHash } from './core/router.js';
import { api } from './core/api.js';
import { registerAllRoutes } from './modules/registry.js';
import { renderHome } from './home.js';

const view = document.getElementById('view');
let started = false;

function showMessage(title, text, actionText, action) {
  clear(view);
  view.append(h('div', { class: 'empty' }, h('h2', {}, title), h('p', { class: 'muted' }, text),
    actionText ? h('button', { class: 'btn btn-primary', type: 'button', onclick: action }, actionText) : null));
}

// Tautan draf AI: /draf/:uuid membaca draf lalu membuka form modul terkait dengan prefill.
route('/', renderHome, { title: 'SiGAP SPPG' });
route('/draf/:uuid', async (ctx) => {
  const res = await api.get(`/ai-drafts/${encodeURIComponent(ctx.params.uuid)}`);
  const d = res.data ?? res;
  const target = d.action === 'update' && d.payload?.id ? `/${d.module}/${d.payload.id}/ubah` : `/${d.module}/baru`;
  navigate(`${target}?draft=${encodeURIComponent(d.uuid || ctx.params.uuid)}`, { replace: true });
}, { title: 'Draf AI' });
registerAllRoutes();

async function start() {
  if (import.meta.env.DEV && !window.Telegram?.WebApp?.initData) {
    (await import('./core/telegram-shim.js')).installShim();
  }
  const tg = getTg();
  tg?.ready?.();
  tg?.expand?.();
  initTheme();
  if (!isNative()) document.getElementById('appbar').hidden = false;

  if (!tg?.initData) {
    return showMessage('Buka lewat Telegram', 'Aplikasi ini hanya dapat dibuka dari bot SiGAP SPPG di Telegram.');
  }
  await doLogin();
}

async function doLogin() {
  showMessage('Masuk...', 'Memverifikasi akun Telegram Anda.');
  try {
    await login();
  } catch (e) {
    const text = e.status === 403 ? 'Akun Telegram Anda belum terdaftar. Hubungi admin SPPG.' : e.message || 'Gagal masuk.';
    return showMessage('Gagal masuk', text, 'Coba lagi', doLogin);
  }
  if (!started) {
    started = true;
    // Deep link draf AI: ?draft=<uuid> atau startapp=draft_<uuid>
    const q = new URLSearchParams(location.search);
    const startParam = getTg()?.initDataUnsafe?.start_param;
    const draft = q.get('draft') || (startParam?.startsWith('draft_') ? startParam.slice(6) : null);
    if (draft && parseHash().path === '/') location.hash = `#/draf/${encodeURIComponent(draft)}`;
    await startRouter(view);
  } else {
    location.reload();
  }
  flushQueue();
}

onAuthRequired(() => showMessage('Sesi berakhir', 'Silakan masuk kembali.', 'Masuk ulang', doLogin));
window.addEventListener('online', flushQueue);
start();
