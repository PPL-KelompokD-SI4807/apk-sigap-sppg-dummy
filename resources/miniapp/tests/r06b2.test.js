// Tes unit R-06b-2 (pembelian bahan, persetujuan pengeluaran, jadwal): node --test resources/miniapp/tests/
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  bolehUbahPembelian, tombolVerifikasiTampil, validasiJumlah, validasiHargaSatuan, validasiTanggalBeli, validasiNota, hitungTotal,
  payloadPembelian, validasiAlasan, payloadVerifikasi, ringkasHargaReferensi, statusLabel, statusBadge,
} from '../modules/pembelian-bahan/logika.js';
import {
  tombolKeputusanPengeluaranTampil, validasiCatatanKeputusan, payloadKeputusanPengeluaran, rowPermsPengeluaran, teksMenunggu, statusPengeluaran,
} from '../modules/keuangan/persetujuan.js';

import { userPerms } from '../core/permissions.js';


test('hak pembelian-bahan per peran', () => {
  assert.deepEqual(['C', 'R', 'U'].map((k) => userPerms({ role: 'staf_gudang' }, 'pembelian-bahan')[k]), [true, true, true]);
  for (const role of ['admin', 'kepala_sppg', 'akuntan']) {
    const p = userPerms({ role }, 'pembelian-bahan');
    assert.deepEqual([p.R, p.C, p.U], [true, false, false], role);
  }
  for (const role of ['ahli_gizi', 'relawan', 'investor']) assert.equal(userPerms({ role }, 'pembelian-bahan').R, false, role);
});

test('ubah pembelian hanya saat diajukan/ditolak', () => {
  const p = { U: true };
  assert.equal(bolehUbahPembelian(p, { status: 'diajukan' }), true);
  assert.equal(bolehUbahPembelian(p, { status: 'ditolak' }), true);
  assert.equal(bolehUbahPembelian(p, { status: 'terverifikasi' }), false);
  assert.equal(bolehUbahPembelian({ U: false }, { status: 'diajukan' }), false);
  assert.equal(statusLabel('diajukan'), 'Menunggu verifikasi');
  assert.equal(statusBadge('terverifikasi'), 'badge-ok');
});

test('tombol verifikasi: akuntan, status diajukan, bukan pencatat', () => {
  const akuntan = { id: 5, role: 'akuntan', keputusan: ['pembelian-bahan'] };
  assert.equal(tombolVerifikasiTampil(akuntan, { status: 'diajukan', dicatat_oleh: { id: 9 } }), true);
  assert.equal(tombolVerifikasiTampil(akuntan, { status: 'diajukan', dicatat_oleh: { id: 5 } }), false);
  assert.equal(tombolVerifikasiTampil(akuntan, { status: 'terverifikasi', dicatat_oleh: { id: 9 } }), false);
  assert.equal(tombolVerifikasiTampil({ id: 1, role: 'staf_gudang', keputusan: [] }, { status: 'diajukan', dicatat_oleh: { id: 9 } }), false);
});

test('validasi angka pembelian dan total tanpa float', () => {
  assert.equal(validasiJumlah(50), '');
  assert.match(validasiJumlah(0), /lebih dari 0/);
  assert.match(validasiJumlah(1.2345), /3 angka/);
  assert.equal(validasiHargaSatuan(12500.5), '');
  assert.match(validasiHargaSatuan(1.234), /2 angka/);
  assert.match(validasiHargaSatuan(-1), /lebih dari 0/);
  assert.equal(hitungTotal(50, 12500), 625000);
  assert.equal(hitungTotal(0.333, 100), 33);
  assert.equal(hitungTotal(1.5, 333.33), 500);
  assert.equal(hitungTotal(0, 100), null);
  assert.match(validasiTanggalBeli('2999-01-01'), /masa depan/);
  assert.equal(validasiTanggalBeli('2020-01-01'), '');
});

