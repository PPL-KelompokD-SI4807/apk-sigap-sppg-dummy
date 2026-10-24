// Tes unit modul grup D (logika murni): node --test resources/miniapp/tests/
import test from 'node:test';
import assert from 'node:assert/strict';
import { can, userPerms } from '../core/permissions.js';
import { errorMessage, statusMessage, PESAN_413, PESAN_429 } from '../core/errors.js';
import { ApiError } from '../core/api.js';
import {
  fitSize, ATTEMPTS, MAX_SIDE, MAX_BYTES, validasiHasil, validasiBerkasAwal, formatUkuran, lampiranPath, fetchLampiranBlob,
} from '../components/photo-upload.js';
import { pesanUnggah, kirimDenganAntrean, uploadLampiran } from '../components/lampiran-field.js';
import * as bukti from '../modules/bukti-distribusi/schema.js';
import * as keu from '../modules/keuangan/schema.js';

const names = (f) => f.map((x) => x.name);

// ---------- hak akses ----------
test('hak akses bukti distribusi dan keuangan sesuai Tabel 5', () => {
  assert.equal(can('relawan', 'bukti-distribusi', 'C'), true);
  assert.equal(can('relawan', 'bukti-distribusi', 'D'), false);
  assert.equal(can('kepala_sppg', 'bukti-distribusi', 'D'), true);
  assert.equal(can('admin', 'bukti-distribusi', 'C'), false);
  assert.equal(can('staf_gudang', 'bukti-distribusi', 'R'), false);
  for (const a of ['C', 'R', 'U', 'D']) assert.equal(can('akuntan', 'keuangan', a), true);
  for (const r of ['admin', 'kepala_sppg']) {
    assert.equal(can(r, 'keuangan', 'R'), true);
    for (const a of ['C', 'U', 'D']) assert.equal(can(r, 'keuangan', a), false, `${r} ${a}`);
  }
  for (const r of ['ahli_gizi', 'staf_gudang', 'relawan']) assert.equal(can(r, 'keuangan', 'R'), false);
  assert.equal(userPerms({ role: 'akuntan', modules: ['bahan-baku'] }, 'keuangan').R, false);
});

test('relawan bukti distribusi hanya unit distribusi', () => {
  assert.equal(bukti.unitDistribusiBoleh({ role: 'relawan', unit: 'distribusi' }), true);
  assert.equal(bukti.unitDistribusiBoleh({ role: 'relawan', unit: 'dapur' }), false);
  assert.equal(bukti.unitDistribusiBoleh({ role: 'kepala_sppg' }), true);
  assert.equal(bukti.unitDistribusiBoleh(null), false);
});

// ---------- rupiah ----------
test('rupiah: format ribuan tampil, parse kembali integer', () => {
  assert.equal(keu.formatRibuan(1250000), '1.250.000');
  assert.equal(keu.formatRibuan(999), '999');
  assert.equal(keu.formatRibuan(0), '0');
  assert.equal(keu.formatRibuan(''), '');
  assert.equal(keu.formatRupiah(480000000), 'Rp 480.000.000');
  assert.equal(keu.formatRupiah(null), '-');
  assert.equal(keu.formatRupiah(-5000), 'Rp -5.000');
  assert.equal(keu.parseRupiah('1.250.000'), 1250000);
  assert.equal(keu.parseRupiah('Rp 8.680.000'), 8680000);
  assert.equal(keu.parseRupiah('  42 '), 42);
  assert.equal(keu.parseRupiah(''), null);
  assert.ok(Number.isNaN(keu.parseRupiah('12,5')));
  assert.ok(Number.isNaN(keu.parseRupiah('abc')));
  assert.ok(Number.isNaN(keu.parseRupiah('-5')));
  for (const n of [1, 12, 1234, 99999999, 9999999999999]) assert.equal(keu.parseRupiah(keu.formatRibuan(n)), n);
  assert.ok(Number.isInteger(keu.parseRupiah('1.000')));
});

test('validasi nominal: bulat, 1..9.999.999.999.999', () => {
  assert.equal(keu.validasiNominal(null), '');
  assert.equal(keu.validasiNominal(1), '');
  assert.equal(keu.validasiNominal(keu.NOMINAL_MAX), '');
  assert.match(keu.validasiNominal(0), /minimal/);
  assert.match(keu.validasiNominal(keu.NOMINAL_MAX + 1), /terlalu besar/);
  assert.match(keu.validasiNominal(NaN), /rupiah bulat/);
  assert.match(keu.validasiNominal(12.5), /rupiah bulat/);
});

