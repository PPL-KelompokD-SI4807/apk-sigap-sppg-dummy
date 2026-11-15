// Pendaftaran rute WEB (R04-8 PR3/PR4). Modul di BACA_WEB mendapat layar baca (daftar + detail, registerWeb); modul lain yang ADA
// di menu (menurut peta hak server) tetap "Segera tersedia di web". Rute Telegram-only (/draf/:uuid, /pilih-sppg, ?r=)
// sengaja tidak didaftarkan. Hak tetap diputuskan server; guard di sini hanya mengikuti peta dari /auth/me.
import { route, navigate, resolve } from './core/router.js';
import { userPerms, dashboardTersedia, agregatBoleh } from './core/permissions.js';
import { renderSegera } from './beranda-web.js';
import { multiSppg } from './core/permissions.js';

/**
 * Modul dengan layar baca web yang sudah jadi (PR4a: data master). Tambah slug di sini HANYA bila modul punya registerWeb()
 * dan bagian tambahannya (listTopWeb/detailExtrasWeb) sudah aman untuk web. Hak tetap dari peta server (guard registerWeb).
 */
/** Modul yang juga mendapat formulir web (Tambah/Ubah/Hapus, PR4c). Hak tetap dari peta/izin server. */
export const TULIS_WEB = new Set([
  'sppg', 'titik-layanan', 'penerima-manfaat', 'pemasok', 'armada', 'pengemudi', // PR4c data master
  'jadwal', 'log-produksi', 'mutu', // PR4d produksi (jadwal/distribusi menyusul di 4e)
]);

export const BACA_WEB = new Set([
  // 4a data master
  'sppg', 'titik-layanan', 'penerima-manfaat', 'pemasok', 'armada', 'pengemudi',
  // 4b operasional (jadwal/distribusi dan keuangan/* adalah sub-modul tersembunyi)
  'jadwal/distribusi', 'jadwal', 'log-produksi', 'mutu', 'notifikasi-perjalanan', 'bukti-distribusi', 'keluhan',
  'keuangan/pencairan', 'keuangan/pengeluaran', 'keuangan', 'rekap-harian', 'dashboard',
  // ekspor PDF Laporan BGN (hak = aksi.lihat_laporan_bgn*, bolehMenu)
  'laporan-bgn',
]);

/** modules: getModules() dari registry. getUser: () => pengguna sesi. */
export function daftarkanRuteWeb(modules, getUser) {
  // Pemilih SPPG aktif (investor multi-SPPG). Pilihan hanya di memori (sppg-aktif-web.js); ganti SPPG = render ulang beranda dengan header baru.
  route('/ganti-sppg', async (ctx) => {
    const { renderPemilihSppg } = await import('./components/sppg-picker.js');
    await renderPemilihSppg(ctx.el, { onPilih: () => { navigate('/', { replace: true }); resolve(); } });
  }, { title: 'Pilih SPPG', guard: () => multiSppg(getUser()) });
  const dashboardWeb = modules.some((m) => m.slug === 'dashboard' && BACA_WEB.has('dashboard') && typeof m.registerWeb === 'function');
  for (const m of modules) {
    if (BACA_WEB.has(m.slug) && typeof m.registerWeb === 'function') { m.registerWeb({ tulis: TULIS_WEB.has(m.slug) }); continue; }
    if (m.hidden) continue;
    const slug = m.permissionSlug ?? m.slug; // modul tanpa baris matriks (mis. Laporan BGN) memakai bolehMenu(user) dari aksi.*, sama dengan menu
    route(`/${m.slug}`, (ctx) => renderSegera(ctx.el, m.title), { title: m.title, guard: () => (typeof m.bolehMenu === 'function' ? m.bolehMenu(getUser()) === true : userPerms(getUser(), slug).R) });
  }
  if (!dashboardWeb) route('/dashboard', (ctx) => renderSegera(ctx.el, 'Dashboard'), { title: 'Dashboard', guard: () => dashboardTersedia(getUser()) });
  route('/penerima-manfaat-agregat', (ctx) => renderSegera(ctx.el, 'Ringkasan Penerima Manfaat'),
    { title: 'Ringkasan Penerima Manfaat', guard: () => agregatBoleh(getUser(), 'penerima-manfaat') });
}
