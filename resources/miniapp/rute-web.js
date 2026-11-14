// Pendaftaran rute WEB (R04-8 PR3/PR4). Modul di BACA_WEB mendapat layar baca (daftar + detail, registerWeb); modul lain yang ADA
// di menu (menurut peta hak server) tetap "Segera tersedia di web". Rute Telegram-only (/draf/:uuid, /pilih-sppg, ?r=)
// sengaja tidak didaftarkan. Hak tetap diputuskan server; guard di sini hanya mengikuti peta dari /auth/me.
import { route } from './core/router.js';
import { userPerms, dashboardTersedia, agregatBoleh } from './core/permissions.js';
import { renderSegera } from './beranda-web.js';

/**
 * Modul dengan layar baca web yang sudah jadi (PR4a: data master). Tambah slug di sini HANYA bila modul punya registerWeb()
 * dan bagian tambahannya (listTopWeb/detailExtrasWeb) sudah aman untuk web. Hak tetap dari peta server (guard registerWeb).
 */
export const BACA_WEB = new Set(['sppg', 'titik-layanan', 'penerima-manfaat', 'pemasok', 'armada', 'pengemudi']);

/** modules: getModules() dari registry. getUser: () => pengguna sesi. */
export function daftarkanRuteWeb(modules, getUser) {
  for (const m of modules) {
    if (BACA_WEB.has(m.slug) && typeof m.registerWeb === 'function') { m.registerWeb(); continue; }
    if (m.hidden) continue;
    const slug = m.permissionSlug ?? m.slug;
    route(`/${m.slug}`, (ctx) => renderSegera(ctx.el, m.title), { title: m.title, guard: () => userPerms(getUser(), slug).R });
  }
  route('/dashboard', (ctx) => renderSegera(ctx.el, 'Dashboard'), { title: 'Dashboard', guard: () => dashboardTersedia(getUser()) });
  route('/penerima-manfaat-agregat', (ctx) => renderSegera(ctx.el, 'Ringkasan Penerima Manfaat'),
    { title: 'Ringkasan Penerima Manfaat', guard: () => agregatBoleh(getUser(), 'penerima-manfaat') });
}