// ---------- status anggaran (ambang 80/100) ----------
test('status anggaran: ambang 80% dan 100% dengan teks, bukan hanya warna', () => {
  const s = (anggaran, terpakai, status) => keu.statusAnggaran({ anggaran, terpakai, status });
  assert.equal(s(100, 79).key, 'aman');
  assert.equal(s(100, 80).key, 'peringatan');
  assert.equal(s(100, 99).key, 'peringatan');
  assert.equal(s(100, 100).key, 'habis');
  assert.equal(s(100, 101).key, 'melampaui');
  assert.equal(s(0, 0).key, 'belum_ada_anggaran');
  assert.equal(s(0, 500).key, 'melampaui');
  assert.equal(s(100, 10, 'peringatan').key, 'peringatan'); // status server diutamakan
  for (const k of ['aman', 'peringatan', 'habis', 'melampaui', 'belum_ada_anggaran']) {
    const i = keu.statusAnggaran({ status: k });
    assert.ok(i.label && i.teks, k);
  }
  assert.equal(keu.statusAnggaran({ status: 'melampaui' }).tone, 'danger');
  assert.equal(keu.statusAnggaran({ status: 'peringatan' }).tone, 'warn');
  assert.equal(keu.statusAnggaran({ status: 'aman' }).tone, 'ok');
  assert.equal(keu.hitungPersen(480000000, 312400000).toFixed(1), '65.1');
  assert.equal(keu.hitungPersen(0, 5), null);
  assert.equal(keu.clampPersen(135), 100);
  assert.equal(keu.clampPersen(-4), 0);
  assert.equal(keu.teksPersen(65.04), '65%');
  assert.equal(keu.teksPersen(null), '-');
  assert.equal(keu.melebihiSisa(500, 400), true);
  assert.equal(keu.melebihiSisa(300, 400), false);
  assert.equal(keu.melebihiSisa(1, -10), true);
});

test('periode dan tanggal keuangan', () => {
  assert.equal(keu.labelPeriode('2026-11'), 'November 2026');
  assert.equal(keu.periodeDari('2026-11-20'), '2026-11');
  assert.equal(keu.periodeSekarang(new Date('2026-11-30T20:00:00Z')), '2026-12'); // WIB sudah 1 Des
  assert.match(keu.validasiPeriode('2026-13'), /Format/);
  assert.equal(keu.validasiPeriode('2026-01'), '');
  assert.match(keu.tidakMasaDepan('2027-01-01', 'Tanggal', '2026-12-31'), /masa depan/);
  assert.equal(keu.tidakMasaDepan('2026-12-31', 'Tanggal', '2026-12-31'), '');
});

test('skema keuangan: kategori enum kontrak, payload integer, nota/bukti opsional', () => {
  assert.deepEqual(keu.KATEGORI.map((k) => k.value), ['bahan_baku', 'insentif_relawan', 'energi', 'transportasi', 'peralatan', 'lainnya']);
  const custom = { periode: { type: 'custom' }, nominal: (name, label) => ({ name, label, type: 'custom', required: true }) };
  const peng = keu.pengeluaranFields({ custom });
  assert.deepEqual(names(peng), ['tanggal', 'kategori', 'uraian', 'nominal', 'pemasok_id', 'pencairan_dana_id', 'nota']);
  assert.equal(peng.find((f) => f.name === 'nota').type, 'photo');
  assert.equal(peng.find((f) => f.name === 'nota').required, undefined);
  assert.equal(keu.pengeluaranFields({ custom, record: { pemasok: { id: 7 } } }).find((f) => f.name === 'pemasok_id').default, 7);
  assert.deepEqual(names(keu.pencairanFields({ custom })), ['periode', 'sumber_dana', 'nominal', 'tanggal_cair', 'no_referensi', 'catatan', 'bukti']);
  const nota = new Blob(['x'], { type: 'image/jpeg' });
  const p = keu.pengeluaranPayload({ tanggal: '2026-11-20', kategori: 'energi', uraian: ' Gas LPG ', nominal: 4920000, pemasok_id: '', pencairan_dana_id: null, nota, ekstra: 1 });
  assert.deepEqual(Object.keys(p), ['tanggal', 'kategori', 'uraian', 'nominal', 'pemasok_id', 'pencairan_dana_id', 'nota']);
  assert.equal(p.uraian, 'Gas LPG');
  assert.equal(p.nominal, 4920000);
  assert.equal(p.pemasok_id, null);
  assert.equal(p.nota, nota);
  const c = keu.pencairanPayload({ periode: '2026-11', sumber_dana: 'BGN Tahap I', nominal: '100000000', tanggal_cair: '2026-11-01', no_referensi: '', catatan: null });
  assert.equal(c.nominal, 100000000);
  assert.equal(c.no_referensi, null);
  assert.equal('bukti' in c, false);
  assert.equal(keu.seriKategori({ per_kategori: [{ kategori: 'energi', nominal: 10, persen: 25 }, { kategori: 'lainnya', nominal: 0 }] }).items.length, 1);
});

