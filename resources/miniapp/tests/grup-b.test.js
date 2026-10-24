// Tes unit modul grup B (logika murni): node --test resources/miniapp/tests/
import test from 'node:test';
import assert from 'node:assert/strict';
import { can, perms, userPerms } from '../core/permissions.js';
import { mapValidationErrors, errorMessage } from '../core/errors.js';
import * as bahan from '../modules/bahan-baku/schema.js';
import * as menu from '../modules/menu-gizi/schema.js';
import * as jadwal from '../modules/jadwal/schema.js';
import * as log from '../modules/log-produksi/schema.js';
import * as mutu from '../modules/mutu/schema.js';

const names = (f) => f.map((x) => x.name);

// ---------- hak akses ----------
test('hak akses grup B sesuai Tabel 5', () => {
  assert.equal(can('staf_gudang', 'bahan-baku', 'U'), true);
  assert.equal(can('kepala_sppg', 'bahan-baku', 'C'), false);
  assert.equal(can('relawan', 'bahan-baku', 'R'), false);
  assert.equal(can('ahli_gizi', 'menu-gizi', 'C'), true);
  assert.equal(can('staf_gudang', 'menu-gizi', 'C'), false);
  assert.equal(can('relawan', 'menu-gizi', 'R'), true);
  assert.equal(can('kepala_sppg', 'log-produksi', 'C'), false);
  assert.equal(can('kepala_sppg', 'log-produksi', 'D'), true);
  assert.equal(can('relawan', 'log-produksi', 'D'), false);
  assert.equal(can('ahli_gizi', 'mutu', 'D'), true);
  assert.equal(can('kepala_sppg', 'mutu', 'U'), false);
  assert.equal(can('staf_gudang', 'mutu', 'R'), false);
  assert.equal(userPerms({ role: 'ahli_gizi', modules: ['menu-gizi'] }, 'mutu').R, false, 'modul tak ada di user.modules');
  assert.equal(perms('relawan', 'jadwal').C, true);
});

test('unit relawan: dapur = produksi, distribusi = distribusi, peran lain keduanya', () => {
  assert.equal(jadwal.unitBoleh({ role: 'relawan', unit: 'dapur' }, 'produksi'), true);
  assert.equal(jadwal.unitBoleh({ role: 'relawan', unit: 'dapur' }, 'distribusi'), false);
  assert.equal(jadwal.unitBoleh({ role: 'relawan', unit: 'distribusi' }, 'produksi'), false);
  assert.equal(jadwal.unitBoleh({ role: 'relawan', unit: 'distribusi' }, 'distribusi'), true);
  assert.equal(jadwal.unitBoleh({ role: 'relawan', unit: null }, 'produksi'), false);
  assert.equal(jadwal.unitBoleh({ role: 'kepala_sppg' }, 'distribusi'), true);
  assert.equal(jadwal.unitBoleh(null, 'produksi'), false);
});

// ---------- bahan baku ----------
test('bahan: skema + payload', () => {
  assert.deepEqual(names(bahan.bahanFields({ mode: 'update', user: { role: 'staf_gudang' } })), ['nama', 'kode', 'kategori', 'satuan', 'stok_minimum', 'pemasok_id']);
  assert.equal(names(bahan.bahanFields({ mode: 'create', user: { role: 'admin' } }))[0], 'sppg_id');
  const p = bahan.mapPayload({ nama: ' Beras ', kode: '', kategori: 'karbohidrat', satuan: 'kg', stok_minimum: '50', pemasok_id: '3', stok: 99 });
  assert.deepEqual(p, { nama: 'Beras', kode: null, kategori: 'karbohidrat', satuan: 'kg', stok_minimum: 50, pemasok_id: 3 });
  assert.equal('stok' in p, false, 'stok tidak boleh dikirim lewat form bahan');
});

