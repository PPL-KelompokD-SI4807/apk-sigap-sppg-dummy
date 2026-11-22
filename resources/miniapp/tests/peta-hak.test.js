// Tes parity "UI hanya menampilkan yang menjadi hak peran" (PR-B): klien membaca peta hak server, tanpa salinan matriks.
// Jalankan: node --test resources/miniapp/tests/*.test.js
// Fixture peta per profil: tests/fixtures/peta.js (cermin keluaran server GET /auth/me, ditulis dari config/rbac.php).
import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, existsSync, readFileSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join } from 'node:path';
import { PROFIL, NAMA_PROFIL, salin } from './fixtures/peta.js';
import {
  KUNCI_AKSI, petaValid, userPerms, bolehAksi, dashboardTersedia, unitScope, jadwalTabBoleh, agregatBoleh, multiSppg,
  punyaKeputusan, bacaSaja, izinRecord, bolehIzin, permsDariIzin, tanpaSppgSendiri,
} from '../core/permissions.js';
import { setUserSesi } from '../core/auth.js';
import { route, cekAkses } from '../core/router.js';
import { menuUntuk } from '../modules/menu.js';
import { tujuanDraf, tombolVerifikasiTampil } from '../modules/pembelian-bahan/logika.js';
import { tombolKeputusanPengeluaranTampil } from '../modules/keuangan/persetujuan.js';
import { bolehUbahStatus } from '../modules/notifikasi-perjalanan/schema.js';
import { bolehAjukan } from '../modules/persetujuan-menu/logika.js';
import { distribusiFields, distribusiBisaDibuat, jendelaRingkas, ringkasOptionLabel, ringkasDapatDipilih } from '../modules/jadwal/schema.js';
import { keluhanFields, validasiBatch, suntingPenuh, rowPermsKeluhan } from '../modules/keluhan/schema.js';
import { sppgField } from '../core/schema-helpers.js';
import { isianUndanganDariDraf, isianPenggunaDariDraf } from '../modules/pengguna/schema.js';
import { lampiranDisembunyikan } from '../core/lampiran-uploader.js';
import { queryAgregat } from '../core/agregat.js';
import { fields as keluhanFormFields } from '../modules/keluhan/index.js';

// ---- muat semua modul seperti registry.js (urut nama, folder "_" terakhir, slug ganda dilewati) ----
const rootModul = join(dirname(fileURLToPath(import.meta.url)), '..', 'modules');
const berkas = readdirSync(rootModul, { withFileTypes: true })
  .filter((d) => d.isDirectory() && existsSync(join(rootModul, d.name, 'index.js')))
  .map((d) => d.name)
  .sort((a, b) => Number(a.startsWith('_')) - Number(b.startsWith('_')) || a.localeCompare(b));
const MODUL = [];
{
  const seen = new Set();
  for (const nama of berkas) {
    const mod = await import(pathToFileURL(join(rootModul, nama, 'index.js')).href);
    for (const m of [mod.default].flat().filter(Boolean)) {
      if (seen.has(m.slug)) continue;
      seen.add(m.slug);
      MODUL.push(m);
    }
  }
}
MODUL.forEach((m) => m.register?.());
// Rute pembantu di main.js (tidak dapat diimpor tanpa DOM): didaftarkan ulang di sini hanya untuk tes dasar.
route('/', () => {}, {});

const akses = (nama, path) => { setUserSesi(salin(nama)); return cekAkses(path); };
const SEMUA = NAMA_PROFIL;
const urut = (a) => [...a].sort();

// ---------------------------------------------------------------------------------------------
test('fail closed: peta tidak ada, rusak, atau bentuk tak dikenal => tanpa menu dan tanpa hak', () => {
  const baik = salin('admin');
  const rusak = {
    'null': null,
    'string': 'admin',
    'tanpa peta (login lama)': { id: 1, role: 'admin', name: 'X' },
    'tanpa unit_scope': (() => { const u = salin('admin'); delete u.unit_scope; return u; })(),
    'tanpa aksi': (() => { const u = salin('admin'); delete u.aksi; return u; })(),
    'tanpa permissions': (() => { const u = salin('admin'); delete u.permissions; return u; })(),
    'tanpa modules': (() => { const u = salin('admin'); delete u.modules; return u; })(),
    'modules bukan array': { ...baik, modules: 'pengguna' },
    'huruf tak dikenal': { ...baik, permissions: { ...baik.permissions, pengguna: 'CRUDX' } },
    'permissions array': { ...baik, permissions: [] },
    'unit_scope array (bukan objek)': { ...baik, unit_scope: [] },
    'unit_scope nilai bukan string': { ...baik, unit_scope: { jadwal: 3 } },
    'aksi bukan boolean': { ...baik, aksi: { ...baik.aksi, undang_pengguna: 'true' } },
    'aksi kehilangan kunci': (() => { const u = salin('admin'); delete u.aksi.susun_rekap; return u; })(),
    'dashboard bukan objek': { ...baik, aksi: { ...baik.aksi, dashboard: true } },
    'dashboard.tersedia bukan boolean': { ...baik, aksi: { ...baik.aksi, dashboard: { tersedia: 1, profil: 'admin' } } },
    'keputusan bukan array string': { ...baik, keputusan: 'x' },
    'baca_saja bukan boolean': { ...baik, baca_saja: 'ya' },
  };
  for (const [nama, u] of Object.entries(rusak)) {
    assert.equal(petaValid(u), false, nama);
    assert.deepEqual(menuUntuk(u, MODUL), [], `${nama}: menu kosong`);
    for (const m of MODUL) {
      const p = userPerms(u, m.permissionSlug ?? m.slug);
      assert.deepEqual([p.C, p.R, p.U, p.D], [false, false, false, false], `${nama}: hak ${m.slug}`);
    }
    for (const k of KUNCI_AKSI) assert.equal(bolehAksi(u, k), false, `${nama}: aksi ${k}`);
    assert.equal(dashboardTersedia(u), false, nama);
    assert.equal(jadwalTabBoleh(u, 'produksi'), false, nama);
    assert.equal(agregatBoleh(u, 'penerima-manfaat'), false, nama);
  }
  for (const k of ['unknown', '', undefined, null]) assert.equal(bolehAksi(baik, k), false, `kunci aksi tak dikenal: ${k}`);
});

