// Bahan Baku (fitur 6, PBI 6): daftar + penanda, tab stok menipis/kedaluwarsa dekat, mutasi stok, batch FEFO. Mockup: Gambar 11.
import { defineCrudModule } from '../../components/crud-module.js';
import { createListView } from '../../components/list-view.js';
import { createFormView } from '../../components/form-view.js';
import { navTabs } from '../../components/nav-tabs.js';
import { rowCard } from '../../components/row-card.js';
import { h, clear, formatValue } from '../../core/dom.js';
import { api, submitWithQueue } from '../../core/api.js';
import { getUser } from '../../core/auth.js';
import { userPerms } from '../../core/permissions.js';
import { route, navigate } from '../../core/router.js';
import { toast } from '../../core/ui.js';
import { bahanFields, mapPayload, kategoriLabel, penandaBahan, fmtQty, sisaHariText, sisaHariCls, jenisMutasiLabel, mutasiFields, cekMutasi, mutasiPayload } from './schema.js';

const unwrap = (r) => (r && r.data !== undefined && !Array.isArray(r) ? r.data : r);
const SLUG = 'bahan-baku';
const me = () => userPerms(getUser(), SLUG);

export const bahanTabs = (active) => navTabs([
  { key: 'semua', href: '#/bahan-baku', label: 'Semua' },
  { key: 'menipis', href: '#/bahan-baku/stok-menipis', label: 'Stok menipis' },
  { key: 'kedaluwarsa', href: '#/bahan-baku/kedaluwarsa-dekat', label: 'Kedaluwarsa dekat' },
], active, 'Bahan baku');

const card = (r, href) => rowCard({
  href,
  title: r.nama,
  sub: [kategoriLabel(r.kategori), r.kode, r.kedaluwarsa_terdekat ? `Exp ${formatValue(r.kedaluwarsa_terdekat, 'date')}` : null].filter(Boolean).join(' · '),
  aside: fmtQty(r.stok, r.satuan),
  asideSub: `min ${fmtQty(r.stok_minimum)}`,
  badges: penandaBahan(r),
});

const columns = [{ key: 'nama', primary: true }, { key: 'stok', label: 'Stok', type: 'number' }];

async function batchPanel(rec) {
  const box = h('div', { class: 'panel' }, h('h2', { class: 'panel-title' }, 'Batch (urutan FEFO)'));
  try {
    const res = await api.get(`/bahan-baku/${rec.id}/batch`, null, { silent: true });
    const rows = res?.data ?? [];
    if (!rows.length) box.append(h('p', { class: 'muted' }, 'Belum ada batch bertanggal kedaluwarsa.'));
    const tbl = h('div', { class: 'tbl' });
    rows.forEach((b, i) => tbl.append(h('div', { class: 'tbl-row' },
      h('span', {}, `${i === 0 ? 'Dipakai dulu: ' : ''}masuk ${formatValue(b.tanggal_masuk, 'date')}`, h('div', { class: 'muted small' }, b.tanggal_kedaluwarsa ? `Exp ${formatValue(b.tanggal_kedaluwarsa, 'date')}` : 'Tanpa tanggal kedaluwarsa')),
      h('span', { class: `badge ${sisaHariCls(b.sisa_hari)}`.trim() }, sisaHariText(b.sisa_hari)),
      h('span', { class: 'num' }, h('strong', {}, fmtQty(b.sisa, rec.satuan)), h('div', { class: 'muted small' }, `dari ${fmtQty(b.jumlah_awal)}`)))));
    box.append(tbl);
    if (Number(res?.stok_tanpa_batch) > 0) box.append(h('p', { class: 'muted small' }, `Stok tanpa batch (stok awal/penyesuaian): ${fmtQty(res.stok_tanpa_batch, rec.satuan)}`));
  } catch (e) {
    box.append(h('p', { class: 'muted' }, e.status === 403 ? 'Tidak ada hak melihat batch.' : 'Batch gagal dimuat.'));
  }
  return box;
}

async function riwayatPanel(rec) {
  const box = h('div', { class: 'panel' }, h('h2', { class: 'panel-title' }, 'Riwayat mutasi terbaru'));
  try {
    const res = await api.get(`/bahan-baku/${rec.id}/mutasi`, { per_page: 8 }, { silent: true });
    const rows = res?.data ?? [];
    if (!rows.length) box.append(h('p', { class: 'muted' }, 'Belum ada mutasi.'));
    const tbl = h('div', { class: 'tbl' });
    rows.forEach((m) => tbl.append(h('div', { class: 'tbl-row' },
      h('span', {}, jenisMutasiLabel(m.jenis), h('div', { class: 'muted small' }, [formatValue(m.tanggal_mutasi, 'date'), m.pencatat, m.catatan].filter(Boolean).join(' · '))),
      h('span', { class: 'num' }, `${m.jenis === 'keluar' ? '-' : (Number(m.jumlah) > 0 ? '+' : '')}${fmtQty(m.jumlah, rec.satuan)}`))));
    box.append(tbl);
  } catch { box.append(h('p', { class: 'muted' }, 'Riwayat gagal dimuat.')); }
  return box;
}

