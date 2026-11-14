// Titik masuk WEB (R04-6/7/9). Tanpa Telegram.WebApp, tanpa Bearer, tanpa storage untuk token:
// sesi = cookie HttpOnly (tak terlihat JS), CSRF hanya di memori (core/api-web.js).
// R04-8 PR3: beranda + menu per peran dari /auth/me; layar modul masih "Segera tersedia di web" (PR berikutnya).
import '../css/miniapp.css';
import { setPlatform } from './core/platform.js';
import { h, clear } from './core/dom.js';
import { initTheme } from './core/theme.js';
import { route, startRouter, navigate } from './core/router.js';
import { klienWeb } from './core/api-web.js';
import { tampilkanMasukWeb } from './modules/auth-web/layar.js';
import { renderSesiWeb } from './modules/sesi/layar.js';
import { CATATAN_IDLE } from './modules/auth-web/logika.js';

setPlatform('web');

const view = document.getElementById('view');
const PARAM = 'sesi';
// Sama dengan multiSppg() di core/permissions.js (peta server: user.multi_sppg); diulang kecil agar permissions.js tetap di chunk lazy.
const multiSppg = (u) => u != null && typeof u === 'object' && u.multi_sppg === true;

// Muat ulang ke halaman bersih: membuang semua state (CSRF, user) dari memori. `berakhir` -> pesan di layar Masuk.
function ulangiDariAwal(berakhir = false) {
  history.replaceState(null, '', location.pathname + (berakhir ? `?${PARAM}=berakhir` : ''));
  location.reload();
}

// Beranda, menu, dan rute modul (registry = seluruh kode modul) dimuat lazy setelah login agar aset awal web tetap kecil.
route('/', async (ctx) => {
  const [{ menuFor }, { renderBerandaWeb }] = await Promise.all([import('./modules/registry.js'), import('./beranda-web.js')]);
  renderBerandaWeb(ctx.el, { user: klienWeb.getUser(), menu: menuFor(klienWeb.getUser()) });
  ctx.el.append(h('p', { class: 'muted small' }, CATATAN_IDLE));
}, { title: 'SiGAP SPPG' });
route('/sesi', (ctx) => renderSesiWeb(ctx, { onKeluar: () => ulangiDariAwal() }), { title: 'Sesi dan Browser' });

let dimulai = false;
async function masukAplikasi() {
  if (dimulai) return;
  dimulai = true;
  try {
    const [{ getModules }, { daftarkanRuteWeb }] = await Promise.all([import('./modules/registry.js'), import('./rute-web.js')]);
    daftarkanRuteWeb(getModules(), () => klienWeb.getUser());
  } catch { /* menu kosong; beranda tetap tampil */ }
  history.replaceState(null, '', location.pathname + location.hash); // buang ?sesi=berakhir
  // Investor multi-SPPG: tentukan SPPG aktif lebih dulu (GET /sppg-saya); bila harus memilih, buka pemilih.
  if (multiSppg(klienWeb.getUser())) {
    try {
      const { muatSppgSaya } = await import('./components/sppg-picker.js');
      if ((await muatSppgSaya()).langkah !== 'siap') location.hash = '#/ganti-sppg';
    } catch { /* beranda menampilkan tautan Pilih SPPG */ }
  }
  klienWeb.onSppgDiperlukan(() => { if (multiSppg(klienWeb.getUser())) navigate('/ganti-sppg', { replace: true }); });
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
