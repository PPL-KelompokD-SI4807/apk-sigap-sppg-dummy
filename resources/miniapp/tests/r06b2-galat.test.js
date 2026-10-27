// Tes unit R-06b-2 bagian 2: ringkasan, pemetaan galat, draf AI, jadwal.
import test from 'node:test';
import assert from 'node:assert/strict';
import { tujuanDraf } from '../modules/pembelian-bahan/logika.js';
import { teksMenunggu } from '../modules/keuangan/persetujuan.js';
import { ringkasGalatAksi, errorMessage } from '../core/errors.js';
import { menuBelumDisetujui, masakDitahanMenu, pesanGagalStatus } from '../modules/jadwal/schema.js';

test('ringkasan menunggu persetujuan', () => {
  assert.equal(teksMenunggu({ menunggu_persetujuan: { jumlah: 0, nominal: 0 } }), null);
  assert.equal(teksMenunggu({}), null);
  assert.match(teksMenunggu({ menunggu_persetujuan: { jumlah: 2, nominal: 1500000 } }), /2 pengeluaran \(Rp 1\.500\.000\)/);
});

test('pemetaan galat 403/404/409/422', () => {
  assert.match(ringkasGalatAksi({ status: 403 }).pesan, /tidak berhak/);
  assert.equal(ringkasGalatAksi({ status: 404 }).muatUlang, true);
  const v = ringkasGalatAksi({ status: 409, data: { alasan: 'versi_tidak_cocok', versi_server: 4 } });
  assert.equal(v.konflik, true);
  assert.equal(v.muatUlang, true);
  assert.match(v.pesan, /Muat ulang/);
  assert.match(ringkasGalatAksi({ status: 409, data: { alasan: 'status_tidak_valid' } }).pesan, /diputuskan/);
  assert.equal(ringkasGalatAksi({ status: 409, data: { alasan: 'pembelian_sudah_diverifikasi' } }).muatUlang, true);
  const pk = ringkasGalatAksi({ status: 409, data: { alasan: 'pengeluaran_dari_pembelian' } });
  assert.equal(pk.muatUlang, false);
  assert.match(pk.pesan, /pembelian bahan/);
  assert.equal(ringkasGalatAksi({ status: 409, data: { message: 'x' } }).konflik, false);
  const u = ringkasGalatAksi({ status: 422, errors: { alasan: ['Alasan wajib.'], nominal: ['Melebihi anggaran.'] } });
  assert.equal(u.fields.alasan, 'Alasan wajib.');
  assert.equal(u.pesan, 'Alasan wajib.');
  assert.match(errorMessage({ status: 409, data: { alasan: 'pengeluaran_dari_pembelian' } }), /tidak dapat diubah/);
});

test('draf AI: tujuan form', () => {
  assert.equal(tujuanDraf({ module: 'pembelian-bahan', action: 'create', payload: {} }), '/pembelian-bahan/baru');
  assert.equal(tujuanDraf({ module: 'pembelian-bahan', action: 'update', target_id: 12, payload: { id: 12, versi: 2 } }), '/pembelian-bahan/12/ubah');
  assert.equal(tujuanDraf({ module: 'keuangan', action: 'create', payload: { uraian: 'Gas', kategori: 'energi' } }), '/keuangan/pengeluaran/baru');
  assert.equal(tujuanDraf({ module: 'keuangan', action: 'update', target_id: 5, payload: { id: 5, versi: 1, nominal: 1 } }), '/keuangan/pengeluaran/5/ubah');
  assert.equal(tujuanDraf({ module: 'keuangan', action: 'create', payload: { sumber_dana: 'BGN' } }), '/keuangan/pencairan/baru');
  assert.equal(tujuanDraf({ module: 'titik-layanan', action: 'create', payload: {} }), '/titik-layanan/baru');
  assert.equal(tujuanDraf({ module: '../x', action: 'create', payload: {} }), '/x/baru');
});

test('jadwal: menu belum disetujui menahan mulai masak', () => {
  assert.equal(menuBelumDisetujui({ menu_belum_disetujui: true }), true);
  assert.equal(menuBelumDisetujui({ menu_belum_disetujui: false, menu: { status_persetujuan: 'draf' } }), false);
  assert.equal(menuBelumDisetujui({ menu: { status_persetujuan: 'ditolak' } }), true);
  assert.equal(masakDitahanMenu('produksi', { menu_belum_disetujui: true }, 'dimasak'), true);
  assert.equal(masakDitahanMenu('produksi', { menu_belum_disetujui: true }, 'dibatalkan'), false);
  assert.equal(masakDitahanMenu('distribusi', { menu_belum_disetujui: true }, 'dimasak'), false);
  assert.match(pesanGagalStatus({ status: 422, errors: { status: ['Menu jadwal ini belum disetujui investor; ajukan ulang menu sebelum memasak.'] } }), /belum disetujui investor/);
  assert.equal(pesanGagalStatus({ status: 500 }), null);
});