test('bahan: penanda stok menipis / kedaluwarsa', () => {
  assert.deepEqual(bahan.penandaBahan({ stok_menipis: true, akan_kedaluwarsa: true }).map((b) => b.text), ['Stok menipis', 'Kedaluwarsa dekat']);
  assert.deepEqual(bahan.penandaBahan({}), []);
  assert.equal(bahan.sisaHariText(-2), 'Lewat 2 hari');
  assert.equal(bahan.sisaHariText(0), 'Kedaluwarsa hari ini');
  assert.equal(bahan.sisaHariText(5), '5 hari lagi');
  assert.equal(bahan.sisaHariCls(-1), 'badge-danger');
  assert.equal(bahan.sisaHariCls(2), 'badge-warn');
  assert.equal(bahan.sisaHariCls(10), 'badge-ok');
});

test('mutasi: validasi bantu klien', () => {
  assert.equal(bahan.cekMutasi({ jenis: 'masuk', jumlah: 10 }, 5).jumlah, undefined);
  assert.match(bahan.cekMutasi({ jenis: 'masuk', jumlah: 0 }, 5).jumlah, /lebih dari 0/);
  assert.match(bahan.cekMutasi({ jenis: 'keluar', jumlah: 6, catatan: 'x' }, 5).jumlah, /melebihi stok/);
  assert.equal(bahan.cekMutasi({ jenis: 'keluar', jumlah: 5, catatan: 'x' }, 5).jumlah, undefined);
  assert.match(bahan.cekMutasi({ jenis: 'keluar', jumlah: 1 }, 5).catatan, /alasan atau pilih jadwal/);
  assert.equal(bahan.cekMutasi({ jenis: 'keluar', jumlah: 1, jadwal_produksi_id: 3 }, 5).catatan, undefined);
  assert.match(bahan.cekMutasi({ jenis: 'penyesuaian', jumlah: 0, catatan: 'x' }, 5).jumlah, /tidak boleh 0/);
  assert.match(bahan.cekMutasi({ jenis: 'penyesuaian', jumlah: -2 }, 5).catatan, /Alasan/);
  assert.match(bahan.cekMutasi({ jenis: 'penyesuaian', jumlah: -9, catatan: 'x' }, 5).jumlah, /negatif/);
  assert.equal(Object.keys(bahan.cekMutasi({ jenis: 'penyesuaian', jumlah: -2, catatan: 'susut' }, 5)).length, 0);
  assert.match(bahan.cekMutasi({ jenis: 'masuk', jumlah: 1.2345 }, 5).jumlah, /3 angka desimal/);
  assert.match(bahan.cekMutasi({ jenis: 'masuk', jumlah: '' }, 5).jumlah, /wajib/);
});

test('mutasi: payload hanya kolom relevan menurut jenis', () => {
  const v = { jenis: 'masuk', jumlah: '100', tanggal_mutasi: '2026-11-02', pemasok_id: '3', harga_satuan: '14500', tanggal_kedaluwarsa: '2027-01-15', jadwal_produksi_id: '9', catatan: '' };
  assert.deepEqual(bahan.mutasiPayload(v), { jenis: 'masuk', jumlah: 100, tanggal_mutasi: '2026-11-02', harga_satuan: 14500, pemasok_id: 3, tanggal_kedaluwarsa: '2027-01-15', catatan: null });
  assert.deepEqual(bahan.mutasiPayload({ ...v, jenis: 'keluar', jumlah: 25.5 }), { jenis: 'keluar', jumlah: 25.5, tanggal_mutasi: '2026-11-02', jadwal_produksi_id: 9, catatan: null });
  assert.deepEqual(bahan.mutasiPayload({ jenis: 'penyesuaian', jumlah: -2, catatan: 'Susut' }), { jenis: 'penyesuaian', jumlah: -2, catatan: 'Susut' });
});