test('fail closed: penjaga rute menolak semua rute modul bila peta rusak, beranda tetap terbuka', () => {
  setUserSesi({ id: 1, role: 'admin', name: 'X' }); // tanpa peta
  for (const p of ['/pengguna', '/pengguna/baru', '/sppg', '/jadwal', '/jadwal/baru', '/keuangan', '/bahan-baku/stok-menipis', '/dashboard', '/rekap-harian/ringkasan']) {
    assert.equal(cekAkses(p), 'ditolak', p);
  }
  assert.equal(cekAkses('/'), 'boleh');
  assert.equal(cekAkses('/rute-tak-ada/x'), 'tidak-ada');
});

test('fail closed: record tanpa izin / izin rusak => tanpa tombol Ubah dan Hapus', () => {
  const p = userPerms(PROFIL.kepala_sppg, 'jadwal'); // CRUD
  for (const rec of [{ id: 1 }, { id: 1, izin: null }, { id: 1, izin: [] }, { id: 1, izin: 'ya' }, { id: 1, izin: { ubah: 'true', hapus: 1 } }, null, undefined]) {
    const r = permsDariIzin(p, rec);
    assert.deepEqual([r.U, r.D], [false, false], JSON.stringify(rec));
  }
  assert.equal(izinRecord({ izin: { ubah: true, hapus: false } }).ubah, true);
  assert.equal(bolehIzin({ izin: { ubah: true } }, 'tidak-ada'), false);
});

// ---------------------------------------------------------------------------------------------
test('menu beranda per profil mengikuti peta server (dan tidak lebih)', () => {
  const harap = {
    admin: ['laporan-bgn', 'pengguna', 'notifikasi-perjalanan', 'sppg', 'titik-layanan', 'penerima-manfaat', 'bahan-baku', 'pemasok', 'menu-gizi', 'jadwal', 'log-produksi', 'mutu', 'armada', 'bukti-distribusi', 'keuangan', 'keluhan', 'rekap-harian', 'persetujuan-menu', 'pembelian-bahan'],
    kepala_sppg: ['laporan-bgn', 'pengguna', 'notifikasi-perjalanan', 'sppg', 'titik-layanan', 'penerima-manfaat', 'bahan-baku', 'pemasok', 'menu-gizi', 'jadwal', 'log-produksi', 'mutu', 'armada', 'bukti-distribusi', 'keuangan', 'keluhan', 'rekap-harian', 'persetujuan-menu', 'pembelian-bahan'],
    ahli_gizi: ['pengguna', 'sppg', 'titik-layanan', 'penerima-manfaat', 'bahan-baku', 'pemasok', 'menu-gizi', 'jadwal', 'log-produksi', 'mutu', 'bukti-distribusi', 'keluhan', 'rekap-harian', 'persetujuan-menu'],
    akuntan: ['laporan-bgn', 'pengguna', 'sppg', 'titik-layanan', 'bahan-baku', 'pemasok', 'log-produksi', 'armada', 'bukti-distribusi', 'keuangan', 'rekap-harian', 'pembelian-bahan'],
    staf_gudang: ['pengguna', 'sppg', 'titik-layanan', 'bahan-baku', 'pemasok', 'menu-gizi', 'jadwal', 'log-produksi', 'pembelian-bahan'],
    relawan_dapur: ['pengguna', 'sppg', 'titik-layanan', 'menu-gizi', 'jadwal', 'log-produksi', 'mutu', 'armada', 'keluhan'],
    relawan_distribusi: ['pengguna', 'notifikasi-perjalanan', 'sppg', 'titik-layanan', 'menu-gizi', 'jadwal', 'mutu', 'armada', 'bukti-distribusi', 'keluhan'],
    relawan_tanpa_unit: ['pengguna', 'sppg', 'titik-layanan', 'menu-gizi', 'mutu', 'armada', 'keluhan'],
    investor: ['laporan-bgn', 'pengguna', 'sppg', 'bahan-baku', 'mutu', 'keuangan', 'keluhan', 'rekap-harian', 'persetujuan-menu'],
  };
  assert.deepEqual(urut(Object.keys(harap)), urut(SEMUA), 'semua profil tercakup');
  for (const nama of SEMUA) {
    const menu = menuUntuk(PROFIL[nama], MODUL).map((m) => m.slug);
    assert.deepEqual(urut(menu), urut(harap[nama]), nama);
    // Sub-modul/tab tidak pernah jadi kartu sendiri (#9: tidak ada kartu Pengemudi ganda).
    for (const s of ['pengemudi', 'jadwal/distribusi', 'keuangan/pencairan', 'keuangan/pengeluaran', 'menu-gizi/standar-gizi', 'dashboard']) assert.ok(!menu.includes(s), `${nama}: ${s}`);
  }
});

