// Pembaca PETA HAK dari server (GET /api/v1/auth/me -> user). Sumber kebenaran hak = SERVER
// (config/rbac.php, App\Support\Rbac, PetaHak, Policy); lihat docs/api/peta-hak.md.
// Klien TIDAK menyalin matriks atau aturan unit/kepemilikan: hanya membaca `modules`, `permissions`,
// `unit_scope`, `aksi`, `izin` (per record), `keputusan`, `agregat`, `baca_saja`.
//
// GAGAL TERTUTUP: peta tidak ada, rusak, atau berbentuk tak dikenal => TIDAK ada menu dan TIDAK ada tombol aksi.
// Server tetap menegakkan hak (aksi tanpa hak = 403); peta hanya menyembunyikan menu/tombol.
export const ROLES = {
  admin: 'Admin',
  kepala_sppg: 'Kepala SPPG',
  ahli_gizi: 'Ahli Gizi',
  akuntan: 'Akuntan',
  staf_gudang: 'Staf Gudang',
  relawan: 'Relawan',
  investor: 'Investor',
};
// Hanya label tampilan (dan pilihan peran pada formulir Admin). Bukan aturan hak.
export const roleLabel = (role) => ROLES[role] ?? role;

/** Kunci `aksi` boolean yang dijanjikan kontrak (docs/api/peta-hak.md). */
export const KUNCI_AKSI = [
  'undang_pengguna', 'reset_password_pengguna', 'kelola_kepemilikan_sppg', 'undang_pic', 'ajukan_menu',
  'lihat_rincian_biaya', 'picu_perjalanan', 'susun_rekap', 'kirim_ulang_rekap', 'tindak_lanjut_keluhan',
  'catat_mutasi_stok', 'catat_harga_pemasok', 'lihat_ringkasan_jadwal_produksi',
  'lihat_mutasi_stok',
];

const NONE = Object.freeze({ C: false, R: false, U: false, D: false, selfOnly: false });
const HURUF = /^[CRUD*]*$/;

const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const isStrArr = (v) => Array.isArray(v) && v.every((x) => typeof x === 'string');

/** Validasi bentuk peta. Mengembalikan peta terurai, atau null (gagal tertutup) bila ada yang tak dikenal. */
function parsePeta(user) {
  if (!isObj(user)) return null;
  const { modules, permissions, unit_scope: unitScope, aksi } = user;
  if (!isStrArr(modules)) return null;
  if (!isObj(permissions) || !Object.values(permissions).every((v) => typeof v === 'string' && HURUF.test(v))) return null;
  if (!isObj(unitScope) || !Object.values(unitScope).every((v) => typeof v === 'string')) return null;
  if (!isObj(aksi) || !KUNCI_AKSI.every((k) => typeof aksi[k] === 'boolean')) return null;
  const db = aksi.dashboard;
  if (!isObj(db) || typeof db.tersedia !== 'boolean' || !(db.profil === null || typeof db.profil === 'string')) return null;
  // Bidang lama: bila ada harus bertipe benar; bila tidak ada dianggap kosong (fail closed untuk hak tambahan).
  if (user.keputusan !== undefined && !isStrArr(user.keputusan)) return null;
  if (user.agregat !== undefined && !isStrArr(user.agregat)) return null;
  if (user.baca_saja !== undefined && typeof user.baca_saja !== 'boolean') return null;
  return {
    modules: new Set(modules),
    permissions,
    unitScope,
    aksi,
    dashboard: { tersedia: db.tersedia, profil: db.profil },
    keputusan: user.keputusan ?? [],
    agregat: user.agregat ?? [],
    bacaSaja: user.baca_saja === true,
  };
}

const cache = new WeakMap();
/** Peta terurai (di-cache per objek user) atau null bila tidak valid. */
export function petaHak(user) {
  if (!isObj(user)) return null;
  if (!cache.has(user)) cache.set(user, parsePeta(user));
  return cache.get(user);
}
export const petaValid = (user) => petaHak(user) !== null;

/** Hak CRUD efektif pada satu modul: {C,R,U,D,selfOnly}. Modul tak ada di peta => semua false. */
export function userPerms(user, slug) {
  const p = petaHak(user);
  if (!p || !p.modules.has(slug)) return NONE;
  const s = p.permissions[slug];
  if (typeof s !== 'string') return NONE;
  // baca_saja (investor) dari server menekan huruf tulis walau permissions keliru: investor tanpa tombol tulis.
  const tulis = !p.bacaSaja;
  return { C: tulis && s.includes('C'), R: s.includes('R'), U: tulis && s.includes('U'), D: tulis && s.includes('D'), selfOnly: s.includes('*') };
}

