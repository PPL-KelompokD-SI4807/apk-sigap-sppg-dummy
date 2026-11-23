// Logika murni layar Masuk web (R04-7). Tanpa DOM dan tanpa storage agar dapat diuji dengan node --test.
import { detikTerkunci, durasiRamah } from '../../core/kredensial.js';

export const PERINGATAN_INGAT = 'Jangan centang di komputer bersama atau warnet. Sandi tetap diminta; hanya kode Telegram yang dilewati selama 30 hari.';
export const CATATAN_ADMIN = 'Akun Admin selalu memakai kode Telegram; pilihan ini diabaikan untuk Admin.';
export const CATATAN_IDLE = 'Demi keamanan, sesi web berakhir setelah 12 jam tidak aktif (kecuali di browser yang Anda ingat).';
export const PETUNJUK_LUPA_SANDI = 'Lupa sandi? Buka Mini App SiGAP SPPG di Telegram, lalu pilih Lupa password.';
// Cadangan bila server tidak mengirim pesan; teks sama dengan pesan server (ember `kredensial_salah`, lima sebab digabung).
export const PESAN_KREDENSIAL_SALAH = 'Identitas atau sandi salah.';
// Cadangan untuk 422 "sandi benar tetapi belum bisa lanjut"; pesan server selalu dipakai lebih dulu.
export const PESAN_TELEGRAM_BELUM_TERHUBUNG = 'Sandi benar, tetapi akun ini belum terhubung ke Telegram sehingga kode tidak dapat dikirim. Buka Mini App SiGAP di Telegram untuk menghubungkan akun, lalu masuk lagi.';
export const PESAN_TERLALU_SERING = 'Terlalu banyak permintaan kode untuk akun ini. Tunggu beberapa menit, lalu coba masuk lagi.';
// Cadangan layar kode. Layar kode hanya tercapai bila sandi BENAR, jadi pesannya tidak berpagar.
export const PESAN_KODE_DIKIRIM = 'Kode 6 digit telah dikirim ke Telegram Anda.';
export const PESAN_KODE_BARU_DIKIRIM = 'Kode baru telah dikirim ke Telegram Anda.';
export const PESAN_KIRIM_ULANG_BELUM = 'Kirim ulang belum bisa dilakukan sekarang. Tunggu hitung mundur selesai.';
export const PESAN_KODE_TIDAK_VALID = 'Kode salah atau kedaluwarsa. Periksa kode terbaru di chat bot, atau kirim ulang kode.';

// Panduan "kode tidak datang". Layar kode hanya tercapai bila sandi benar DAN akun sudah terhubung ke Telegram
// (sandi salah dan akun belum terhubung dijawab server di layar masuk), jadi tidak ada butir tentang sandi atau
// penautan. Hanya sebab yang masih mungkin: bot terblokir/belum Start, pesan tertunda, atau salah akun Telegram.
export const JUDUL_PANDUAN_KODE = 'Kode tidak datang?';
export const PENGANTAR_PANDUAN_KODE = 'Kode dikirim ke chat bot SiGAP di akun Telegram yang tertaut dengan akun Anda. Bila belum muncul, periksa hal berikut.';
export const SEBAB_KODE_TIDAK_DATANG = [
  'Anda mungkin belum menekan Start di bot SiGAP, atau pernah memblokirnya. Buka bot di Telegram, tekan Start, lalu kirim ulang kode.',
  'Telegram sedang lambat atau bermasalah. Tunggu sebentar, lalu periksa chat bot lagi sebelum meminta kode baru.',
  'Anda membuka Telegram di akun yang berbeda. Periksa chat bot di akun Telegram yang tertaut dengan SiGAP, termasuk chat yang dibisukan atau diarsipkan.',
];
export const PESAN_KUOTA_HABIS = 'Jatah kirim ulang habis. Bila kode belum datang, mulai dari awal.';
export const PESAN_TANTANGAN_MATI = 'Kode untuk percobaan masuk ini sudah tidak berlaku. Mulai dari awal untuk meminta kode baru.';
export const PESAN_PERCOBAAN_HABIS = 'Percobaan memasukkan kode sudah habis. Mulai dari awal untuk meminta kode baru.';

/**
 * Tantangan sudah mati (kode tidak bisa dipakai lagi): server menandainya `kedaluwarsa_pada` = waktu respons =
 * `kirim_ulang_tersedia_pada`, jadi kedaluwarsa <= tersedia dibaca tanpa bergantung pada jam perangkat. Selain itu mati
 * bila `kedaluwarsa_pada` <= jam server sekarang (masa 5 menit habis saat layar terbuka). Waktu tak terbaca -> belum mati.
 */
export function tantanganMati(t, sekarangMs = Date.now()) {
  const exp = Date.parse(t?.kedaluwarsa_pada);
  if (!Number.isFinite(exp)) return false;
  const tersedia = Date.parse(t?.kirim_ulang_tersedia_pada);
  return (Number.isFinite(tersedia) && exp <= tersedia) || exp <= sekarangMs;
}

