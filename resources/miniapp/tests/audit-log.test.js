import test from 'node:test';
import assert from 'node:assert/strict';
import { labelAksi, diffNilai, teksNilai, waktuSingkat, namaPengguna, bolehLogAudit, bangunQuery, pesanGalat } from '../modules/audit-log/logika.js';

test('label aksi Indonesia dan apa adanya', () => {
  assert.equal(labelAksi('created'), 'Dibuat');
  assert.equal(labelAksi('updated'), 'Diubah');
  assert.equal(labelAksi('deleted'), 'Dihapus');
  assert.equal(labelAksi('restored'), 'Dipulihkan');
  assert.equal(labelAksi('ai_eksekusi_draf'), 'ai_eksekusi_draf');
});
test('diff hanya kunci berubah, bersarang jadi JSON', () => {
  assert.deepEqual(diffNilai({ status: 'baru', a: 1 }, { status: 'diproses', a: 1 }), [{ kolom: 'status', lama: 'baru', baru: 'diproses' }]);
  assert.deepEqual(diffNilai(null, { x: { y: 1 } }), [{ kolom: 'x', lama: '-', baru: '{"y":1}' }]);
  assert.deepEqual(diffNilai({ x: 1 }, null), [{ kolom: 'x', lama: '1', baru: '-' }]);
  assert.deepEqual(diffNilai(null, null), []);
});
test('teksNilai tidak menafsirkan HTML', () => {
  assert.equal(teksNilai('<img onerror=x>'), '<img onerror=x>');
  assert.equal(teksNilai(null), '-');
});
test('waktu, pengguna, hak, query, galat', () => {
  assert.equal(waktuSingkat('2026-11-10T08:00:00+07:00'), '10/11 08:00');
  assert.equal(waktuSingkat(null), '-');
  assert.equal(namaPengguna({ pengguna: null }), 'Sistem');
  assert.equal(namaPengguna({ pengguna: { nama: 'Budi' } }), 'Budi');
  assert.equal(bolehLogAudit({ role: 'admin' }), true);
  assert.equal(bolehLogAudit({ role: 'akuntan' }), false);
  assert.deepEqual(bangunQuery({ modul: 'keluhan', aksi: '', user_id: ' 3 ' }, 2), { page: 2, per_page: 25, modul: 'keluhan', user_id: '3' });
  assert.equal(pesanGalat({ status: 403 }), 'Tidak punya akses');
});
