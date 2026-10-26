// Skema Pemasok (fitur 7). Kontrak: docs/api/paths/pemasok.yaml (PemasokInput).
import { buildPayload, activeField, sppgField } from '../../core/schema-helpers.js';
import { phone } from '../../core/validators.js';

export const JENIS_PEMASOK = [
  { value: 'umkm', label: 'UMKM' },
  { value: 'petani', label: 'Petani' },
  { value: 'peternak', label: 'Peternak' },
  { value: 'nelayan', label: 'Nelayan' },
  { value: 'koperasi', label: 'Koperasi' },
];
export const jenisPemasokLabel = (v) => JENIS_PEMASOK.find((j) => j.value === v)?.label ?? v ?? '-';

export const pemasokFields = ({ mode, user }) => [
  ...sppgField(user, mode),
  { name: 'nama', label: 'Nama pemasok', type: 'text', required: true, maxlength: 255 },
  { name: 'jenis', label: 'Jenis pemasok', type: 'select', required: true, options: JENIS_PEMASOK },
  { name: 'kontak', label: 'Kontak (telepon)', type: 'text', maxlength: 30, placeholder: '0812-0000-0000', validate: (v) => phone(v, { label: 'Kontak' }) },
  { name: 'alamat', label: 'Alamat', type: 'textarea', maxlength: 255 },
  { name: 'jenis_bahan', label: 'Jenis bahan yang dipasok', type: 'text', maxlength: 255, placeholder: 'Mis. sayuran, telur, ikan' },
  activeField(),
  ...(mode === 'detail' ? [{ name: 'bahan_baku_count', label: 'Bahan baku aktif', type: 'number', onlyIfPresent: true }] : []),
];

export const mapPayload = (values) => buildPayload(values, {
  keys: ['sppg_id', 'nama', 'jenis', 'kontak', 'alamat', 'jenis_bahan', 'is_active'],
  ints: ['sppg_id'],
  nullable: ['kontak', 'alamat', 'jenis_bahan'],
  bools: ['is_active'],
});

// ---- Harga pemasok (append-only; kontrak HargaPemasokInput) ----
/** Satuan yang konversinya ke gram dikenal server; selain ini gram_per_satuan wajib. */
const SATUAN_LANGSUNG = ['kg', 'g', 'gram', 'liter', 'l', 'ml'];
export const perluGramPerSatuan = (satuan) => !!satuan && !SATUAN_LANGSUNG.includes(String(satuan).trim().toLowerCase());

const dayShift = (n) => { const d = new Date(); d.setFullYear(d.getFullYear() + n); return d.toISOString().slice(0, 10); };

/** Mengembalikan {field: pesan}; kosong = valid. Server tetap otoritas. */
export function validasiHarga(v, satuan) {
  const e = {};
  if (!v.bahan_baku_id) e.bahan_baku_id = 'Pilih bahan.';
  const harga = Number(v.harga);
  if (v.harga === '' || v.harga == null || Number.isNaN(harga) || harga <= 0) e.harga = 'Harga harus lebih dari 0.';
  if (perluGramPerSatuan(satuan)) {
    const g = Number(v.gram_per_satuan);
    if (v.gram_per_satuan === '' || v.gram_per_satuan == null || Number.isNaN(g) || g <= 0) e.gram_per_satuan = `Isi gram per ${satuan} (satuan ini tidak dikenal).`;
  }
  if (!v.berlaku_mulai) e.berlaku_mulai = 'Tanggal berlaku wajib diisi.';
  else if (v.berlaku_mulai < dayShift(-1) || v.berlaku_mulai > dayShift(1)) e.berlaku_mulai = 'Tanggal berlaku maksimal 1 tahun dari hari ini.';
  if (String(v.catatan ?? '').length > 255) e.catatan = 'Catatan maksimal 255 karakter.';
  return e;
}

export const hargaPayload = (v) => buildPayload(v, {
  keys: ['bahan_baku_id', 'harga', 'gram_per_satuan', 'berlaku_mulai', 'catatan'],
  ints: ['bahan_baku_id'],
  nums: ['harga', 'gram_per_satuan'],
});

/** Kelompokkan riwayat (terbaru dulu) per bahan; entri pertama tiap bahan yang sudah berlaku = harga terkini. */
export function kelompokHarga(items) {
  const map = new Map();
  for (const it of items || []) {
    if (!map.has(it.bahan_baku_id)) map.set(it.bahan_baku_id, { bahan_baku_id: it.bahan_baku_id, nama: it.bahan_baku_nama ?? `Bahan #${it.bahan_baku_id}`, satuan: it.satuan, entri: [] });
    map.get(it.bahan_baku_id).entri.push(it);
  }
  return [...map.values()].map((g) => ({ ...g, terkini: g.entri.find((x) => x.sudah_berlaku) ?? null }));
}
