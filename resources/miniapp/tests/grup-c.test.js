// Tes unit modul grup C (logika murni): node --test resources/miniapp/tests/
import test from 'node:test';
import assert from 'node:assert/strict';
import { can, userPerms } from '../core/permissions.js';
import { mapValidationErrors, errorMessage } from '../core/errors.js';
import { todayWIB, jamWIB, addDays, hariLengkap } from '../core/waktu.js';
import * as trip from '../modules/notifikasi-perjalanan/schema.js';
import * as kel from '../modules/keluhan/schema.js';
import * as rekap from '../modules/rekap-harian/schema.js';

const names = (f) => f.map((x) => x.name);

// ---------- hak akses ----------
test('hak akses grup C sesuai Tabel 5', () => {
  assert.equal(can('relawan', 'notifikasi-perjalanan', 'C'), true);
  assert.equal(can('kepala_sppg', 'notifikasi-perjalanan', 'C'), true);
  assert.equal(can('admin', 'notifikasi-perjalanan', 'C'), false);
  assert.equal(can('ahli_gizi', 'notifikasi-perjalanan', 'R'), false);
  assert.equal(can('relawan', 'keluhan', 'C'), true);
  assert.equal(can('relawan', 'keluhan', 'U'), false);
  assert.equal(can('kepala_sppg', 'keluhan', 'U'), true);
  assert.equal(can('ahli_gizi', 'keluhan', 'U'), true);
  assert.equal(can('kepala_sppg', 'keluhan', 'D'), false);
  assert.equal(can('admin', 'keluhan', 'D'), true);
  assert.equal(can('kepala_sppg', 'rekap-harian', 'C'), true);
  assert.equal(can('ahli_gizi', 'rekap-harian', 'C'), false);
  assert.equal(can('akuntan', 'rekap-harian', 'R'), true);
  assert.equal(can('staf_gudang', 'rekap-harian', 'R'), false);
  assert.equal(userPerms({ role: 'kepala_sppg', modules: ['keluhan'] }, 'rekap-harian').R, false);
  assert.deepEqual(rekap.aksiRekap({ C: true, U: true }), { susun: true, ubah: true });
});

test('relawan perjalanan hanya unit distribusi', () => {
  assert.equal(trip.unitPerjalananBoleh({ role: 'relawan', unit: 'distribusi' }), true);
  assert.equal(trip.unitPerjalananBoleh({ role: 'relawan', unit: 'dapur' }), false);
  assert.equal(trip.unitPerjalananBoleh({ role: 'relawan', unit: null }), false);
  assert.equal(trip.unitPerjalananBoleh({ role: 'kepala_sppg' }), true);
  assert.equal(trip.unitPerjalananBoleh(null), false);
});

// ---------- perjalanan ----------
test('transisi perjalanan: hanya yang valid', () => {
  assert.deepEqual(trip.transisiValid('berangkat'), ['dalam_perjalanan', 'dibatalkan']);
  assert.deepEqual(trip.transisiValid('dalam_perjalanan'), ['tiba', 'dibatalkan']);
  assert.deepEqual(trip.transisiValid('tiba'), []);
  assert.deepEqual(trip.transisiValid('dibatalkan'), []);
  assert.deepEqual(trip.transisiValid('xyz'), []);
  const t = trip.transisiValid('berangkat'); t.push('tiba');
  assert.deepEqual(trip.transisiValid('berangkat'), ['dalam_perjalanan', 'dibatalkan'], 'salinan, bukan referensi');
});

test('perjalanan: siapa boleh ubah status', () => {
  const rec = { status: 'berangkat', dipicu_oleh: { id: 5 } };
  assert.equal(trip.bolehUbahStatus({ role: 'relawan', id: 5 }, rec, { C: true }), true);
  assert.equal(trip.bolehUbahStatus({ role: 'relawan', id: 6 }, rec, { C: true }), false, 'milik relawan lain');
  assert.equal(trip.bolehUbahStatus({ role: 'kepala_sppg', id: 1 }, rec, { C: true }), true);
  assert.equal(trip.bolehUbahStatus({ role: 'admin', id: 1 }, rec, { C: false }), false, 'admin hanya baca');
  assert.equal(trip.bolehUbahStatus({ role: 'kepala_sppg' }, { status: 'tiba' }, { C: true }), false, 'status final');
});

