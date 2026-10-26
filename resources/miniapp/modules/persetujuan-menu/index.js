// Persetujuan Menu oleh Investor (R-06b-1). Rute: #/persetujuan-menu dan #/persetujuan-menu/<id>.
// Investor memutuskan lewat POST /persetujuan-menu/{id}/keputusan; peran lain hanya membaca.
import { h, formatValue } from '../../core/dom.js';
import { api, request, newKey } from '../../core/api.js';
import { getUser } from '../../core/auth.js';
import { userPerms } from '../../core/permissions.js';
import { route, resolve } from '../../core/router.js';
import { toast, showConfirm } from '../../core/ui.js';
import { createListView } from '../../components/list-view.js';
import { rowCard } from '../../components/row-card.js';
import { rincianBiayaPanel } from '../../components/rincian-biaya.js';
import { SLUG, STATUS_PENGAJUAN, statusBadge, statusLabel, KEPUTUSAN, tombolKeputusanTampil, validasiCatatan, payloadKeputusan, ringkasGalat, MIN_CATATAN } from './logika.js';

const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);
const me = () => userPerms(getUser(), SLUG);
const rp = (v) => formatValue(v, 'rupiah');

function riwayatPanel(rec, daftar) {
  const lain = daftar.filter((x) => x.id !== rec.id);
  const box = h('div', { class: 'panel' }, h('h2', { class: 'panel-title' }, 'Riwayat keputusan menu ini'));
  if (!lain.length) {
    box.append(h('p', { class: 'muted' }, 'Belum ada pengajuan lain untuk menu ini.'));
    return box;
  }
  for (const x of lain) {
    box.append(rowCard({
      href: `#/${SLUG}/${x.id}`,
      title: `Pengajuan #${x.nomor} · versi ${x.menu_versi}`,
      sub: [x.diputuskan_oleh ? `oleh ${x.diputuskan_oleh}` : null, formatValue(x.diputuskan_pada ?? x.diajukan_pada, 'datetime')].filter(Boolean).join(' · '),
      badge: { text: x.status_label || statusLabel(x.status), cls: statusBadge(x.status) },
    }));
  }
  return box;
}

/** Panel keputusan investor: catatan + tiga tombol. 409 -> pesan server lalu muat ulang. */
function keputusanPanel(rec) {
  const catatan = h('textarea', { class: 'input', rows: '3', maxlength: '1000', id: 'catatan-keputusan', placeholder: `Wajib diisi untuk Tolak/Perlu Revisi (min. ${MIN_CATATAN} karakter)` });
  const err = h('p', { class: 'field-error', role: 'alert' });
  const row = h('div', { class: 'row-actions' });
  const tombol = [];
  async function kirim(keputusan, label) {
    const salah = validasiCatatan(keputusan, catatan.value);
    err.textContent = salah;
    if (salah) { catatan.focus(); return; }
    if (!(await showConfirm({ title: label, message: `${label} menu "${rec.nama_menu}" (versi ${rec.menu_versi})? Keputusan tidak dapat diubah.`, okText: 'Ya, kirim', destructive: keputusan === 'ditolak' }))) return;
    tombol.forEach((b) => { b.disabled = true; });
    try {
      await request('POST', `/persetujuan-menu/${rec.id}/keputusan`, { body: payloadKeputusan({ keputusan, catatan: catatan.value, versiMenu: rec.menu_versi }), key: newKey(), silent: true });
      toast('Keputusan tersimpan.', 'success');
      resolve();
    } catch (e) {
      if (e?.status === 401) throw e;
      const g = ringkasGalat(e, 'Keputusan gagal dikirim.');
      toast(g.pesan, 'error', 6000);
      err.textContent = g.fields.catatan || g.fields.keputusan || g.fields.versi_menu || '';
      if (g.muatUlang) { resolve(); return; }
      tombol.forEach((b) => { b.disabled = false; });
    }
  }
  for (const k of KEPUTUSAN) {
    const b = h('button', { class: `btn ${k.value === 'disetujui' ? 'btn-primary' : k.value === 'ditolak' ? 'btn-danger' : ''}`.trim(), type: 'button', onclick: () => kirim(k.value, k.label) }, k.label);
    tombol.push(b);
    row.append(b);
  }
  return h('div', { class: 'panel' }, h('h2', { class: 'panel-title' }, 'Keputusan Anda'),
    h('label', { class: 'label', for: 'catatan-keputusan' }, 'Catatan'), catatan, err, row);
}