// ---------- menu & gizi ----------
test('menu: komposisi dinormalkan dan divalidasi', () => {
  assert.deepEqual(menu.normalisasiKomposisi([{ bahan_baku_id: '4', gram_per_porsi: '120' }, { bahan_baku_id: '', gram_per_porsi: '' }]), [{ bahan_baku_id: 4, gram_per_porsi: 120 }]);
  assert.equal(menu.validasiKomposisi([{ bahan_baku_id: 4, gram_per_porsi: 120 }, { bahan_baku_id: 9, gram_per_porsi: 60 }]), '');
  assert.equal(menu.validasiKomposisi([]), '');
  assert.match(menu.validasiKomposisi([{ bahan_baku_id: '', gram_per_porsi: 10 }]), /pilih bahan/);
  assert.match(menu.validasiKomposisi([{ bahan_baku_id: 1, gram_per_porsi: '' }]), /takaran/);
  assert.match(menu.validasiKomposisi([{ bahan_baku_id: 1, gram_per_porsi: 0 }]), /lebih dari 0/);
  assert.match(menu.validasiKomposisi([{ bahan_baku_id: 1, gram_per_porsi: 5 }, { bahan_baku_id: '1', gram_per_porsi: 6 }]), /ganda/);
  assert.match(menu.validasiKomposisi(Array.from({ length: 51 }, (_, i) => ({ bahan_baku_id: i + 1, gram_per_porsi: 1 }))), /Maksimal 50/);
});

test('menu: payload memuat komposisi dan angka', () => {
  const p = menu.mapPayload({ nama_menu: ' Nasi Ayam ', kelompok_sasaran: 'siswa', tanggal_sajian: '', siklus: 'Siklus 1', energi_kkal: '620', protein_g: 22, lemak_g: 18, karbohidrat_g: 90, serat_g: 7, catatan: null, bahan: [{ bahan_baku_id: '4', gram_per_porsi: '120' }] });
  assert.equal(p.nama_menu, 'Nasi Ayam');
  assert.equal(p.tanggal_sajian, null);
  assert.equal(p.energi_kkal, 620);
  assert.deepEqual(p.bahan, [{ bahan_baku_id: 4, gram_per_porsi: 120 }]);
  assert.equal('bahan' in menu.mapPayload({ nama_menu: 'x', kelompok_sasaran: 'siswa' }), false, 'tanpa bahan di form = tidak dikirim');
});

test('menu: peringatan gizi di bawah/atas standar (tidak memblokir)', () => {
  const standar = { energi_min: 500, energi_max: 700, protein_min: 18, protein_max: 30, lemak_min: 10, lemak_max: 25, karbohidrat_min: 60, karbohidrat_max: 100, serat_min: 5, serat_max: 12 };
  assert.deepEqual(menu.hitungPeringatanGizi({ energi_kkal: 600, protein_g: 20, lemak_g: 15, karbohidrat_g: 80, serat_g: 6 }, standar), []);
  const w = menu.hitungPeringatanGizi({ energi_kkal: 400, protein_g: 15, lemak_g: 30, karbohidrat_g: 80, serat_g: '' }, standar);
  assert.deepEqual(w.map((x) => [x.kolom, x.status]), [['energi_kkal', 'kurang'], ['protein_g', 'kurang'], ['lemak_g', 'lebih']]);
  assert.equal(w[1].pesan, 'Protein 15 g di bawah standar minimum 18 g.');
  assert.deepEqual(menu.hitungPeringatanGizi({ energi_kkal: 1 }, null), []);
});

test('standar gizi: skema, validasi max >= min, payload', () => {
  const f = menu.standarFields();
  assert.equal(f.length, 11);
  const max = f.find((x) => x.name === 'protein_max');
  assert.match(max.validate(10, { protein_min: 18 }), /Maksimum harus/);
  assert.equal(max.validate(20, { protein_min: 18 }), '');
  const p = menu.standarPayload({ kelompok_sasaran: 'balita', energi_min: '300', energi_max: '500', protein_min: 10, protein_max: 20, lemak_min: 5, lemak_max: 15, karbohidrat_min: 40, karbohidrat_max: 70, serat_min: 3, serat_max: 8 });
  assert.equal(p.energi_min, 300);
  assert.equal(Object.keys(p).length, 11);
  assert.equal(menu.rentang({ energi_min: 500, energi_max: 700 }, menu.GIZI[0]), '500 - 700 kkal');
});