test('perjalanan: alasan batal wajib, payload status', () => {
  assert.match(trip.validasiAlasan('dibatalkan', '  '), /wajib/);
  assert.equal(trip.validasiAlasan('dibatalkan', 'Mobil mogok'), '');
  assert.equal(trip.validasiAlasan('tiba', ''), '');
  assert.match(trip.validasiAlasan('dibatalkan', 'x'.repeat(501)), /500/);
  assert.deepEqual(trip.statusPayload('tiba', 'abaikan'), { status: 'tiba' });
  assert.deepEqual(trip.statusPayload('dibatalkan', ' Banjir '), { status: 'dibatalkan', alasan: 'Banjir' });
});

test('perjalanan: payload, jadwal layak, PIC dialihkan, estimasi WIB', () => {
  assert.deepEqual(trip.perjalananPayload({ jadwal_distribusi_id: '41', estimasi_menit: '' }), { jadwal_distribusi_id: 41, estimasi_menit: null });
  assert.deepEqual(trip.perjalananPayload({ jadwal_distribusi_id: '41', estimasi_menit: '25' }), { jadwal_distribusi_id: 41, estimasi_menit: 25 });
  assert.equal(trip.jadwalLayak({ status: 'siap', layak_distribusi: true }), true);
  assert.equal(trip.jadwalLayak({ status: 'siap', layak_distribusi: false }), false);
  assert.equal(trip.jadwalLayak({ status: 'menunggu' }), false);
  assert.equal(trip.picDialihkan({ dialihkan_ke_kepala: true }), true);
  assert.equal(trip.picDialihkan({ penerima: 'kepala_sppg' }), true);
  assert.equal(trip.picDialihkan({ penerima: 'pic' }), false);
  assert.equal(trip.PENANDA_PIC, 'PIC belum terhubung, dialihkan ke Kepala SPPG');
  const r = trip.ringkasEstimasi({ estimasi_tiba: '2026-11-02T08:50:00+07:00', estimasi_menit: 20, estimasi_sumber: 'jarak', penerima: 'kepala_sppg' });
  assert.deepEqual(r, { estimasi: '08:50 WIB', durasi: '20 menit', sumber: 'dihitung dari jarak', dialihkan: true });
  assert.equal(jamWIB('2026-11-02T01:50:00Z'), '08:50 WIB', 'UTC -> WIB');
  assert.equal(jamWIB(null), '-');
  assert.deepEqual(names(trip.perjalananFields({ mode: 'create' })), ['jadwal_distribusi_id', 'estimasi_menit']);
  const f = trip.perjalananFields({ mode: 'create' })[1];
  assert.equal(f.max, 720);
  assert.equal(f.validate(1.5), 'Harus bilangan bulat.');
});

test('waktu: hari ini WIB, geser hari', () => {
  assert.equal(todayWIB(new Date('2026-11-02T20:00:00Z')), '2026-11-03', '20.00 UTC sudah esok di WIB');
  assert.equal(todayWIB(new Date('2026-11-02T10:00:00Z')), '2026-11-02');
  assert.equal(addDays('2026-11-01', -14), '2026-10-18');
  assert.match(hariLengkap('2026-11-02'), /Senin/);
});

// ---------- keluhan ----------
test('keluhan: prioritas otomatis dan kritis', () => {
  assert.equal(kel.prioritasOtomatis('makanan_basi'), 'kritis');
  assert.equal(kel.prioritasOtomatis('gejala_sakit'), 'kritis');
  assert.equal(kel.prioritasOtomatis('porsi_kurang'), 'sedang');
  assert.equal(kel.prioritasOtomatis('terlambat'), 'sedang');
  assert.equal(kel.prioritasOtomatis('rasa'), 'rendah');
  assert.equal(kel.isKritis('gejala_sakit'), true);
  assert.equal(kel.isKritis('kemasan'), false);
  assert.equal(kel.prioritasBadge('kritis'), 'badge-danger');
});