test('#8 #9: label kartu menyesuaikan hak (Profil Saya untuk hak R*, Armada dan Pengemudi satu kartu)', () => {
  const judul = (nama, slug) => menuUntuk(PROFIL[nama], MODUL).find((m) => m.slug === slug)?.title;
  assert.equal(judul('admin', 'pengguna'), 'Data Pengguna');
  assert.equal(judul('kepala_sppg', 'pengguna'), 'Data Pengguna');
  for (const n of ['ahli_gizi', 'akuntan', 'staf_gudang', 'relawan_dapur', 'relawan_distribusi', 'investor']) assert.equal(judul(n, 'pengguna'), 'Profil Saya', n);
  assert.equal(judul('admin', 'armada'), 'Armada dan Pengemudi');
  assert.equal(MODUL.filter((m) => m.title === 'Pengemudi' && !m.hidden).length, 0);
  assert.equal(menuUntuk(PROFIL.admin, MODUL).filter((m) => /pengemudi/i.test(m.title)).length, 1, 'tepat satu kartu yang menyebut Pengemudi');
});

test('#7 kartu Dashboard hanya bila server menyatakan tersedia; relawan tanpa unit tidak', () => {
  for (const nama of SEMUA) assert.equal(dashboardTersedia(PROFIL[nama]), nama !== 'relawan_tanpa_unit', nama);
  assert.equal(akses('relawan_tanpa_unit', '/dashboard'), 'ditolak');
  for (const nama of SEMUA.filter((n) => n !== 'relawan_tanpa_unit')) assert.equal(akses(nama, '/dashboard'), 'boleh', nama);
});

test('#10 ringkasan penerima manfaat dari user.agregat (bukan peran investor); tetap hanya investor di fixture', () => {
  for (const nama of SEMUA) assert.equal(agregatBoleh(PROFIL[nama], 'penerima-manfaat'), nama === 'investor', nama);
  const bukanInvestor = { ...salin('akuntan'), agregat: ['penerima-manfaat'] };
  assert.equal(agregatBoleh(bukanInvestor, 'penerima-manfaat'), true, 'mengikuti data server, bukan nama peran');
  const investorTanpaAgregat = { ...salin('investor'), agregat: [] };
  assert.equal(agregatBoleh(investorTanpaAgregat, 'penerima-manfaat'), false);
});

test('multi_sppg, baca_saja, keputusan dibaca dari server', () => {
  for (const nama of SEMUA) {
    assert.equal(multiSppg(PROFIL[nama]), nama === 'investor', nama);
    assert.equal(bacaSaja(PROFIL[nama]), nama === 'investor', nama);
  }
  assert.equal(punyaKeputusan(PROFIL.investor, 'persetujuan-menu'), true);
  assert.equal(punyaKeputusan(PROFIL.akuntan, 'pembelian-bahan'), true);
  assert.equal(punyaKeputusan(PROFIL.ahli_gizi, 'persetujuan-menu'), false);
  assert.equal(lampiranDisembunyikan(PROFIL.investor), true);
  assert.equal(lampiranDisembunyikan(PROFIL.kepala_sppg), false);
  assert.equal(lampiranDisembunyikan(PROFIL.kepala_sppg, { lampiran_disembunyikan: true }), true);
});