async function detailScreen(ctx) {
  const rec = unwrap(await api.get(`/persetujuan-menu/${ctx.params.id}`));
  if (ctx.isStale()) return;
  ctx.setTitle(rec.nama_menu ?? 'Pengajuan Menu');
  const ket = h('dl', { class: 'detail' });
  const baris = [
    ['Menu', rec.nama_menu], ['Status', rec.status_label || statusLabel(rec.status)], ['Pengajuan ke', `#${rec.nomor} (versi menu ${rec.menu_versi})`],
    ['Diajukan oleh', rec.diajukan_oleh], ['Diajukan pada', formatValue(rec.diajukan_pada, 'datetime')],
    ['Catatan pengaju', rec.catatan_pengaju],
    ...(rec.diputuskan_pada ? [['Diputuskan oleh', rec.diputuskan_oleh ?? 'Sistem'], ['Diputuskan pada', formatValue(rec.diputuskan_pada, 'datetime')], ['Catatan keputusan', rec.catatan_keputusan]] : []),
  ];
  for (const [k, v] of baris) ket.append(h('dt', {}, k), h('dd', {}, formatValue(v)));
  ctx.el.append(h('div', { class: 'panel' }, ket));
  if (rec.kedaluwarsa) ctx.el.append(h('div', { class: 'alert-danger' }, `Menu sudah berubah sejak diajukan (versi sekarang ${rec.menu_versi_sekarang ?? '-'}). Pengajuan ini tidak dapat diputuskan.`));

  const r = rec.rincian ?? {};
  const gizi = r.menu?.gizi_per_porsi;
  if (gizi) {
    ctx.el.append(h('div', { class: 'panel' }, h('h2', { class: 'panel-title' }, 'Gizi per porsi'),
      h('div', { class: 'card-row' }, h('span', {}, `Energi ${gizi.energi_kkal ?? 0} kkal`), h('span', {}, `Protein ${gizi.protein_g ?? 0} g`)),
      h('div', { class: 'card-row' }, h('span', {}, `Lemak ${gizi.lemak_g ?? 0} g`), h('span', {}, `Karbohidrat ${gizi.karbohidrat_g ?? 0} g`)),
      h('div', { class: 'card-row' }, h('span', {}, `Serat ${gizi.serat_g ?? 0} g`))));
  }
  ctx.el.append(rincianBiayaPanel({ ...rec, bahan: r.bahan }, { judul: `Rincian biaya (harga per ${formatValue(r.tanggal_acuan, 'date')})` }));

  if (tombolKeputusanTampil(getUser(), rec)) ctx.el.append(keputusanPanel(rec));

  let daftar = [];
  try { daftar = (await api.get('/persetujuan-menu', { menu_id: rec.menu_id, per_page: 50 }, { silent: true }))?.data ?? []; } catch { /* riwayat opsional */ }
  if (ctx.isStale()) return;
  ctx.el.append(riwayatPanel(rec, daftar));
}

function listScreen(ctx) {
  ctx.el.append(createListView({
    endpoint: '/persetujuan-menu',
    columns: [{ key: 'nama_menu', primary: true }],
    searchable: false,
    filters: { param: 'status', label: 'Status pengajuan', options: [{ value: '', label: 'Semua' }, ...STATUS_PENGAJUAN] },
    itemHref: (r) => `#/${SLUG}/${r.id}`,
    emptyText: 'Belum ada pengajuan menu.',
    renderCard: (r, href) => rowCard({
      href,
      title: r.nama_menu ?? `Pengajuan #${r.id}`,
      sub: [`Pengajuan #${r.nomor} · versi ${r.menu_versi}`, `${rp(r.biaya_per_porsi)}/porsi`, r.diajukan_oleh].filter(Boolean).join(' · '),
      aside: rp(r.total_biaya),
      asideSub: `${Number(r.jumlah_porsi ?? 0).toLocaleString('id-ID')} porsi`,
      badge: { text: r.status_label || statusLabel(r.status), cls: statusBadge(r.status) },
    }),
  }).el);
}

export default {
  slug: SLUG,
  title: 'Persetujuan Menu',
  icon: '✅',
  description: 'Pengajuan menu ke investor',
  permissionSlug: SLUG,
  register() {
    route(`/${SLUG}`, listScreen, { title: 'Persetujuan Menu', guard: () => me().R });
    route(`/${SLUG}/:id`, detailScreen, { title: 'Pengajuan Menu', guard: () => me().R });
  },
};
