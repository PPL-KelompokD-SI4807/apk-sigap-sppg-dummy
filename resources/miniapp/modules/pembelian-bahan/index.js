// Pembelian Bahan (R-06b-2). Rute: #/pembelian-bahan, /baru, /:id, /:id/ubah.
// Staf gudang mencatat pembelian + foto nota (1-3); akuntan memverifikasi di layar detail; investor tidak punya akses (403).
// Nota diunggah dulu ke POST /lampiran (modul pembelian-bahan), lalu id-nya dikirim sebagai lampiran_ids.
import { defineCrudModule } from '../../components/crud-module.js';
import { rowCard } from '../../components/row-card.js';
import { createAuthImage } from '../../components/photo-upload.js';
import { lampiranField } from '../../components/lampiran-field.js';
import { h, formatValue } from '../../core/dom.js';
import { fetchList, request, newKey } from '../../core/api.js';
import { getUser } from '../../core/auth.js';
import { userPerms } from '../../core/permissions.js';
import { resolve } from '../../core/router.js';
import { toast, showConfirm } from '../../core/ui.js';
import { ringkasGalatAksi } from '../../core/errors.js';
import { todayWIB } from '../../core/waktu.js';
import { formatRupiah } from '../keuangan/schema.js';
import {
  SLUG, MAKS_NOTA, STATUS_PEMBELIAN, statusBadge, statusLabel, bolehUbahPembelian, tombolVerifikasiTampil, KEPUTUSAN_VERIFIKASI,
  validasiJumlah, validasiHargaSatuan, validasiTanggalBeli, validasiNota, hitungTotal, payloadPembelian,
  validasiAlasan, payloadVerifikasi, ringkasHargaReferensi, MAKS_ALASAN,
} from './logika.js';

const angka = (v) => Number(v ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 3 });

// ---- Skema form ----
function notaField(mode, record) {
  const existing = record?.lampirans || [];
  const base = lampiranField({
    modul: SLUG, label: 'Foto nota', kategori: 'nota', max: MAKS_NOTA, existing,
    help: `Wajib, 1-${MAKS_NOTA} foto (JPEG/PNG/WebP, maks 5 MB). Terunggah langsung; Simpan menunggu semua foto selesai.`,
  });
  return { ...base, validate: (ids) => base.validate() || validasiNota({ mode, jumlahBaru: Array.isArray(ids) ? ids.length : 0, jumlahTersimpan: existing.length }) };
}

function formFields({ mode, record }) {
  return [
    { name: 'tanggal_beli', label: 'Tanggal beli', type: 'date', required: true, default: todayWIB(), validate: (v) => validasiTanggalBeli(v) },
    { name: 'bahan_baku_id', label: 'Bahan baku', type: 'relasi', required: true, relation: { endpoint: '/bahan-baku', value: 'id', filter: (b) => b.is_active !== false, format: (b) => (b.satuan ? `${b.nama} (${b.satuan})` : b.nama) } },
    { name: 'pemasok_id', label: 'Pemasok', type: 'relasi', required: true, relation: { endpoint: '/pemasok', label: 'nama', value: 'id', filter: (p) => p.is_active !== false } },
    { name: 'jumlah', label: 'Jumlah (dalam satuan bahan)', type: 'number', required: true, step: 'any', placeholder: 'Mis. 50', validate: (v) => validasiJumlah(v) },
    { name: 'harga_satuan', label: 'Harga per satuan (Rp)', type: 'number', required: true, step: 'any', placeholder: 'Mis. 12500', validate: (v) => validasiHargaSatuan(v) },
    { name: 'catatan', label: 'Catatan (opsional)', type: 'textarea', maxlength: 500 },
    notaField(mode, record),
  ];
}

const detailFields = [
  { name: 'tanggal_beli', label: 'Tanggal beli', type: 'date' },
  { name: 'status', label: 'Status', display: (r) => r.status_label || statusLabel(r.status) },
  { name: 'bahan_baku', label: 'Bahan baku', display: (r) => r.bahan_baku?.nama ?? '-' },
  { name: 'pemasok', label: 'Pemasok', display: (r) => r.pemasok?.nama ?? '-' },
  { name: 'jumlah', label: 'Jumlah', display: (r) => `${angka(r.jumlah)} ${r.satuan ?? ''}`.trim() },
  { name: 'harga_satuan', label: 'Harga per satuan', display: (r) => `${formatRupiah(r.harga_satuan)}${r.satuan ? `/${r.satuan}` : ''}` },
  { name: 'total', label: 'Total', display: (r) => formatRupiah(r.total) },
  { name: 'catatan', label: 'Catatan' },
  { name: 'dicatat_oleh', label: 'Dicatat oleh', display: (r) => r.dicatat_oleh?.name ?? '-' },
  { name: 'diverifikasi_oleh', label: 'Diverifikasi oleh', display: (r) => r.diverifikasi_oleh?.name ?? '-', onlyIfPresent: true },
  { name: 'diverifikasi_pada', label: 'Diverifikasi pada', type: 'datetime', onlyIfPresent: true },
];

