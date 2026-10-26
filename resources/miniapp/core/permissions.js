// Salinan matriks hak akses (Tabel 5). C=create R=read U=update D=delete. "*" = hanya profil sendiri.
// Kunci = slug modul (urutan resmi 1-16). Peran yang tidak tercantum = tanpa akses.
export const ROLES = {
  admin: 'Admin',
  kepala_sppg: 'Kepala SPPG',
  ahli_gizi: 'Ahli Gizi',
  akuntan: 'Akuntan',
  staf_gudang: 'Staf Gudang',
  relawan: 'Relawan',
  investor: 'Investor',
};

export const MATRIX = {
  'pengguna': { admin: 'CRUD', kepala_sppg: 'R', ahli_gizi: 'R*', akuntan: 'R*', staf_gudang: 'R*', relawan: 'R*', investor: 'R*' },
  'notifikasi-perjalanan': { admin: 'R', kepala_sppg: 'CR', relawan: 'CR' },
  'sppg': { admin: 'CRUD', kepala_sppg: 'RU', ahli_gizi: 'R', akuntan: 'R', staf_gudang: 'R', relawan: 'R', investor: 'R' },
  'titik-layanan': { admin: 'CRUD', kepala_sppg: 'CRUD', ahli_gizi: 'R', akuntan: 'R', staf_gudang: 'R', relawan: 'R' },
  'penerima-manfaat': { admin: 'CRUD', kepala_sppg: 'CRU', ahli_gizi: 'R' },
  'bahan-baku': { admin: 'CRUD', kepala_sppg: 'R', ahli_gizi: 'R', akuntan: 'R', staf_gudang: 'CRUD', investor: 'R' },
  'pemasok': { admin: 'CRUD', kepala_sppg: 'R', ahli_gizi: 'R', akuntan: 'R', staf_gudang: 'CRU' },
  'menu-gizi': { admin: 'R', kepala_sppg: 'R', ahli_gizi: 'CRUD', staf_gudang: 'R', relawan: 'R' },
  'jadwal': { admin: 'R', kepala_sppg: 'CRUD', ahli_gizi: 'CRU', staf_gudang: 'R', relawan: 'CRU' },
  'log-produksi': { admin: 'R', kepala_sppg: 'RUD', ahli_gizi: 'R', akuntan: 'R', staf_gudang: 'R', relawan: 'CRU' },
  'mutu': { admin: 'R', kepala_sppg: 'R', ahli_gizi: 'CRUD', relawan: 'R', investor: 'R' },
  'armada': { admin: 'CRUD', kepala_sppg: 'CRUD', akuntan: 'R', relawan: 'R' },
  'bukti-distribusi': { admin: 'R', kepala_sppg: 'CRUD', ahli_gizi: 'R', akuntan: 'R', relawan: 'CRU' },
  'keuangan': { admin: 'R', kepala_sppg: 'R', akuntan: 'CRUD', investor: 'R' },
  'keluhan': { admin: 'CRUD', kepala_sppg: 'RU', ahli_gizi: 'RU', relawan: 'CR', investor: 'R' },
  'rekap-harian': { admin: 'R', kepala_sppg: 'CRU', ahli_gizi: 'R', akuntan: 'R', investor: 'R' },
};

export const MODULE_ORDER = Object.keys(MATRIX);

/** Hak akses satu peran pada satu modul: {C,R,U,D,selfOnly}. */
export function perms(role, slug) {
  const s = MATRIX[slug]?.[role] ?? '';
  return { C: s.includes('C'), R: s.includes('R'), U: s.includes('U'), D: s.includes('D'), selfOnly: s.includes('*') };
}

/** can('admin','pengguna','C') -> boolean. Slug tak dikenal = ditolak. */
export const can = (role, slug, action) => !!perms(role, slug)[action];

/**
 * Hak efektif pengguna login pada satu modul = irisan matriks peran (salinan Tabel 5), daftar
 * `user.modules` dan huruf `user.permissions[slug]` dari respons login (bila ada).
 * Server tetap otoritas akhir; ini hanya untuk menyembunyikan menu/tombol.
 */
export function userPerms(user, slug) {
  const base = perms(user?.role, slug);
  if (!user) return { C: false, R: false, U: false, D: false, selfOnly: false };
  if (Array.isArray(user.modules) && !user.modules.includes(slug)) return { C: false, R: false, U: false, D: false, selfOnly: false };
  const eff = user.permissions && typeof user.permissions[slug] === 'string' ? user.permissions[slug] : null;
  if (eff == null) return base;
  return { C: base.C && eff.includes('C'), R: base.R && eff.includes('R'), U: base.U && eff.includes('U'), D: base.D && eff.includes('D'), selfOnly: base.selfOnly || eff.includes('*') };
}

export const roleLabel = (role) => ROLES[role] ?? role;
