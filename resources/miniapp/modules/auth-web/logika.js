// Logika murni layar Masuk web (R04-7). Tanpa DOM dan tanpa storage agar dapat diuji dengan node --test.
import { detikTerkunci, durasiRamah } from '../../core/kredensial.js';

export const PERINGATAN_INGAT = 'Jangan centang di komputer bersama atau warnet. Sandi tetap diminta; hanya kode Telegram yang dilewati selama 30 hari.';
export const CATATAN_ADMIN = 'Akun Admin selalu memakai kode Telegram; pilihan ini diabaikan untuk Admin.';
export const CATATAN_IDLE = 'Demi keamanan, sesi web berakhir setelah 12 jam tidak aktif (kecuali di browser yang Anda ingat).';
export const PETUNJUK_LUPA_SANDI = 'Lupa sandi? Buka Mini App SiGAP SPPG di Telegram, lalu pilih Lupa password.';
export const PESAN_KREDENSIAL_SALAH = 'Username/no telepon atau sandi salah.';
export const PESAN_KODE_TIDAK_VALID = 'Kode salah atau kedaluwarsa. Periksa kode terbaru di chat bot, atau kirim ulang kode.';

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