// ---------- jadwal ----------
test('jadwal: transisi status valid', () => {
  assert.deepEqual(jadwal.transisiValid('produksi', 'direncanakan'), ['dimasak', 'dibatalkan']);
  assert.deepEqual(jadwal.transisiValid('produksi', 'dimasak'), ['selesai', 'dibatalkan']);
  assert.deepEqual(jadwal.transisiValid('produksi', 'selesai'), []);
  assert.deepEqual(jadwal.transisiValid('produksi', 'dibatalkan'), []);
  assert.deepEqual(jadwal.transisiValid('distribusi', 'menunggu'), ['siap', 'dibatalkan']);
  assert.deepEqual(jadwal.transisiValid('distribusi', 'siap'), ['berangkat', 'dibatalkan']);
  assert.deepEqual(jadwal.transisiValid('distribusi', 'berangkat'), ['diterima', 'dibatalkan']);
  assert.deepEqual(jadwal.transisiValid('distribusi', 'diterima'), []);
  assert.deepEqual(jadwal.transisiValid('distribusi', 'nonsens'), []);
  const x = jadwal.transisiValid('produksi', 'direncanakan'); x.push('selesai');
  assert.deepEqual(jadwal.transisiValid('produksi', 'direncanakan'), ['dimasak', 'dibatalkan'], 'tidak termutasi');
  assert.equal(jadwal.aksiLabel('produksi', 'dimasak'), 'Mulai masak');
});

test('jadwal: sisa kapasitas harian', () => {
  const j = [{ id: 1, target_porsi: 1500, status: 'direncanakan' }, { id: 2, target_porsi: 500, status: 'dimasak' }, { id: 3, target_porsi: 900, status: 'dibatalkan' }];
  assert.deepEqual(jadwal.hitungKapasitas({ kapasitas: 3000, jadwal: j, target: 1000 }), { kapasitas: 3000, terjadwal: 2000, sisa: 1000, melebihi: false });
  assert.equal(jadwal.hitungKapasitas({ kapasitas: 3000, jadwal: j, target: 1500 }).melebihi, true);
  assert.equal(jadwal.hitungKapasitas({ kapasitas: 3000, jadwal: j, excludeId: 1, target: 2500 }).melebihi, false, 'jadwal yang diubah tidak dihitung ganda');
  assert.equal(jadwal.hitungKapasitas({ kapasitas: null, jadwal: j, target: 99999 }).melebihi, false);
  assert.equal(jadwal.hitungKapasitas({ kapasitas: 1000, jadwal: j }).sisa, 0);
  assert.equal(jadwal.sisaPorsiDistribusi({ target_porsi: 1000, porsi_terjadwal_distribusi: 700 }), 300);
  assert.equal(jadwal.sisaPorsiDistribusi({ target_porsi: 1000, porsi_terjadwal_distribusi: 700 }, 200), 500);
  assert.equal(jadwal.sisaPorsiDistribusi(null), null);
});

test('jadwal: skema produksi, jadwal berjalan hanya catatan', () => {
  const baru = jadwal.produksiFields({ mode: 'create' });
  assert.equal(baru.every((f) => !f.readonly), true);
  const jalan = jadwal.produksiFields({ mode: 'update', record: { status: 'dimasak' } });
  assert.deepEqual(jalan.filter((f) => !f.readonly).map((f) => f.name), ['catatan']);
  const rencana = jadwal.produksiFields({ mode: 'update', record: { status: 'direncanakan' } });
  assert.equal(rencana.every((f) => !f.readonly), true);
  const tgl = baru.find((f) => f.name === 'tanggal_produksi');
  assert.match(tgl.validate('2000-01-01'), /sebelum hari ini/);
  assert.equal(jadwal.produksiFields({ mode: 'update', record: { status: 'direncanakan' } }).find((f) => f.name === 'tanggal_produksi').validate('2000-01-01'), '', 'tanggal lama boleh saat ubah');
  assert.deepEqual(jadwal.produksiPayload({ menu_id: '5', tanggal_produksi: '2026-11-02', jam_mulai_masak: '', target_porsi: '2500', catatan: '' }), { menu_id: 5, tanggal_produksi: '2026-11-02', jam_mulai_masak: null, target_porsi: 2500, catatan: null });
  assert.deepEqual(jadwal.produksiPayload({ catatan: 'x' }), { catatan: 'x' }, 'kolom readonly tak ikut terkirim');
});

