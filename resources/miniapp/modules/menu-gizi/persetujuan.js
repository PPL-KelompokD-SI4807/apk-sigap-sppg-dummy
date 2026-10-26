// Bagian persetujuan pada Menu dan Gizi (R-06b-1): rincian biaya, status persetujuan, tombol "Ajukan ke Investor".
import { h } from '../../core/dom.js';
import { api, request, newKey } from '../../core/api.js';
import { getUser } from '../../core/auth.js';
import { userPerms } from '../../core/permissions.js';
import { resolve } from '../../core/router.js';
import { toast, showConfirm } from '../../core/ui.js';
import { rincianBiayaPanel } from '../../components/rincian-biaya.js';
import { SLUG, statusBadge, statusLabel, bolehAjukan, payloadAjukan, ringkasGalat, bahanBermasalah, MAKS_CATATAN } from '../persetujuan-menu/logika.js';

const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);

/** Badge status persetujuan + versi untuk kartu/daftar menu. */
export const statusBadges = (r) => [
  { text: r.status_persetujuan_label || statusLabel(r.status_persetujuan), cls: statusBadge(r.status_persetujuan) },
  ...(r.versi ? [{ text: `v${r.versi}`, cls: '' }] : []),
];

const daftarBermasalah = (items) => h('ul', { class: 'stack-tight' }, items.map((b) => h('li', {}, h('strong', {}, b.nama), `: ${b.masalah}`)));

/** Panel di detail menu: status persetujuan, tautan rincian biaya, dan tombol Ajukan (ahli gizi). */
export function persetujuanPanel(rec) {
  const p = userPerms(getUser(), SLUG);
  const box = h('div', { class: 'panel' }, h('h2', { class: 'panel-title' }, 'Persetujuan investor'),
    h('div', { class: 'card-row' }, h('span', { class: 'muted' }, 'Status'),
      h('span', {}, ...statusBadges(rec).map((b) => h('span', { class: `badge ${b.cls}`.trim() }, b.text), ' '))));
  if (rec.status_persetujuan !== 'disetujui') box.append(h('p', { class: 'muted small' }, 'Hanya menu yang disetujui investor yang dapat dipakai pada Jadwal Produksi.'));
  const aksi = h('div', { class: 'row-actions' }, h('a', { class: 'btn', href: `#/menu-gizi/${rec.id}/rincian-biaya` }, 'Rincian biaya'));
  if (userPerms(getUser(), SLUG).R) aksi.append(h('a', { class: 'btn', href: `#/${SLUG}` }, 'Pengajuan menu'));
  box.append(aksi);
  if (bolehAjukan(p, rec.status_persetujuan)) box.append(ajukanForm(rec));
  return box;
}

function ajukanForm(rec) {
  const catatan = h('textarea', { class: 'input', rows: '2', maxlength: String(MAKS_CATATAN), 'aria-label': 'Catatan untuk investor', placeholder: 'Catatan untuk investor (opsional)' });
  const hasil = h('div', { class: 'stack-tight', 'aria-live': 'polite' });
  const btn = h('button', { class: 'btn btn-primary', type: 'button' }, 'Ajukan ke Investor');
  btn.addEventListener('click', async () => {
    if (!(await showConfirm({ title: 'Ajukan ke Investor', message: `Ajukan "${rec.nama_menu}" (versi ${rec.versi}) ke investor? Harga dan porsi dihitung otomatis oleh sistem.`, okText: 'Ya, ajukan' }))) return;
    btn.disabled = true;
    hasil.replaceChildren();
    try {
      const res = await request('POST', `/menu-gizi/${rec.id}/ajukan`, { body: payloadAjukan({ versi: rec.versi, catatan: catatan.value }), key: newKey(), silent: true });
      toast('Menu diajukan ke investor.', 'success');
      const id = unwrap(res)?.id;
      if (id) location.hash = `#/${SLUG}/${id}`; else resolve();
    } catch (e) {
      if (e?.status === 401) throw e;
      const g = ringkasGalat(e, 'Pengajuan gagal.');
      toast(g.pesan, 'error', 6000);
      if (g.muatUlang) return resolve();
      if (g.bahan.length) hasil.append(h('div', { class: 'alert-danger' }, 'Bahan berikut belum punya harga berlaku atau konversi satuan:', daftarBermasalah(g.bahan)));
      else if (Object.keys(g.fields).length) hasil.append(h('div', { class: 'alert-danger' }, Object.values(g.fields).join(' ')));
      btn.disabled = false;
    }
  });
  return h('div', { class: 'stack-tight' }, catatan, btn, hasil);
}

/** Layar #/menu-gizi/:id/rincian-biaya. */
export async function rincianBiayaScreen(ctx) {
  const d = unwrap(await api.get(`/menu-gizi/${ctx.params.id}/rincian-biaya`));
  if (ctx.isStale()) return;
  ctx.setTitle('Rincian Biaya Menu');
  ctx.el.append(h('div', { class: 'notice' }, `Pratinjau memakai harga pemasok terkini (acuan ${d.tanggal_acuan ?? '-'}). Menu versi ${d.menu_versi}.`));
  const masalah = d.bahan_bermasalah?.length ? d.bahan_bermasalah : bahanBermasalah(d);
  if (!d.lengkap || masalah.length) {
    ctx.el.append(h('div', { class: 'alert-danger' }, 'Rincian belum lengkap, menu belum dapat diajukan. Lengkapi harga/konversi bahan berikut di Pemasok:', daftarBermasalah(masalah)));
  }
  ctx.el.append(rincianBiayaPanel(d));
  ctx.el.append(h('a', { class: 'btn', href: `#/menu-gizi/${ctx.params.id}` }, 'Kembali ke menu'));
}
