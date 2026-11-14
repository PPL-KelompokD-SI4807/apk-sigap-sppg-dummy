// Pendaftaran rute WEB (R04-8 PR3). Layar kerja (baca/formulir) belum ada di web, jadi setiap modul yang ADA di menu
// (menurut peta hak server) mendapat layar "Segera tersedia di web". Rute Telegram-only (/draf/:uuid, /pilih-sppg, ?r=)
// sengaja tidak didaftarkan. Hak tetap diputuskan server; guard di sini hanya mengikuti peta dari /auth/me.
import { route } from './core/router.js';
import { userPerms, dashboardTersedia, agregatBoleh } from './core/permissions.js';
import { renderSegera } from './beranda-web.js';

/** modules: getModules() dari registry. getUser: () => pengguna sesi. */
export function daftarkanRuteWeb(modules, getUser) {
  for (const m of modules) {
    if (m.hidden) continue;
    const slug = m.permissionSlug ?? m.slug;
    route(`/${m.slug}`, (ctx) => renderSegera(ctx.el, m.title), { title: m.title, guard: () => userPerms(getUser(), slug).R });
  }
  route('/dashboard', (ctx) => renderSegera(ctx.el, 'Dashboard'), { title: 'Dashboard', guard: () => dashboardTersedia(getUser()) });
  route('/penerima-manfaat-agregat', (ctx) => renderSegera(ctx.el, 'Ringkasan Penerima Manfaat'),
    { title: 'Ringkasan Penerima Manfaat', guard: () => agregatBoleh(getUser(), 'penerima-manfaat') });
}