/** Aksi di luar CRUD (`aksi.<kunci>`). Kunci tak dikenal/peta rusak => false. */
export function bolehAksi(user, kunci) {
  const p = petaHak(user);
  return !!p && KUNCI_AKSI.includes(kunci) && p.aksi[kunci] === true;
}

/**
 * Flag `aksi` OPSIONAL (belum tentu dikirim server): boolean bila server mengirimnya, selain itu null.
 * Beda dengan bolehAksi: kunci tak ada TIDAK berarti ditolak, supaya fitur lama tidak hilang sebelum server siap.
 * Pemanggil yang memakai ini wajib menaati nilai false (tombol/panel tidak ada).
 */
export function aksiOpsional(user, kunci) {
  const v = petaHak(user)?.aksi?.[kunci];
  return typeof v === 'boolean' ? v : null;
}

/** Kartu/rute Dashboard: hanya bila server menyatakan tersedia (relawan tanpa unit = false). */
export const dashboardTersedia = (user) => petaHak(user)?.dashboard.tersedia === true;

/** Sub-jenis data yang dikelola pada modul (mis. jadwal -> 'produksi'); '' = tanpa pembatasan. */
export const unitScope = (user, modul) => petaHak(user)?.unitScope[modul] ?? '';

/** Tab Jadwal: ada hak baca jadwal DAN (unit_scope kosong ATAU sama dengan jenis). */
export function jadwalTabBoleh(user, jenis) {
  if (!userPerms(user, 'jadwal').R) return false;
  const s = unitScope(user, 'jadwal');
  return s === '' || s === jenis;
}

/** Modul yang hanya boleh dibaca agregatnya (mis. penerima-manfaat untuk investor). */
export const agregatBoleh = (user, slug) => !!petaHak(user)?.agregat.includes(slug);

/** Hak memutuskan pada modul (persetujuan-menu, pembelian-bahan, keuangan) menurut server. */
export const punyaKeputusan = (user, slug) => !!petaHak(user)?.keputusan.includes(slug);

/** Pengguna tanpa C/U/D di modul mana pun (investor). */
export const bacaSaja = (user) => petaHak(user)?.bacaSaja === true;

/** Pengguna yang harus memilih SPPG aktif (server: multi_sppg). */
export const multiSppg = (user) => isObj(user) && user.multi_sppg === true;

/**
 * Pengguna lintas SPPG yang BUKAN multi-SPPG (Admin: tanpa sppg_id): harus memilih SPPG secara eksplisit pada layar
 * tertentu. Investor multi-SPPG (multi_sppg) dikecualikan: SPPG-nya dipilih lewat header X-SPPG-Id, termasuk sebelum
 * sppg_aktif_id terisi (user dari /auth/telegram). Ini pelingkupan DATA (parameter sppg_id), bukan keputusan hak.
 */
export const tanpaSppgSendiri = (user) => isObj(user) && !multiSppg(user) && user.sppg_id == null;

// ---- izin per record ----
/** `rec.izin` bila objek boolean; selain itu null (tidak ada izin = tidak ada tombol). */
export function izinRecord(rec) {
  const z = rec?.izin;
  if (!isObj(z)) return null;
  return Object.values(z).every((v) => typeof v === 'boolean') ? z : null;
}
/** izin.<kunci> === true. Tanpa izin di record atau nilai bukan boolean => false. */
export const bolehIzin = (rec, kunci) => izinRecord(rec)?.[kunci] === true;

/**
 * Hak per baris untuk resource BERIZIN: U/D hanya dari `izin.ubah`/`izin.hapus` server (bukan hitung ulang di klien),
 * dikalikan hak modul agar tak pernah lebih longgar dari peta modul. C/R tetap dari modul.
 */
export function permsDariIzin(p, rec) {
  return { ...p, U: p.U && bolehIzin(rec, 'ubah'), D: p.D && bolehIzin(rec, 'hapus') };
}

/** Pesan singkat saat penjaga rute mengalihkan ke beranda. */
export const PESAN_BUKAN_HAK = 'Halaman itu bukan hak akses Anda.';