test('jadwal: skema distribusi + validasi jam + payload', () => {
  const f = jadwal.distribusiFields({ mode: 'create' });
  assert.equal(f[0].name, 'jadwal_produksi_id');
  assert.equal(jadwal.distribusiFields({ mode: 'update', record: { status: 'siap' } }).some((x) => x.name === 'jadwal_produksi_id'), false);
  const jb = f.find((x) => x.name === 'jam_berangkat');
  assert.match(jb.validate(null, { armada_id: '2' }), /wajib/);
  assert.equal(jb.validate('08:30', { armada_id: '2' }), '');
  assert.equal(jb.validate(null, {}), '');
  const jt = f.find((x) => x.name === 'jam_rencana_tiba');
  assert.match(jt.validate('08:00', { jam_berangkat: '08:30' }), /setelah jam berangkat/);
  assert.equal(jt.validate('09:15', { jam_berangkat: '08:30' }), '');
  const berjalan = jadwal.distribusiFields({ mode: 'update', record: { status: 'berangkat' } });
  assert.deepEqual(berjalan.filter((x) => !x.readonly).map((x) => x.name), ['catatan']);
  const armada = f.find((x) => x.name === 'armada_id').relation;
  assert.equal(armada.filter({ status: 'servis' }), false);
  assert.equal(armada.filter({ status: 'tersedia' }), true);
  assert.deepEqual(jadwal.distribusiPayload({ jadwal_produksi_id: '12', titik_layanan_id: '3', armada_id: '', pengemudi_id: '4', jam_berangkat: '08:30', jam_rencana_tiba: '', jumlah_porsi: '300' }),
    { jadwal_produksi_id: 12, titik_layanan_id: 3, armada_id: null, pengemudi_id: 4, jam_berangkat: '08:30', jam_rencana_tiba: null, jumlah_porsi: 300 });
});

test('jadwal: hak per baris (kepemilikan relawan, status yang dapat dihapus)', () => {
  const p = { C: true, R: true, U: true, D: true };
  const relawan = { role: 'relawan', id: 7 };
  assert.equal(jadwal.rowPermsJadwal('produksi', relawan, { dibuat_oleh: 7, status: 'direncanakan' }, p).U, true);
  assert.equal(jadwal.rowPermsJadwal('produksi', relawan, { dibuat_oleh: 8, status: 'direncanakan' }, p).U, false);
  assert.equal(jadwal.rowPermsJadwal('produksi', { role: 'kepala_sppg', id: 1 }, { dibuat_oleh: 8, status: 'direncanakan' }, p).U, true);
  assert.equal(jadwal.rowPermsJadwal('produksi', { role: 'kepala_sppg', id: 1 }, { status: 'dimasak' }, p).D, false);
  assert.equal(jadwal.rowPermsJadwal('produksi', { role: 'kepala_sppg', id: 1 }, { status: 'dibatalkan' }, p).D, true);
  assert.equal(jadwal.rowPermsJadwal('distribusi', { role: 'kepala_sppg', id: 1 }, { status: 'berangkat' }, p).D, false);
  assert.equal(jadwal.rowPermsJadwal('distribusi', { role: 'kepala_sppg', id: 1 }, { status: 'siap' }, p).D, true);
  assert.equal(jadwal.rowPermsJadwal('produksi', { role: 'ahli_gizi', id: 1 }, { status: 'direncanakan' }, { ...p, D: false }).D, false);
});

test('jadwal: kebutuhan bahan, baris tak dapat dibandingkan diberi catatan', () => {
  const cukup = { dapat_dibandingkan: true, kekurangan: 0, cukup: true };
  const kurang = { dapat_dibandingkan: true, kekurangan: 5, cukup: false };
  const tak = { dapat_dibandingkan: false, kekurangan: null, cukup: null, satuan: 'butir', kebutuhan_gram: 1500 };
  assert.equal(jadwal.statusKebutuhan(cukup), 'cukup');
  assert.equal(jadwal.statusKebutuhan(kurang), 'kurang');
  assert.equal(jadwal.statusKebutuhan(tak), 'tidak_dapat');
  assert.deepEqual(jadwal.ringkasKebutuhan({ bahan: [cukup, kurang, tak, kurang] }), { kurang: 2, tidakDapat: 1, cukup: 1, total: 4 });
  assert.deepEqual(jadwal.ringkasKebutuhan(null), { kurang: 0, tidakDapat: 0, cukup: 0, total: 0 });
  assert.match(jadwal.catatanTidakDapat(tak), /butir.*tidak dapat dikonversi.*1\.500 g/);
});