// ---- Atas form: perkiraan total + alasan penolakan ----
function formTop({ mode, record, form, collect }) {
  const box = h('div', { class: 'stack-tight', 'aria-live': 'polite' });
  if (mode === 'update' && record?.status === 'ditolak') {
    box.append(h('div', { class: 'alert-danger', role: 'alert' }, `Ditolak akuntan: ${record.alasan_penolakan ?? '-'}. Menyimpan perubahan mengajukan ulang pembelian ini.`));
  }
  const total = h('div', { class: 'notice' });
  function hitung() {
    const v = collect();
    const t = hitungTotal(v.jumlah, v.harga_satuan);
    total.textContent = t == null ? 'Total dihitung otomatis dari jumlah x harga satuan.' : `Perkiraan total: ${formatRupiah(t)} (dihitung ulang oleh server).`;
  }
  form.addEventListener('input', hitung);
  form.addEventListener('change', hitung);
  hitung();
  box.append(total);
  return box;
}

// ---- Daftar ----
const listQuery = { from: '', to: '', bahan_baku_id: '', pemasok_id: '' };

function selectRelasi(id, label, param, endpoint, format) {
  const sel = h('select', { id, 'aria-label': label }, h('option', { value: '' }, label));
  fetchList(endpoint, { perPage: 100 }).then(({ items }) => {
    items.forEach((it) => sel.append(h('option', { value: it.id }, format(it))));
    sel.value = listQuery[param];
  }).catch(() => { /* filter opsional */ });
  sel.addEventListener('change', () => { listQuery[param] = sel.value; resolve(); });
  return sel;
}
function inputTanggal(id, label, param) {
  const el = h('input', { type: 'date', id, value: listQuery[param], 'aria-label': label, title: label });
  el.addEventListener('change', () => { listQuery[param] = el.value; resolve(); });
  return el;
}
function listTop() {
  return h('div', { class: 'stack' },
    h('div', { class: 'filter-row' }, inputTanggal('f-dari', 'Dari tanggal', 'from'), inputTanggal('f-sampai', 'Sampai tanggal', 'to')),
    h('div', { class: 'filter-row' },
      selectRelasi('f-bahan', 'Semua bahan', 'bahan_baku_id', '/bahan-baku', (b) => b.nama),
      selectRelasi('f-pemasok', 'Semua pemasok', 'pemasok_id', '/pemasok', (p) => p.nama)));
}

// ---- Panel verifikasi akuntan ----
function verifikasiPanel(rec) {
  const alasan = h('textarea', { class: 'input', id: 'alasan-verifikasi', rows: '3', maxlength: String(MAKS_ALASAN), placeholder: 'Wajib diisi bila menolak (min. 5 karakter)' });
  const err = h('p', { class: 'field-error', role: 'alert' });
  const tombol = [];
  async function kirim(keputusan, label) {
    const salah = validasiAlasan(keputusan, alasan.value);
    err.textContent = salah;
    if (salah) { alasan.focus(); return; }
    const pesan = keputusan === 'disetujui'
      ? `Setujui pembelian ${rec.bahan_baku?.nama ?? 'bahan'} senilai ${formatRupiah(rec.total)}? Pengeluaran bahan baku akan dibuat dan dihitung ke anggaran. Keputusan tidak dapat diubah.`
      : `Tolak pembelian ${rec.bahan_baku?.nama ?? 'bahan'} senilai ${formatRupiah(rec.total)}? Staf gudang dapat memperbaiki lalu mengajukan ulang.`;
    if (!(await showConfirm({ title: label, message: pesan, okText: 'Ya, kirim', destructive: keputusan === 'ditolak' }))) return;
    tombol.forEach((b) => { b.disabled = true; });
    try {
      await request('POST', `/${SLUG}/${rec.id}/verifikasi`, { body: payloadVerifikasi({ keputusan, alasan: alasan.value, versi: rec.versi }), key: newKey(), silent: true });
      toast(keputusan === 'disetujui' ? 'Pembelian diverifikasi dan dicatat sebagai pengeluaran.' : 'Pembelian ditolak.', 'success');
      resolve();
    } catch (e) {
      if (e?.status === 401) throw e;
      const g = ringkasGalatAksi(e, 'Verifikasi gagal dikirim.');
      toast(g.pesan, 'error', 6000);
      err.textContent = g.fields.alasan || g.fields.keputusan || g.fields.nominal || '';
      if (g.muatUlang) { resolve(); return; }
      tombol.forEach((b) => { b.disabled = false; });
    }
  }
  const row = h('div', { class: 'row-actions' });
  for (const k of KEPUTUSAN_VERIFIKASI) {
    const b = h('button', { class: `btn ${k.value === 'disetujui' ? 'btn-primary' : 'btn-danger'}`, type: 'button', onclick: () => kirim(k.value, k.label) }, k.label);
    tombol.push(b);
    row.append(b);
  }
  return h('div', { class: 'panel stack-tight' }, h('h2', { class: 'panel-title' }, 'Verifikasi pembelian'),
    h('p', { class: 'muted small' }, 'Periksa foto nota dan harga di atas sebelum memutuskan.'),
    h('label', { class: 'label', for: 'alasan-verifikasi' }, 'Alasan'), alasan, err, row);
}

