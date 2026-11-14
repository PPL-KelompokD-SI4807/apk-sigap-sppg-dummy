// Beranda WEB (R04-8 PR3): pengguna, peran, SPPG, dan menu modul. Menu = hasil menuUntuk() dari peta hak SERVER
// (GET /auth/me); klien tidak menyimpan matriks hak. Tanpa fitur Telegram-only (draf AI, ?r=, share t.me, undangan, antrean offline).
import { h } from './core/dom.js';
import { roleLabel, petaValid, dashboardTersedia, agregatBoleh } from './core/permissions.js';

export const PESAN_SEGERA = 'Segera tersedia di web';
export const PESAN_PETA_RUSAK = 'Hak akses akun Anda tidak dapat dimuat, jadi menu disembunyikan. Muat ulang halaman, atau hubungi Admin bila berulang.';

const kartu = (href, ikon, judul, deskripsi) => h('a', { class: 'menu-card', href },
  h('span', { class: 'menu-icon', 'aria-hidden': 'true' }, ikon),
  h('span', { class: 'menu-title' }, judul),
  deskripsi ? h('span', { class: 'muted small' }, deskripsi) : null);

/** Kartu pintasan non-modul, ditentukan peta server (dashboard, agregat) + selalu: Sesi dan Browser. */
export function pintasanWeb(user) {
  const p = [];
  if (dashboardTersedia(user)) p.push({ href: '#/dashboard', ikon: '\u{1F4CA}', judul: 'Dashboard', deskripsi: 'Lihat dashboard sesuai peran' });
  if (agregatBoleh(user, 'penerima-manfaat')) p.push({ href: '#/penerima-manfaat-agregat', ikon: '\u{1F9D2}', judul: 'Ringkasan Penerima Manfaat', deskripsi: 'Jumlah per kelompok dan titik layanan' });
  return p;
}

/** menu: keluaran menuUntuk(user, modul) / menuFor(user). */
export function renderBerandaWeb(el, { user, menu }) {
  const u = user ?? {};
  el.append(h('section', { class: 'hero' },
    h('p', { class: 'muted small' }, 'Selamat datang'),
    h('h2', {}, u.name || ''),
    h('p', { class: 'muted small' }, [u.role_label || roleLabel(u.role), u.sppg_name].filter(Boolean).join(' • '))));
  const adaPeta = petaValid(user);
  if (!adaPeta) el.append(h('div', { class: 'alert-danger', role: 'alert' }, PESAN_PETA_RUSAK));
  el.append(h('h2', { class: 'section-title' }, 'Menu'));
  const grid = h('nav', { class: 'menu-grid', 'aria-label': 'Menu modul' });
  if (adaPeta && !menu.length) grid.append(h('p', { class: 'muted pad' }, 'Belum ada modul yang tersedia untuk akun Anda.'));
  if (adaPeta) for (const p of pintasanWeb(user)) grid.append(kartu(p.href, p.ikon, p.judul, p.deskripsi));
  for (const m of menu) grid.append(kartu(`#/${m.slug}`, m.icon, m.title, m.description));
  grid.append(kartu('#/sesi', '\u{1F4F1}', 'Sesi dan Browser', 'Sesi aktif, browser tepercaya, dan keluar'));
  el.append(grid);
  return grid;
}

/** Layar sementara untuk modul yang layar webnya belum ada. */
export function renderSegera(el, judul) {
  el.append(h('section', { class: 'panel stack' },
    h('h2', { class: 'panel-title' }, judul),
    h('p', { class: 'notice' }, PESAN_SEGERA),
    h('a', { class: 'btn btn-block', href: '#/' }, 'Kembali ke beranda')));
}
