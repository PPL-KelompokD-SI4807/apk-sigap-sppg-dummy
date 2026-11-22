// Draf Agen AI bertanda _resource: alihan layar, prefill mutasi stok, ai_draft_id, pemetaan galat 422, penjaga kirim.
// Jalankan: node --test resources/miniapp/tests/*.test.js
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { tujuanDraf, draftCocok, isianDariPayload, jalurBersih } from '../core/draf-tujuan.js';
import { tujuanDraf as tujuanLama } from '../modules/pembelian-bahan/logika.js';
import { isianMutasiDariDraf, mutasiPayload } from '../modules/bahan-baku/schema.js';
import { galatDraf, errorMessage, ringkasGalatAksi, PESAN_DRAF_TIDAK_COCOK } from '../core/errors.js';
import { buatPenjagaSubmit } from '../core/submit-guard.js';
import { perluDiperbaiki, petakanGalatDraf } from '../core/draf-galat.js';
import { ApiError } from '../core/api.js';

const sumber = (p) => readFileSync(new URL(p, import.meta.url), 'utf8');

// ---------- pengalihan _resource -> layar ----------
test('draf catat_mutasi_stok (mutasi_stok, update) menuju /bahan-baku/:id/mutasi, bukan form edit bahan', () => {
  const d = { module: 'bahan-baku', action: 'update', target_id: 12, payload: { id: 12, jenis: 'masuk', jumlah: 5, _resource: 'mutasi_stok' } };
  assert.equal(tujuanDraf(d), '/bahan-baku/12/mutasi');
  assert.equal(tujuanLama(d), '/bahan-baku/12/mutasi', 'ekspor ulang dari logika.js');
  // id dari payload bila target_id kosong; tanpa id valid -> daftar (bukan form tambah/ubah bahan)
  assert.equal(tujuanDraf({ ...d, target_id: null }), '/bahan-baku/12/mutasi');
  assert.equal(tujuanDraf({ ...d, target_id: null, payload: { _resource: 'mutasi_stok' } }), '/bahan-baku');
  assert.equal(tujuanDraf({ ...d, action: 'create' }), '/bahan-baku');
  // draf ubah bahan baku biasa (tanpa penanda) tetap ke form ubah
  assert.equal(tujuanDraf({ module: 'bahan-baku', action: 'update', target_id: 12, payload: { nama: 'Beras' } }), '/bahan-baku/12/ubah');
});

test('pencairan U parsial memakai penanda, bukan tebakan payload', () => {
  // pengeluaran selalu bertanda: menang walau payload mirip pencairan (hanya nominal)
  assert.equal(tujuanDraf({ module: 'keuangan', action: 'update', target_id: 5, payload: { id: 5, nominal: 100000, _resource: 'pengeluaran' } }), '/keuangan/pengeluaran/5/ubah');
  // pencairan tanpa penanda dan tanpa kolom pembeda -> pencairan (dulu salah ke pengeluaran)
  assert.equal(tujuanDraf({ module: 'keuangan', action: 'update', target_id: 7, payload: { id: 7, nominal: 100000, catatan: 'koreksi' } }), '/keuangan/pencairan/7/ubah');
  assert.equal(tujuanDraf({ module: 'keuangan', action: 'delete', target_id: 7, payload: {} }), '/keuangan/pencairan/7');
  assert.equal(tujuanDraf({ module: 'keuangan', action: 'create', payload: { periode: '2026-11', sumber_dana: 'BGN' } }), '/keuangan/pencairan/baru');
  // draf lama tanpa penanda tetap dikenali dari kolom
  assert.equal(tujuanDraf({ module: 'keuangan', action: 'create', payload: { uraian: 'Gas', kategori: 'energi' } }), '/keuangan/pengeluaran/baru');
  assert.equal(tujuanDraf({ module: 'keuangan', action: 'update', target_id: 5, payload: { id: 5, versi: 1, nominal: 1 } }), '/keuangan/pengeluaran/5/ubah');
});

test('penanda sub-modul lain tetap menuju layar masing-masing', () => {
  assert.equal(tujuanDraf({ module: 'jadwal', action: 'create', payload: { _resource: 'distribusi' } }), '/jadwal/distribusi/baru');
  assert.equal(tujuanDraf({ module: 'armada', action: 'update', target_id: 3, payload: { _resource: 'pengemudi' } }), '/pengemudi/3/ubah');
  assert.equal(tujuanDraf({ module: 'menu-gizi', action: 'update', target_id: 2, payload: { _resource: 'standar_gizi' } }), '/menu-gizi/standar-gizi/2/ubah');
});

test('jalurBersih menyamakan trailing slash dan query', () => {
  assert.equal(jalurBersih('/bahan-baku/12/mutasi/'), '/bahan-baku/12/mutasi');
  assert.equal(jalurBersih('/x?draft=1'), '/x');
  assert.equal(jalurBersih(''), '/');
});

