// Tes temuan uji Ahli Gizi: tombol hapus mutu per peran, gating riwayat mutasi stok.
import test from 'node:test';
import assert from 'node:assert/strict';
import { userPerms, permsDariIzin, aksiOpsional } from '../core/permissions.js';
import { PROFIL, salin } from './fixtures/peta.js';
import { rowPermsMutu } from '../modules/mutu/schema.js';
import { bolehRiwayatMutasi } from '../modules/bahan-baku/schema.js';

// ---- mutu: tombol Hapus per peran ----
const hapusMutu = (nama, rec) => rowPermsMutu(rec, userPerms(PROFIL[nama], 'mutu')).D;

test('mutu: Ahli Gizi melihat Hapus bila pemeriksaan belum memengaruhi distribusi', () => {
  assert.equal(hapusMutu('ahli_gizi', { memengaruhi_distribusi: false }), true);
  assert.equal(hapusMutu('ahli_gizi', {}), true, 'flag tidak ada = tidak diblokir klien; server tetap menolak 422 bila perlu');
});

test('mutu: Hapus disembunyikan bila memengaruhi distribusi (aturan server)', () => {
  assert.equal(hapusMutu('ahli_gizi', { memengaruhi_distribusi: true }), false);
});

test('mutu: peran tanpa D tidak pernah melihat Hapus', () => {
  for (const nama of ['admin', 'kepala_sppg', 'investor', 'akuntan', 'staf_gudang']) {
    assert.equal(hapusMutu(nama, { memengaruhi_distribusi: false }), false, nama);
  }
});

test('mutu: modul tanpa izin per record tidak membaca izin.hapus (D murni dari peta + keadaan)', () => {
  const p = userPerms(PROFIL.ahli_gizi, 'mutu');
  assert.equal(permsDariIzin(p, { izin: { hapus: false } }).D, false, 'kontrol: resource berizin tetap patuh');
  assert.equal(rowPermsMutu({ izin: { hapus: false }, memengaruhi_distribusi: false }, p).D, true);
});

// ---- mutu: daftar (baris) ----
test('mutu: baris daftar dengan memengaruhi_distribusi true tidak punya Hapus; false/tak ada tetap punya', () => {
  const p = userPerms(PROFIL.ahli_gizi, 'mutu');
  const baris = [{ id: 1, memengaruhi_distribusi: true }, { id: 2, memengaruhi_distribusi: false }, { id: 3 }];
  assert.deepEqual(baris.map((r) => rowPermsMutu(r, p).D), [false, true, true]);
});

// ---- riwayat mutasi: aksi.lihat_mutasi_stok (fail closed) ----
const K = 'lihat_mutasi_stok';
test('riwayat mutasi: flag false -> disembunyikan', () => {
  const u = salin('ahli_gizi');
  u.aksi[K] = false;
  assert.equal(bolehRiwayatMutasi(u, aksiOpsional), false);
});

test('riwayat mutasi: flag tidak ada -> disembunyikan (fail closed)', () => {
  const u = salin('staf_gudang');
  delete u.aksi[K];
  assert.equal(aksiOpsional(u, K), null);
  assert.equal(bolehRiwayatMutasi(u, aksiOpsional), false);
});

test('riwayat mutasi: hanya boolean true yang menampilkan', () => {
  // petaHak men-cache per objek: gunakan salinan baru untuk tiap nilai.
  for (const v of ['true', 1, 'false', null, {}]) { const x = salin('staf_gudang'); x.aksi[K] = v; assert.equal(bolehRiwayatMutasi(x, aksiOpsional), false, String(v)); }
  const u = salin('staf_gudang');
  u.aksi[K] = true;
  assert.equal(bolehRiwayatMutasi(u, aksiOpsional), true);
});

test('riwayat mutasi: peta tidak valid / pengguna kosong -> disembunyikan', () => {
  assert.equal(bolehRiwayatMutasi(null, aksiOpsional), false);
  assert.equal(bolehRiwayatMutasi({}, aksiOpsional), false);
});

// Snapshot server: berlaku setelah backend menambahkan kunci; sebelum itu dilewati (kunci tak ada = disembunyikan).
test('riwayat mutasi: snapshot server per peran (Admin/Kepala/Staf Gudang/Akuntan boleh; Ahli Gizi/Investor tidak)', () => {
  assert.ok(Object.values(PROFIL).every((u) => typeof u.aksi?.[K] === 'boolean'), 'snapshot memuat aksi.lihat_mutasi_stok');
  const boleh = { admin: true, kepala_sppg: true, staf_gudang: true, akuntan: true, ahli_gizi: false, investor: false };
  for (const [nama, harap] of Object.entries(boleh)) assert.equal(bolehRiwayatMutasi(PROFIL[nama], aksiOpsional), harap, nama);
});

// ---- batch: teks "dari …" hanya bila jumlah_awal ada ----
import { batchDariText } from '../modules/bahan-baku/schema.js';

test('batch: "dari" tampil bila jumlah_awal ada, hilang bila dibuang server', () => {
  assert.equal(batchDariText({ jumlah_awal: 10 }), 'dari 10');
  assert.equal(batchDariText({ jumlah_awal: 0 }), 'dari 0');
  assert.equal(batchDariText({ sisa: 5 }), null);
  assert.equal(batchDariText({ jumlah_awal: null }), null);
});