test('nota wajib 1..3', () => {
  assert.match(validasiNota({ mode: 'create', jumlahBaru: 0, jumlahTersimpan: 0 }), /wajib/);
  assert.equal(validasiNota({ mode: 'update', jumlahBaru: 0, jumlahTersimpan: 1 }), '');
  assert.match(validasiNota({ mode: 'update', jumlahBaru: 3, jumlahTersimpan: 1 }), /maksimal 3/);
});

test('payload pembelian tidak memuat total/status/satuan', () => {
  const o = payloadPembelian({ bahan_baku_id: '3', pemasok_id: '7', tanggal_beli: '2026-10-27', jumlah: 50, harga_satuan: 12500, catatan: '', total: 1, status: 'x', satuan: 'kg', lampiran_ids: [41, 41, 42] });
  assert.deepEqual(o, { bahan_baku_id: 3, pemasok_id: 7, tanggal_beli: '2026-10-27', jumlah: 50, harga_satuan: 12500, catatan: null, lampiran_ids: [41, 42] });
});

test('verifikasi: alasan wajib saat tolak, payload', () => {
  assert.match(validasiAlasan('ditolak', 'abc'), /minimal 5/);
  assert.equal(validasiAlasan('ditolak', 'Harga tidak wajar'), '');
  assert.equal(validasiAlasan('disetujui', ''), '');
  assert.match(validasiAlasan('ditolak', 'x'.repeat(501)), /maksimal 500/);
  assert.deepEqual(payloadVerifikasi({ keputusan: 'ditolak', alasan: ' Harga tinggi ', versi: '2' }), { keputusan: 'ditolak', alasan: 'Harga tinggi', versi: 2 });
  assert.deepEqual(payloadVerifikasi({ keputusan: 'disetujui', alasan: 'abaikan', versi: 1 }), { keputusan: 'disetujui', versi: 1 });
});

test('harga referensi dan selisih', () => {
  assert.equal(ringkasHargaReferensi(null).ada, false);
  assert.match(ringkasHargaReferensi({ harga: 10000, selisih_persen: 25 }).teks, /25% lebih mahal/);
  assert.equal(ringkasHargaReferensi({ harga: 10000, selisih_persen: 25 }).tone, 'badge-warn');
  assert.match(ringkasHargaReferensi({ harga: 10000, selisih_persen: -12.5 }).teks, /12,5% lebih murah/);
  assert.match(ringkasHargaReferensi({ harga: 10000, selisih_persen: 0 }).teks, /Sama/);
  assert.match(ringkasHargaReferensi({ harga: 10000, selisih_persen: null }).teks, /tidak dapat/);
});

test('keputusan investor atas pengeluaran', () => {
  const inv = { role: 'investor', keputusan: ['persetujuan-menu', 'keuangan'] };
  assert.equal(tombolKeputusanPengeluaranTampil(inv, { status_persetujuan: 'diajukan' }), true);
  assert.equal(tombolKeputusanPengeluaranTampil(inv, { status_persetujuan: 'disetujui' }), false);
  assert.equal(tombolKeputusanPengeluaranTampil({ role: 'akuntan', keputusan: ['pembelian-bahan'] }, { status_persetujuan: 'diajukan' }), false);
  assert.match(validasiCatatanKeputusan('ditolak', ' '), /wajib/);
  assert.equal(validasiCatatanKeputusan('disetujui', ''), '');
  assert.deepEqual(payloadKeputusanPengeluaran({ keputusan: 'ditolak', catatan: ' Tunda dulu ', versi: 3 }), { keputusan: 'ditolak', catatan: 'Tunda dulu', versi: 3 });
  assert.deepEqual(payloadKeputusanPengeluaran({ keputusan: 'disetujui', catatan: '', versi: 1 }), { keputusan: 'disetujui', versi: 1 });
  assert.equal(statusPengeluaran({}), 'disetujui');
  assert.deepEqual(rowPermsPengeluaran({ pembelian_bahan_id: 4 }, { U: true, D: true, R: true }), { U: false, D: false, R: true });
  assert.equal(rowPermsPengeluaran({ pembelian_bahan_id: null }, { U: true }).U, true);
});
