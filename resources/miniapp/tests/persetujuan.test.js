// Tes unit R-06b-1 (persetujuan menu): node --test resources/miniapp/tests/
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  bolehAjukan, bolehMemutuskan, tombolKeputusanTampil, validasiCatatan, payloadKeputusan, payloadAjukan,
  filterMenuDisetujui, labelPilihanMenu, parseSppgHint, ringkasGalat, bahanBermasalah,
} from '../modules/persetujuan-menu/logika.js';
import { userPerms, perms } from '../core/permissions.js';
import { perluGramPerSatuan, validasiHarga, hargaPayload, kelompokHarga, JENIS_PEMASOK } from '../modules/pemasok/schema.js';

const investor = { role: 'investor', keputusan: ['persetujuan-menu'] };
const ahliGizi = { role: 'ahli_gizi', keputusan: [] };

test('tombol Ajukan hanya untuk hak C (ahli gizi) pada status draf/ditolak/perlu_revisi', () => {
  const p = userPerms(ahliGizi, 'persetujuan-menu');
  for (const s of ['draf', 'ditolak', 'perlu_revisi']) assert.equal(bolehAjukan(p, s), true, s);
  for (const s of ['diajukan', 'disetujui']) assert.equal(bolehAjukan(p, s), false, s);
  for (const role of ['admin', 'kepala_sppg', 'investor']) assert.equal(bolehAjukan(userPerms({ role }, 'persetujuan-menu'), 'draf'), false, role);
});

test('tombol keputusan hanya untuk pemegang keputusan pada status diajukan', () => {
  assert.equal(bolehMemutuskan(investor), true);
  assert.equal(bolehMemutuskan({ role: 'admin', keputusan: [] }), false);
  assert.equal(bolehMemutuskan({ role: 'investor' }), false);
  assert.equal(tombolKeputusanTampil(investor, { status: 'diajukan' }), true);
  assert.equal(tombolKeputusanTampil(investor, { status: 'disetujui' }), false);
  assert.equal(tombolKeputusanTampil(investor, { status: 'diajukan', kedaluwarsa: true }), false);
  assert.equal(tombolKeputusanTampil(ahliGizi, { status: 'diajukan' }), false);
});

test('investor tetap baca-saja pada menu-gizi/pemasok/jadwal, boleh baca persetujuan-menu', () => {
  for (const slug of ['menu-gizi', 'pemasok', 'jadwal']) assert.equal(userPerms(investor, slug).R, false, slug);
  const p = perms('investor', 'persetujuan-menu');
  assert.deepEqual([p.R, p.C, p.U, p.D], [true, false, false, false]);
});

test('catatan wajib (min 5) untuk tolak dan perlu_revisi, opsional untuk setujui', () => {
  assert.equal(validasiCatatan('disetujui', ''), '');
  assert.notEqual(validasiCatatan('ditolak', ''), '');
  assert.notEqual(validasiCatatan('perlu_revisi', '  abc '), '');
  assert.equal(validasiCatatan('perlu_revisi', 'Turunkan porsi ayam.'), '');
  assert.notEqual(validasiCatatan('disetujui', 'x'.repeat(1001)), '');
});

test('payload keputusan dan ajukan tidak memuat harga/biaya/porsi', () => {
  const k = payloadKeputusan({ keputusan: 'ditolak', catatan: ' Terlalu mahal ', versiMenu: '2', harga: 1, biaya_per_porsi: 2, jumlah_porsi: 3 });
  assert.deepEqual(k, { keputusan: 'ditolak', catatan: 'Terlalu mahal', versi_menu: 2 });
  assert.deepEqual(payloadKeputusan({ keputusan: 'disetujui', catatan: '', versiMenu: 1 }), { keputusan: 'disetujui', versi_menu: 1 });
  const a = payloadAjukan({ versi: 3, catatan: 'Mohon ditinjau.', biaya_per_porsi: 9, jumlah_porsi: 9, total_biaya: 9 });
  assert.deepEqual(a, { versi: 3, catatan_pengaju: 'Mohon ditinjau.' });
  assert.deepEqual(payloadAjukan({ versi: 3 }), { versi: 3 });
  for (const o of [k, a]) assert.ok(!Object.keys(o).some((x) => /harga|biaya|porsi/.test(x)));
});

