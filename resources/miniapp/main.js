import '../css/miniapp.css';
import { h, clear } from './core/dom.js';
import { getTg, isNative } from './core/telegram.js';
import { initTheme } from './core/theme.js';
import { login } from './core/auth.js';
import { onAuthRequired, onSppgDiperlukan, flushQueue } from './core/api.js';
import { route, startRouter, navigate, parseHash } from './core/router.js';
import { api } from './core/api.js';
import { registerAllRoutes } from './modules/registry.js';
import { renderHome } from './home.js';
import { renderPemilihSppg, muatSppgSaya } from './components/sppg-picker.js';
import { renderAgregatPenerima } from './components/agregat-view.js';
import { isInvestor, setSppgAktif } from './core/sppg-aktif.js';
import { parseSppgHint } from './modules/persetujuan-menu/logika.js';
import { getUser } from './core/auth.js';
import { showLoginFlow } from './components/login-flow.js';
import { renderSesi } from './components/sesi-view.js';
import { renderUbahPassword } from './components/password-view.js';
import { detikTerkunci } from './core/kredensial.js';

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
route('/sesi', (ctx) => renderSesi(ctx, { onKeluar: () => { navigate('/', { replace: true }); location.reload(); } }), { title: 'Perangkat & Sesi' });
route('/pilih-sppg', (ctx) => renderPemilihSppg(ctx.el, { onPilih: () => { navigate('/', { replace: true }); location.reload(); } }),
  { title: 'Pilih SPPG', guard: () => isInvestor(getUser()) });
route('/penerima-manfaat-agregat', renderAgregatPenerima, { title: 'Ringkasan Penerima Manfaat', guard: () => isInvestor(getUser()) });
route('/password', renderUbahPassword, { title: 'Ubah Password' });
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

let flow = null;

async function doLogin() {
  flow?.destroy();
  flow = null;
  showMessage('Masuk...', 'Memverifikasi akun Telegram Anda.');
  let hasil;
  try {
    hasil = await login();
  } catch (e) {
    if (e.status === 429 && e.data?.alasan === 'terkunci') {
      const menit = Math.ceil(detikTerkunci(e.data) / 60);
      return showMessage('Akun terkunci sementara', `Terlalu banyak percobaan gagal. Coba lagi ${menit > 0 ? `dalam ${menit} menit` : 'sebentar lagi'}.`, 'Coba lagi', doLogin);
    }
    const text = e.status === 403 ? 'Akun Telegram Anda belum terdaftar. Hubungi admin SPPG.' : e.message || 'Gagal masuk.';
    return showMessage('Gagal masuk', text, 'Coba lagi', doLogin);
  }
  if (hasil.langkah === 'sesi_aktif') return masukBeranda();
  clear(view);
  flow = showLoginFlow(view, { langkah: hasil.langkah, akun: hasil.akun, onDone: masukBeranda, onRestart: doLogin });
}

async function masukBeranda() {
  flow?.destroy();
  flow = null;
  if (isInvestor(getUser())) {
    // Investor multi-SPPG: pastikan SPPG aktif ada (header X-SPPG-Id) sebelum membuka data.
    // Deep link bot (?sppg=<id>) hanya petunjuk: dipakai bila ada dalam daftar /sppg-saya; server tetap memvalidasi.
    const hint = parseSppgHint(location.search, location.hash);
    if (hint) setSppgAktif(hint);
    let hasil;
    try { hasil = await muatSppgSaya(); } catch (e) {
      return showMessage('Gagal memuat SPPG', e.message || 'Coba lagi.', 'Coba lagi', masukBeranda);
    }
    if (hasil.langkah !== 'siap') {
      clear(view);
      return renderPemilihSppg(view, { onPilih: masukBeranda });
    }
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
onSppgDiperlukan(() => { if (started) navigate('/pilih-sppg', { replace: true }); });
window.addEventListener('online', flushQueue);
start();
