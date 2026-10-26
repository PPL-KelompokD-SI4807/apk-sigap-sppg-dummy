import test from 'node:test';
import assert from 'node:assert/strict';
import { aturanPassword, passwordValid, sisaDetik, formatMenit, durasiRamah, detikTerkunci, kodeValid } from '../core/kredensial.js';

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
