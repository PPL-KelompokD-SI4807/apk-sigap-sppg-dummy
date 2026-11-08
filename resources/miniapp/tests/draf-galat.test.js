// Galat draf Agen AI -> kolom/baris formulir (core/draf-galat.js). Murni, tanpa DOM.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { perluDiperbaiki, petakanGalatDraf, pilihanPerBaris, labelKandidat, ringkasGalatBaris } from '../core/draf-galat.js';

test('perluDiperbaiki: flag server menang, fallback errors tidak kosong', () => {
  assert.equal(perluDiperbaiki({ perlu_diperbaiki: true, errors: {} }), true);
  assert.equal(perluDiperbaiki({ perlu_diperbaiki: false, errors: { a: ['x'] } }), false);
  assert.equal(perluDiperbaiki({ errors: { a: ['x'] } }), true);
  assert.equal(perluDiperbaiki({ errors: {} }), false);
  assert.equal(perluDiperbaiki({}), false);
  assert.equal(perluDiperbaiki(null), false);
});

test('petakanGalatDraf: kunci bertitik -> baris, kolom datar, sisanya ke daftar', () => {
  const p = petakanGalatDraf({
    nama_menu: ['Nama wajib diisi.'],
    'bahan.0.bahan_baku_id': ['Bahan tidak ditemukan.'],
    'bahan.1.gram_per_porsi': ['Harus angka.'],
    'bahan.1.bahan_baku_id': ['Satuan tidak cocok.'],
    bahan: ['Minimal satu bahan.'],
    tidak_ada_di_form: ['Galat lain.'],
    'bahan_lain.0.x': ['Galat baris tak dikenal.'],
  }, ['nama_menu', 'bahan']);
  assert.deepEqual(p.kolom, { nama_menu: 'Nama wajib diisi.', bahan: 'Minimal satu bahan.' });
  assert.deepEqual(p.baris.bahan, { 0: { bahan_baku_id: 'Bahan tidak ditemukan.' }, 1: { gram_per_porsi: 'Harus angka.', bahan_baku_id: 'Satuan tidak cocok.' } });
  assert.deepEqual(p.lainnya, ['Galat lain.', 'Galat baris tak dikenal.']);
});

test('petakanGalatDraf: pesan string, kosong, dan errors tidak valid ditangani', () => {
  assert.deepEqual(petakanGalatDraf({ a: 'teks' }, ['a']).kolom, { a: 'teks' });
  assert.deepEqual(petakanGalatDraf({ a: [] }, ['a']), { kolom: {}, baris: {}, lainnya: [] });
  assert.deepEqual(petakanGalatDraf(null, ['a']), { kolom: {}, baris: {}, lainnya: [] });
});

test('ringkasGalatBaris menggabungkan pesan baris', () => {
  assert.equal(ringkasGalatBaris({ a: 'Satu.', b: 'Dua.' }), 'Satu. Dua.');
  assert.equal(ringkasGalatBaris(undefined), '');
});

test('pilihanPerBaris: kandidat untuk baris komposisi, kolom lain/kosong diabaikan', () => {
  const pil = [
    { kolom: 'bahan', indeks: 1, jenis: 'bahan_baku', dicari: 'ayam', jumlah: '10', satuan: 'gram', kandidat: [{ id: 3, nama: 'Ayam', satuan: 'kg' }, { id: 4, nama: 'Ayam fillet', satuan: 'kg' }], lebih_banyak: false },
    { kolom: 'pemasok', indeks: 0, kandidat: [{ id: 9, nama: 'X' }] },
    { kolom: 'bahan', indeks: 2, kandidat: [] },
  ];
  const out = pilihanPerBaris(pil, 'bahan');
  assert.deepEqual(Object.keys(out), ['1']);
  assert.equal(out[1].dicari, 'ayam');
  assert.equal(out[1].kandidat.map(labelKandidat).join(', '), 'Ayam (kg), Ayam fillet (kg)');
  assert.deepEqual(pilihanPerBaris(undefined, 'bahan'), {});
});

test('tidak ada simpan otomatis: save() hanya dipanggil dari onSubmit pengguna', () => {
  const src = readFileSync(new URL('../components/crud-module.js', import.meta.url), 'utf8');
  const panggilan = src.match(/await save\(\{/g) || [];
  assert.equal(panggilan.length, 1);
  assert.match(src, /onSubmit: async \(v\) => \{[\s\S]*?await save\(\{/);
  // terapkanDraf hanya menandai galat; bukan jalur simpan.
  const fv = readFileSync(new URL('../components/form-view.js', import.meta.url), 'utf8');
  const blok = fv.slice(fv.indexOf('function terapkanDraf'), fv.indexOf('const draftErr'));
  assert.doesNotMatch(blok, /onSubmit|submit\(/);
});
