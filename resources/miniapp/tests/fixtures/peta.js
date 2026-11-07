// Fixture TES: peta hak seperti dikirim server pada GET /api/v1/auth/me -> user (docs/api/peta-hak.md).
// Ditulis tangan dari config/rbac.php + docs/api/peta-hak.md (ringkasan per profil). Klien TIDAK memakai ini;
// hanya tes yang memakainya untuk memastikan klien mengikuti peta server. Bila config/rbac.php berubah, perbarui di sini.
// Data fiktif.

const M = (s) => Object.fromEntries(s.trim().split(/\s+/).map((x) => x.split(':')));
const AKSI_FALSE = {
  undang_pengguna: false, reset_password_pengguna: false, kelola_kepemilikan_sppg: false, undang_pic: false,
  ajukan_menu: false, lihat_rincian_biaya: false, picu_perjalanan: false, susun_rekap: false,
  kirim_ulang_rekap: false, tindak_lanjut_keluhan: false, catat_mutasi_stok: false, catat_harga_pemasok: false,
  lihat_ringkasan_jadwal_produksi: false,
};

function user({ id, role, role_label, unit = null, sppg_id = 1, perm, aksiTrue = [], dashboard, unit_scope = {}, keputusan = [], agregat = [], baca_saja = false, multi_sppg = false }) {
  const permissions = M(perm);
  return {
    id, name: `Pengguna ${role_label}`, username: `u_${id}`, role, role_label, unit, sppg_id, sppg_name: sppg_id ? 'SPPG Contoh' : null,
    sppg_aktif_id: sppg_id, multi_sppg,
    modules: Object.keys(permissions), permissions, baca_saja, agregat, keputusan,
    unit_scope,
    aksi: { ...AKSI_FALSE, ...Object.fromEntries(aksiTrue.map((k) => [k, true])), dashboard },
  };
}

const ADMIN = 'pengguna:CRUD notifikasi-perjalanan:R sppg:CRUD titik-layanan:CRUD penerima-manfaat:CRUD bahan-baku:CRUD pemasok:CRUD menu-gizi:R jadwal:R log-produksi:R mutu:R armada:CRUD bukti-distribusi:R keuangan:R keluhan:CRUD rekap-harian:R persetujuan-menu:R pembelian-bahan:R';

export const PROFIL = {
  admin: user({
    id: 1, role: 'admin', role_label: 'Admin', sppg_id: null, perm: ADMIN,
    aksiTrue: ['undang_pengguna', 'reset_password_pengguna', 'kelola_kepemilikan_sppg', 'undang_pic', 'lihat_rincian_biaya', 'tindak_lanjut_keluhan', 'catat_mutasi_stok', 'catat_harga_pemasok', 'lihat_ringkasan_jadwal_produksi'],
    dashboard: { tersedia: true, profil: 'admin' },
  }),
  kepala_sppg: user({
    id: 2, role: 'kepala_sppg', role_label: 'Kepala SPPG',
    perm: 'pengguna:R notifikasi-perjalanan:CR sppg:RU titik-layanan:CRUD penerima-manfaat:CRU bahan-baku:R pemasok:R menu-gizi:R jadwal:CRUD log-produksi:RUD mutu:R armada:CRUD bukti-distribusi:CRUD keuangan:R keluhan:RU rekap-harian:CRU persetujuan-menu:R pembelian-bahan:R',
    aksiTrue: ['undang_pic', 'lihat_rincian_biaya', 'picu_perjalanan', 'susun_rekap', 'kirim_ulang_rekap', 'tindak_lanjut_keluhan', 'lihat_ringkasan_jadwal_produksi'],
    dashboard: { tersedia: true, profil: 'kepala_sppg' },
  }),
  ahli_gizi: user({
    id: 3, role: 'ahli_gizi', role_label: 'Ahli Gizi',
    perm: 'pengguna:R* sppg:R titik-layanan:R penerima-manfaat:R bahan-baku:R pemasok:R menu-gizi:CRUD jadwal:CRU log-produksi:R mutu:CRUD bukti-distribusi:R keluhan:RU rekap-harian:R persetujuan-menu:CR',
    aksiTrue: ['ajukan_menu', 'lihat_rincian_biaya', 'tindak_lanjut_keluhan', 'lihat_ringkasan_jadwal_produksi'],
    dashboard: { tersedia: true, profil: 'ahli_gizi' },
  }),
  akuntan: user({
    id: 4, role: 'akuntan', role_label: 'Akuntan',
    perm: 'pengguna:R* sppg:R titik-layanan:R bahan-baku:R pemasok:R log-produksi:R armada:R bukti-distribusi:R keuangan:CRUD rekap-harian:R pembelian-bahan:R',
    keputusan: ['pembelian-bahan'],
    dashboard: { tersedia: true, profil: 'akuntan' },
  }),
  staf_gudang: user({
    id: 5, role: 'staf_gudang', role_label: 'Staf Gudang',
    perm: 'pengguna:R* sppg:R titik-layanan:R bahan-baku:CRUD pemasok:CRU menu-gizi:R jadwal:R log-produksi:R pembelian-bahan:CRU',
    aksiTrue: ['lihat_rincian_biaya', 'catat_mutasi_stok', 'catat_harga_pemasok', 'lihat_ringkasan_jadwal_produksi'],
    dashboard: { tersedia: true, profil: 'staf_gudang' },
  }),
  relawan_dapur: user({
    id: 6, role: 'relawan', role_label: 'Relawan', unit: 'dapur',
    perm: 'pengguna:R* sppg:R titik-layanan:R menu-gizi:R jadwal:CRU log-produksi:CRU mutu:R armada:R keluhan:CR',
    unit_scope: { jadwal: 'produksi' }, aksiTrue: ['lihat_ringkasan_jadwal_produksi'],
    dashboard: { tersedia: true, profil: 'relawan_dapur' },
  }),
  relawan_distribusi: user({
    id: 7, role: 'relawan', role_label: 'Relawan', unit: 'distribusi',
    perm: 'pengguna:R* notifikasi-perjalanan:CR sppg:R titik-layanan:R menu-gizi:R jadwal:CRU mutu:R armada:R bukti-distribusi:CRU keluhan:CR',
    unit_scope: { jadwal: 'distribusi' }, aksiTrue: ['picu_perjalanan', 'lihat_ringkasan_jadwal_produksi'],
    dashboard: { tersedia: true, profil: 'relawan_distribusi' },
  }),
  relawan_tanpa_unit: user({
    id: 8, role: 'relawan', role_label: 'Relawan', unit: null,
    perm: 'pengguna:R* sppg:R titik-layanan:R menu-gizi:R mutu:R armada:R keluhan:CR',
    dashboard: { tersedia: false, profil: null },
  }),
  investor: user({
    id: 9, role: 'investor', role_label: 'Investor', sppg_id: null, multi_sppg: true, baca_saja: true,
    perm: 'pengguna:R* sppg:R bahan-baku:R mutu:R keuangan:R keluhan:R rekap-harian:R persetujuan-menu:R',
    keputusan: ['persetujuan-menu', 'keuangan'], agregat: ['penerima-manfaat'],
    dashboard: { tersedia: true, profil: 'investor' },
  }),
};

/** Salinan dalam (tes boleh memutasi tanpa memengaruhi tes lain). */
export const salin = (nama) => structuredClone(PROFIL[nama]);
export const NAMA_PROFIL = Object.keys(PROFIL);