// ---------------------------------------------------------------------------------------------
// Penjaga rute: path -> profil yang BOLEH; profil lain harus 'ditolak'.
const RUTE = {
  '/pengguna': SEMUA, // semua punya R atau R* (R* diarahkan ke profil sendiri)
  '/pengguna/baru': ['admin'],
  '/pengguna/7/ubah': ['admin'],
  '/pengguna/undangan': ['admin'],
  '/pengguna/undangan/baru': ['admin'],
  '/pengguna/permintaan-reset': ['admin'],
  '/pengguna/7/sesi': ['admin'],
  '/notifikasi-perjalanan': ['admin', 'kepala_sppg', 'relawan_distribusi'],
  '/notifikasi-perjalanan/terakhir': ['admin', 'kepala_sppg', 'relawan_distribusi'],
  '/notifikasi-perjalanan/baru': ['kepala_sppg', 'relawan_distribusi'],
  '/sppg/baru': ['admin'],
  '/sppg/1/ubah': ['admin', 'kepala_sppg'],
  '/titik-layanan/baru': ['admin', 'kepala_sppg'],
  '/penerima-manfaat': ['admin', 'kepala_sppg', 'ahli_gizi'],
  '/penerima-manfaat/baru': ['admin', 'kepala_sppg'],
  '/bahan-baku/stok-menipis': ['admin', 'kepala_sppg', 'ahli_gizi', 'akuntan', 'staf_gudang', 'investor'],
  '/bahan-baku/baru': ['admin', 'staf_gudang'],
  '/bahan-baku/3/mutasi': ['admin', 'staf_gudang'],
  '/pemasok/baru': ['admin', 'staf_gudang'],
  '/menu-gizi/baru': ['ahli_gizi'],
  '/menu-gizi/1/rincian-biaya': ['admin', 'kepala_sppg', 'ahli_gizi', 'staf_gudang'], // #2: relawan tidak
  '/jadwal/baru': ['kepala_sppg', 'ahli_gizi', 'relawan_dapur'],
  '/jadwal/5': ['admin', 'kepala_sppg', 'ahli_gizi', 'staf_gudang', 'relawan_dapur'], // relawan distribusi tidak (unit_scope)
  '/jadwal/5/ubah': ['kepala_sppg', 'ahli_gizi', 'relawan_dapur'],
  '/jadwal/5/kebutuhan': ['admin', 'kepala_sppg', 'ahli_gizi', 'staf_gudang', 'relawan_dapur'],
  '/jadwal/distribusi/baru': ['kepala_sppg', 'ahli_gizi', 'relawan_distribusi'], // dapur tidak (unit_scope)
  '/jadwal/distribusi/5': ['admin', 'kepala_sppg', 'ahli_gizi', 'staf_gudang', 'relawan_distribusi'],
  '/jadwal/distribusi/5/ubah': ['kepala_sppg', 'ahli_gizi', 'relawan_distribusi'],
  '/log-produksi': ['admin', 'kepala_sppg', 'ahli_gizi', 'akuntan', 'staf_gudang', 'relawan_dapur'],
  '/log-produksi/baru': ['relawan_dapur'],
  '/log-produksi/5/ubah': ['kepala_sppg', 'relawan_dapur'],
  '/mutu/baru': ['ahli_gizi'],
  '/armada': ['admin', 'kepala_sppg', 'akuntan', 'relawan_dapur', 'relawan_distribusi', 'relawan_tanpa_unit'],
  '/armada/baru': ['admin', 'kepala_sppg'],
  '/pengemudi': ['admin', 'kepala_sppg', 'akuntan', 'relawan_dapur', 'relawan_distribusi', 'relawan_tanpa_unit'],
  '/pengemudi/baru': ['admin', 'kepala_sppg'],
  '/bukti-distribusi': ['admin', 'kepala_sppg', 'ahli_gizi', 'akuntan', 'relawan_distribusi'],
  '/bukti-distribusi/baru': ['kepala_sppg', 'relawan_distribusi'],
  '/keuangan': ['admin', 'kepala_sppg', 'akuntan', 'investor'],
  '/keuangan/pencairan/baru': ['akuntan'],
  '/keuangan/pengeluaran': ['admin', 'kepala_sppg', 'akuntan', 'investor'],
  '/keuangan/pengeluaran/baru': ['akuntan'],
  '/keuangan/pengeluaran/3/ubah': ['akuntan'],
  '/keluhan/baru': ['admin', 'relawan_dapur', 'relawan_distribusi', 'relawan_tanpa_unit'],
  '/keluhan/5/ubah': ['admin', 'kepala_sppg', 'ahli_gizi'],
  '/rekap-harian/baru': ['kepala_sppg'],
  '/rekap-harian/ringkasan': ['admin', 'kepala_sppg', 'ahli_gizi', 'akuntan', 'investor'],
  '/persetujuan-menu': ['admin', 'kepala_sppg', 'ahli_gizi', 'investor'],
  '/pembelian-bahan': ['admin', 'kepala_sppg', 'akuntan', 'staf_gudang'],
  '/pembelian-bahan/baru': ['staf_gudang'],
  '/pembelian-bahan/5/ubah': ['staf_gudang'],
};

test('penjaga rute per profil: rute hak = boleh, selain itu ditolak (dialihkan ke beranda)', () => {
  for (const [path, boleh] of Object.entries(RUTE)) {
    for (const nama of SEMUA) {
      const harap = boleh.includes(nama) ? 'boleh' : 'ditolak';
      assert.equal(akses(nama, path), harap, `${nama} ${path}`);
    }
  }
});

test('investor tanpa satu pun rute tulis (baru/ubah/mutasi/undangan)', () => {
  for (const path of Object.keys(RUTE).filter((p) => /\/(baru|ubah|mutasi|undangan|permintaan-reset|sesi)$/.test(p) || p.endsWith('/sesi'))) {
    assert.equal(akses('investor', path), 'ditolak', path);
  }
  for (const m of MODUL) {
    const p = userPerms(PROFIL.investor, m.permissionSlug ?? m.slug);
    assert.deepEqual([p.C, p.U, p.D], [false, false, false], m.slug);
  }
  // Investor hanya boleh membaca agregat laporan BGN; tanpa lihat_laporan_bgn (30b, 30d-C) dan tanpa penerbitan nomor.
  for (const k of KUNCI_AKSI) assert.equal(bolehAksi(PROFIL.investor, k), k === 'lihat_laporan_bgn_agregat', k);
});

// ---------------------------------------------------------------------------------------------
test('aksi di luar CRUD mengikuti aksi.* server per profil', () => {
  const harap = {
    admin: ['undang_pengguna', 'reset_password_pengguna', 'kelola_kepemilikan_sppg', 'undang_pic', 'lihat_rincian_biaya', 'tindak_lanjut_keluhan', 'catat_mutasi_stok', 'catat_harga_pemasok', 'lihat_ringkasan_jadwal_produksi', 'lihat_mutasi_stok', 'lihat_laporan_bgn', 'lihat_laporan_bgn_agregat', 'terbitkan_nomor_laporan_bgn'],
    kepala_sppg: ['lihat_mutasi_stok', 'undang_pic', 'lihat_rincian_biaya', 'picu_perjalanan', 'susun_rekap', 'kirim_ulang_rekap', 'tindak_lanjut_keluhan', 'lihat_ringkasan_jadwal_produksi', 'lihat_laporan_bgn', 'lihat_laporan_bgn_agregat', 'terbitkan_nomor_laporan_bgn'],
    ahli_gizi: ['ajukan_menu', 'lihat_rincian_biaya', 'tindak_lanjut_keluhan', 'lihat_ringkasan_jadwal_produksi'],
    akuntan: ['lihat_mutasi_stok', 'lihat_laporan_bgn', 'lihat_laporan_bgn_agregat', 'terbitkan_nomor_laporan_bgn'],
    staf_gudang: ['lihat_mutasi_stok', 'lihat_rincian_biaya', 'catat_mutasi_stok', 'catat_harga_pemasok', 'lihat_ringkasan_jadwal_produksi'],
    relawan_dapur: ['lihat_ringkasan_jadwal_produksi'],
    relawan_distribusi: ['picu_perjalanan', 'lihat_ringkasan_jadwal_produksi'],
    relawan_tanpa_unit: [],
    investor: ['lihat_laporan_bgn_agregat'],
  };
  for (const nama of SEMUA) {
    const aktif = KUNCI_AKSI.filter((k) => bolehAksi(PROFIL[nama], k));
    assert.deepEqual(urut(aktif), urut(harap[nama]), nama);
  }
});

