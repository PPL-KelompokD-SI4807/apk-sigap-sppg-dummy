// Pemetaan galat API -> teks Indonesia. Murni (tanpa DOM) agar bisa diuji dengan node --test.

/** Pesan pertama dari objek errors Laravel {field:[msg,...]}. */
export function firstError(errors) {
  if (!errors || typeof errors !== 'object') return '';
  for (const v of Object.values(errors)) {
    const m = Array.isArray(v) ? v[0] : v;
    if (m) return String(m);
  }
  return '';
}

/** Ratakan errors 422 -> {field: pesanPertama}. Kunci bersarang "a.0.b" dipertahankan apa adanya. */
export function mapValidationErrors(errors) {
  const out = {};
  if (!errors || typeof errors !== 'object') return out;
  for (const [k, v] of Object.entries(errors)) {
    const m = Array.isArray(v) ? v[0] : v;
    if (m) out[k] = String(m);
  }
  return out;
}

export const PESAN_413 = 'Berkas terlalu besar. Maksimal 5 MB per foto.';
export const PESAN_429 = 'Terlalu banyak permintaan (unggahan maks 20 per menit). Tunggu sebentar lalu coba lagi.';
export const PESAN_409 = 'Permintaan yang sama masih diproses. Coba lagi sebentar lagi.';

/** Pesan bawaan bila server tidak memberi pesan JSON (mis. 413 dari nginx) atau memberi pesan berbahasa Inggris (429). */
export function statusMessage(status, serverMessage = '') {
  if (status === 413) return PESAN_413;
  if (status === 429) return PESAN_429;
  return serverMessage || `Permintaan gagal (${status}).`;
}

/** Pesan ramah untuk toast dari ApiError (status, message, errors). */
export function errorMessage(e, fallback = 'Terjadi kesalahan.') {
  if (!e) return fallback;
  if (e.status === 0) return 'Tidak ada koneksi internet.';
  if (e.status === 403) return PESAN_KONFLIK[e?.data?.alasan] || 'Anda tidak punya hak akses.';
  if (e.status === 404) return 'Data tidak ditemukan atau sudah dihapus.';
  if (e.status === 413) return PESAN_413;
  if (e.status === 429) return PESAN_429;
  if (e.status === 409) return pesanKonflik(e);
  if (e.status === 422) return firstError(e.errors) || e.message || 'Data tidak valid.';
  if (e.status >= 500) return 'Terjadi gangguan pada server. Coba lagi nanti.';
  return e.message || fallback;
}

// ---- Konflik 409 R-06b-2 (versi basi, status berubah, pembelian final, pengeluaran dari pembelian) ----
export const PESAN_KONFLIK = {
  versi_tidak_cocok: 'Data sudah berubah sejak Anda membukanya. Muat ulang lalu coba lagi.',
  status_tidak_valid: 'Status data sudah berubah atau sudah diputuskan pihak lain. Muat ulang untuk melihat keadaan terbaru.',
  pembelian_sudah_diverifikasi: 'Pembelian ini sudah diverifikasi dan tidak dapat diubah.',
  pengeluaran_dari_pembelian: 'Pengeluaran ini berasal dari pembelian bahan terverifikasi sehingga tidak dapat diubah atau dihapus.',
  pengeluaran_sudah_disetujui: 'Pengeluaran sudah disetujui. Ubah pengeluaran agar diajukan ulang sebelum menambah lampiran.',
};
/** Alasan 409 yang berarti data di layar basi: pengguna perlu memuat ulang. */
export const ALASAN_MUAT_ULANG = ['versi_tidak_cocok', 'status_tidak_valid', 'pembelian_sudah_diverifikasi', 'pengeluaran_sudah_disetujui'];

/** Pesan 409 dari `data.alasan` server; alasan tak dikenal (mis. kunci idempotensi) memakai PESAN_409. */
export function pesanKonflik(e) {
  return PESAN_KONFLIK[e?.data?.alasan] || PESAN_409;
}

/**
 * Ringkasan galat untuk aksi tulis (simpan/verifikasi/keputusan) -> {pesan, muatUlang, konflik, fields}.
 * 403/404/409/422/429 dipetakan ke teks Indonesia; 422 membawa galat per field (mis. `alasan`, `catatan`, `lampiran_ids`, `nominal`).
 */
export function ringkasGalatAksi(e, fallback = 'Aksi gagal.') {
  const s = e?.status;
  const dasar = { muatUlang: false, konflik: false, fields: {} };
  if (s === 0) return { ...dasar, pesan: 'Tidak ada koneksi internet.' };
  if (s === 403) return { ...dasar, pesan: PESAN_KONFLIK[e?.data?.alasan] || 'Anda tidak berhak melakukan aksi ini.' };
  if (s === 404) return { ...dasar, pesan: 'Data tidak ditemukan atau sudah dihapus.', muatUlang: true };
  if (s === 409) {
    const alasan = e?.data?.alasan;
    return { ...dasar, pesan: pesanKonflik(e), konflik: !!PESAN_KONFLIK[alasan], muatUlang: ALASAN_MUAT_ULANG.includes(alasan) };
  }
  if (s === 422) {
    const fields = mapValidationErrors(e.errors);
    return { ...dasar, fields, pesan: Object.values(fields)[0] || e.message || 'Data tidak valid.' };
  }
  if (s === 429) return { ...dasar, pesan: PESAN_429 };
  if (s >= 500) return { ...dasar, pesan: 'Terjadi gangguan pada server. Coba lagi nanti.' };
  return { ...dasar, pesan: e?.message || fallback };
}