test('pilihan menu Jadwal hanya menu disetujui', () => {
  const m = [{ id: 1, nama_menu: 'A', status_persetujuan: 'disetujui' }, { id: 2, nama_menu: 'B', status_persetujuan: 'draf' }, { id: 3, nama_menu: 'C' }];
  assert.deepEqual(filterMenuDisetujui(m).map((x) => x.id), [1]);
  assert.equal(labelPilihanMenu(m[0]), 'A');
  assert.match(labelPilihanMenu(m[1]), /belum dapat dipilih/);
});

test('deep link: petunjuk sppg dari query atau hash, tidak valid -> null', () => {
  assert.equal(parseSppgHint('?sppg=3', ''), 3);
  assert.equal(parseSppgHint('', '#/persetujuan-menu/7?sppg=5'), 5);
  assert.equal(parseSppgHint('?sppg=abc', ''), null);
  assert.equal(parseSppgHint('?sppg=0', '#/'), null);
  assert.equal(parseSppgHint('', '#tgWebAppData=x'), null);
});

test('galat: 409 memuat ulang, 422 memetakan field dan bahan, 403 tidak berhak', () => {
  const e409 = ringkasGalat({ status: 409, message: 'Pengajuan ini sudah diputuskan.', data: { alasan: 'pengajuan_sudah_diputuskan' } });
  assert.equal(e409.muatUlang, true);
  assert.match(e409.pesan, /sudah diputuskan/);
  const e422 = ringkasGalat({ status: 422, errors: { bahan: ['Ada bahan tanpa harga.'] }, data: { bahan_bermasalah: [{ nama: 'Beras', masalah: 'tanpa harga' }] } });
  assert.equal(e422.fields.bahan, 'Ada bahan tanpa harga.');
  assert.deepEqual(e422.bahan, [{ nama: 'Beras', masalah: 'tanpa harga' }]);
  assert.equal(ringkasGalat({ status: 403 }).muatUlang, false);
  assert.deepEqual(bahanBermasalah({ bahan: [{ nama: 'A', masalah: null }, { nama: 'B', masalah: 'x' }] }).map((b) => b.nama), ['B']);
});

test('harga pemasok: koperasi tersedia, konversi satuan, validasi, payload, pengelompokan', () => {
  assert.ok(JENIS_PEMASOK.some((j) => j.value === 'koperasi'));
  assert.equal(perluGramPerSatuan('kg'), false);
  assert.equal(perluGramPerSatuan('Butir'), true);
  const hari = new Date().toISOString().slice(0, 10);
  assert.deepEqual(validasiHarga({ bahan_baku_id: '3', harga: '12500', berlaku_mulai: hari }, 'kg'), {});
  assert.ok(validasiHarga({ bahan_baku_id: '3', harga: '0', berlaku_mulai: hari }, 'kg').harga);
  assert.ok(validasiHarga({ bahan_baku_id: '3', harga: '10', berlaku_mulai: hari }, 'butir').gram_per_satuan);
  assert.ok(validasiHarga({ bahan_baku_id: '3', harga: '10', berlaku_mulai: '2000-01-01' }, 'kg').berlaku_mulai);
  assert.deepEqual(hargaPayload({ bahan_baku_id: '3', harga: '12500', gram_per_satuan: '', berlaku_mulai: hari, catatan: '' }), { bahan_baku_id: 3, harga: 12500, berlaku_mulai: hari });
  const g = kelompokHarga([
    { bahan_baku_id: 3, bahan_baku_nama: 'Beras', satuan: 'kg', harga: 13000, sudah_berlaku: false },
    { bahan_baku_id: 3, bahan_baku_nama: 'Beras', satuan: 'kg', harga: 12500, sudah_berlaku: true },
  ]);
  assert.equal(g.length, 1);
  assert.equal(g[0].terkini.harga, 12500);
});
