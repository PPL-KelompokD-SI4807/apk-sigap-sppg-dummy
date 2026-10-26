import test from 'node:test';
import assert from 'node:assert/strict';
import { aturanPassword, passwordValid, sisaDetik, formatMenit, durasiRamah, detikTerkunci, kodeValid, infoStatusReset, bisaAjukanReset, isTerkunci, pesanGalatAkun } from '../core/kredensial.js';

test('aturan password', () => {
  assert.equal(passwordValid('Abcdefg1'), true);
  assert.equal(passwordValid('abcdefg1'), false);
  assert.equal(passwordValid('ABCDEFG1'), false);
  assert.equal(passwordValid('Abcdefgh'), false);
  assert.equal(passwordValid('Ab1'), false);
  assert.equal(passwordValid('Budi1234', 'budi1234'), false);
  assert.equal(aturanPassword('x').length, 4);
});

test('waktu', () => {
  const now = Date.parse('2026-10-26T01:00:00Z');
  assert.equal(sisaDetik('2026-10-26T01:05:00Z', now), 300);
  assert.equal(sisaDetik('2026-10-26T00:00:00Z', now), 0);
  assert.equal(sisaDetik('bukan', now), 0);
  assert.equal(formatMenit(125), '02:05');
  assert.equal(durasiRamah(900), '15 menit');
  assert.equal(durasiRamah(45), '45 detik');
  assert.equal(detikTerkunci({ terkunci_sampai: '2026-10-26T01:15:00Z' }, now), 900);
  assert.equal(detikTerkunci({ retry_after: 30 }, now), 30);
});

test('kode 6 digit', () => {
  assert.equal(kodeValid('123456'), true);
  assert.equal(kodeValid('12345'), false);
  assert.equal(kodeValid('12a456'), false);
});

test('status permintaan reset', () => {
  for (const st of ['menunggu', 'disetujui', 'ditolak', 'kedaluwarsa', 'selesai']) assert.ok(infoStatusReset(st).label);
  assert.equal(infoStatusReset('aneh').label, 'aneh');
  assert.equal(bisaAjukanReset(null), true);
  assert.equal(bisaAjukanReset({ status: 'ditolak' }), true);
  assert.equal(bisaAjukanReset({ status: 'kedaluwarsa' }), true);
  assert.equal(bisaAjukanReset({ status: 'menunggu' }), false);
  assert.equal(bisaAjukanReset({ status: 'disetujui' }), false);
});

test('galat akun 401/403/423/429', () => {
  assert.equal(isTerkunci({ status: 423 }), true);
  assert.equal(isTerkunci({ status: 429, data: { alasan: 'terkunci' } }), true);
  assert.equal(isTerkunci({ status: 429, data: { alasan: 'terlalu_sering' } }), false);
  assert.match(pesanGalatAkun({ status: 423, data: { retry_after: 900 } }), /terkunci.*15 menit/);
  assert.match(pesanGalatAkun({ status: 401 }), /Sesi berakhir/);
  assert.match(pesanGalatAkun({ status: 403 }), /hak akses/);
  assert.match(pesanGalatAkun({ status: 0 }), /koneksi/);
  assert.equal(pesanGalatAkun({ status: 422 }), null);
});
