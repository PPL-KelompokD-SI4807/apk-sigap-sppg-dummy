// Tes unit Dashboard R-07 (logika murni): node --test resources/miniapp/tests/
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  rentangValid, bangunQuery, formatPersen, formatAngka, singkatRupiah, penandaAnggaran, statusAnggaran, tampilSel, tampilTotalSamar,
  widgetTampil, labelPeriode, deretGrafik, ringkasanDeret, pasanganSkalar, jumlahBuktiBelumAda, pilihanSppg, pesanGalat, judulWidget, periodeWidget,
} from '../modules/dashboard/logika.js';

test('rentang hanya 7 atau 30, selain itu bawaan 7', () => {
  assert.equal(rentangValid(30), 30);
  assert.equal(rentangValid('30'), 30);
  for (const v of [0, 14, 'x', null, undefined]) assert.equal(rentangValid(v), 7, String(v));
});

test('query: sppg_id hanya bila bilangan bulat positif', () => {
  assert.deepEqual(bangunQuery({ rentang: 30, sppgId: 2 }), { rentang: 30, sppg_id: 2 });
  assert.deepEqual(bangunQuery({ rentang: 7, sppgId: null }), { rentang: 7 });
  assert.deepEqual(bangunQuery({ rentang: 99, sppgId: '' }), { rentang: 7 });
  assert.deepEqual(bangunQuery({ sppgId: 'abc' }), { rentang: 7 });
  assert.deepEqual(bangunQuery(), { rentang: 7 });
});

test('persen null tampil "-" dan angka berformat Indonesia', () => {
  assert.equal(formatPersen(null), '-');
  assert.equal(formatPersen(undefined), '-');
  assert.equal(formatPersen(76.67), '76,67%');
  assert.equal(formatPersen(50), '50%');
  assert.equal(formatPersen(0), '0%');
  assert.equal(formatAngka(1234567), '1.234.567');
  assert.equal(formatAngka(1.5), '1,5');
  assert.equal(formatAngka(null), '-');
  assert.equal(singkatRupiah(1500000), '1,5jt');
  assert.equal(singkatRupiah(23000000), '23jt');
});

test('penanda anggaran: ambang kuning, 100 oranye, melampaui merah, dengan prioritas', () => {
  assert.equal(penandaAnggaran({ mencapai_ambang: false, mencapai_100: false, melampaui: false }), null);
  assert.equal(penandaAnggaran(null), null);
  const a = penandaAnggaran({ ambang_peringatan_persen: 80, mencapai_ambang: true });
  assert.equal(a.kunci, 'mencapai_ambang');
  assert.equal(a.warna, 'kuning');
  assert.match(a.label, /80%/);
  assert.equal(penandaAnggaran({ mencapai_ambang: true, mencapai_100: true }).warna, 'oranye');
  const m = penandaAnggaran({ mencapai_ambang: true, mencapai_100: true, melampaui: true });
  assert.equal(m.kunci, 'melampaui');
  assert.equal(m.warna, 'merah');
});

test('status anggaran punya label teks, status tak dikenal tidak crash', () => {
  for (const s of ['belum_ada_anggaran', 'aman', 'peringatan', 'habis', 'melampaui']) assert.ok(statusAnggaran(s).label.length > 0, s);
  assert.equal(statusAnggaran('baru').label, 'baru');
  assert.equal(statusAnggaran(null).label, '-');
});

test('penyamaran: <5 ditampilkan apa adanya dan tidak dihitung ulang', () => {
  assert.deepEqual(tampilSel({ jumlah: null, penanda: '<5' }), { teks: '<5', disamarkan: true });
  assert.deepEqual(tampilSel({ jumlah: 0, penanda: null }), { teks: '0', disamarkan: false });
  assert.deepEqual(tampilSel({ jumlah: 12, penanda: null }), { teks: '12', disamarkan: false });
  assert.deepEqual(tampilSel(null), { teks: '-', disamarkan: false });
  assert.equal(tampilTotalSamar(null).disamarkan, true);
  assert.equal(tampilTotalSamar(7).teks, '7');
});