// ---------- kompres: rasio dan ukuran target ----------
test('kompres: sisi terpanjang maks 1600 px, rasio tetap, tidak diperbesar', () => {
  assert.deepEqual(fitSize(4000, 3000), { width: 1600, height: 1200, scale: 0.4 });
  assert.deepEqual(fitSize(3000, 4000), { width: 1200, height: 1600, scale: 0.4 });
  assert.equal(fitSize(800, 600).width, 800);
  assert.equal(fitSize(800, 600).scale, 1);
  assert.equal(fitSize(1600, 1600).width, 1600);
  for (const [w, hh] of [[4032, 3024], [1920, 1080], [9000, 100], [101, 7000]]) {
    const r = fitSize(w, hh);
    assert.ok(Math.max(r.width, r.height) <= MAX_SIDE, `${w}x${hh}`);
    assert.ok(Math.abs(r.width / r.height - w / hh) / (w / hh) < 0.02 || Math.min(r.width, r.height) === 1, `rasio ${w}x${hh}`);
    assert.ok(r.width >= 1 && r.height >= 1);
  }
  assert.throws(() => fitSize(0, 100));
  assert.equal(ATTEMPTS[0].maxSide, 1600);
  assert.equal(ATTEMPTS[0].quality, 0.8);
  for (let i = 1; i < ATTEMPTS.length; i++) assert.ok(ATTEMPTS[i].quality <= ATTEMPTS[i - 1].quality && ATTEMPTS[i].maxSide <= ATTEMPTS[i - 1].maxSide);
  assert.equal(MAX_BYTES, 5 * 1024 * 1024);
  assert.equal(validasiHasil(MAX_BYTES), '');
  assert.match(validasiHasil(MAX_BYTES + 1), /5 MB/);
  assert.match(validasiBerkasAwal({ type: 'application/pdf', size: 10 }), /gambar/);
  assert.match(validasiBerkasAwal({ type: 'image/jpeg', size: 0 }), /kosong/);
  assert.match(validasiBerkasAwal({ type: 'image/jpeg', size: 31 * 1024 * 1024 }), /30 MB/);
  assert.equal(validasiBerkasAwal({ type: 'image/heic', size: 1000 }), '');
  assert.equal(validasiBerkasAwal({ type: '', size: 1000 }), '');
  assert.equal(formatUkuran(312 * 1024), '312 KB');
  assert.equal(formatUkuran(1.5 * 1024 * 1024), '1,5 MB');
});

// ---------- pemetaan galat unggah ----------
test('pemetaan galat 413/422/429/409 berbahasa Indonesia', () => {
  assert.equal(pesanUnggah({ status: 413 }), PESAN_413);
  assert.match(pesanUnggah({ status: 413 }), /5 MB/);
  assert.equal(pesanUnggah({ status: 429 }), PESAN_429);
  assert.match(pesanUnggah({ status: 429 }), /20 per menit/);
  assert.equal(pesanUnggah({ status: 422, errors: { berkas: ['Hanya gambar JPEG, PNG, atau WebP yang diizinkan.'] } }), 'Hanya gambar JPEG, PNG, atau WebP yang diizinkan.');
  assert.match(pesanUnggah({ status: 422, errors: null }), /ditolak/);
  assert.match(pesanUnggah({ status: 409 }), /diproses/);
  assert.match(pesanUnggah({ status: 0 }), /koneksi/);
  assert.match(pesanUnggah({ status: 403 }), /hak/);
  assert.match(pesanUnggah({ status: 404 }), /tidak ditemukan/);
  assert.match(pesanUnggah({ status: 503 }), /gangguan/);
  assert.equal(errorMessage({ status: 413 }), PESAN_413);
  assert.equal(errorMessage({ status: 429, message: 'Too Many Attempts.' }), PESAN_429);
  assert.equal(errorMessage({ status: 422, errors: { jadwal_distribusi_id: ['Jadwal ditahan karena mutu belum Layak.'] } }), 'Jadwal ditahan karena mutu belum Layak.');
  assert.equal(statusMessage(413, ''), PESAN_413);
  assert.equal(statusMessage(429, 'Too Many Attempts.'), PESAN_429);
  assert.equal(statusMessage(400, 'Salah'), 'Salah');
  assert.equal(statusMessage(400), 'Permintaan gagal (400).');
});