test('galat 422 kapasitas/bentrok dipetakan ke kolomnya', () => {
  const errors = { target_porsi: ['Total jadwal tanggal 2026-11-02 menjadi 3500 porsi, melebihi kapasitas harian 3000 porsi. Sisa kapasitas 1000 porsi.'], armada_id: ['Armada bentrok jam.'] };
  const m = mapValidationErrors(errors);
  assert.match(m.target_porsi, /Sisa kapasitas 1000 porsi/);
  assert.equal(m.armada_id, 'Armada bentrok jam.');
  assert.match(errorMessage({ status: 422, errors }), /melebihi kapasitas/);
  assert.match(errorMessage({ status: 422, errors: { status: ['Distribusi tidak dapat berangkat: mutu belum Layak.'] } }), /mutu belum Layak/);
});

// ---------- log produksi ----------
test('log produksi: selisih, capaian, peringatan', () => {
  assert.deepEqual(log.hitungCapaian(980, 1000), { selisih: -20, persen: 98, peringatan: 'Realisasi 20 porsi di bawah target.' });
  assert.deepEqual(log.hitungCapaian(1000, 1000), { selisih: 0, persen: 100, peringatan: null });
  assert.equal(log.hitungCapaian(1050, 1000).peringatan, 'Realisasi 50 porsi di atas target.');
  assert.equal(log.hitungCapaian(333, 1000).persen, 33.3);
  assert.equal(log.hitungCapaian('', 1000), null);
  assert.equal(log.hitungCapaian(10, null), null);
  assert.equal(log.hitungCapaian(10, 0), null);
  assert.equal(log.selisihText(20), '+20 porsi');
  assert.equal(log.selisihText(-30), '-30 porsi');
  assert.equal(log.selisihText(null), '-');
});

test('log produksi: skema (kode batch readonly), payload, hak per baris', () => {
  const c = log.logFields({ mode: 'create' });
  assert.equal(c[0].name, 'jadwal_produksi_id');
  assert.equal(c.find((f) => f.name === 'kode_batch').readonly, true);
  assert.equal(c.some((f) => f.type === 'photo'), false, 'unggah foto belum tersedia di backend');
  assert.equal(log.logFields({ mode: 'update', record: { kode_batch: 'BATCH-1' } }).some((f) => f.name === 'jadwal_produksi_id'), false);
  const jsf = c.find((f) => f.name === 'jam_selesai');
  assert.match(jsf.validate('03:00', { waktu_mulai: '03:10' }), /setelah jam mulai/);
  assert.equal(jsf.validate('08:20', { waktu_mulai: '03:10' }), '');
  assert.deepEqual(log.logPayload({ jadwal_produksi_id: '12', realisasi_porsi: '980', sisa_makanan_kg: '3.5', waktu_mulai: '03:10', jam_selesai: '', kendala: 'Gas', kode_batch: 'X' }, { mode: 'create' }),
    { jadwal_produksi_id: 12, realisasi_porsi: 980, sisa_makanan_kg: 3.5, waktu_mulai: '03:10', jam_selesai: null, kendala: 'Gas' });
  assert.equal('jadwal_produksi_id' in log.logPayload({ jadwal_produksi_id: '12', realisasi_porsi: 1 }, { mode: 'update' }), false);
  const p = { C: false, R: true, U: true, D: false };
  assert.equal(log.rowPermsLog({ role: 'relawan', id: 3 }, { dicatat_oleh: 4 }, p).U, false);
  assert.equal(log.rowPermsLog({ role: 'relawan', id: 3 }, { dicatat_oleh: 3 }, p).U, true);
  assert.equal(log.rowPermsLog({ role: 'kepala_sppg', id: 1 }, { dicatat_oleh: 3 }, p).U, true);
});

