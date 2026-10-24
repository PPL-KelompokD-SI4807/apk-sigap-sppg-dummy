// Validator sisi klien (murni). Mengembalikan '' bila valid, atau pesan galat berbahasa Indonesia.
// Server tetap otoritas; ini hanya mempercepat umpan balik.

const digitsOf = (v) => String(v ?? '').replace(/\D/g, '');

/** Nomor telepon: boleh diawali +, spasi/strip; 8-15 digit. Kosong = valid (opsional). */
export function phone(v, { min = 8, max = 15, label = 'Nomor telepon' } = {}) {
  if (v == null || v === '') return '';
  const s = String(v).trim();
  if (!/^\+?[\d\s().-]+$/.test(s)) return `${label} hanya boleh berisi angka, spasi, atau tanda hubung.`;
  const n = digitsOf(s).length;
  if (n < min || n > max) return `${label} harus ${min}-${max} digit.`;
  return '';
}

/** Tolak deret angka panjang mirip NIK pada nama (UU PDP). */
export function noNik(v, label = 'Nama') {
  if (v == null || v === '') return '';
  if (digitsOf(v).length >= 10) return `${label} tidak boleh memuat deret angka panjang (mirip NIK). Gunakan inisial atau nama saja.`;
  return '';
}

export const todayISO = (now = new Date()) => {
  const p = (n) => String(n).padStart(2, '0');
  return `${now.getFullYear()}-${p(now.getMonth() + 1)}-${p(now.getDate())}`;
};

/** Tanggal (YYYY-MM-DD) tidak boleh sebelum hari ini. */
export function notBeforeToday(v, label = 'Tanggal', now = new Date()) {
  if (v == null || v === '') return '';
  return String(v).slice(0, 10) < todayISO(now) ? `${label} tidak boleh sebelum hari ini.` : '';
}

/** Plat nomor Indonesia sederhana: huruf, angka, spasi; dinormalkan huruf besar spasi tunggal. */
export const normalizePlat = (v) => String(v ?? '').toUpperCase().replace(/\s+/g, ' ').trim();
export function plat(v) {
  if (v == null || v === '') return '';
  return /^[A-Z]{1,2} ?\d{1,4} ?[A-Z]{0,3}$/.test(normalizePlat(v)) ? '' : 'Format plat tidak sesuai (contoh: B 1234 XY).';
}

/** Konversi nilai pilihan 'true'/'false' dari <select> ke boolean; null bila kosong. */
export function toBool(v) {
  if (v === true || v === 'true' || v === '1' || v === 1) return true;
  if (v === false || v === 'false' || v === '0' || v === 0) return false;
  return null;
}

/** Bersihkan string kosong -> null; pertahankan tipe lain. */
export const nullIfEmpty = (v) => (v === '' || v === undefined ? null : v);
