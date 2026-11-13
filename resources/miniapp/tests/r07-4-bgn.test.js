// Tes R07-4: kolom BGN pada form (SPPG, penerima manfaat, pengeluaran, log produksi) dan layar ekspor laporan BGN.
// Jalankan: node --test resources/miniapp/tests/*.test.js
import test from 'node:test';
import assert from 'node:assert/strict';
import { NAMA_PROFIL, salin } from './fixtures/peta.js';
import { validasiJenjang, validasiJenisSatuan, mapPayload as payloadPenerima, penerimaFields } from '../modules/penerima-manfaat/schema.js';
import { sppgFields, mapPayload as payloadSppg, bolehUbahSaldoAwal, parseSaldo, validasiSaldo, validasiTanggalSaldo, payloadSaldoAwal } from '../modules/sppg/schema.js';
import { pengeluaranFields, pengeluaranPayload, kategoriBerSub, SUB_KATEGORI, KATEGORI } from '../modules/keuangan/schema.js';
import { logPayload, logFields } from '../modules/log-produksi/schema.js';
import { JENIS, jenisUntuk, periodeBawaan, validasiPeriode, badanPermintaan, ringkasGalatEkspor, telegramBelumTerhubung } from '../modules/laporan-bgn/logika.js';

const profil = (nama) => salin(nama);

test('jenjang: siswa bukan ats, ats wajib ats, kelompok lain kosong', () => {
  assert.equal(validasiJenjang('sd_1_3', 'siswa'), '');
  assert.equal(validasiJenjang('', 'siswa'), '');
  assert.notEqual(validasiJenjang('ats_9_18', 'siswa'), '');
  assert.equal(validasiJenjang('ats_9_18', 'ats'), '');
  assert.notEqual(validasiJenjang('smp', 'ats'), '');
  assert.notEqual(validasiJenjang('', 'ats'), '');
  assert.equal(validasiJenjang('', 'balita'), '');
  assert.notEqual(validasiJenjang('paud_tk', 'balita'), '');
});

test('jenis satuan hanya untuk siswa', () => {
  assert.equal(validasiJenisSatuan('slb', 'siswa'), '');
  assert.notEqual(validasiJenisSatuan('slb', 'ats'), '');
  assert.equal(validasiJenisSatuan('', 'balita'), '');
});

test('payload penerima manfaat membawa kolom BGN; kolom kosong -> null', () => {
  const p = payloadPenerima({ titik_layanan_id: '3', nama: 'AB', kelompok_sasaran: 'siswa', jenis_kelamin: 'perempuan', jenjang: 'sd_4_6', jenis_satuan: '', is_active: 'true' });
  assert.equal(p.jenis_kelamin, 'perempuan');
  assert.equal(p.jenjang, 'sd_4_6');
  assert.equal(p.jenis_satuan, null);
  assert.ok(penerimaFields().some((f) => f.name === 'jenjang' && f.showIf({ kelompok_sasaran: 'ats' })));
  assert.ok(!penerimaFields().find((f) => f.name === 'jenis_satuan').showIf({ kelompok_sasaran: 'ats' }));
});

test('SPPG: kolom keuangan hanya untuk pembaca keuangan; saldo awal hanya dengan izin server', () => {
  const kepala = profil(NAMA_PROFIL.find((n) => /kepala/i.test(n)));
  const nama = (u, mode = 'update') => sppgFields({ mode, user: u, record: {} }).map((f) => f.name);
  assert.ok(nama(kepala).includes('nama_ketua_yayasan'));
  assert.ok(nama(kepala).includes('id_sppg_bgn'));
  const tanpaKeu = { ...kepala, modules: kepala.modules.filter((m) => m !== 'keuangan') };
  assert.ok(!nama(tanpaKeu).includes('no_va'));
  assert.ok(nama(tanpaKeu).includes('kecamatan'));
  assert.equal(bolehUbahSaldoAwal({ izin: { ubah_saldo_awal: true } }), true);
  assert.equal(bolehUbahSaldoAwal({ izin: { ubah_saldo_awal: false } }), false);
  assert.equal(bolehUbahSaldoAwal({}), false);
  const p = payloadSppg({ kode: 'A', nama: 'B', kapasitas_porsi_harian: 100, no_va: '', acuan_bahan_baku_kecil: 8500 });
  assert.equal(p.no_va, null);
  assert.equal(p.acuan_bahan_baku_kecil, 8500);
});

test('saldo awal: nol sah, negatif/desimal ditolak, tanggal masa depan ditolak', () => {
  assert.equal(parseSaldo('25.000.000'), 25000000);
  assert.equal(validasiSaldo(parseSaldo('0')), '');
  assert.notEqual(validasiSaldo(parseSaldo('')), '');
  assert.notEqual(validasiSaldo(parseSaldo('1,5')), '');
  assert.notEqual(validasiTanggalSaldo('2026-12-01', '2026-11-13'), '');
  assert.equal(validasiTanggalSaldo('2026-11-01', '2026-11-13'), '');
  assert.deepEqual(payloadSaldoAwal(0, '2026-11-01'), { saldo_awal: 0, saldo_awal_tanggal: '2026-11-01' });
});