test('unit_scope menentukan tab Jadwal (tanpa membaca unit relawan)', () => {
  assert.equal(unitScope(PROFIL.relawan_dapur, 'jadwal'), 'produksi');
  assert.equal(unitScope(PROFIL.relawan_distribusi, 'jadwal'), 'distribusi');
  assert.equal(unitScope(PROFIL.kepala_sppg, 'jadwal'), '');
  const tab = (nama) => ['produksi', 'distribusi'].filter((j) => jadwalTabBoleh(PROFIL[nama], j));
  assert.deepEqual(tab('relawan_dapur'), ['produksi']);
  assert.deepEqual(tab('relawan_distribusi'), ['distribusi']);
  for (const n of ['admin', 'kepala_sppg', 'ahli_gizi', 'staf_gudang']) assert.deepEqual(tab(n), ['produksi', 'distribusi'], n);
  for (const n of ['akuntan', 'relawan_tanpa_unit', 'investor']) assert.deepEqual(tab(n), [], n);
  // Server menentukan: unit_scope diubah => tab ikut, tanpa melihat role/unit.
  const u = { ...salin('relawan_dapur'), unit: 'distribusi' };
  assert.deepEqual(['produksi', 'distribusi'].filter((j) => jadwalTabBoleh(u, j)), ['produksi']);
});

// ---------------------------------------------------------------------------------------------
test('izin per record: tombol Ubah/Hapus hanya dari izin server (#5 relawan, record hari lalu)', () => {
  const p = userPerms(PROFIL.relawan_dapur, 'jadwal'); // CRU
  const kemarin = { id: 41, dibuat_oleh: 6, status: 'direncanakan', izin: { ubah: false, hapus: false } };
  const hariIni = { id: 42, dibuat_oleh: 6, status: 'direncanakan', izin: { ubah: true, hapus: false } };
  assert.equal(permsDariIzin(p, kemarin).U, false);
  assert.equal(permsDariIzin(p, hariIni).U, true);
  assert.equal(permsDariIzin(p, hariIni).D, false, 'relawan tak punya D walau izin.hapus (AND dengan hak modul)');
  // Klien tidak menghitung ulang: milik orang lain tapi izin.ubah true => tombol tampil (server yang menjamin).
  assert.equal(permsDariIzin(p, { id: 1, dibuat_oleh: 999, izin: { ubah: true, hapus: false } }).U, true);
  // Kepala: hapus mengikuti izin.hapus (status).
  const k = userPerms(PROFIL.kepala_sppg, 'jadwal');
  assert.equal(permsDariIzin(k, { status: 'dimasak', izin: { ubah: true, hapus: false } }).D, false);
  assert.equal(permsDariIzin(k, { status: 'direncanakan', izin: { ubah: true, hapus: true } }).D, true);
  // Investor: izin keliru dari server tidak pernah melampaui hak modul.
  const inv = userPerms(PROFIL.investor, 'keuangan');
  assert.deepEqual([permsDariIzin(inv, { izin: { ubah: true, hapus: true } }).U, permsDariIzin(inv, { izin: { ubah: true, hapus: true } }).D], [false, false]);
});

test('tombol verifikasi pembelian dan putuskan pengeluaran dari izin server', () => {
  assert.equal(tombolVerifikasiTampil({ status: 'diajukan', izin: { ubah: false, hapus: false, lampirkan: false, verifikasi: true } }), true);
  assert.equal(tombolVerifikasiTampil({ status: 'diajukan', izin: { verifikasi: false } }), false, 'pencatat sendiri / bukan akuntan');
  assert.equal(tombolVerifikasiTampil({ status: 'diajukan' }), false, 'tanpa izin');
  assert.equal(tombolKeputusanPengeluaranTampil({ status_persetujuan: 'diajukan', izin: { putuskan: true } }), true);
  assert.equal(tombolKeputusanPengeluaranTampil({ status_persetujuan: 'diajukan', izin: { putuskan: false } }), false);
  assert.equal(tombolKeputusanPengeluaranTampil({ status_persetujuan: 'diajukan' }), false);
  // Penjaga rute Ubah pembelian/pengeluaran memakai izin record (diuji lewat permsDariIzin yang dipakai crud-module).
  const staf = userPerms(PROFIL.staf_gudang, 'pembelian-bahan');
  assert.equal(permsDariIzin(staf, { status: 'terverifikasi', izin: { ubah: false, hapus: false } }).U, false);
  assert.equal(permsDariIzin(staf, { status: 'ditolak', izin: { ubah: true, hapus: false } }).U, true);
  const akt = userPerms(PROFIL.akuntan, 'keuangan');
  assert.equal(permsDariIzin(akt, { pembelian_bahan_id: 7, izin: { ubah: false, hapus: false, lampirkan: false } }).U, false);
});