// ---------- mutu ----------
test('mutu: konsistensi hasil layak dengan checklist', () => {
  const ok = { hasil: 'layak', kebersihan_dapur: 'true', kebersihan_petugas: 'true', organoleptik_baik: 'true', suhu_makanan_c: 72.5 };
  assert.equal(mutu.cekKonsistensiMutu(ok), '');
  assert.equal(mutu.cekKonsistensiMutu({ ...ok, suhu_makanan_c: null }), '', 'suhu opsional');
  assert.match(mutu.cekKonsistensiMutu({ ...ok, suhu_makanan_c: 55 }), /suhu di bawah 60/);
  assert.match(mutu.cekKonsistensiMutu({ ...ok, kebersihan_dapur: 'false' }), /kebersihan dapur/);
  assert.match(mutu.cekKonsistensiMutu({ ...ok, organoleptik_baik: 'false' }), /rasa\/aroma/);
  assert.equal(mutu.cekKonsistensiMutu({ ...ok, organoleptik_baik: 'false', hasil: 'tidak_layak' }), '');
});

test('mutu: skema bergantung mode, koreksi wajib bila memengaruhi distribusi', () => {
  const baru = mutu.mutuFields({ mode: 'create' });
  assert.equal(baru[0].name, 'jadwal_produksi_id');
  assert.equal(baru.some((f) => f.name === 'catatan_koreksi'), false);
  assert.equal(baru.some((f) => f.type === 'photo'), false);
  const ubahBiasa = mutu.mutuFields({ mode: 'update', record: { memengaruhi_distribusi: false } });
  assert.equal(ubahBiasa.some((f) => f.name === 'catatan_koreksi'), false);
  const koreksi = mutu.mutuFields({ mode: 'update', record: { memengaruhi_distribusi: true } });
  assert.equal(koreksi.find((f) => f.name === 'catatan_koreksi').required, true);
  const tk = baru.find((f) => f.name === 'tindakan_korektif');
  assert.equal(tk.required, true);
  assert.equal(tk.showIf({ hasil: 'tidak_layak' }), true);
  assert.equal(tk.showIf({ hasil: 'layak' }), false);
  assert.match(tk.help, /menahan keberangkatan distribusi/);
  const co = baru.find((f) => f.name === 'catatan_organoleptik');
  assert.equal(co.showIf({ organoleptik_baik: 'false' }), true);
  assert.equal(co.showIf({ organoleptik_baik: 'true' }), false);
});

test('mutu: payload boolean, tindakan korektif dibersihkan saat layak', () => {
  const v = { jadwal_produksi_id: '12', suhu_makanan_c: '72.5', kebersihan_dapur: 'true', kebersihan_petugas: 'true', organoleptik_baik: 'true', catatan_organoleptik: null, sampel_disimpan: 'false', hasil: 'layak', tindakan_korektif: 'sisa isian', catatan_koreksi: null };
  assert.deepEqual(mutu.mutuPayload(v, { mode: 'create' }), { jadwal_produksi_id: 12, suhu_makanan_c: 72.5, kebersihan_dapur: true, kebersihan_petugas: true, organoleptik_baik: true, catatan_organoleptik: null, sampel_disimpan: false, hasil: 'layak', tindakan_korektif: null });
  const tl = mutu.mutuPayload({ ...v, hasil: 'tidak_layak', kebersihan_dapur: 'false', tindakan_korektif: ' Masak ulang ' }, { mode: 'update' });
  assert.equal(tl.tindakan_korektif, 'Masak ulang');
  assert.equal(tl.kebersihan_dapur, false);
  assert.equal('jadwal_produksi_id' in tl, false);
});

test('mutu: tombol hapus disembunyikan bila memengaruhi distribusi', () => {
  const p = { C: true, R: true, U: true, D: true };
  assert.equal(mutu.rowPermsMutu({ memengaruhi_distribusi: true }, p).D, false);
  assert.equal(mutu.rowPermsMutu({ memengaruhi_distribusi: true }, p).U, true);
  assert.equal(mutu.rowPermsMutu({ memengaruhi_distribusi: false }, p).D, true);
  assert.equal(mutu.hasilBadge('tidak_layak'), 'badge-danger');
});
