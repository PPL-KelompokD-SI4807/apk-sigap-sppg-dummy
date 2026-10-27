// Tes unit R-06b-2: panel lampiran pengeluaran terkunci (disetujui / dari pembelian).
import test from 'node:test';
import assert from 'node:assert/strict';
import { pesanLampiranTerkunci, PESAN_NOTA_DISETUJUI, PESAN_NOTA_DARI_PEMBELIAN } from '../modules/keuangan/persetujuan.js';
import { errorMessage, ringkasGalatAksi } from '../core/errors.js';

test('lampiran terkunci untuk disetujui dan dari pembelian', () => {
  assert.equal(pesanLampiranTerkunci({ status_persetujuan: 'disetujui' }), PESAN_NOTA_DISETUJUI);
  assert.equal(pesanLampiranTerkunci({ status_persetujuan: 'diajukan', pembelian_bahan_id: 3 }), PESAN_NOTA_DARI_PEMBELIAN);
  assert.equal(pesanLampiranTerkunci({ status_persetujuan: 'disetujui', pembelian_bahan_id: 3 }), PESAN_NOTA_DARI_PEMBELIAN);
});

test('lampiran tetap terbuka untuk diajukan/ditolak non-pembelian', () => {
  assert.equal(pesanLampiranTerkunci({ status_persetujuan: 'diajukan', pembelian_bahan_id: null }), null);
  assert.equal(pesanLampiranTerkunci({ status_persetujuan: 'ditolak' }), null);
  assert.equal(pesanLampiranTerkunci(null), null);
});

test('galat 403/409 lampiran tetap ramah', () => {
  assert.match(errorMessage({ status: 403, data: { alasan: 'pengeluaran_dari_pembelian' } }), /pembelian bahan/);
  assert.equal(errorMessage({ status: 403 }), 'Anda tidak punya hak akses.');
  assert.match(errorMessage({ status: 409, data: { alasan: 'pengeluaran_sudah_disetujui' } }), /sudah disetujui/);
  assert.match(ringkasGalatAksi({ status: 403, data: { alasan: 'pengeluaran_dari_pembelian' } }).pesan, /pembelian bahan/);
});