const base = defineCrudModule({
  slug: SLUG,
  title: 'Bahan Baku',
  icon: '🥬',
  description: 'Stok, mutasi, batch, dan kedaluwarsa',
  endpoint: '/bahan-baku',
  columns,
  fields: ({ mode, user, record }) => (mode === 'detail'
    ? [
      { name: 'kode', label: 'Kode' }, { name: 'kategori', label: 'Kategori', display: (r) => kategoriLabel(r.kategori) },
      { name: 'satuan', label: 'Satuan' },
      { name: 'stok', label: 'Stok saat ini', display: (r) => fmtQty(r.stok, r.satuan) },
      { name: 'stok_minimum', label: 'Stok minimum', display: (r) => fmtQty(r.stok_minimum, r.satuan) },
      { name: 'kedaluwarsa_terdekat', label: 'Kedaluwarsa terdekat', type: 'date' },
      { name: 'pemasok_id', label: 'Pemasok', type: 'relasi', relation: { as: 'pemasok', label: 'nama' } },
    ]
    : bahanFields({ mode, user, record })),
  mapPayload,
  searchPlaceholder: 'Cari nama atau kode bahan...',
  filters: { param: 'kategori', label: 'Kategori', options: [{ value: '', label: 'Semua' }, ...['karbohidrat', 'protein_hewani', 'sayur', 'buah', 'susu', 'bumbu'].map((v) => ({ value: v, label: kategoriLabel(v) }))] },
  listTop: () => bahanTabs('semua'),
  renderCard: card,
  detailExtras: async (rec, p) => {
    const nodes = [];
    const flags = penandaBahan(rec);
    if (rec.stok_menipis) nodes.push(h('div', { class: 'alert-danger' }, `Stok menipis: ${fmtQty(rec.stok, rec.satuan)} (minimum ${fmtQty(rec.stok_minimum)}). Segera catat penerimaan dari pemasok.`));
    if (rec.akan_kedaluwarsa) nodes.push(h('div', { class: 'notice' }, 'Ada batch yang segera atau sudah kedaluwarsa. Pakai batch terdekat lebih dulu (FEFO).'));
    if (flags.length === 0 && Number(rec.stok) > 0) nodes.push(h('div', { class: 'alert-ok' }, 'Stok aman.'));
    if (p.U) nodes.push(h('a', { class: 'btn btn-primary btn-block', href: `#/bahan-baku/${rec.id}/mutasi` }, 'Catat mutasi stok'));
    nodes.push(await batchPanel(rec), await riwayatPanel(rec));
    return nodes;
  },
});

// ---- Layar tambahan ----
function listScreen(endpoint, tab, emptyText) {
  return (ctx) => {
    ctx.el.append(bahanTabs(tab));
    const lv = createListView({ endpoint, columns, renderCard: card, itemHref: (r) => `#/bahan-baku/${r.id}`, emptyText, searchable: false });
    ctx.el.append(lv.el);
  };
}

async function mutasiScreen(ctx) {
  const rec = unwrap(await api.get(`/bahan-baku/${ctx.params.id}`));
  if (ctx.isStale()) return;
  ctx.setTitle(`Mutasi: ${rec.nama}`);
  clear(ctx.el);
  ctx.el.append(h('div', { class: 'panel' }, h('strong', {}, rec.nama), h('div', { class: 'muted' }, `Stok saat ini ${fmtQty(rec.stok, rec.satuan)} · minimum ${fmtQty(rec.stok_minimum)}`)));
  let fv;
  fv = createFormView({
    fields: mutasiFields({ pemasokId: rec.pemasok_id }).map((f) => (f.name === 'jumlah' ? { ...f, label: `Jumlah (${rec.satuan})`, validate: (v, all) => cekMutasi(all, rec.stok).jumlah || '' } : f.name === 'catatan' ? { ...f, validate: (v, all) => cekMutasi(all, rec.stok).catatan || '' } : f)),
    values: {},
    submitText: 'Catat mutasi',
    onSubmit: async (v) => {
      const res = await submitWithQueue('POST', `/bahan-baku/${rec.id}/mutasi`, mutasiPayload(v), { label: `Mutasi ${rec.nama}` });
      if (res.queued) toast('Tersimpan sebagai draf offline, dikirim saat online.', 'info');
      else toast(`Mutasi tercatat. Stok sekarang ${fmtQty(res.data?.bahan_baku?.stok, rec.satuan)}.`, 'success');
      navigate(`/bahan-baku/${rec.id}`, { replace: true });
    },
  });
  ctx.el.append(fv.el);
  return fv.destroy;
}

export default {
  ...base,
  register() {
    // Rute statis harus didaftarkan SEBELUM /bahan-baku/:id.
    route('/bahan-baku/stok-menipis', listScreen('/bahan-baku/stok-menipis', 'menipis', 'Tidak ada bahan dengan stok menipis.'), { title: 'Stok Menipis', guard: () => me().R });
    route('/bahan-baku/kedaluwarsa-dekat', listScreen('/bahan-baku/kedaluwarsa-dekat', 'kedaluwarsa', 'Tidak ada bahan yang segera kedaluwarsa.'), { title: 'Kedaluwarsa Dekat', guard: () => me().R });
    route('/bahan-baku/:id/mutasi', mutasiScreen, { title: 'Catat Mutasi Stok', guard: () => me().U });
    base.register();
  },
};
