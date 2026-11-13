// Titik masuk WEB (R04-6/7/9). Tanpa Telegram.WebApp, tanpa Bearer, tanpa storage untuk token:
// sesi = cookie HttpOnly (tak terlihat JS), CSRF hanya di memori (core/api-web.js).
// Layar kerja CRUD/dasbor menyusul di R04-8; saat ini cangkang: beranda, Sesi & Browser, Keluar.
import '../css/miniapp.css';
import { setPlatform } from './core/platform.js';
import { h, clear } from './core/dom.js';
import { initTheme } from './core/theme.js';
import { route, startRouter } from './core/router.js';
import { klienWeb } from './core/api-web.js';
import { tampilkanMasukWeb } from './modules/auth-web/layar.js';
import { renderSesiWeb } from './modules/sesi/layar.js';
import { CATATAN_IDLE } from './modules/auth-web/logika.js';

setPlatform('web');

const view = document.getElementById('view');
const PARAM = 'sesi';

// Muat ulang ke halaman bersih: membuang semua state (CSRF, user) dari memori. `berakhir` -> pesan di layar Masuk.
function ulangiDariAwal(berakhir = false) {
  history.replaceState(null, '', location.pathname + (berakhir ? `?${PARAM}=berakhir` : ''));
  location.reload();
}

function berandaWeb(ctx) {
  const user = klienWeb.getUser() || {};
  ctx.el.append(h('section', { class: 'panel stack' },
    h('h2', { class: 'panel-title' }, user.name ? `Halo, ${user.name}` : 'Halo'),
    h('p', { class: 'muted small' }, [user.role_label, user.sppg_name].filter(Boolean).join(' - ')),
    h('p', { class: 'notice' }, 'Versi web sedang disiapkan: menu kerja menyusul. Untuk saat ini Anda dapat mengelola sesi dan browser tepercaya.'),
    h('a', { class: 'btn btn-block', href: '#/sesi' }, 'Sesi aktif dan browser tepercaya'),
    h('p', { class: 'muted small' }, CATATAN_IDLE)));
}

route('/', berandaWeb, { title: 'SiGAP SPPG' });
route('/sesi', (ctx) => renderSesiWeb(ctx, { onKeluar: () => ulangiDariAwal() }), { title: 'Sesi dan Browser' });

let dimulai = false;
function masukAplikasi() {
  if (dimulai) return;
  dimulai = true;
  history.replaceState(null, '', location.pathname + location.hash); // buang ?sesi=berakhir
  clear(view);
  startRouter(view);
}

function tampilkanMasuk(pesanAwal = '') {
  clear(view);
  tampilkanMasukWeb(view, { pesanAwal, onSelesai: masukAplikasi });
}

async function mulai() {
  initTheme();
  document.getElementById('appbar').hidden = false;
  klienWeb.onSesiHabis(() => ulangiDariAwal(true));
  let sesi = null;
  try { sesi = await klienWeb.muatSesi(); } catch { /* jaringan/server: tampilkan layar Masuk */ }
  if (sesi) return masukAplikasi();
  const berakhir = new URLSearchParams(location.search).get(PARAM) === 'berakhir';
  return tampilkanMasuk(berakhir ? 'Sesi Anda telah berakhir. Silakan masuk kembali.' : '');
}

mulai();