function hargaReferensiPanel(rec) {
  const ref = rec.harga_referensi;
  const r = ringkasHargaReferensi(ref);
  const box = h('div', { class: 'panel stack-tight' }, h('h2', { class: 'panel-title' }, 'Harga referensi pemasok'));
  if (!r.ada) { box.append(h('p', { class: 'muted small' }, r.teks)); return box; }
  box.append(
    h('div', { class: 'card-row' }, h('span', { class: 'muted' }, 'Harga referensi'), h('span', {}, `${formatRupiah(ref.harga)}${rec.satuan ? `/${rec.satuan}` : ''}`)),
    h('div', { class: 'card-row' }, h('span', { class: 'muted' }, 'Berlaku mulai'), h('span', {}, formatValue(ref.berlaku_mulai, 'date'))),
    h('div', { class: 'card-row' }, h('span', { class: 'muted' }, 'Harga pembelian ini'), h('span', {}, formatRupiah(rec.harga_satuan))),
    h('p', { class: 'small' }, h('span', { class: `badge ${r.tone}`.trim() }, 'Selisih'), ' ', r.teks));
  return box;
}

function detailExtras(rec, p) {
  const nodes = [];
  const user = getUser();
  if (rec.status === 'ditolak') {
    nodes.push(h('div', { class: 'alert-danger', role: 'alert' }, h('strong', {}, 'Ditolak akuntan. '), rec.alasan_penolakan ?? '-',
      p.U ? ' Ubah data lalu simpan untuk mengajukan ulang.' : ''));
  }
  if (rec.status === 'terverifikasi') {
    nodes.push(h('div', { class: 'alert-ok' }, 'Pembelian terverifikasi dan sudah tercatat sebagai pengeluaran bahan baku. Data ini tidak dapat diubah. ',
      rec.pengeluaran_id && userPerms(user, 'keuangan').R ? h('a', { class: 'link', href: `#/keuangan/pengeluaran/${rec.pengeluaran_id}` }, 'Lihat pengeluaran') : null));
  }
  const nota = (rec.lampirans || []).filter((l) => l?.url);
  nodes.push(h('section', { class: 'panel stack-tight', 'aria-label': 'Foto nota' }, h('h2', { class: 'panel-title' }, 'Foto nota'),
    nota.length ? h('div', { class: 'photo-grid' }, nota.map((l, i) => createAuthImage(l, { alt: `Foto nota ${i + 1}` }))) : h('p', { class: 'muted small' }, 'Tidak ada foto nota.')));
  nodes.push(hargaReferensiPanel(rec));
  if (tombolVerifikasiTampil(user, rec)) nodes.push(verifikasiPanel(rec));
  else if (rec.status === 'diajukan' && rec.dicatat_oleh?.id != null && rec.dicatat_oleh.id === user?.id && Array.isArray(user?.keputusan) && user.keputusan.includes(SLUG)) {
    nodes.push(h('div', { class: 'notice' }, 'Anda yang mencatat pembelian ini, sehingga tidak dapat memverifikasinya sendiri.'));
  }
  return nodes;
}

export default defineCrudModule({
  slug: SLUG,
  title: 'Pembelian Bahan',
  icon: '🛒',
  description: 'Catat pembelian dan verifikasi nota',
  permissionSlug: SLUG,
  endpoint: `/${SLUG}`,
  createLabel: '+ Catat Pembelian',
  columns: [{ key: 'bahan_baku', primary: true, format: (r) => r.bahan_baku?.nama ?? `#${r.id}` }],
  fields: ({ mode, record }) => (mode === 'detail' ? detailFields : formFields({ mode, record })),
  mapPayload: (v) => payloadPembelian(v),
  sertakanVersi: true,
  itemTitle: (r) => `Pembelian ${r.bahan_baku?.nama ?? `#${r.id}`}`,
  searchPlaceholder: 'Cari bahan atau pemasok...',
  filters: { param: 'status', label: 'Status pembelian', options: [{ value: '', label: 'Semua' }, ...STATUS_PEMBELIAN] },
  listQuery,
  listTop,
  formTop,
  rowPerms: (rec, p) => ({ ...p, U: bolehUbahPembelian(p, rec), D: false }),
  renderCard: (r, href) => rowCard({
    href,
    title: r.bahan_baku?.nama ?? `Pembelian #${r.id}`,
    sub: [formatValue(r.tanggal_beli, 'date'), r.pemasok?.nama, `${angka(r.jumlah)} ${r.satuan ?? ''}`.trim()].filter(Boolean).join(' · '),
    aside: formatRupiah(r.total),
    badge: { text: r.status_label || statusLabel(r.status), cls: statusBadge(r.status) },
  }),
  detailExtras,
});