// ---------- alur antrean offline (tanpa DOM) ----------
test('kirimDenganAntrean: sukses, offline masuk antrean dengan kunci yang sama, galat server tidak diantrekan', async () => {
  const calls = [];
  const queued = [];
  const ok = async (m, p, o) => { calls.push({ m, p, key: o.key, silent: o.silent }); return { data: { id: 12 } }; };
  const offline = async () => { throw new ApiError(0, 'Tidak ada koneksi internet.'); };
  const enqueue = async (item) => { queued.push(item); return true; };
  const berkas = new Blob(['x'], { type: 'image/jpeg' });

  const r1 = await uploadLampiran({ modul: 'keuangan-pengeluaran', recordId: 5, file: berkas, kategori: 'nota', key: 'kunci-1' }, { request: ok, enqueue });
  assert.equal(r1.status, 'terunggah');
  assert.deepEqual(calls[0], { m: 'POST', p: '/lampiran', key: 'kunci-1', silent: true });

  const r2 = await uploadLampiran({ modul: 'bukti-distribusi', recordId: 9, file: berkas, kategori: 'foto', key: 'kunci-2' }, { request: offline, enqueue });
  assert.equal(r2.status, 'menunggu');
  assert.equal(queued.length, 1);
  assert.equal(queued[0].id, 'kunci-2'); // Idempotency-Key = id antrean (dipakai saat flushQueue)
  assert.equal(queued[0].path, '/lampiran');
  assert.equal(queued[0].method, 'POST');
  assert.deepEqual({ ...queued[0].body, berkas: undefined }, { modul: 'bukti-distribusi', lampiranable_id: 9, kategori: 'foto', berkas: undefined });
  assert.equal(queued[0].body.berkas, berkas);
  assert.ok(typeof queued[0].createdAt === 'number');

  // IndexedDB tidak tersedia (enqueue === false): galat offline diteruskan agar UI menampilkan "gagal"
  await assert.rejects(() => uploadLampiran({ modul: 'mutu', recordId: 1, file: berkas, key: 'k3' }, { request: offline, enqueue: async () => false }), (e) => e.status === 0);

  // 422/413/429 tidak masuk antrean
  for (const status of [422, 413, 429, 403]) {
    const n = queued.length;
    await assert.rejects(() => uploadLampiran({ modul: 'mutu', recordId: 1, file: berkas, key: `k${status}` }, { request: async () => { throw new ApiError(status, 'x'); }, enqueue }), (e) => e.status === status);
    assert.equal(queued.length, n);
  }

  // ulang kirim memakai kunci yang sama
  const keys = [];
  let n = 0;
  const flaky = async (m, p, o) => { keys.push(o.key); if (n++ === 0) throw new ApiError(429, 'x'); return {}; };
  await assert.rejects(() => uploadLampiran({ modul: 'mutu', recordId: 1, file: berkas, key: 'sama' }, { request: flaky, enqueue }));
  await uploadLampiran({ modul: 'mutu', recordId: 1, file: berkas, key: 'sama' }, { request: flaky, enqueue });
  assert.deepEqual(keys, ['sama', 'sama']);

  // modul tak dikenal ditolak sebelum jaringan
  await assert.rejects(() => uploadLampiran({ modul: 'sembarang', recordId: 1, file: berkas }, { request: ok, enqueue }), (e) => e.status === 422);
  assert.ok(kirimDenganAntrean);
});

