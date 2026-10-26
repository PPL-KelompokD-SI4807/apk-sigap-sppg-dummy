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

/** Status permintaan reset password -> label, kelas badge, dan penjelasan untuk pemohon. */
export const STATUS_RESET = {
  menunggu: { label: 'Menunggu persetujuan', cls: 'badge-info', teks: 'Permintaan sudah dikirim ke Admin. Admin akan memastikan identitas Anda lebih dulu. Periksa lagi beberapa saat lagi.' },
  disetujui: { label: 'Disetujui', cls: 'badge-ok', teks: 'Admin menyetujui permintaan Anda. Tentukan password baru, lalu masukkan kode 6 digit dari bot Telegram.' },
  ditolak: { label: 'Ditolak', cls: 'badge-danger', teks: 'Admin menolak permintaan terakhir Anda. Hubungi Admin SPPG bila perlu, atau ajukan permintaan baru.' },
  kedaluwarsa: { label: 'Kedaluwarsa', cls: 'badge-warn', teks: 'Permintaan sudah melewati batas 24 jam. Ajukan permintaan baru.' },
  selesai: { label: 'Selesai', cls: 'badge-ok', teks: 'Permintaan sebelumnya sudah selesai. Ajukan permintaan baru bila masih perlu.' },
};
export const infoStatusReset = (status) => STATUS_RESET[status] || { label: String(status || '-'), cls: 'badge-info', teks: '' };

/** Permintaan baru boleh diajukan bila belum ada, atau yang terakhir sudah tidak aktif. */
export const bisaAjukanReset = (p) => !p || ['ditolak', 'kedaluwarsa', 'selesai'].includes(p.status);

/** Akun terkunci: 429 `terkunci` (kontrak) atau 423 (cadangan). */
export const isTerkunci = (e) => !!e && (e.status === 423 || (e.status === 429 && e.data?.alasan === 'terkunci'));

/** Pesan galat umum untuk layar terautentikasi (ubah password, sesi, admin). null bila tidak dikenal. */
export function pesanGalatAkun(e) {
  if (!e) return null;
  if (e.status === 0) return 'Tidak ada koneksi internet.';
  if (isTerkunci(e)) return `Akun terkunci sementara. Coba lagi dalam ${durasiRamah(detikTerkunci(e.data))}.`;
  if (e.status === 401) return 'Sesi berakhir. Silakan masuk kembali.';
  if (e.status === 403) return 'Anda tidak punya hak akses.';
  return null;
}
