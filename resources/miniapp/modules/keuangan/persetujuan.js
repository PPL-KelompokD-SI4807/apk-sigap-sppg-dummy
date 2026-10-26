// Logika murni persetujuan pengeluaran oleh investor (R-06b-2), tanpa DOM agar bisa diuji node --test.
// Kontrak: docs/api/paths/keuangan.yaml (POST /keuangan/pengeluaran/{id}/keputusan), components/keuangan.yaml.
import { formatRupiah } from './schema.js';

export const SLUG_KEPUTUSAN = 'keuangan';
export const MIN_CATATAN = 5;
export const MAKS_CATATAN = 500;

export const STATUS_PERSETUJUAN = [
  { value: 'diajukan', label: 'Menunggu persetujuan' },
  { value: 'disetujui', label: 'Disetujui' },
  { value: 'ditolak', label: 'Ditolak' },
];
const LABEL_RIWAYAT = { diajukan: 'Menunggu persetujuan', disetujui: 'Disetujui', ditolak: 'Ditolak', dibatalkan: 'Dibatalkan (diubah akuntan)' };
const BADGE = { diajukan: 'badge-info', disetujui: 'badge-ok', ditolak: 'badge-danger', dibatalkan: '' };
export const statusPersetujuanBadge = (s) => BADGE[s] ?? '';
export const statusPersetujuanLabel = (s) => LABEL_RIWAYAT[s] ?? s ?? '-';
/** Status record lama tanpa kolom (server lama) dianggap disetujui agar tampilan tidak menyesatkan. */
export const statusPengeluaran = (rec) => rec?.status_persetujuan ?? 'disetujui';

export const KEPUTUSAN_PENGELUARAN = [
  { value: 'disetujui', label: 'Setujui' },
  { value: 'ditolak', label: 'Tolak' },
];

/** Investor = user.keputusan memuat "keuangan"; hanya saat pengajuan masih `diajukan`. Server tetap memvalidasi SPPG. */
export const bolehMemutuskanPengeluaran = (user) => Array.isArray(user?.keputusan) && user.keputusan.includes(SLUG_KEPUTUSAN);
export const tombolKeputusanPengeluaranTampil = (user, rec) => bolehMemutuskanPengeluaran(user) && rec?.status_persetujuan === 'diajukan';

/** Pengeluaran hasil pembelian bahan terverifikasi tidak dapat diubah/dihapus lewat jalur pengeluaran (409 di server). */
export const dariPembelian = (rec) => rec?.pembelian_bahan_id != null;
export const rowPermsPengeluaran = (rec, p) => (dariPembelian(rec) ? { ...p, U: false, D: false } : p);

/** Catatan wajib 5..500 karakter bila ditolak; opsional (maks 500) bila disetujui. '' bila valid. */
export function validasiCatatanKeputusan(keputusan, catatan) {
  const c = String(catatan ?? '').trim();
  if (c.length > MAKS_CATATAN) return `Catatan maksimal ${MAKS_CATATAN} karakter.`;
  if (keputusan === 'ditolak' && c.length < MIN_CATATAN) return `Catatan penolakan wajib diisi (minimal ${MIN_CATATAN} karakter).`;
  return '';
}
export function payloadKeputusanPengeluaran({ keputusan, catatan, versi }) {
  const c = String(catatan ?? '').trim();
  const out = { keputusan, versi: Math.trunc(Number(versi)) };
  if (c) out.catatan = c;
  return out;
}

/** Teks ringkasan "menunggu persetujuan" dari GET /keuangan/ringkasan; null bila tidak ada. */
export function teksMenunggu(r) {
  const m = r?.menunggu_persetujuan;
  const n = Number(m?.jumlah ?? 0);
  if (!m || n <= 0) return null;
  return `${n} pengeluaran (${formatRupiah(m.nominal)}) menunggu persetujuan investor dan belum dihitung ke anggaran.`;
}