test('tombol ubah status perjalanan: izin.ubah_status server + masih ada transisi', () => {
  assert.equal(bolehUbahStatus({ status: 'berangkat', izin: { ubah_status: true } }), true);
  assert.equal(bolehUbahStatus({ status: 'berangkat', izin: { ubah_status: false } }), false, 'relawan lain / bukan hak');
  assert.equal(bolehUbahStatus({ status: 'tiba', izin: { ubah_status: true } }), false, 'final');
  assert.equal(bolehUbahStatus({ status: 'berangkat' }), false, 'tanpa izin');
});

test('tombol Ajukan menu: aksi.ajukan_menu server + status menu', () => {
  for (const nama of SEMUA) assert.equal(bolehAjukan(PROFIL[nama], 'draf'), nama === 'ahli_gizi', nama);
  assert.equal(bolehAjukan(PROFIL.ahli_gizi, 'disetujui'), false);
  assert.equal(bolehAjukan(PROFIL.ahli_gizi, 'perlu_revisi'), true);
});

// ---------------------------------------------------------------------------------------------
test('#1 dropdown jadwal produksi pada form distribusi memakai /jadwal/produksi-ringkas (nama_menu datar)', () => {
  const f = distribusiFields({ mode: 'create', user: PROFIL.relawan_distribusi }).find((x) => x.name === 'jadwal_produksi_id');
  assert.equal(f.relation.endpoint, '/jadwal/produksi-ringkas');
  assert.ok(f.relation.query.from && f.relation.query.to);
  const jarak = (new Date(f.relation.query.to) - new Date(f.relation.query.from)) / 86400000;
  assert.ok(jarak >= 0 && jarak <= 62, `selisih ${jarak} hari harus <= 62`);
  const baris = { id: 12, tanggal_produksi: '2026-11-02', nama_menu: 'Nasi, ayam suwir, sayur bayam', status: 'direncanakan' };
  assert.equal(f.relation.format(baris), '2026-11-02 · Nasi, ayam suwir, sayur bayam');
  assert.equal(ringkasOptionLabel({ id: 3, tanggal_produksi: '2026-11-02', nama_menu: null }), '2026-11-02 · Jadwal #3');
  assert.equal(ringkasDapatDipilih(baris), true);
  assert.equal(ringkasDapatDipilih({ ...baris, status: 'dibatalkan' }), false);
  assert.deepEqual(jendelaRingkas({ hariLalu: 14, hariDepan: 0 }, '2026-11-07'), { from: '2026-10-24', to: '2026-11-07' });
  assert.deepEqual(jendelaRingkas(undefined, '2026-11-07'), { from: '2026-11-07', to: '2026-11-20' });
});

test('#4 dropdown Armada/Pengemudi disembunyikan bagi yang tak berhak; Tambah disembunyikan bila form tak bisa selesai', () => {
  const nama = (u) => distribusiFields({ mode: 'create', user: u }).map((f) => f.name);
  assert.ok(nama(PROFIL.kepala_sppg).includes('armada_id') && nama(PROFIL.kepala_sppg).includes('pengemudi_id'));
  assert.ok(nama(PROFIL.relawan_distribusi).includes('armada_id'));
  for (const n of ['armada_id', 'pengemudi_id']) assert.ok(!nama(PROFIL.ahli_gizi).includes(n), `ahli gizi tak punya hak armada: ${n}`);
  assert.ok(nama(PROFIL.ahli_gizi).includes('titik_layanan_id') && nama(PROFIL.ahli_gizi).includes('jadwal_produksi_id'));
  // Tambah: butuh ringkasan jadwal + hak baca titik layanan.
  assert.equal(distribusiBisaDibuat(PROFIL.kepala_sppg), true);
  assert.equal(distribusiBisaDibuat(PROFIL.ahli_gizi), true);
  assert.equal(distribusiBisaDibuat(PROFIL.relawan_distribusi), true);
  assert.equal(distribusiBisaDibuat(PROFIL.akuntan), false, 'tanpa aksi.lihat_ringkasan_jadwal_produksi');
  const tanpaTitik = salin('kepala_sppg');
  delete tanpaTitik.permissions['titik-layanan'];
  tanpaTitik.modules = tanpaTitik.modules.filter((m) => m !== 'titik-layanan');
  assert.equal(distribusiBisaDibuat(tanpaTitik), false, 'tanpa hak baca titik layanan form tak dapat diselesaikan');
  const tanpaRingkas = salin('kepala_sppg');
  tanpaRingkas.aksi.lihat_ringkasan_jadwal_produksi = false;
  assert.equal(distribusiBisaDibuat(tanpaRingkas), false);
  assert.equal(akses('relawan_distribusi', '/jadwal/distribusi/baru'), 'boleh');
  setUserSesi(tanpaTitik);
  assert.equal(cekAkses('/jadwal/distribusi/baru'), 'ditolak');
  setUserSesi(tanpaRingkas);
  assert.equal(cekAkses('/jadwal/distribusi/baru'), 'ditolak');
});

test('#3 dropdown batch Keluhan: /jadwal/produksi-ringkas?from=&to= bagi yang berhak, disembunyikan bagi lainnya', () => {
  const f = (u) => keluhanFields({ mode: 'create', user: u }).find((x) => x.name === 'jadwal_produksi_id');
  for (const n of ['admin', 'relawan_dapur', 'relawan_distribusi']) {
    const kolom = f(PROFIL[n]);
    assert.equal(kolom?.relation.endpoint, '/jadwal/produksi-ringkas', n);
    assert.ok(kolom.relation.query.from && kolom.relation.query.to, n);
    assert.equal(kolom.relation.format({ id: 1, tanggal_produksi: '2026-11-02', nama_menu: 'Bubur' }), '2026-11-02 · Bubur');
  }
  assert.equal(f(PROFIL.relawan_tanpa_unit), undefined, 'tanpa hak ringkasan: dropdown disembunyikan');
  // Keluhan kritis tetap dapat diselesaikan tanpa dropdown (Batch tidak diketahui).
  assert.equal(validasiBatch({ kategori: 'makanan_basi', batch_tidak_diketahui: true }), '');
  assert.match(validasiBatch({ kategori: 'makanan_basi' }), /wajib dikaitkan/);
});

