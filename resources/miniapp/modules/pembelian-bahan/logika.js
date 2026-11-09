// Logika murni Pembelian Bahan (R-06b-2), tanpa DOM agar bisa diuji node --test.
// Kontrak: docs/api/paths/pembelian-bahan.yaml, components/pembelian-bahan.yaml. Server tetap otoritas akhir.
import { buildPayload } from '../../core/schema-helpers.js';
import { bolehIzin } from '../../core/permissions.js';
import { lampiranIdsPayload } from '../../core/lampiran-uploader.js';
import { todayWIB } from '../../core/waktu.js';

export const SLUG = 'pembelian-bahan';
export const MAKS_NOTA = 3;
export const MIN_ALASAN = 5;
export const MAKS_ALASAN = 500;
export const MAKS_JUMLAH = 999999999;
export const MAKS_HARGA = 99999999999;

export const STATUS_PEMBELIAN = [
  { value: 'diajukan', label: 'Menunggu verifikasi' },
  { value: 'terverifikasi', label: 'Terverifikasi' },
  { value: 'ditolak', label: 'Ditolak' },
];
const BADGE = { diajukan: 'badge-info', terverifikasi: 'badge-ok', ditolak: 'badge-danger' };
export const statusBadge = (s) => BADGE[s] ?? '';
export const statusLabel = (s) => STATUS_PEMBELIAN.find((x) => x.value === s)?.label ?? s ?? '-';

/**
 * Tombol Verifikasi: murni dari izin.verifikasi server (akuntan SPPG sama, bukan pencatat, status `diajukan`).
 * Tombol Ubah mengikuti izin.ubah (false bila terverifikasi), diterapkan oleh crud-module (izin: true).
 */
export const tombolVerifikasiTampil = (rec) => bolehIzin(rec, 'verifikasi');

// ---- Angka ----
function desimalMaks(v, n) {
  const k = 10 ** n;
  return Math.abs(v * k - Math.round(v * k)) < 1e-6;
}
export function validasiJumlah(v) {
  if (v == null) return '';
  if (typeof v !== 'number' || Number.isNaN(v)) return 'Jumlah harus berupa angka.';
  if (v <= 0) return 'Jumlah harus lebih dari 0.';
  if (v > MAKS_JUMLAH) return 'Jumlah terlalu besar.';
  if (!desimalMaks(v, 3)) return 'Jumlah maksimal 3 angka di belakang koma.';
  return '';
}
export function validasiHargaSatuan(v) {
  if (v == null) return '';
  if (typeof v !== 'number' || Number.isNaN(v)) return 'Harga satuan harus berupa angka.';
  if (v <= 0) return 'Harga satuan harus lebih dari 0.';
  if (v > MAKS_HARGA) return 'Harga satuan terlalu besar.';
  if (!desimalMaks(v, 2)) return 'Harga satuan maksimal 2 angka di belakang koma.';
  return '';
}
export function validasiTanggalBeli(v, today = todayWIB()) {
  if (!v) return '';
  return String(v).slice(0, 10) > today ? 'Tanggal beli tidak boleh di masa depan.' : '';
}

/** Perkiraan total = round(jumlah x harga) rupiah bulat (tanpa float: jumlah 3 desimal x harga 2 desimal dalam BigInt). Server menghitung ulang. */
export function hitungTotal(jumlah, harga) {
  const j = Number(jumlah);
  const g = Number(harga);
  if (!Number.isFinite(j) || !Number.isFinite(g) || j <= 0 || g <= 0) return null;
  const prod = BigInt(Math.round(j * 1000)) * BigInt(Math.round(g * 100));
  return Number((prod + 50000n) / 100000n);
}

/** Nota: wajib minimal 1 foto saat baru; saat ubah cukup bila sudah ada nota tersimpan. -> pesan atau ''. */
export function validasiNota({ mode, jumlahBaru = 0, jumlahTersimpan = 0 }) {
  const total = jumlahBaru + jumlahTersimpan;
  if (total < 1) return 'Foto nota wajib diunggah (minimal 1 foto).';
  if (total > MAKS_NOTA) return `Foto nota maksimal ${MAKS_NOTA}.`;
  return '';
}

/** Muatan simpan: tidak pernah memuat total/status/satuan (dihitung server). `versi` ditambahkan crud-module saat ubah. */
export const payloadPembelian = (v) => {
  const out = buildPayload(v, {
    keys: ['bahan_baku_id', 'pemasok_id', 'tanggal_beli', 'jumlah', 'harga_satuan', 'catatan'],
    ints: ['bahan_baku_id', 'pemasok_id'],
    nums: ['jumlah', 'harga_satuan'],
    nullable: ['catatan'],
  });
  const lampiran = lampiranIdsPayload(v.lampiran_ids);
  if (lampiran) out.lampiran_ids = lampiran;
  return out;
};

// ---- Verifikasi akuntan ----
export const KEPUTUSAN_VERIFIKASI = [
  { value: 'disetujui', label: 'Setujui' },
  { value: 'ditolak', label: 'Tolak' },
];
/** Alasan wajib 5..500 karakter bila ditolak; opsional (maks 500) bila disetujui. */
export function validasiAlasan(keputusan, alasan) {
  const a = String(alasan ?? '').trim();
  if (a.length > MAKS_ALASAN) return `Alasan maksimal ${MAKS_ALASAN} karakter.`;
  if (keputusan === 'ditolak' && a.length < MIN_ALASAN) return `Alasan penolakan wajib diisi (minimal ${MIN_ALASAN} karakter).`;
  return '';
}
export function payloadVerifikasi({ keputusan, alasan, versi }) {
  const a = String(alasan ?? '').trim();
  const out = { keputusan, versi: Math.trunc(Number(versi)) };
  if (keputusan === 'ditolak' && a) out.alasan = a;
  return out;
}

// ---- Harga referensi (detail) ----
/** -> {ada, teks, tone}. selisih_persen = (harga_satuan - harga) / harga x 100; tone untuk badge. */
export function ringkasHargaReferensi(ref) {
  if (!ref || ref.harga == null) return { ada: false, teks: 'Belum ada harga referensi dari pemasok ini.', tone: '' };
  const p = ref.selisih_persen;
  if (p == null || Number.isNaN(Number(p))) return { ada: true, teks: 'Selisih tidak dapat dihitung.', tone: '' };
  const n = Math.round(Number(p) * 10) / 10;
  const angka = Math.abs(n).toString().replace('.', ',');
  if (n > 0) return { ada: true, teks: `${angka}% lebih mahal dari harga referensi`, tone: 'badge-warn' };
  if (n < 0) return { ada: true, teks: `${angka}% lebih murah dari harga referensi`, tone: 'badge-ok' };
  return { ada: true, teks: 'Sama dengan harga referensi', tone: 'badge-info' };
}

// Dipindah ke core/draf-tujuan.js (dipakai juga crud-module); diekspor ulang agar impor lama tetap berlaku.
export { tujuanDraf } from '../../core/draf-tujuan.js';
