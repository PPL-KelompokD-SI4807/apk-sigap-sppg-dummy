// Skema Bahan Baku + mutasi stok (fitur 6). Kontrak: docs/api/paths/bahan-baku.yaml, components/bahan-baku.yaml.
import { buildPayload, sppgField } from '../../core/schema-helpers.js';
import { todayISO } from '../../core/validators.js';

export const KATEGORI = [
  { value: 'karbohidrat', label: 'Karbohidrat' },
  { value: 'protein_hewani', label: 'Protein hewani' },
  { value: 'protein_nabati', label: 'Protein nabati' },
  { value: 'sayur', label: 'Sayur' },
  { value: 'buah', label: 'Buah' },
  { value: 'susu', label: 'Susu' },
  { value: 'bumbu', label: 'Bumbu' },
  { value: 'lainnya', label: 'Lainnya' },
];
export const kategoriLabel = (v) => KATEGORI.find((k) => k.value === v)?.label ?? v ?? '-';

export const JENIS_MUTASI = [
  { value: 'masuk', label: 'Masuk (penerimaan)' },
  { value: 'keluar', label: 'Keluar (pemakaian)' },
  { value: 'penyesuaian', label: 'Penyesuaian (stok opname)' },
];
export const jenisMutasiLabel = (v) => JENIS_MUTASI.find((k) => k.value === v)?.label.replace(/ \(.*\)$/, '') ?? v ?? '-';

export const bahanFields = ({ mode, user }) => [
  ...sppgField(user, mode),
  { name: 'nama', label: 'Nama bahan', type: 'text', required: true, maxlength: 255 },
  { name: 'kode', label: 'Kode', type: 'text', maxlength: 30, help: 'Opsional, unik per SPPG.' },
  { name: 'kategori', label: 'Kategori', type: 'select', required: true, options: KATEGORI },
  { name: 'satuan', label: 'Satuan', type: 'text', required: true, maxlength: 20, placeholder: 'kg, liter, butir' },
  { name: 'stok_minimum', label: 'Stok minimum', type: 'number', min: 0, step: 'any', default: 0, help: 'Peringatan "Stok menipis" muncul bila stok <= nilai ini.' },
  { name: 'pemasok_id', label: 'Pemasok', type: 'relasi', relation: { endpoint: '/pemasok', label: 'nama', value: 'id', as: 'pemasok' } },
];

export const mapPayload = (values) => buildPayload(values, {
  keys: ['sppg_id', 'nama', 'kode', 'kategori', 'satuan', 'stok_minimum', 'pemasok_id'],
  ints: ['sppg_id', 'pemasok_id'],
  nums: ['stok_minimum'],
  nullable: ['kode', 'pemasok_id'],
});

/** Penanda daftar: [{text, cls}] berdasarkan flag server (stok_menipis, akan_kedaluwarsa). */
export function penandaBahan(rec) {
  const out = [];
  if (rec?.stok_menipis) out.push({ text: 'Stok menipis', cls: 'badge-danger' });
  if (rec?.akan_kedaluwarsa) out.push({ text: 'Kedaluwarsa dekat', cls: 'badge-warn' });
  return out;
}

export const fmtQty = (v, satuan = '') => (v == null || v === '' ? '-' : `${Number(v).toLocaleString('id-ID', { maximumFractionDigits: 3 })}${satuan ? ` ${satuan}` : ''}`);

/** Teks status kedaluwarsa dari sisa_hari (batch): negatif = lewat. */
export function sisaHariText(d) {
  if (d == null) return 'Tanpa tanggal kedaluwarsa';
  if (d < 0) return `Lewat ${-d} hari`;
  if (d === 0) return 'Kedaluwarsa hari ini';
  return `${d} hari lagi`;
}
export const sisaHariCls = (d, ambang = 3) => (d == null ? '' : d < 0 ? 'badge-danger' : d <= ambang ? 'badge-warn' : 'badge-ok');

