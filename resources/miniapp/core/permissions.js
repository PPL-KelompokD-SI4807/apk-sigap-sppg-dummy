// Salinan matriks hak akses (Tabel 5). C=create R=read U=update D=delete. "*" = hanya profil sendiri.
// Kunci = slug modul (urutan resmi 1-16). Peran yang tidak tercantum = tanpa akses.
export const ROLES = {
  admin: 'Admin',
  kepala_sppg: 'Kepala SPPG',
  ahli_gizi: 'Ahli Gizi',
  akuntan: 'Akuntan',
  staf_gudang: 'Staf Gudang',
  relawan: 'Relawan',
};

export const MATRIX = {
  'pengguna': { admin: 'CRUD', kepala_sppg: 'R', ahli_gizi: 'R*', akuntan: 'R*', staf_gudang: 'R*', relawan: 'R*' },
  'notifikasi-perjalanan': { admin: 'R', kepala_sppg: 'CR', relawan: 'CR' },
  'sppg': { admin: 'CRUD', kepala_sppg: 'RU', ahli_gizi: 'R', akuntan: 'R', staf_gudang: 'R', relawan: 'R' },
  'titik-layanan': { admin: 'CRUD', kepala_sppg: 'CRUD', ahli_gizi: 'R', akuntan: 'R', staf_gudang: 'R', relawan: 'R' },
  'penerima-manfaat': { admin: 'CRUD', kepala_sppg: 'CRU', ahli_gizi: 'R' },
  'bahan-baku': { admin: 'CRUD', kepala_sppg: 'R', ahli_gizi: 'R', akuntan: 'R', staf_gudang: 'CRUD' },
  'pemasok': { admin: 'CRUD', kepala_sppg: 'R', ahli_gizi: 'R', akuntan: 'R', staf_gudang: 'CRU' },
  'menu-gizi': { admin: 'R', kepala_sppg: 'R', ahli_gizi: 'CRUD', staf_gudang: 'R', relawan: 'R' },
  'jadwal': { admin: 'R', kepala_sppg: 'CRUD', ahli_gizi: 'CRU', staf_gudang: 'R', relawan: 'CRU' },
  'log-produksi': { admin: 'R', kepala_sppg: 'RUD', ahli_gizi: 'R', akuntan: 'R', staf_gudang: 'R', relawan: 'CRU' },
  'mutu': { admin: 'R', kepala_sppg: 'R', ahli_gizi: 'CRUD', relawan: 'R' },
  'armada': { admin: 'CRUD', kepala_sppg: 'CRUD', akuntan: 'R', relawan: 'R' },
  'bukti-distribusi': { admin: 'R', kepala_sppg: 'CRUD', ahli_gizi: 'R', akuntan: 'R', relawan: 'CRU' },
  'keuangan': { admin: 'R', kepala_sppg: 'R', akuntan: 'CRUD' },
  'keluhan': { admin: 'CRUD', kepala_sppg: 'RU', ahli_gizi: 'RU', relawan: 'CR' },
  'rekap-harian': { admin: 'R', kepala_sppg: 'CRU', ahli_gizi: 'R', akuntan: 'R' },
};

export const MODULE_ORDER = Object.keys(MATRIX);

/** Hak akses satu peran pada satu modul: {C,R,U,D,selfOnly}. */
export function perms(role, slug) {
  const s = MATRIX[slug]?.[role] ?? '';
  return { C: s.includes('C'), R: s.includes('R'), U: s.includes('U'), D: s.includes('D'), selfOnly: s.includes('*') };
}

/** can('admin','pengguna','C') -> boolean. Slug tak dikenal = ditolak. */
export const can = (role, slug, action) => !!perms(role, slug)[action];

export const roleLabel = (role) => ROLES[role] ?? role;