test('keluhan: kritis wajib batch / titik / batch tidak diketahui', () => {
  assert.match(kel.validasiBatch({ kategori: 'makanan_basi' }), /batch/i);
  assert.equal(kel.validasiBatch({ kategori: 'makanan_basi', jadwal_produksi_id: 18 }), '');
  assert.equal(kel.validasiBatch({ kategori: 'makanan_basi', batch_tidak_diketahui: true }), '');
  assert.equal(kel.validasiBatch({ kategori: 'gejala_sakit', titik_layanan_id: 3 }), '');
  assert.equal(kel.validasiBatch({ kategori: 'rasa' }), '');
});

test('keluhan: transisi status + tindak lanjut wajib', () => {
  assert.deepEqual(kel.transisiValid('baru'), ['diproses']);
  assert.deepEqual(kel.transisiValid('diproses'), ['selesai', 'ditolak']);
  assert.equal(kel.isFinal('selesai'), true);
  assert.equal(kel.isFinal('ditolak'), true);
  assert.equal(kel.isFinal('baru'), false);
  assert.deepEqual(kel.statusOptionsUpdate('diproses').map((o) => o.value), ['diproses', 'selesai', 'ditolak']);
  assert.match(kel.validasiTindakLanjut('selesai', ' '), /wajib/);
  assert.match(kel.validasiTindakLanjut('ditolak', null), /wajib/);
  assert.equal(kel.validasiTindakLanjut('diproses', ''), '');
  assert.equal(kel.validasiTindakLanjut('selesai', 'Batch ditarik'), '');
  const f = kel.keluhanFields({ mode: 'update', user: { role: 'kepala_sppg' }, record: { status: 'diproses' } });
  assert.deepEqual(names(f), ['status', 'tindak_lanjut']);
  assert.match(f[1].validate(null, { status: 'selesai' }), /wajib/);
});

test('keluhan: skema dan payload per peran', () => {
  assert.deepEqual(names(kel.keluhanFields({ mode: 'create', user: { role: 'relawan' } })), ['sumber', 'kategori', 'titik_layanan_id', 'jadwal_produksi_id', 'deskripsi']);
  assert.equal(names(kel.keluhanFields({ mode: 'create', user: { role: 'admin' } }))[0], 'sppg_id');
  assert.ok(names(kel.keluhanFields({ mode: 'update', user: { role: 'admin' }, record: { status: 'baru' } })).includes('prioritas'));
  const rel = { role: 'relawan' };
  assert.deepEqual(
    kel.keluhanPayload({ sumber: 'guru', kategori: 'rasa', titik_layanan_id: '3', jadwal_produksi_id: '', deskripsi: ' Hambar ', sppg_id: '9', foto_path: 'x' }, { mode: 'create', user: rel }),
    { sumber: 'guru', kategori: 'rasa', titik_layanan_id: 3, jadwal_produksi_id: null, deskripsi: 'Hambar' });
  const k = kel.keluhanPayload({ sumber: 'guru', kategori: 'makanan_basi', deskripsi: 'Basi', batch_tidak_diketahui: true }, { mode: 'create', user: rel });
  assert.equal(k.batch_tidak_diketahui, true);
  assert.equal(kel.keluhanPayload({ sumber: 'guru', kategori: 'rasa', deskripsi: 'a', batch_tidak_diketahui: true }, { mode: 'create', user: rel }).batch_tidak_diketahui, undefined);
  assert.equal(kel.keluhanPayload({ sumber: 'guru', kategori: 'makanan_basi', deskripsi: 'a', jadwal_produksi_id: '4', batch_tidak_diketahui: true }, { mode: 'create', user: rel }).batch_tidak_diketahui, undefined);
  assert.equal(kel.keluhanPayload({ kategori: 'rasa', sumber: 'guru', deskripsi: 'a', sppg_id: '2' }, { mode: 'create', user: { role: 'admin' } }).sppg_id, 2);
  assert.deepEqual(kel.keluhanPayload({ status: 'selesai', tindak_lanjut: ' Ditarik ', deskripsi: 'tidak boleh' }, { mode: 'update', user: { role: 'kepala_sppg' } }), { status: 'selesai', tindak_lanjut: 'Ditarik' });
});

