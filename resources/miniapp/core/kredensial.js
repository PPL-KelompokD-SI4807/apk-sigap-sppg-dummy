// Fungsi murni untuk alur masuk kredensial (R-03). Tanpa DOM agar bisa diuji dengan node --test.

/** Aturan password: min 8, huruf besar+kecil, angka, bukan sama dengan username. -> [{ok, teks}] */
export function aturanPassword(password = '', username = '') {
  const u = String(username || '').trim().toLowerCase();
  return [
    { ok: password.length >= 8, teks: 'Minimal 8 karakter' },
    { ok: /[a-z]/.test(password) && /[A-Z]/.test(password), teks: 'Memuat huruf besar dan huruf kecil' },
    { ok: /\d/.test(password), teks: 'Memuat angka' },
    { ok: !(u && password.toLowerCase() === u), teks: 'Tidak sama dengan username' },
  ];
}
export const passwordValid = (password, username) => aturanPassword(password, username).every((r) => r.ok);

/** Sisa detik sampai waktu ISO (>= 0). */
export function sisaDetik(iso, now = Date.now()) {
  const t = Date.parse(iso);
  return Number.isFinite(t) ? Math.max(0, Math.ceil((t - now) / 1000)) : 0;
}

/** 125 -> "02:05" */
export function formatMenit(detik) {
  const d = Math.max(0, Math.floor(detik));
  return `${String(Math.floor(d / 60)).padStart(2, '0')}:${String(d % 60).padStart(2, '0')}`;
}

/** Durasi terkunci ramah: "15 menit" / "45 detik". */
export function durasiRamah(detik) {
  const d = Math.max(0, Math.ceil(detik));
  return d >= 60 ? `${Math.ceil(d / 60)} menit` : `${d} detik`;
}

/** Detik terkunci dari galat 429: terkunci_sampai, lalu Retry-After. */
export function detikTerkunci(data, now = Date.now()) {
  if (data?.terkunci_sampai) return sisaDetik(data.terkunci_sampai, now);
  return Number(data?.retry_after) || 0;
}

export const kodeValid = (kode) => /^\d{6}$/.test(kode);

export const LABEL_TUJUAN = {
  masuk: 'masuk',
  perangkat_baru: 'masuk di perangkat baru',
  aktivasi: 'aktivasi akun',
  reset_password: 'atur ulang password',
};

/** Alasan token yang mengharuskan mengulang dari awal. */
export const ALASAN_ULANG = new Set(['token_dipakai', 'token_hangus', 'token_tidak_valid']);
