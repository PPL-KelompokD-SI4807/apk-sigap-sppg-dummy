import { h } from '../core/dom.js';

// Slot dasbor beranda. Modul mendaftarkan widget: registerWidget({id, title, roles, render(el)}).
// render boleh async dan boleh memakai renderChart() dari core/charts.js (lazy).
const widgets = [];
export const registerWidget = (w) => { if (!widgets.some((x) => x.id === w.id)) widgets.push(w); };

export function renderDashboard(role) {
  const mine = widgets.filter((w) => !w.roles || w.roles.includes(role));
  const slot = h('section', { class: 'dash-slot', id: 'dash-slot', 'aria-label': 'Dasbor' });
  for (const w of mine) {
    const body = h('div', { class: 'dash-body' }, h('p', { class: 'muted small' }, 'Memuat...'));
    slot.append(h('div', { class: 'panel' }, h('h2', { class: 'panel-title' }, w.title), body));
    Promise.resolve(w.render(body)).then(() => body.querySelector('p.muted')?.remove()).catch(() => { body.textContent = 'Widget gagal dimuat.'; });
  }
  return slot;
}