test('keluhan: hak per baris', () => {
  const p = { C: false, R: true, U: true, D: false };
  assert.equal(kel.rowPermsKeluhan({ role: 'kepala_sppg' }, { status: 'baru' }, p).U, true);
  assert.equal(kel.rowPermsKeluhan({ role: 'kepala_sppg' }, { status: 'selesai' }, p).U, false);
  assert.equal(kel.rowPermsKeluhan({ role: 'admin' }, { status: 'selesai' }, { ...p, D: true }).D, true);
});

// ---------- pemetaan 422 ----------
test('pemetaan galat 422', () => {
  assert.deepEqual(mapValidationErrors({ status: ['Transisi tidak valid.'], jadwal_produksi_id: ['Batch wajib.'] }), { status: 'Transisi tidak valid.', jadwal_produksi_id: 'Batch wajib.' });
  assert.equal(errorMessage({ status: 422, errors: { alasan: ['Alasan wajib diisi.'] } }), 'Alasan wajib diisi.');
  assert.equal(errorMessage({ status: 403 }), 'Anda tidak punya hak akses.');
  assert.equal(errorMessage({ status: 0 }), 'Tidak ada koneksi internet.');
});

// ---------- rekap / dasbor ----------
const RINGKASAN = {
  tanggal: '2026-11-02', sppg_id: 1,
  porsi: { jumlah_jadwal: 2, target: 1200, realisasi: 1180, capaian_persen: 98.33, jadwal_tanpa_log: 0 },
  distribusi: { total: 4, tiba: 3, terlambat: 1, batal: 0, belum_selesai: 1 },
  mutu: { batch: 2, layak: 2, tidak_layak: 0, belum_diperiksa: 0 },
  stok: { menipis: 2, sudah_kedaluwarsa: 0, akan_kedaluwarsa: 1 },
  keuangan: { periode: '2026-11', pencairan_periode: '50000000.00', pengeluaran_hari_ini: '1250000.00', pengeluaran_periode: '21250000.00', sisa_anggaran: '28750000.00', serapan_persen: 42.5 },
  keluhan: { baru: 1, kritis: 0, terbuka: 3 },
  sisa_makanan_kg: 3.2,
};

test('rekap: format rupiah', () => {
  assert.equal(rekap.rupiah('28750000.00'), 'Rp 28.750.000');
  assert.equal(rekap.rupiahRingkas('312400000.00'), 'Rp312,4 jt');
  assert.equal(rekap.rupiahRingkas('1200000000'), 'Rp1,2 M');
  assert.equal(rekap.rupiahRingkas('850000'), 'Rp850 rb');
  assert.equal(rekap.rupiahRingkas('500'), 'Rp500');
  assert.equal(rekap.rupiahRingkas(null), 'Rp0');
});

test('rekap: ambang anggaran 80/100 dan mutu', () => {
  assert.equal(rekap.anggaranTone(79.9), 'ok');
  assert.equal(rekap.anggaranTone(80), 'warn');
  assert.equal(rekap.anggaranTone(99.9), 'warn');
  assert.equal(rekap.anggaranTone(100), 'danger');
  assert.equal(rekap.mutuStatus({ batch: 2, layak: 2 }).text, 'Layak');
  assert.equal(rekap.mutuStatus({ batch: 2, layak: 1, tidak_layak: 1 }).tone, 'danger');
  assert.equal(rekap.mutuStatus({ batch: 2, layak: 1, belum_diperiksa: 1 }).text, 'Belum lengkap');
  assert.equal(rekap.mutuStatus({ batch: 0 }).tone, 'muted');
});

