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

/** Pesan ramah untuk toast dari ApiError (status, message, errors). */
export function errorMessage(e, fallback = 'Terjadi kesalahan.') {
  if (!e) return fallback;
  if (e.status === 0) return 'Tidak ada koneksi internet.';
  if (e.status === 403) return 'Anda tidak punya hak akses.';
  if (e.status === 404) return 'Data tidak ditemukan atau sudah dihapus.';
  if (e.status === 422) return firstError(e.errors) || e.message || 'Data tidak valid.';
  if (e.status >= 500) return 'Terjadi gangguan pada server. Coba lagi nanti.';
  return e.message || fallback;
}