test('crud-module mengalihkan draf milik layar lain sebelum mengirim ai_draft_id', () => {
  const src = sumber('../components/crud-module.js');
  assert.match(src, /tujuanDraf\(draft\)/);
  assert.match(src, /jalurBersih\(tujuan\) !== jalurBersih\(ctx\.path\)\) return navigate\(/);
  assert.match(src, /if \(draftId\) body\.ai_draft_id = draftId;/);
});

// ---------- pencocokan draf dengan layar mutasi ----------
test('draftCocok: modul, penanda, aksi, dan target harus sama', () => {
  const ok = { module: 'bahan-baku', action: 'update', target_id: 12, payload: { id: 12, _resource: 'mutasi_stok' } };
  const cek = (d, t = 12) => draftCocok(d, { module: 'bahan-baku', marker: 'mutasi_stok', aksi: 'update', targetId: t });
  assert.equal(cek(ok), true);
  assert.equal(cek(ok, 13), false, 'bahan lain');
  assert.equal(cek({ ...ok, payload: { id: 12 } }), false, 'tanpa penanda = draf ubah bahan');
  assert.equal(cek({ ...ok, module: 'pemasok' }), false);
  assert.equal(cek({ ...ok, action: 'create' }), false);
  assert.equal(cek(null), false);
  assert.equal(draftCocok({ module: 'm', action: 'create', payload: {} }, { module: 'm', aksi: 'create' }), true, 'marker null = layar bawaan');
});

// ---------- prefill mutasi ----------
test('prefill mutasi dari payload draf: tipe, jumlah, tanggal, catatan, pemasok; penanda dan id dibuang', () => {
  const p = { id: 12, _resource: 'mutasi_stok', _peringatan: 'x', jenis: 'masuk', jumlah: 5.5, tanggal_mutasi: '2026-11-09', catatan: 'Nota 17', pemasok_id: 4, harga_satuan: 12000, kolom_asing: 'x', jadwal_produksi_id: null, tanggal_kedaluwarsa: '' };
  assert.deepEqual(isianMutasiDariDraf(p), { jenis: 'masuk', jumlah: 5.5, tanggal_mutasi: '2026-11-09', pemasok_id: 4, harga_satuan: 12000, catatan: 'Nota 17' });
  assert.deepEqual(isianMutasiDariDraf(null), {});
  assert.deepEqual(isianDariPayload({ id: 1, _resource: 'x', nama: 'A' }), { nama: 'A' });
});

test('galat _galat draf mutasi dipetakan ke kolom form mutasi (aturan PR #58)', () => {
  const d = { perlu_diperbaiki: true, errors: { jumlah: ['Jumlah wajib diisi.'], tidak_dikenal: ['Lain.'] } };
  assert.equal(perluDiperbaiki(d), true);
  const peta = petakanGalatDraf(d.errors, ['jenis', 'jumlah', 'catatan']);
  assert.deepEqual(peta.kolom, { jumlah: 'Jumlah wajib diisi.' });
  assert.deepEqual(peta.lainnya, ['Lain.']);
});

