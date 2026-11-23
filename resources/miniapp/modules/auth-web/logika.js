// Logika murni layar Masuk web (R04-7). Tanpa DOM dan tanpa storage agar dapat diuji dengan node --test.
import { detikTerkunci, durasiRamah } from '../../core/kredensial.js';

export const PERINGATAN_INGAT = 'Jangan centang di komputer bersama atau warnet. Sandi tetap diminta; hanya kode Telegram yang dilewati selama 30 hari.';
export const CATATAN_ADMIN = 'Akun Admin selalu memakai kode Telegram; pilihan ini diabaikan untuk Admin.';
export const CATATAN_IDLE = 'Demi keamanan, sesi web berakhir setelah 12 jam tidak aktif (kecuali di browser yang Anda ingat).';
export const PETUNJUK_LUPA_SANDI = 'Lupa sandi? Buka Mini App SiGAP SPPG di Telegram, lalu pilih Lupa password.';
export const PESAN_KREDENSIAL_SALAH = 'Username/no telepon atau sandi salah.';
export const PESAN_KODE_TIDAK_VALID = 'Kode salah atau kedaluwarsa. Periksa kode terbaru di chat bot, atau kirim ulang kode.';

// Panduan "kode tidak datang" (R04 anti-enumerasi): tiga sebab nyata, kalimat netral yang sama untuk semua orang.
// JANGAN menyiratkan mana yang berlaku; sistem sengaja tidak memberi tahu apakah username/sandi salah.
export const JUDUL_PANDUAN_KODE = 'Kode tidak datang?';
export const PENGANTAR_PANDUAN_KODE = 'Ada tiga sebab yang mungkin. Demi keamanan akun, sistem tidak menyebut mana yang berlaku untuk Anda.';
export const SEBAB_KODE_TIDAK_DATANG = [
  'Sandi yang dimasukkan mungkin salah. Pilih Mulai dari awal, lalu masukkan username dan sandi dengan teliti.',
  'Akun mungkin belum tertaut dengan Telegram. Hubungi Admin SPPG untuk memeriksa penautan akun.',
  'Anda mungkin belum pernah menekan Start di bot SiGAP. Buka bot di Telegram, tekan Start, lalu kirim ulang kode.',
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

/** Galat masuk/kode -> {pesan, tipe, sisaDetik?}. tipe: 'kredensial' | 'kode' | 'validasi' | 'laju' | 'csrf' | 'offline' | 'lain'. */
export function klasifikasiGalat(e) {
  if (!e) return { tipe: 'lain', pesan: 'Terjadi kesalahan.' };
  if (e.status === 0) return { tipe: 'offline', pesan: 'Tidak ada koneksi internet.' };
  const alasan = e.data?.alasan ?? null;
  if (e.status === 403) return { tipe: 'csrf', pesan: e.message };
  if (e.status === 422 && alasan === 'kredensial_salah') return { tipe: 'kredensial', pesan: PESAN_KREDENSIAL_SALAH };
  if (e.status === 422 && alasan === 'kode_tidak_valid') return { tipe: 'kode', pesan: PESAN_KODE_TIDAK_VALID };
  if (e.status === 422) return { tipe: 'validasi', pesan: e.message };
  if (e.status === 429) {
    const detik = alasan === 'terkunci' ? detikTerkunci(e.data) : e.retryAfter || 0;
    const tunggu = detik > 0 ? ` Coba lagi dalam ${durasiRamah(detik)}.` : ' Tunggu sebentar lalu coba lagi.';
    return { tipe: 'laju', pesan: `${alasan === 'terkunci' ? 'Terlalu banyak percobaan gagal.' : 'Terlalu sering mencoba.'}${tunggu}`, sisaDetik: detik };
  }
  return { tipe: 'lain', pesan: e.message || 'Terjadi kesalahan.' };
}