/** `sisa_percobaan` dari badan 422 kode_tidak_valid -> bilangan bulat >= 0, atau null bila tidak ada. */
export function bacaSisaPercobaan(e) {
  const n = e?.data?.sisa_percobaan;
  return Number.isInteger(n) && n >= 0 ? n : null;
}

/** "Sisa percobaan: 4 dari 5." (tanpa "dari" bila batas tidak diketahui). */
export const teksSisaPercobaan = (sisa, maks) => `Sisa percobaan: ${sisa}${Number.isInteger(maks) && maks >= sisa ? ` dari ${maks}` : ''}.`;

/** Tantangan baru dari respons kirim ulang -> objek tantangan atau null bila bentuknya tak dikenal. */
export const bacaTantangan = (data) => (data?.tantangan?.id ? data.tantangan : null);

/** Hasil POST /web/auth/masuk -> {langkah: 'kode', tantangan, pesan} | {langkah: 'selesai', data}. */
export function bacaHasilMasuk(data) {
  if (data?.langkah === 'selesai') return { langkah: 'selesai', data };
  if (data?.langkah === 'kode' && data.tantangan?.id) return { langkah: 'kode', tantangan: data.tantangan, pesan: data.message || '' };
  return { langkah: 'galat' };
}

/**
 * Ringkas info browser tepercaya dari respons langkah selesai:
 * {admin: true} -> "Admin selalu kode" (browser TIDAK diingat); {diingat: true, sampai}; {dipakai: true}; {} lainnya.
 */
export function bacaBrowserTepercaya(data) {
  const b = data?.browser_tepercaya;
  if (!b || typeof b !== 'object') return {};
  if (b.alasan === 'admin_selalu_kode') return { admin: true, diingat: false };
  if (b.diingat === true) return { diingat: true, sampai: b.berlaku_sampai ?? null };
  if (b.dipakai === true) return { dipakai: true };
  return {};
}

/** Pesan sukses singkat setelah langkah selesai; null bila tak perlu. */
export function pesanSelesai(data, { ingatDiminta = false } = {}) {
  const b = bacaBrowserTepercaya(data);
  if (b.admin) return ingatDiminta ? 'Admin selalu memakai kode, jadi browser ini tidak diingat.' : null;
  if (b.diingat) return 'Browser ini diingat 30 hari. Login berikutnya cukup sandi.';
  if (b.dipakai) return 'Masuk lewat browser tepercaya (kode dilewati).';
  return null;
}

/** Pesan pertama pada errors.password dari badan 422 (kontrak: sama dengan `message`), atau '' bila tidak ada. */
const pesanPassword = (e) => {
  const m = e?.errors?.password;
  const t = Array.isArray(m) ? m[0] : m;
  return typeof t === 'string' ? t : '';
};

/**
 * Galat masuk/kode -> {pesan, tipe, sisaDetik?}.
 * tipe: 'kredensial' (di field sandi) | 'form' (galat tingkat form, mis. 422 tanpa `errors`) | 'kode' | 'validasi' | 'laju' | 'csrf' | 'offline' | 'lain'.
 * Pesan server selalu didahulukan; konstanta hanya cadangan bila badan tidak memuat pesan.
 */
export function klasifikasiGalat(e) {
  if (!e) return { tipe: 'lain', pesan: 'Terjadi kesalahan.' };
  if (e.status === 0) return { tipe: 'offline', pesan: 'Tidak ada koneksi internet.' };
  const alasan = e.data?.alasan ?? null;
  if (e.status === 403) return { tipe: 'csrf', pesan: e.message };
  if (e.status === 422 && alasan === 'kredensial_salah') return { tipe: 'kredensial', pesan: pesanPassword(e) || e.data?.message || PESAN_KREDENSIAL_SALAH };
  if (e.status === 422 && alasan === 'telegram_belum_terhubung') return { tipe: 'form', pesan: e.data?.message || PESAN_TELEGRAM_BELUM_TERHUBUNG };
  if (e.status === 422 && alasan === 'terlalu_sering') return { tipe: 'form', pesan: e.data?.message || PESAN_TERLALU_SERING };
  if (e.status === 422 && alasan === 'kode_tidak_valid') return { tipe: 'kode', pesan: PESAN_KODE_TIDAK_VALID };
  if (e.status === 422) return { tipe: 'validasi', pesan: e.message };
  if (e.status === 429) {
    const detik = alasan === 'terkunci' ? detikTerkunci(e.data) : e.retryAfter || 0;
    const tunggu = detik > 0 ? ` Coba lagi dalam ${durasiRamah(detik)}.` : ' Tunggu sebentar lalu coba lagi.';
    return { tipe: 'laju', pesan: `${alasan === 'terkunci' ? 'Terlalu banyak percobaan gagal.' : 'Terlalu sering mencoba.'}${tunggu}`, sisaDetik: detik };
  }
  return { tipe: 'lain', pesan: e.message || 'Terjadi kesalahan.' };
}