// ---------- pengiriman ai_draft_id ----------
test('mutasiScreen: ai_draft_id hanya dikirim bila draf cocok, ke POST /bahan-baku/{id}/mutasi; guard hak tetap', () => {
  const src = sumber('../modules/bahan-baku/index.js');
  assert.match(src, /draftCocok\(draft, \{ module: SLUG, marker: 'mutasi_stok', aksi: 'update', targetId: rec\.id \}\)/);
  assert.match(src, /if \(draft\) body\.ai_draft_id = draft\.uuid \|\| uuid;/);
  assert.match(src, /submitWithQueue\('POST', `\/bahan-baku\/\$\{rec\.id\}\/mutasi`, body/);
  assert.match(src, /route\('\/bahan-baku\/:id\/mutasi', mutasiScreen, \{[^}]*guard: \(\) => bolehAksi\(getUser\(\), 'catat_mutasi_stok'\)/);
  assert.doesNotMatch(src, /put\(|'PUT'/, 'mutasi tidak pernah lewat PUT /bahan-baku/{id}');
  // payload form tidak pernah memuat ai_draft_id sendiri (ditambahkan eksplisit di atas)
  assert.equal('ai_draft_id' in mutasiPayload({ jenis: 'masuk', jumlah: 1, ai_draft_id: 'x' }), false);
});

// ---------- pemetaan galat 422 ai_draft_id ----------
test('422 pada ai_draft_id dipetakan ke pesan "tidak cocok", bukan "tidak ditemukan"', () => {
  assert.equal(PESAN_DRAF_TIDAK_COCOK, 'Draf ini tidak cocok dengan layar ini. Buka ulang dari bot.');
  const e = new ApiError(422, 'Draf AI tidak ditemukan, sudah dipakai, atau kedaluwarsa.', { ai_draft_id: ['Draf AI tidak ditemukan, sudah dipakai, atau kedaluwarsa.'] });
  assert.equal(galatDraf(e), true);
  assert.equal(errorMessage(e), PESAN_DRAF_TIDAK_COCOK);
  const r = ringkasGalatAksi(e);
  assert.equal(r.pesan, PESAN_DRAF_TIDAK_COCOK);
  assert.deepEqual(r.fields, { ai_draft_id: PESAN_DRAF_TIDAK_COCOK });
  // galat lain tidak terpengaruh
  const lain = new ApiError(422, 'x', { jumlah: ['Jumlah wajib diisi.'] });
  assert.equal(galatDraf(lain), false);
  assert.equal(errorMessage(lain), 'Jumlah wajib diisi.');
  assert.equal(galatDraf(new ApiError(500, 'x')), false);
  assert.equal(galatDraf(null), false);
});

// ---------- penjaga kirim: tombol nonaktif selama permintaan, tanpa kirim ulang ----------
function penjagaUji({ kirim, valid = true } = {}) {
  const log = { loading: [], disabled: [], draf: [], galat: [], kirim: 0 };
  const penjaga = buatPenjagaSubmit({
    validate: () => valid,
    kirim: async () => { log.kirim++; await kirim?.(log.kirim); },
    setLoading: (on) => log.loading.push(on),
    setDisabled: (on) => log.disabled.push(on),
    onGalatDraf: (pesan) => log.draf.push(pesan),
    onGalat: (e) => log.galat.push(e),
  });
  return { penjaga, log };
}

test('penjaga kirim: tombol loading selama permintaan; ketukan ganda diabaikan', async () => {
  let lepas;
  const { penjaga, log } = penjagaUji({ kirim: () => new Promise((r) => { lepas = r; }) });
  const p1 = penjaga.jalankan();
  assert.equal(penjaga.berjalan, true);
  assert.deepEqual(log.loading, [true]);
  assert.equal(await penjaga.jalankan(), false, 'ketukan kedua saat berjalan diabaikan');
  assert.equal(await penjaga.jalankan(), false);
  assert.equal(log.kirim, 1);
  lepas();
  assert.equal(await p1, true);
  assert.deepEqual(log.loading, [true, false]);
  assert.equal(penjaga.berjalan, false);
  assert.deepEqual(log.disabled, []);
});

test('penjaga kirim: validasi gagal tidak mengirim', async () => {
  const { penjaga, log } = penjagaUji({ valid: false });
  assert.equal(await penjaga.jalankan(), false);
  assert.equal(log.kirim, 0);
  assert.deepEqual(log.loading, []);
});

test('penjaga kirim: 422 ai_draft_id mengunci tombol, menampilkan alert, dan tidak kirim ulang', async () => {
  const { penjaga, log } = penjagaUji({ kirim: () => { throw new ApiError(422, 'x', { ai_draft_id: ['Draf AI tidak ditemukan.'] }); } });
  assert.equal(await penjaga.jalankan(), false);
  assert.equal(log.kirim, 1);
  assert.deepEqual(log.draf, [PESAN_DRAF_TIDAK_COCOK]);
  assert.deepEqual(log.galat, []);
  assert.equal(penjaga.terkunci, true);
  assert.deepEqual(log.loading, [true, false]);
  assert.deepEqual(log.disabled, [true], 'tombol simpan dinonaktifkan setelah galat draf');
  // ketukan berikutnya (pengguna mengetuk berulang) tidak mengirim apa pun
  for (let i = 0; i < 3; i++) assert.equal(await penjaga.jalankan(), false);
  assert.equal(log.kirim, 1);
});

test('penjaga kirim: 422 kolom biasa membuka tombol lagi dan tidak otomatis mengirim ulang', async () => {
  const { penjaga, log } = penjagaUji({ kirim: (n) => { if (n === 1) throw new ApiError(422, 'x', { jumlah: ['Salah.'] }); } });
  assert.equal(await penjaga.jalankan(), false);
  assert.equal(log.kirim, 1, 'tidak ada kirim ulang otomatis');
  assert.equal(log.galat.length, 1);
  assert.equal(penjaga.terkunci, false);
  assert.deepEqual(log.disabled, []);
  assert.equal(await penjaga.jalankan(), true, 'pengguna memperbaiki lalu mengetuk lagi');
  assert.equal(log.kirim, 2);
});

test('form-view memakai penjaga kirim, alert draf di atas formulir, dan mengunci MainButton', () => {
  const src = sumber('../components/form-view.js');
  assert.match(src, /buatPenjagaSubmit\(\{/);
  assert.match(src, /setDisabled: setMainButtonDisabled/);
  assert.match(src, /form\.prepend\(draftAlert\)/);
  assert.match(src, /draftAlert\.scrollIntoView/);
  const ui = sumber('../core/ui.js');
  assert.match(ui, /export function setMainButtonDisabled/);
  // tidak ada pengulangan otomatis pada jalur simpan
  const api = sumber('../core/api.js');
  const blok = api.slice(api.indexOf('export async function submitWithQueue'), api.indexOf('let flushing'));
  assert.doesNotMatch(blok, /setTimeout|for \(|while/);
});