test('pengeluaran: sub_kategori hanya untuk kategori operasional, insentif_fasilitas tersedia', () => {
  assert.ok(KATEGORI.some((k) => k.value === 'insentif_fasilitas'));
  assert.equal(SUB_KATEGORI.length, 17);
  assert.equal(kategoriBerSub('bahan_baku'), false);
  assert.equal(kategoriBerSub('insentif_fasilitas'), false);
  assert.equal(kategoriBerSub('energi'), true);
  const f = pengeluaranFields({ custom: { nominal: (name, label) => ({ name, label }) } }).find((x) => x.name === 'sub_kategori');
  assert.equal(f.showIf({ kategori: 'energi' }), true);
  assert.equal(f.showIf({ kategori: 'bahan_baku' }), false);
  assert.equal(pengeluaranPayload({ tanggal: '2026-11-01', kategori: 'energi', sub_kategori: 'gas', uraian: 'x', nominal: '1000' }).sub_kategori, 'gas');
  assert.equal(pengeluaranPayload({ kategori: 'bahan_baku', sub_kategori: null }).sub_kategori, null);
});

test('log produksi: porsi food sample dan uji organoleptik dikirim sebagai integer', () => {
  const p = logPayload({ realisasi_porsi: '100', porsi_food_sample: '5', porsi_uji_organoleptik: '' }, { mode: 'update' });
  assert.equal(p.porsi_food_sample, 5);
  assert.equal(p.porsi_uji_organoleptik, null);
  assert.ok(logFields({ mode: 'update' }).some((f) => f.name === 'porsi_uji_organoleptik'));
});

test('ekspor BGN: Investor tidak melihat 30b dan 30d-C', () => {
  const inv = profil(NAMA_PROFIL.find((n) => /investor/i.test(n)));
  const kunci = jenisUntuk(inv).map((j) => j.key);
  assert.deepEqual(kunci, ['30a', '30c', '30d-A', '30d-B']);
  const kepala = profil(NAMA_PROFIL.find((n) => /kepala/i.test(n)));
  assert.equal(jenisUntuk(kepala).length, JENIS.length);
});

test('ekspor BGN: periode, validasi, badan permintaan', () => {
  const j = (k) => JENIS.find((x) => x.key === k);
  assert.deepEqual(periodeBawaan(j('30c'), '2026-11-13'), { tanggal_awal: '2026-11-01', tanggal_akhir: '2026-11-15' });
  assert.deepEqual(periodeBawaan(j('30c'), '2026-12-20'), { tanggal_awal: '2026-12-16', tanggal_akhir: '2026-12-31' });
  assert.notEqual(validasiPeriode(j('30c'), { tanggal_awal: '2026-11-01', tanggal_akhir: '2026-11-30' }, '2026-12-01').tanggal_akhir, undefined);
  assert.deepEqual(validasiPeriode(j('30c'), { tanggal_awal: '2026-11-16', tanggal_akhir: '2026-11-30' }, '2026-12-01'), {});
  assert.ok(validasiPeriode(j('30d-A'), { bulan: '2027-01' }, '2026-12-01').bulan);
  assert.ok(validasiPeriode(j('30b'), { tanggal: '2026-12-02' }, '2026-12-01').tanggal);
  assert.deepEqual(badanPermintaan(j('30d-B'), { bulan: '2026-11' }, '4'), { bulan: '2026-11', sppg_id: 4 });
  assert.deepEqual(badanPermintaan(j('30b'), { tanggal: '2026-11-12' }), { tanggal: '2026-11-12' });
  assert.equal(j('30a').nomor, null);
  assert.equal(j('30d-C').nomor, null);
  assert.equal(j('30d-A').nomor, j('30d-B').nomor);
});

test('ekspor BGN: pemetaan galat 403, 422 telegram, 429', () => {
  assert.match(ringkasGalatEkspor({ status: 403 }, 'pdf').pesan, /tidak berhak/);
  assert.match(ringkasGalatEkspor({ status: 429 }, 'pdf').pesan, /Terlalu banyak/);
  const t = { status: 422, data: { alasan: 'telegram_belum_terhubung' }, errors: null };
  assert.equal(telegramBelumTerhubung(t), true);
  assert.match(ringkasGalatEkspor(t, 'pdf').pesan, /Telegram/);
  const v = ringkasGalatEkspor({ status: 422, errors: { tanggal_akhir: ['Rentang lebih dari 16 hari.'] } }, 'pdf');
  assert.equal(v.fields.tanggal_akhir, 'Rentang lebih dari 16 hari.');
});