test('keluhan: sunting penuh dari hak modul (bukan nama peran); final tak dapat ditindaklanjuti', () => {
  for (const nama of SEMUA) assert.equal(suntingPenuh(PROFIL[nama]), nama === 'admin', nama);
  const p = userPerms(PROFIL.kepala_sppg, 'keluhan');
  assert.equal(rowPermsKeluhan(PROFIL.kepala_sppg, { status: 'selesai' }, p).U, false);
  assert.equal(rowPermsKeluhan(PROFIL.kepala_sppg, { status: 'baru' }, p).U, true);
  assert.equal(rowPermsKeluhan(PROFIL.admin, { status: 'selesai' }, userPerms(PROFIL.admin, 'keluhan')).U, true);
});

test('pelingkupan SPPG: kolom SPPG hanya untuk pengguna tanpa SPPG sendiri', () => {
  assert.equal(tanpaSppgSendiri(PROFIL.admin), true);
  for (const n of ['kepala_sppg', 'ahli_gizi', 'akuntan', 'staf_gudang', 'relawan_dapur', 'relawan_distribusi', 'investor']) assert.equal(tanpaSppgSendiri(PROFIL[n]), false, n);
  assert.equal(sppgField(PROFIL.admin, 'create').length, 1);
  assert.equal(sppgField(PROFIL.kepala_sppg, 'create').length, 0);
  assert.equal(sppgField(PROFIL.admin, 'update').length, 0);
  assert.deepEqual(queryAgregat(PROFIL.admin, 5), { sppg_id: 5 });
  assert.equal(queryAgregat(PROFIL.investor, 5), undefined);
});

// ---------------------------------------------------------------------------------------------
test('#5a draf undangan (pengguna + _resource=undangan) menuju form undangan, bukan /pengguna/baru', () => {
  const d = { module: 'pengguna', action: 'create', target_id: null, payload: { role: 'staf_gudang', sppg_id: 3, _resource: 'undangan' } };
  assert.equal(tujuanDraf(d), '/pengguna/undangan/baru');
  assert.equal(tujuanDraf({ ...d, payload: { role: 'admin', _resource: 'undangan' } }), '/pengguna/undangan/baru');
  assert.equal(tujuanDraf({ module: 'pengguna', action: 'create', payload: { name: 'X' } }), '/pengguna/baru', 'tanpa penanda: pola standar');
});

test('#5a prefill form undangan dari payload draf', () => {
  assert.deepEqual(isianUndanganDariDraf({ role: 'staf_gudang', sppg_id: 3, _resource: 'undangan' }), { mode: 'baru', role: 'staf_gudang', sppg_id: 3 });
  assert.deepEqual(isianUndanganDariDraf({ role: 'relawan', unit: 'dapur', sppg_id: 3, _resource: 'undangan' }), { mode: 'baru', role: 'relawan', unit: 'dapur', sppg_id: 3 });
  assert.deepEqual(isianUndanganDariDraf({ role: 'investor', _resource: 'undangan' }), { mode: 'baru', role: 'investor' });
  assert.deepEqual(isianUndanganDariDraf({ user_id: 8 }), { mode: 'akun', user_id: 8 });
  // Nilai tak dikenal tidak masuk form; penanda "_" dibuang.
  assert.deepEqual(isianUndanganDariDraf({ role: 'raja', unit: 'langit', _resource: 'undangan', _peringatan: 'x' }), { mode: 'baru' });
  assert.deepEqual(isianUndanganDariDraf(null), { mode: 'baru' });
});

test('#5b draf hapus tidak pernah dipetakan ke form /baru', () => {
  assert.equal(tujuanDraf({ module: 'pengguna', action: 'delete', target_id: 12, payload: {} }), '/pengguna/12');
  assert.equal(tujuanDraf({ module: 'pengguna', action: 'delete', target_id: 4, payload: { _resource: 'undangan' } }), '/pengguna/undangan');
  assert.equal(tujuanDraf({ module: 'titik-layanan', action: 'delete', target_id: 9, payload: {} }), '/titik-layanan/9');
  assert.equal(tujuanDraf({ module: 'keuangan', action: 'delete', target_id: 5, payload: { uraian: 'Gas' } }), '/keuangan/pengeluaran/5');
  assert.equal(tujuanDraf({ module: 'pengguna', action: 'delete', target_id: null, payload: {} }), '/pengguna', 'tanpa target: daftar, bukan form baru');
  for (const d of [
    { module: 'pengguna', action: 'delete', target_id: 12 }, { module: 'bahan-baku', action: 'delete', target_id: 1 }, { module: 'pengguna', action: 'archive', target_id: 1 },
    { module: 'pengguna', action: 'update', target_id: null, payload: {} },
  ]) assert.ok(!tujuanDraf(d).endsWith('/baru'), JSON.stringify(d));
});