test('rekap: ringkasan -> kartu KPI', () => {
  const cards = rekap.kpiCards(RINGKASAN);
  assert.deepEqual(cards.map((c) => c.id), ['porsi', 'distribusi', 'mutu', 'stok', 'anggaran', 'keluhan', 'sisa']);
  const by = Object.fromEntries(cards.map((c) => [c.id, c]));
  assert.equal(by.porsi.value, '1.180 / 1.200');
  assert.equal(by.porsi.tone, 'ok');
  assert.equal(by.porsi.progress, 98.33);
  assert.equal(by.distribusi.value, '3 / 4 titik');
  assert.equal(by.distribusi.progress, 75);
  assert.equal(by.distribusi.tone, 'warn', 'ada terlambat');
  assert.match(by.distribusi.sub, /1 terlambat/);
  assert.equal(by.mutu.value, 'Layak');
  assert.equal(by.stok.value, '2 bahan');
  assert.equal(by.stok.tone, 'warn');
  assert.equal(by.anggaran.value, 'Rp28,8 jt');
  assert.equal(by.anggaran.progress, 42.5);
  assert.equal(by.anggaran.tone, 'ok');
  assert.equal(by.keluhan.value, '3');
  assert.equal(by.keluhan.tone, 'warn');
  assert.equal(by.sisa.value, '3,2 kg');
  assert.deepEqual(rekap.kpiCards(null), []);
  const kosong = rekap.kpiCards({ porsi: { target: 0, realisasi: 0, capaian_persen: 0 } });
  assert.equal(kosong[0].tone, 'muted');
  const over = rekap.kpiCards({ ...RINGKASAN, keuangan: { ...RINGKASAN.keuangan, serapan_persen: 120 }, keluhan: { baru: 0, kritis: 1, terbuka: 1 } });
  assert.equal(over.find((c) => c.id === 'anggaran').progress, 100, 'bilah dibatasi 100');
  assert.equal(over.find((c) => c.id === 'anggaran').tone, 'danger');
  assert.equal(over.find((c) => c.id === 'keluhan').tone, 'danger');
});

test('rekap: seri grafik distribusi dan tren', () => {
  const d = rekap.distribusiSeries(RINGKASAN);
  assert.deepEqual(d.items.map((x) => x.value), [2, 1, 0, 1]);
  assert.equal(d.total, 4);
  const snaps = [1, 2, 3, 4, 5, 6, 7, 8].map((i) => ({ tanggal: `2026-10-${String(20 + i).padStart(2, '0')}`, ringkasan: { porsi: { capaian_persen: 90 + i } } }));
  const t = rekap.trendSeries(snaps.reverse(), RINGKASAN);
  assert.equal(t.values.length, 7);
  assert.equal(t.tanggal.at(-1), '2026-11-02');
  assert.equal(t.values.at(-1), 98.33);
  assert.equal(t.aktif, 6);
  assert.equal(t.labels.at(-1), '2/11');
  const replace = rekap.trendSeries([{ tanggal: '2026-11-02', ringkasan: { porsi: { capaian_persen: 10 } } }], RINGKASAN);
  assert.deepEqual(replace.values, [98.33], 'ringkasan langsung menggantikan snapshot tanggal sama');
  assert.deepEqual(rekap.trendSeries([], null).values, []);
  assert.equal(rekap.labelTanggalPendek('2026-12-29'), '29/12');
  assert.equal(rekap.withAlpha('#2481cc', 0.4), '#2481cc66');
  assert.equal(rekap.withAlpha('rgb(1,2,3)', 0.4), 'rgb(1,2,3)');
});

test('rekap: validator dan payload', () => {
  assert.equal(rekap.validasiTanggalRekap('2026-11-03', '2026-11-02'), 'Tanggal tidak boleh di masa depan.');
  assert.equal(rekap.validasiTanggalRekap('2026-11-02', '2026-11-02'), '');
  assert.match(rekap.validasiCatatan('x'.repeat(1001)), /1000/);
  assert.equal(rekap.validasiCatatan('ok'), '');
  assert.deepEqual(rekap.rekapPayload({ tanggal: '2026-11-02', catatan: ' Hujan ', kirim: 'true' }), { tanggal: '2026-11-02', catatan: 'Hujan', kirim: true });
  assert.deepEqual(rekap.rekapPayload({ tanggal: '2026-11-02', catatan: '', kirim: 'false' }), { tanggal: '2026-11-02', kirim: false });
  assert.deepEqual(rekap.rekapPayload({ tanggal: null, catatan: null, kirim: null }), {});
  assert.equal(rekap.statusKirimLabel('tidak_terkirim'), 'Tidak terkirim');
  assert.equal(rekap.ringkasanKosong({ porsi: { jumlah_jadwal: 0 }, distribusi: { total: 0 } }), true);
  assert.equal(rekap.ringkasanKosong(RINGKASAN), false);
});
