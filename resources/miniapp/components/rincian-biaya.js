import { h, formatValue } from '../core/dom.js';
import { sumberPorsiLabel } from '../modules/persetujuan-menu/logika.js';

const rp = (v) => (v == null ? '-' : formatValue(v, 'rupiah'));
const g = (v) => `${Number(v ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 2 })} g`;

/**
 * Panel rincian biaya: tabel bahan x takaran x harga x pemasok, biaya per porsi, total, dan asumsi porsi.
 * Dipakai layar rincian biaya menu dan detail pengajuan (snapshot). Bahan bermasalah ditandai.
 */
export function rincianBiayaPanel(d, { judul = 'Rincian biaya per porsi' } = {}) {
  const box = h('div', { class: 'panel' }, h('h2', { class: 'panel-title' }, judul));
  const bahan = d.bahan ?? [];
  if (!bahan.length) box.append(h('p', { class: 'muted' }, 'Tidak ada bahan.'));
  const tbl = h('div', { class: 'tbl' });
  for (const b of bahan) {
    tbl.append(h('div', { class: `tbl-row${b.masalah ? ' bad' : ''}` },
      h('span', {}, h('strong', {}, b.nama),
        h('div', { class: 'muted small' }, `${g(b.gram_per_porsi)} per porsi`),
        h('div', { class: 'muted small' }, b.harga_per_satuan == null ? 'Harga belum ada' : `${rp(b.harga_per_satuan)}/${b.satuan ?? 'satuan'} · ${b.pemasok_nama ?? '-'}${b.pemasok_utama ? ' (utama)' : ''}`),
        b.masalah ? h('div', { class: 'small' }, h('span', { class: 'badge badge-danger' }, 'Perlu dilengkapi'), ` ${b.masalah}`) : null),
      h('span', { class: 'num' }, rp(b.biaya_per_porsi))));
  }
  box.append(tbl);
  box.append(h('div', { class: 'card-row' }, h('strong', {}, 'Biaya per porsi'), h('strong', {}, rp(d.biaya_per_porsi))));
  box.append(h('div', { class: 'card-row' }, h('span', { class: 'muted' }, 'Jumlah porsi (asumsi)'), h('span', {}, `${Number(d.jumlah_porsi ?? 0).toLocaleString('id-ID')} porsi`)));
  box.append(h('div', { class: 'card-row' }, h('strong', {}, 'Total biaya'), h('strong', {}, rp(d.total_biaya))));
  box.append(h('div', { class: 'notice' }, h('strong', {}, 'Asumsi porsi: '), sumberPorsiLabel(d.sumber_porsi), d.keterangan_asumsi ? ` — ${d.keterangan_asumsi}` : '',
    h('div', { class: 'muted small' }, 'Jumlah porsi adalah perkiraan, bukan angka pasti.')));
  return box;
}