test('#5c draf ubah peran/status menuju /pengguna/:id/ubah dengan prefill', () => {
  assert.equal(tujuanDraf({ module: 'pengguna', action: 'update', target_id: 12, payload: { role: 'ahli_gizi', sppg_id: 3 } }), '/pengguna/12/ubah');
  assert.equal(tujuanDraf({ module: 'pengguna', action: 'update', target_id: 12, payload: { is_active: false } }), '/pengguna/12/ubah');
  assert.deepEqual(isianPenggunaDariDraf({ id: 12, role: 'ahli_gizi', sppg_id: 3 }), { role: 'ahli_gizi', unit: '', sppg_id: 3 });
  assert.deepEqual(isianPenggunaDariDraf({ role: 'relawan', unit: 'distribusi', sppg_id: 3 }), { role: 'relawan', unit: 'distribusi', sppg_id: 3 });
  assert.deepEqual(isianPenggunaDariDraf({ is_active: false }), { is_active: 'false' });
  assert.deepEqual(isianPenggunaDariDraf({ is_active: true }), { is_active: 'true' });
  assert.deepEqual(isianPenggunaDariDraf({ role: 'dewa', unit: 'x', is_active: 'false', _resource: 'x' }), {}, 'nilai tak dikenal dibuang');
  assert.equal(akses('admin', '/pengguna/12/ubah'), 'boleh');
  assert.equal(akses('kepala_sppg', '/pengguna/12/ubah'), 'ditolak');
});

test('tujuanDraf: perilaku lama tetap dan sub-modul bertanda _resource', () => {
  assert.equal(tujuanDraf({ module: 'pembelian-bahan', action: 'create', payload: {} }), '/pembelian-bahan/baru');
  assert.equal(tujuanDraf({ module: 'pembelian-bahan', action: 'update', target_id: 12, payload: { id: 12, versi: 2 } }), '/pembelian-bahan/12/ubah');
  assert.equal(tujuanDraf({ module: 'keuangan', action: 'create', payload: { sumber_dana: 'BGN' } }), '/keuangan/pencairan/baru');
  assert.equal(tujuanDraf({ module: 'keuangan', action: 'create', payload: { uraian: 'Gas' } }), '/keuangan/pengeluaran/baru');
  assert.equal(tujuanDraf({ module: 'jadwal', action: 'create', payload: { _resource: 'distribusi' } }), '/jadwal/distribusi/baru');
  assert.equal(tujuanDraf({ module: 'armada', action: 'create', payload: { _resource: 'pengemudi' } }), '/pengemudi/baru');
  assert.equal(tujuanDraf({ module: 'menu-gizi', action: 'update', target_id: 2, payload: { _resource: 'standar_gizi' } }), '/menu-gizi/standar-gizi/2/ubah');
  assert.equal(tujuanDraf({ module: '../x', action: 'create', payload: {} }), '/x/baru');
});

// ---------------------------------------------------------------------------------------------
test('investor multi-SPPG sebelum memilih SPPG aktif (sppg_aktif_id null) bukan "admin lintas SPPG"', () => {
  const investorBaru = { ...salin('investor'), sppg_id: null, sppg_aktif_id: null };
  assert.equal(tanpaSppgSendiri(investorBaru), false);
  assert.equal(tanpaSppgSendiri(PROFIL.investor), false, 'fixture tidak lagi memaksa sppg_aktif_id');
  assert.equal(PROFIL.investor.sppg_aktif_id, null);
  assert.equal(tanpaSppgSendiri(PROFIL.admin), true);
  assert.equal(sppgField(investorBaru, 'create').length, 0);
  assert.equal(queryAgregat(investorBaru, 3), undefined);
  // Layar keuangan (pilih SPPG) dan rekap harian (sppg_id wajib) memakai helper yang sama, bukan nama peran.
  for (const f of ['modules/keuangan/index.js', 'modules/rekap-harian/index.js']) {
    const src = readFileSync(join(dirname(fileURLToPath(import.meta.url)), '..', f), 'utf8');
    assert.match(src, /tanpaSppgSendiri\(/, f);
    assert.doesNotMatch(src, /role\s*===?\s*'admin'/, f);
  }
});

test('keluhan: tombol "Batch tidak diketahui" tetap ada dan aturan batch kritis berlaku tanpa dropdown jadwal', () => {
  const nama = (u) => keluhanFormFields({ mode: 'create', user: u }).map((f) => f.name);
  // Dengan dropdown: tombol tepat setelah jadwal.
  const dengan = nama(PROFIL.relawan_dapur);
  assert.equal(dengan[dengan.indexOf('jadwal_produksi_id') + 1], 'batch_tidak_diketahui');
  // Tanpa dropdown (relawan tanpa unit): tidak di paling atas, sebelum deskripsi.
  const tanpa = nama(PROFIL.relawan_tanpa_unit);
  assert.ok(!tanpa.includes('jadwal_produksi_id'));
  assert.ok(tanpa.indexOf('batch_tidak_diketahui') > 0);
  assert.ok(tanpa.indexOf('batch_tidak_diketahui') < tanpa.indexOf('deskripsi'));
  const tombol = keluhanFormFields({ mode: 'create', user: PROFIL.relawan_tanpa_unit }).find((f) => f.name === 'batch_tidak_diketahui');
  assert.match(tombol.validate(null, { kategori: 'makanan_basi' }), /wajib dikaitkan/);
  assert.equal(tombol.validate(null, { kategori: 'makanan_basi', titik_layanan_id: '3' }), '');
  assert.equal(tombol.validate(null, { kategori: 'rasa' }), '');
});

test('prefill jadwal produksi di luar jendela daftar tetap menjadi opsi', () => {
  const f = distribusiFields({ mode: 'create', user: PROFIL.kepala_sppg }).find((x) => x.name === 'jadwal_produksi_id');
  assert.equal(typeof f.relation.opsiNilaiAwal, 'function');
  assert.match(f.relation.opsiNilaiAwal('77'), /#77/);
});
