// Skema Data SPPG (fitur 3). Kontrak: docs/api/paths/sppg.yaml + components/sppg.yaml (SppgInput).
import { buildPayload } from '../../core/schema-helpers.js';
import { phone, noNik } from '../../core/validators.js';
import { userPerms } from '../../core/permissions.js';

export const STATUS_OPERASIONAL = [
  { value: 'aktif', label: 'Aktif' },
  { value: 'libur', label: 'Libur' },
  { value: 'nonaktif', label: 'Nonaktif' },
];
export const statusBadge = (s) => ({ aktif: 'badge-ok', libur: 'badge-warn', nonaktif: 'badge-danger' }[s] || '');

const rp = (v) => (v == null || v === '' ? '-' : `Rp ${Math.trunc(Number(v)).toLocaleString('id-ID')}`);
const KUNCI_ANGGARAN = ['acuan_bahan_baku_kecil', 'acuan_bahan_baku_lainnya', 'insentif_fasilitas_per_hari', 'insentif_fasilitas_maks_hari_minggu'];
const efektif = (k) => (r) => {
  const v = r.pengaturan_anggaran?.[k];
  if (v == null) return '-';
  return k === 'insentif_fasilitas_maks_hari_minggu' ? `${v} hari/minggu` : rp(v);
};
const PAT_ID_BGN = /^[A-Za-z0-9./_-]*$/;

/** Peran pembaca keuangan (admin, kepala_sppg, akuntan, investor): server hanya mengirim kolom keuangan bagi mereka. */
const pembacaKeuangan = (user) => userPerms(user, 'keuangan').R;

/** kode: hanya yang berhak Tambah SPPG (Admin) yang boleh mengisi/ubah; hak ubah lain (Kepala SPPG) membaca saja. */
export function sppgFields({ mode, user, record }) {
  const isAdmin = userPerms(user, 'sppg').C;
  const keu = pembacaKeuangan(user);
  const detail = mode === 'detail';
  const kustom = record?.pengaturan_anggaran_kustom ?? {};
  const angka = (name, label, extra) => ({ name, label, type: 'number', step: 1, default: kustom[name] ?? '', display: efektif(name), help: 'Kosongkan untuk memakai nilai bawaan.', ...extra });
  return [
    { name: 'kode', label: 'Kode SPPG', type: 'text', required: true, maxlength: 30, readonly: mode !== 'create' && !isAdmin, help: isAdmin ? null : 'Kode hanya dapat diubah Admin.' },
    { name: 'nama', label: 'Nama SPPG', type: 'text', required: true, maxlength: 255 },
    { name: 'id_sppg_bgn', label: 'ID SPPG (BGN)', type: 'text', maxlength: 30, validate: (v) => (PAT_ID_BGN.test(String(v ?? '')) ? '' : 'Hanya huruf, angka, titik, garis miring, garis bawah, dan tanda hubung.'), help: 'Dipakai pada kop Lampiran 30.' },
    { name: 'alamat', label: 'Alamat', type: 'textarea', required: true, maxlength: 255 },
    { name: 'kecamatan', label: 'Kecamatan', type: 'text', maxlength: 100 },
    { name: 'kab_kota', label: 'Kabupaten/Kota', type: 'text', maxlength: 100 },
    { name: 'provinsi', label: 'Provinsi', type: 'text', maxlength: 100 },
    { name: 'mitra_yayasan', label: 'Mitra / yayasan pengelola', type: 'text', maxlength: 255 },
    ...(keu ? [
      { name: 'nama_ketua_yayasan', label: 'Nama ketua yayasan', type: 'text', maxlength: 150, onlyIfPresent: true, help: 'Nama saja. Jangan isi nomor identitas.', validate: (v) => noNik(v, 'Nama ketua yayasan') },
      { name: 'no_va', label: 'Nomor rekening / Virtual Account', type: 'text', maxlength: 50, onlyIfPresent: true, validate: (v) => (/^[0-9\s.-]*$/.test(String(v ?? '')) ? '' : 'Hanya angka, spasi, titik, dan tanda hubung.') },
    ] : []),
    { name: 'telepon', label: 'Telepon', type: 'text', maxlength: 30, validate: (v) => phone(v) },
    { name: 'kapasitas_porsi_harian', label: 'Kapasitas porsi per hari', type: 'number', required: true, min: 1, max: 100000, step: 1, help: 'Menjadi batas target porsi pada jadwal produksi.' },
    { name: 'status_operasional', label: 'Status operasional', type: 'select', required: true, default: 'aktif', options: STATUS_OPERASIONAL },
    ...(keu && (!detail || (record && 'pengaturan_anggaran' in record)) ? [
      angka('acuan_bahan_baku_kecil', 'Acuan bahan baku kecil (Rp/porsi)', { min: 1, max: 1000000, help: 'Balita, PAUD/TK, SD kelas 1-3. Kosong = bawaan Rp8.000.' }),
      angka('acuan_bahan_baku_lainnya', 'Acuan bahan baku lainnya (Rp/porsi)', { min: 1, max: 1000000, help: 'Kelompok lain. Kosong = bawaan Rp10.000.' }),
      angka('insentif_fasilitas_per_hari', 'Insentif fasilitas per hari (Rp)', { min: 0, max: 1000000000, help: 'Kosong = bawaan Rp6.000.000.' }),
      angka('insentif_fasilitas_maks_hari_minggu', 'Maks. hari insentif per minggu', { min: 1, max: 7, help: 'Kosong = bawaan 6 hari.' }),
    ] : []),
  ];
}

