import { h } from './core/dom.js';
import { getUser } from './core/auth.js';
import { roleLabel } from './core/permissions.js';
import { menuFor } from './modules/registry.js';
import { renderDashboard } from './components/dashboard.js';
import { countQueue } from './core/offline-queue.js';
import { flushQueue } from './core/api.js';
import { isInvestor, getSppgAktif } from './core/sppg-aktif.js';

export async function renderHome(ctx) {
  const user = getUser();
  const menu = menuFor(user.role);
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

  if (isInvestor(user)) {
    ctx.el.append(h('div', { class: 'notice' }, getSppgAktif() ? `SPPG aktif: #${getSppgAktif()}. ` : '',
      h('a', { class: 'link', href: '#/pilih-sppg' }, 'Ganti SPPG')));
  }

  ctx.el.append(renderDashboard(user.role));

  ctx.el.append(h('h2', { class: 'section-title' }, 'Menu'));
  const grid = h('nav', { class: 'menu-grid', 'aria-label': 'Menu modul' });
  if (!menu.length) grid.append(h('p', { class: 'muted pad' }, 'Belum ada modul yang tersedia untuk peran Anda.'));
  for (const m of menu) {
    grid.append(h('a', { class: 'menu-card', href: `#/${m.slug}` },
      h('span', { class: 'menu-icon', 'aria-hidden': 'true' }, m.icon),
      h('span', { class: 'menu-title' }, m.title),
      m.description ? h('span', { class: 'muted small' }, m.description) : null));
  }
  if (isInvestor(user)) {
    grid.prepend(h('a', { class: 'menu-card', href: '#/penerima-manfaat-agregat' },
      h('span', { class: 'menu-icon', 'aria-hidden': 'true' }, '🧒'),
      h('span', { class: 'menu-title' }, 'Ringkasan Penerima Manfaat'),
      h('span', { class: 'muted small' }, 'Jumlah per kelompok dan titik layanan')));
  }
  grid.append(h('a', { class: 'menu-card', href: '#/sesi' },
    h('span', { class: 'menu-icon', 'aria-hidden': 'true' }, '\u{1F4F1}'),
    h('span', { class: 'menu-title' }, 'Perangkat & Sesi'),
    h('span', { class: 'muted small' }, 'Perangkat aktif dan keluar')));
  ctx.el.append(grid);
}