// ---- Form mutasi stok ----
export const mutasiFields = ({ pemasokId } = {}) => [
  { name: 'jenis', label: 'Jenis mutasi', type: 'select', required: true, default: 'masuk', options: JENIS_MUTASI },
  { name: 'jumlah', label: 'Jumlah', type: 'number', required: true, step: 'any', help: 'Masuk/keluar: angka positif. Penyesuaian: selisih bertanda (mis. -2), bukan 0. Maks 3 desimal.' },
  { name: 'tanggal_mutasi', label: 'Tanggal', type: 'date', default: todayISO(), max: todayISO() },
  { name: 'pemasok_id', label: 'Pemasok', type: 'relasi', default: pemasokId ?? '', relation: { endpoint: '/pemasok', label: 'nama', value: 'id' }, showIf: (v) => v.jenis === 'masuk' },
  { name: 'harga_satuan', label: 'Harga satuan (Rp)', type: 'number', min: 0, step: 'any', showIf: (v) => v.jenis === 'masuk' },
  { name: 'tanggal_kedaluwarsa', label: 'Tanggal kedaluwarsa', type: 'date', showIf: (v) => v.jenis === 'masuk', help: 'Dipakai untuk urutan batch FEFO.' },
  { name: 'jadwal_produksi_id', label: 'Untuk jadwal produksi', type: 'relasi', relation: { endpoint: '/jadwal', filter: (j) => ['direncanakan', 'dimasak'].includes(j.status), format: (j) => `${j.tanggal_produksi} · ${j.menu?.nama_menu ?? 'Menu'} (${j.target_porsi} porsi)` }, showIf: (v) => v.jenis === 'keluar', help: 'Isi jadwal atau catatan alasan.' },
  { name: 'catatan', label: 'Catatan / alasan', type: 'textarea', maxlength: 500 },
];

/**
 * Validasi bantu sisi klien (server otoritas): kembalikan {field: pesan}.
 * stok = saldo saat ini (angka) untuk menolak keluar > stok.
 */
export function cekMutasi(v, stok) {
  const err = {};
  const jumlah = v.jumlah == null || v.jumlah === '' ? null : Number(v.jumlah);
  if (jumlah == null || Number.isNaN(jumlah)) { err.jumlah = 'Jumlah wajib diisi.'; return err; }
  if (Math.round(jumlah * 1000) / 1000 !== jumlah) err.jumlah = 'Maksimal 3 angka desimal.';
  if (v.jenis === 'masuk' || v.jenis === 'keluar') {
    if (jumlah <= 0) err.jumlah = 'Jumlah harus lebih dari 0.';
  }
  if (v.jenis === 'keluar' && !err.jumlah && stok != null && jumlah > Number(stok)) {
    err.jumlah = `Jumlah keluar melebihi stok tersedia (${fmtQty(stok)}).`;
  }
  if (v.jenis === 'keluar' && !v.jadwal_produksi_id && !String(v.catatan ?? '').trim()) err.catatan = 'Isi catatan alasan atau pilih jadwal produksi.';
  if (v.jenis === 'penyesuaian') {
    if (jumlah === 0) err.jumlah = 'Selisih penyesuaian tidak boleh 0.';
    else if (stok != null && Number(stok) + jumlah < 0) err.jumlah = `Penyesuaian membuat stok negatif (stok ${fmtQty(stok)}).`;
    if (!String(v.catatan ?? '').trim()) err.catatan = 'Alasan penyesuaian wajib diisi.';
  }
  return err;
}

/** Payload mutasi: hanya kolom yang relevan menurut jenis (pemasok/kedaluwarsa hanya masuk; jadwal hanya keluar). */
export function mutasiPayload(v) {
  const base = buildPayload(v, {
    keys: ['jenis', 'jumlah', 'tanggal_mutasi', 'harga_satuan', 'pemasok_id', 'tanggal_kedaluwarsa', 'jadwal_produksi_id', 'catatan'],
    ints: ['pemasok_id', 'jadwal_produksi_id'],
    nums: ['jumlah', 'harga_satuan'],
    nullable: ['catatan'],
  });
  if (base.jenis !== 'masuk') { delete base.pemasok_id; delete base.harga_satuan; delete base.tanggal_kedaluwarsa; }
  if (base.jenis !== 'keluar') delete base.jadwal_produksi_id;
  return base;
}