export const mapPayload = (values) => buildPayload(values, {
  keys: ['kode', 'nama', 'id_sppg_bgn', 'alamat', 'kecamatan', 'kab_kota', 'provinsi', 'mitra_yayasan', 'nama_ketua_yayasan', 'no_va', 'telepon', 'kapasitas_porsi_harian', 'status_operasional', ...KUNCI_ANGGARAN],
  ints: ['kapasitas_porsi_harian', ...KUNCI_ANGGARAN],
  nullable: ['id_sppg_bgn', 'kecamatan', 'kab_kota', 'provinsi', 'mitra_yayasan', 'nama_ketua_yayasan', 'no_va', 'telepon', ...KUNCI_ANGGARAN],
});

// ---- Saldo awal (R07-1): PUT /sppg/{id}/saldo-awal, hanya akuntan SPPG ini / admin (izin.ubah_saldo_awal dari server) ----
export const SALDO_MAKS = 9999999999999;
/** Tombol/form saldo awal hanya bila server menyatakan izin.ubah_saldo_awal === true (fail closed). */
export const bolehUbahSaldoAwal = (rec) => rec?.izin?.ubah_saldo_awal === true;
/** Teks isian -> integer rupiah ('' -> null; titik/spasi/awalan Rp = pemisah ribuan; selain itu NaN). */
export function parseSaldo(text) {
  const s = String(text ?? '').replace(/^\s*rp\.?\s*/i, '').replace(/[\s.]/g, '');
  if (s === '') return null;
  if (!/^\d+$/.test(s)) return NaN;
  const n = Number(s);
  return Number.isSafeInteger(n) ? n : NaN;
}
/** Nol sah; wajib terisi, bulat, tidak negatif. */
export function validasiSaldo(n) {
  if (n == null) return 'Saldo awal wajib diisi (isi 0 bila nol).';
  if (Number.isNaN(n)) return 'Saldo awal harus berupa rupiah bulat tanpa desimal.';
  if (n < 0) return 'Saldo awal tidak boleh negatif.';
  if (n > SALDO_MAKS) return 'Saldo awal terlalu besar.';
  return '';
}
export function validasiTanggalSaldo(v, today) {
  if (!v) return 'Tanggal saldo awal wajib diisi.';
  return String(v).slice(0, 10) > today ? 'Tanggal saldo awal tidak boleh di masa depan.' : '';
}
export const payloadSaldoAwal = (saldo, tanggal) => ({ saldo_awal: saldo, saldo_awal_tanggal: String(tanggal).slice(0, 10) });