test('widget null disembunyikan; widgets kosong tidak menghasilkan kartu', () => {
  const w = widgetTampil({ a: { x: 1 }, b: null, c: undefined, d: 'teks', e: { y: 2 } });
  assert.deepEqual(w.map(([k]) => k), ['a', 'e']);
  assert.deepEqual(widgetTampil({}), []);
  assert.deepEqual(widgetTampil(null), []);
  assert.deepEqual(widgetTampil([1, 2]), []);
});

test('keterangan periode per jenis', () => {
  assert.equal(labelPeriode({ jenis: 'hari_ini', tanggal: '2026-11-02' }), 'Hari ini (2 Nov 2026)');
  assert.equal(labelPeriode({ jenis: 'bulan_berjalan', bulan: '2026-11' }), 'Bulan berjalan (November 2026)');
  assert.equal(labelPeriode({ jenis: 'tren', rentang_hari: 7, dari: '2026-10-27', sampai: '2026-11-02' }), 'Tren 7 hari (27 Okt 2026 s.d. 2 Nov 2026)');
  assert.equal(labelPeriode({ jenis: 'saat_ini', per: '2026-11-02' }), 'Saat ini (per 2 Nov 2026)');
  assert.equal(labelPeriode({ jenis: 'asing' }), '');
  assert.equal(labelPeriode(undefined), '');
  assert.equal(periodeWidget({ tren: { periode: { jenis: 'tren' } } }).jenis, 'tren');
});

test('deret grafik: labels + series disejajarkan, tidak valid -> null', () => {
  const d = deretGrafik({ labels: ['2026-11-01', '2026-11-02'], series: { a: [1], b: [2, 3], c: 'x' } });
  assert.deepEqual(d.series, { a: [1, 0], b: [2, 3] });
  assert.equal(deretGrafik({ labels: ['x'], series: {} }), null);
  assert.equal(deretGrafik(undefined), null);
  assert.deepEqual(Object.keys(deretGrafik({ labels: ['x'], series: { a: [1], b: [2] } }, ['b']).series), ['b']);
  assert.match(ringkasanDeret(d, 'Tren'), /2 titik data/);
  assert.match(ringkasanDeret(null, 'Tren'), /tidak ada data/);
});

test('widget pengganti/kosong tidak melempar galat', () => {
  assert.equal(pasanganSkalar(null).length, 0);
  assert.deepEqual(pasanganSkalar({ periode: {}, a_b: 3, obj: {}, list: [], t: null }), [['A b', 3], ['T', null]]);
  assert.equal(jumlahBuktiBelumAda({ bukti_belum_ada: { jumlah: 4 } }), 4);
  assert.equal(jumlahBuktiBelumAda({ bukti_belum_ada: { jumlah: 0 } }), 0);
  assert.equal(jumlahBuktiBelumAda({}), 0);
  assert.equal(jumlahBuktiBelumAda(null), 0);
  assert.equal(judulWidget('kunci_baru_x'), 'Kunci baru x');
  assert.deepEqual(pilihanSppg({ sppg_pilihan: null }), []);
  assert.equal(pilihanSppg({ sppg_pilihan: [{ id: 1, nama: 'A' }, null, { nama: 'x' }] }).length, 1);
});

test('galat API dipetakan ke pesan Indonesia', () => {
  assert.match(pesanGalat({ status: 403, data: { alasan: 'perlu_login_penuh' } }), /login/i);
  assert.match(pesanGalat({ status: 403, data: { alasan: 'sppg_aktif_ditolak' } }), /bukan milik/);
  assert.match(pesanGalat({ status: 403, data: { alasan: 'tanpa_sppg' } }), /belum ditautkan/);
  assert.match(pesanGalat({ status: 403 }), /hak akses/);
  assert.match(pesanGalat({ status: 422 }), /tidak valid/);
  assert.match(pesanGalat({ status: 429 }), /Terlalu banyak/);
  assert.match(pesanGalat({ status: 401 }), /Sesi/);
  assert.match(pesanGalat({ status: 0 }), /koneksi/);
  assert.match(pesanGalat({ status: 500 }), /gangguan/);
});