// ---------- tampilan gambar privat ----------
test('lampiranPath hanya menerima jalur API lampiran (token tidak bocor)', () => {
  assert.equal(lampiranPath('/api/v1/lampiran/12'), '/api/v1/lampiran/12');
  assert.equal(lampiranPath({ lampiran_id: 12, url: '/api/v1/lampiran/12' }), '/api/v1/lampiran/12');
  assert.equal(lampiranPath({ lampiran_id: 12 }), '/api/v1/lampiran/12');
  assert.equal(lampiranPath({ id: 3, url: '/api/v1/lampiran/3' }), '/api/v1/lampiran/3');
  assert.equal(lampiranPath('https://evil.example/api/v1/lampiran/12'), null);
  assert.equal(lampiranPath('//evil.example/api/v1/lampiran/1'), null);
  assert.equal(lampiranPath('/api/v1/lampiran/12/../../auth'), null);
  assert.equal(lampiranPath(null), null);
});

test('fetchLampiranBlob memakai header Authorization dan memetakan galat', async () => {
  let seen;
  const blob = new Blob(['x'], { type: 'image/jpeg' });
  const ok = async (url, init) => { seen = { url, init }; return { ok: true, status: 200, blob: async () => blob }; };
  assert.equal(await fetchLampiranBlob('/api/v1/lampiran/12', { fetchFn: ok, token: 'T0K' }), blob);
  assert.equal(seen.init.headers.Authorization, 'Bearer T0K');
  const st = (status) => async () => ({ ok: false, status });
  await assert.rejects(() => fetchLampiranBlob('/api/v1/lampiran/1', { fetchFn: st(404), token: 't' }), /tidak ditemukan/);
  await assert.rejects(() => fetchLampiranBlob('/api/v1/lampiran/1', { fetchFn: st(403), token: 't' }), /hak akses/);
  await assert.rejects(() => fetchLampiranBlob('/api/v1/lampiran/1', { fetchFn: async () => { throw new Error('x'); }, token: 't' }), /koneksi/);
  await assert.rejects(() => fetchLampiranBlob('https://evil.example/x', { fetchFn: ok, token: 't' }), /tidak valid/);
  await assert.rejects(() => fetchLampiranBlob('/api/v1/lampiran/1', { fetchFn: async () => ({ ok: true, status: 200, blob: async () => new Blob(['<html>'], { type: 'text/html' }) }), token: 't' }), /bukan gambar/);
});

// ---------- bukti distribusi ----------
test('jadwal yang dapat dipilih: relawan hanya hari ini dan milik sendiri/belum bertugas', () => {
  const relawan = { role: 'relawan', unit: 'distribusi', id: 7 };
  const j = (o) => ({ id: 1, status: 'siap', tanggal_distribusi: '2026-11-20', petugas_id: null, ...o });
  assert.equal(bukti.jadwalDapatDipilih(j({}), relawan, '2026-11-20'), true);
  assert.equal(bukti.jadwalDapatDipilih(j({ petugas_id: 7 }), relawan, '2026-11-20'), true);
  assert.equal(bukti.jadwalDapatDipilih(j({ petugas_id: 8 }), relawan, '2026-11-20'), false);
  assert.equal(bukti.jadwalDapatDipilih(j({ tanggal_distribusi: '2026-11-19' }), relawan, '2026-11-20'), false);
  assert.equal(bukti.jadwalDapatDipilih(j({ status: 'diterima' }), relawan, '2026-11-20'), false);
  assert.equal(bukti.jadwalDapatDipilih(j({ status: 'dibatalkan' }), { role: 'kepala_sppg' }, '2026-11-20'), false);
  assert.equal(bukti.jadwalDapatDipilih(j({ petugas_id: 8, tanggal_distribusi: '2026-11-01' }), { role: 'kepala_sppg', id: 2 }, '2026-11-20'), true);
});

test('peringatan jadwal: ditahan mutu, belum siap, belum berangkat', () => {
  assert.equal(bukti.peringatanJadwal(null), null);
  assert.equal(bukti.peringatanJadwal({ status: 'berangkat', layak_distribusi: true }), null);
  assert.equal(bukti.peringatanJadwal({ status: 'menunggu' }).tone, 'danger');
  assert.match(bukti.peringatanJadwal({ status: 'berangkat', layak_distribusi: false }).text, /mutu/);
  assert.equal(bukti.peringatanJadwal({ status: 'siap', layak_distribusi: true }).tone, 'warn');
  assert.equal(bukti.peringatanJadwal({ status: 'dibatalkan' }).tone, 'danger');
  assert.equal(bukti.peringatanJadwal({ status: 'diterima' }).tone, 'danger');
});

