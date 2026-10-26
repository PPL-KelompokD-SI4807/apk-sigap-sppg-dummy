// Agregat penerima manfaat untuk Investor: hanya hitungan per kelompok sasaran dan titik layanan.
import { h } from '../core/dom.js';
import { api } from '../core/api.js';
import { getUser } from '../core/auth.js';
import { errorMessage } from '../core/errors.js';
import { normalisasiAgregat } from '../core/agregat.js';

function kartuAngka(label, nilai) {
  return h('div', { class: 'panel' }, h('p', { class: 'muted small' }, label), h('h2', {}, String(nilai)));
}

function daftarBaris(judul, rows) {
  const list = h('div', { class: 'cards' });
  if (!rows.length) list.append(h('p', { class: 'muted pad center' }, 'Belum ada data.'));
  for (const r of rows) {
    list.append(h('div', { class: 'row-card' },
      h('span', { class: 'rc-main' }, h('span', { class: 'rc-title' }, r.label), h('div', { class: 'rc-sub' }, `${r.persen}% dari total`)),
      h('span', { class: 'badge badge-info' }, String(r.jumlah))));
  }
  return h('section', {}, h('h2', { class: 'section-title' }, judul), list);
}

export async function renderAgregatPenerima(ctx) {
  ctx.el.append(h('p', { class: 'muted pad' }, 'Memuat ringkasan...'));
  let a;
  try {
    a = normalisasiAgregat(await api.get('/penerima-manfaat/agregat', undefined, { silent: true }));
  } catch (e) {
    ctx.el.replaceChildren(h('p', { class: 'notice' }, errorMessage(e, 'Gagal memuat ringkasan penerima manfaat.')));
    return;
  }
  ctx.el.replaceChildren(
    h('p', { class: 'muted small' }, `Ringkasan jumlah penerima manfaat${getUser()?.role === 'investor' ? ' SPPG terpilih' : ''}. Data pribadi tidak ditampilkan.`),
    h('div', { class: 'stack' }, kartuAngka('Total', a.total), kartuAngka('Aktif', a.aktif), kartuAngka('Nonaktif', a.nonaktif)),
    daftarBaris('Per kelompok sasaran', a.kelompok),
    daftarBaris('Per titik layanan', a.titik));
}
