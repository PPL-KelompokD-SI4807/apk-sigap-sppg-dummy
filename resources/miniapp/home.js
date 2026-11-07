import { h } from './core/dom.js';
import { getUser } from './core/auth.js';
import { roleLabel, petaValid, dashboardTersedia, agregatBoleh, multiSppg } from './core/permissions.js';
import { menuFor } from './modules/registry.js';
import { renderDashboard } from './components/dashboard.js';
import { countQueue } from './core/offline-queue.js';
import { flushQueue } from './core/api.js';
import { getSppgAktif } from './core/sppg-aktif.js';

const kartu = (href, ikon, judul, deskripsi) => h('a', { class: 'menu-card', href },
  h('span', { class: 'menu-icon', 'aria-hidden': 'true' }, ikon),
  h('span', { class: 'menu-title' }, judul),
  deskripsi ? h('span', { class: 'muted small' }, deskripsi) : null);

export async function renderHome(ctx) {
  const user = getUser();
  const adaPeta = petaValid(user);
  const menu = menuFor(user);
  ctx.el.append(
    h('section', { class: 'hero' },
      h('p', { class: 'muted small' }, 'Selamat datang'),
      h('h2', {}, user.name),
      h('p', { class: 'muted small' }, [roleLabel(user.role), user.sppg_name].filter(Boolean).join(' • '))));

  const pending = await countQueue();
  if (pending) {
    ctx.el.append(h('div', { class: 'notice' }, `${pending} draf offline menunggu dikirim. `,
      h('button', { class: 'link', type: 'button', onclick: () => flushQueue().then(() => location.reload()) }, 'Kirim sekarang')));
  }

  if (multiSppg(user)) {
    ctx.el.append(h('div', { class: 'notice' }, getSppgAktif() ? `SPPG aktif: #${getSppgAktif()}. ` : '',
      h('a', { class: 'link', href: '#/pilih-sppg' }, 'Ganti SPPG')));
  }

  // Peta hak tidak diterima/rusak: gagal tertutup (tanpa menu modul, kartu dashboard, atau widget).
  if (adaPeta) ctx.el.append(renderDashboard(user));
  else ctx.el.append(h('div', { class: 'alert-danger', role: 'alert' }, 'Hak akses akun Anda tidak dapat dimuat, jadi menu disembunyikan. Tutup lalu buka ulang aplikasi, atau hubungi Admin bila berulang.'));

  ctx.el.append(h('h2', { class: 'section-title' }, 'Menu'));
  const grid = h('nav', { class: 'menu-grid', 'aria-label': 'Menu modul' });
  if (adaPeta && !menu.length) grid.append(h('p', { class: 'muted pad' }, 'Belum ada modul yang tersedia untuk akun Anda.'));
  for (const m of menu) grid.append(kartu(`#/${m.slug}`, m.icon, m.title, m.description));
  if (agregatBoleh(user, 'penerima-manfaat')) {
    grid.prepend(kartu('#/penerima-manfaat-agregat', '🧒', 'Ringkasan Penerima Manfaat', 'Jumlah per kelompok dan titik layanan'));
  }
  // Kartu Dashboard hanya bila server menyatakan tersedia (relawan tanpa unit = tidak ada).
  if (dashboardTersedia(user)) grid.prepend(kartu('#/dashboard', '\u{1F4CA}', 'Dashboard', 'Lihat dashboard sesuai peran'));
  // Perangkat & Sesi = pengelolaan akun sendiri (keluar/cabut sesi), bukan modul data; selalu ada agar pengguna bisa keluar.
  grid.append(kartu('#/sesi', '\u{1F4F1}', 'Perangkat & Sesi', 'Perangkat aktif dan keluar'));
  ctx.el.append(grid);
}