test('terlambat dan selisih porsi hasil server', () => {
  assert.equal(bukti.terlambatLabel({ terlambat: true, menit_keterlambatan: 22 }), 'Terlambat 22 menit');
  assert.equal(bukti.terlambatLabel({ terlambat: false, menit_keterlambatan: null }), 'Tepat waktu');
  assert.equal(bukti.terlambatBadge({ terlambat: true }), 'badge-danger');
  assert.equal(bukti.terlambatBadge({ terlambat: false }), 'badge-ok');
  assert.equal(bukti.selisihLabel({ selisih_porsi: 2 }), 'Kurang 2 porsi');
  assert.equal(bukti.selisihLabel({ selisih_porsi: 0 }), 'Sesuai');
  assert.equal(bukti.selisihLabel({ selisih_porsi: null }), 'Sesuai');
});

test('validator porsi dan catatan selisih', () => {
  assert.equal(bukti.validasiPorsi(240, 240), '');
  assert.equal(bukti.validasiPorsi(0, 240), '');
  assert.match(bukti.validasiPorsi(241, 240), /melebihi/);
  assert.match(bukti.validasiPorsi(-1, 240), /bulat/);
  assert.match(bukti.validasiPorsi(2.5, 240), /bulat/);
  assert.equal(bukti.validasiPorsi(500, null), '');
  assert.match(bukti.validasiCatatanSelisih('', 238, 240), /wajib/);
  assert.match(bukti.validasiCatatanSelisih('  ', 238, 240), /wajib/);
  assert.equal(bukti.validasiCatatanSelisih('2 porsi tumpah', 238, 240), '');
  assert.equal(bukti.validasiCatatanSelisih('', 240, 240), '');
  assert.equal(bukti.validasiCatatanSelisih('', 5, null), '');
});

test('hak per baris bukti: relawan ubah hanya milik sendiri hari ini, tak boleh hapus', () => {
  const relawan = { role: 'relawan', id: 7 };
  const p = { C: true, R: true, U: true, D: false };
  const today = '2026-11-20';
  const rec = (o) => ({ dicatat_oleh: { id: 7 }, waktu_tiba: '2026-11-20T02:00:00Z', ...o });
  assert.equal(bukti.rowPermsBukti(relawan, rec({}), p, today).U, true);
  assert.equal(bukti.rowPermsBukti(relawan, rec({ dicatat_oleh: { id: 8 } }), p, today).U, false);
  assert.equal(bukti.rowPermsBukti(relawan, rec({ waktu_tiba: '2026-11-19T02:00:00Z' }), p, today).U, false);
  assert.equal(bukti.rowPermsBukti(relawan, rec({}), { ...p, D: true }, today).D, false);
  const kepala = { C: true, R: true, U: true, D: true };
  assert.deepEqual(bukti.rowPermsBukti({ role: 'kepala_sppg', id: 2 }, rec({}), kepala, today), kepala);
});

test('skema bukti: foto wajib saat baru, opsional saat ubah; payload multipart', () => {
  const sel = { cur: { jumlah_porsi: 240 } };
  const custom = { jadwal: { name: 'jadwal_distribusi_id', type: 'custom', required: true }, porsiDikirim: { name: 'porsi_dikirim', type: 'custom' }, waktuTiba: { name: 'waktu_tiba', type: 'custom' }, lokasi: { name: 'lokasi', type: 'custom' } };
  const baru = bukti.buktiFields({ mode: 'create', jadwalSel: sel, custom });
  assert.deepEqual(names(baru), ['jadwal_distribusi_id', 'foto', 'porsi_dikirim', 'porsi_diterima', 'nama_pic', 'no_berita_acara', 'catatan_selisih', 'catatan', 'waktu_tiba', 'berita_acara', 'lokasi']);
  assert.equal(baru.find((f) => f.name === 'foto').required, true);
  assert.equal(baru.find((f) => f.name === 'foto').type, 'photo');
  const ubah = bukti.buktiFields({ mode: 'update', jadwalSel: sel, custom });
  assert.equal(ubah.find((f) => f.name === 'foto').required, false);
  assert.equal(names(ubah).includes('jadwal_distribusi_id'), false);
  assert.equal(names(ubah).includes('lokasi'), false);
  // validator terhubung ke jadwal terpilih
  const porsi = baru.find((f) => f.name === 'porsi_diterima');
  assert.match(porsi.validate(241), /melebihi/);
  assert.match(baru.find((f) => f.name === 'catatan_selisih').validate('', { porsi_diterima: 200 }), /wajib/);
  assert.match(baru.find((f) => f.name === 'nama_pic').validate('Ibu 081234567890'), /NIK/);

  const foto = new Blob(['x'], { type: 'image/jpeg' });
  const p = bukti.buktiPayload({ jadwal_distribusi_id: '31', porsi_diterima: '238', nama_pic: ' Ibu Contoh ', catatan_selisih: '2 tumpah', catatan: '', no_berita_acara: null, foto, lokasi: { latitude: -6.2, longitude: 106.8 }, waktu_tiba: 'x' }, { mode: 'create' });
  assert.equal(p.jadwal_distribusi_id, 31);
  assert.equal(p.porsi_diterima, 238);
  assert.equal(p.nama_pic, 'Ibu Contoh');
  assert.equal(p.catatan, null);
  assert.equal(p.foto, foto);
  assert.equal(p.latitude, -6.2);
  assert.equal('waktu_tiba' in p, false); // waktu tiba milik server
  const u = bukti.buktiPayload({ jadwal_distribusi_id: 31, porsi_diterima: 239, nama_pic: 'Bapak Contoh' }, { mode: 'update' });
  assert.equal('jadwal_distribusi_id' in u, false);
  assert.equal('foto' in u, false);
});

// ---------- flushQueue: 409/429 tidak membuang draf ----------
test('flushQueue: 409/429 dicoba ulang dengan backoff dan tidak dibuang; 422 dibuang', async () => {
  const { flushQueue, FLUSH_BACKOFF_MS } = await import('../core/api.js');
  const dasar = (items, send) => {
    const sisa = new Map(items.map((i) => [i.id, i]));
    const jeda = [];
    const notif = [];
    return {
      sisa, jeda, notif,
      deps: {
        request: send,
        list: async () => [...sisa.values()],
        remove: async (id) => { sisa.delete(id); },
        sleep: async (ms) => { jeda.push(ms); },
        authed: () => true,
        notify: (m, t) => notif.push([m, t]),
        schedule: false,
      },
    };
  };
  const item = (id) => ({ id, method: 'POST', path: '/x', body: {}, label: `Draf ${id}`, createdAt: 1 });

  // 409 dua kali lalu sukses: terkirim, backoff 1s lalu 2s, antrian kosong.
  let n = 0;
  const a = dasar([item('a')], async () => { if (++n <= 2) throw new ApiError(409, 'sedang diproses'); return {}; });
  assert.equal(await flushQueue(a.deps), 1);
  assert.deepEqual(a.jeda, FLUSH_BACKOFF_MS.slice(0, 2));
  assert.equal(a.sisa.size, 0);

  // 429 terus-menerus: draf tetap di antrian, tidak ada toast "ditolak", putaran berhenti (item berikut tidak dicoba).
  const dicoba = [];
  const b = dasar([item('b1'), item('b2')], async (m, p, o) => { dicoba.push(o.key); throw new ApiError(429, 'Too Many Attempts.'); });
  assert.equal(await flushQueue(b.deps), 0);
  assert.deepEqual([...b.sisa.keys()], ['b1', 'b2']);
  assert.deepEqual(b.jeda, FLUSH_BACKOFF_MS);
  assert.deepEqual([...new Set(dicoba)], ['b1']);
  assert.deepEqual(b.notif, []);

  // 409 terus-menerus pada item pertama: tetap di antrian, item kedua tetap dicoba dan terkirim.
  const c = dasar([item('c1'), item('c2')], async (m, p, o) => { if (o.key === 'c1') throw new ApiError(409, 'sedang diproses'); return {}; });
  assert.equal(await flushQueue(c.deps), 1);
  assert.deepEqual([...c.sisa.keys()], ['c1']);

  // 422 ditolak permanen: dibuang dengan toast; offline: berhenti tanpa membuang.
  const d = dasar([item('d1')], async () => { throw new ApiError(422, 'tidak valid'); });
  await flushQueue(d.deps);
  assert.equal(d.sisa.size, 0);
  assert.equal(d.notif[0][1], 'error');
  const e = dasar([item('e1')], async () => { throw new ApiError(0, 'offline'); });
  await flushQueue(e.deps);
  assert.equal(e.sisa.size, 1);
});
