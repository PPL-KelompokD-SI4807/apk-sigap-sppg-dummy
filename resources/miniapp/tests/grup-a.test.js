// Tes unit tanpa dependensi: node --test resources/miniapp/tests/
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { perms, can, userPerms } from '../core/permissions.js';
import * as V from '../core/validators.js';
import { firstError, mapValidationErrors, errorMessage } from '../core/errors.js';
import { buildShareUrl, isTelegramLink, hoursLeft } from '../core/share.js';
import { buildPayload, sppgField } from '../core/schema-helpers.js';
import * as sppg from '../modules/sppg/schema.js';
import * as titik from '../modules/titik-layanan/schema.js';
import * as penerima from '../modules/penerima-manfaat/schema.js';
import * as pemasok from '../modules/pemasok/schema.js';
import * as armada from '../modules/armada/schema.js';
import * as pengemudi from '../modules/pengemudi/schema.js';
import * as pengguna from '../modules/pengguna/schema.js';

const names = (fields) => fields.map((f) => f.name);

// ---------- permissions ----------
test('matriks hak: pengguna R* untuk non-admin, CRUD admin, R kepala', () => {
  assert.deepEqual(perms('admin', 'pengguna'), { C: true, R: true, U: true, D: true, selfOnly: false });
  assert.equal(perms('relawan', 'pengguna').selfOnly, true);
  assert.equal(can('kepala_sppg', 'pengguna', 'C'), false);
  assert.equal(can('kepala_sppg', 'sppg', 'U'), true);
  assert.equal(can('kepala_sppg', 'sppg', 'C'), false);
  assert.equal(can('staf_gudang', 'pemasok', 'D'), false);
  assert.equal(can('staf_gudang', 'pemasok', 'U'), true);
  assert.equal(can('ahli_gizi', 'penerima-manfaat', 'C'), false);
  assert.equal(can('kepala_sppg', 'penerima-manfaat', 'D'), false);
  assert.equal(can('relawan', 'penerima-manfaat', 'R'), false);
  assert.equal(can('x', 'tidak-ada', 'R'), false);
});

test('userPerms: irisan matriks, user.modules, dan user.permissions', () => {
  const admin = { role: 'admin' };
  assert.equal(userPerms(admin, 'sppg').D, true);
  assert.equal(userPerms({ role: 'admin', modules: ['sppg'] }, 'pemasok').R, false, 'modul tak ada di user.modules');
  assert.equal(userPerms({ role: 'admin', modules: ['sppg'], permissions: { sppg: 'RU' } }, 'sppg').D, false, 'huruf efektif membatasi');
  assert.equal(userPerms({ role: 'relawan', permissions: { armada: 'CRUD' } }, 'armada').C, false, 'matriks tetap membatasi');
  assert.equal(userPerms({ role: 'ahli_gizi', modules: ['pengguna'], permissions: { pengguna: 'R*' } }, 'pengguna').selfOnly, true);
  assert.equal(userPerms(null, 'sppg').R, false);
});

// ---------- validators ----------
test('validator telepon 8-15 digit', () => {
  assert.equal(V.phone(''), '');
  assert.equal(V.phone('0812-0000-1234'), '');
  assert.equal(V.phone('+62 812 0000 1234'), '');
  assert.match(V.phone('1234567'), /8-15 digit/);
  assert.match(V.phone('1234567890123456'), /8-15 digit/);
  assert.match(V.phone('08x12345678'), /hanya boleh/);
});

test('validator nama tanpa deret angka mirip NIK', () => {
  assert.equal(V.noNik('Rina A.'), '');
  assert.equal(V.noNik('Kelas 4A'), '');
  assert.match(V.noNik('3201234567890001'), /NIK/);
  assert.match(V.noNik('Budi 3201 2345 6789'), /NIK/);
});

test('validator tanggal tidak sebelum hari ini', () => {
  const now = new Date(2026, 9, 26, 10, 0, 0);
  assert.equal(V.todayISO(now), '2026-10-26');
  assert.equal(V.notBeforeToday('2026-10-26', 'SIM', now), '');
  assert.equal(V.notBeforeToday('2027-01-01', 'SIM', now), '');
  assert.match(V.notBeforeToday('2026-10-25', 'SIM', now), /sebelum hari ini/);
  assert.equal(V.notBeforeToday('', 'SIM', now), '');
});

test('plat nomor: validasi dan normalisasi', () => {
  assert.equal(V.normalizePlat('  b   1234  xy '), 'B 1234 XY');
  assert.equal(V.plat('B 1234 XY'), '');
  assert.equal(V.plat('b1234xy'), '');
  assert.match(V.plat('12345'), /Format plat/);
});

test('toBool', () => {
  assert.equal(V.toBool('true'), true);
  assert.equal(V.toBool('false'), false);
  assert.equal(V.toBool(''), null);
});

// ---------- galat 422 ----------
test('pemetaan galat 422 Laravel', () => {
  const errors = { nama: ['Nama wajib diisi.', 'lain'], kode: ['Kode sudah dipakai.'] };
  assert.equal(firstError(errors), 'Nama wajib diisi.');
  assert.deepEqual(mapValidationErrors(errors), { nama: 'Nama wajib diisi.', kode: 'Kode sudah dipakai.' });
  assert.deepEqual(mapValidationErrors(null), {});
  assert.equal(errorMessage({ status: 422, errors: { user: ['Tidak dapat menghapus akun sendiri.'] }, message: 'x' }), 'Tidak dapat menghapus akun sendiri.');
  assert.equal(errorMessage({ status: 422, message: 'Data tidak valid dari server' }), 'Data tidak valid dari server');
  assert.equal(errorMessage({ status: 403 }), 'Anda tidak punya hak akses.');
  assert.equal(errorMessage({ status: 0 }), 'Tidak ada koneksi internet.');
  assert.match(errorMessage({ status: 500 }), /gangguan/);
});

// ---------- berbagi tautan undangan ----------
test('tautan undangan: bagikan, validasi, sisa jam', () => {
  const link = 'https://t.me/sigap_sppg_bot?start=inv_abc-DEF_123';
  assert.equal(isTelegramLink(link), true);
  assert.equal(isTelegramLink('javascript:alert(1)'), false);
  assert.equal(isTelegramLink('https://evil.example/?start=inv_x'), false);
  assert.equal(isTelegramLink(null), false);
  const u = buildShareUrl(link, 'Halo & selamat');
  assert.ok(u.startsWith('https://t.me/share/url?url='));
  assert.ok(u.includes(encodeURIComponent(link)) && u.includes('text=Halo%20%26%20selamat'));
  const now = Date.parse('2026-10-26T08:00:00+07:00');
  assert.equal(hoursLeft('2026-10-28T08:00:00+07:00', now), 48);
  assert.equal(hoursLeft('2026-10-25T08:00:00+07:00', now), 0);
  assert.equal(hoursLeft('bukan tanggal', now), null);
});

// ---------- buildPayload & skema ----------
test('buildPayload: tipe, null, boolean, kolom hilang dilewati', () => {
  const out = buildPayload({ a: ' x ', b: '5', c: '', d: 'true', e: '2.5', f: null }, { keys: ['a', 'b', 'c', 'd', 'e', 'f', 'g'], ints: ['b'], nums: ['e'], bools: ['d'], nullable: ['c'] });
  assert.deepEqual(out, { a: 'x', b: 5, c: null, d: true, e: 2.5 });
});

test('sppgField hanya Admin saat tambah', () => {
  assert.deepEqual(names(sppgField({ role: 'admin' }, 'create')), ['sppg_id']);
  assert.deepEqual(sppgField({ role: 'admin' }, 'update'), []);
  assert.deepEqual(sppgField({ role: 'kepala_sppg' }, 'create'), []);
});

test('SPPG: kode readonly untuk Kepala SPPG, payload tanpa kode bila tak dikirim', () => {
  const kepala = sppg.sppgFields({ mode: 'update', user: { role: 'kepala_sppg' } });
  assert.equal(kepala.find((f) => f.name === 'kode').readonly, true);
  const admin = sppg.sppgFields({ mode: 'update', user: { role: 'admin' } });
  assert.equal(!!admin.find((f) => f.name === 'kode').readonly, false);
  const p = sppg.mapPayload({ nama: 'SPPG A', alamat: 'Jl. X', kapasitas_porsi_harian: '2000', status_operasional: 'aktif', telepon: '', mitra_yayasan: '' });
  assert.deepEqual(p, { nama: 'SPPG A', alamat: 'Jl. X', kapasitas_porsi_harian: 2000, status_operasional: 'aktif', telepon: null, mitra_yayasan: null });
  assert.ok(!('kode' in p));
  assert.equal(sppg.sppgFields({ mode: 'create', user: { role: 'admin' } }).find((f) => f.name === 'kapasitas_porsi_harian').min, 1);
});

test('Titik layanan: payload, sppg_id Admin, jarak', () => {
  const p = titik.mapPayload({ sppg_id: '1', nama: 'SD Contoh 1', jenis: 'sekolah', alamat: 'Jl. A', jarak_km: '1.20', jumlah_sasaran: '240', pic_nama: 'Ibu Ani', pic_telepon: '', is_active: 'true' });
  assert.deepEqual(p, { sppg_id: 1, nama: 'SD Contoh 1', jenis: 'sekolah', alamat: 'Jl. A', jarak_km: 1.2, jumlah_sasaran: 240, pic_nama: 'Ibu Ani', pic_telepon: null, is_active: true });
  assert.equal(names(titik.titikFields({ mode: 'create', user: { role: 'admin' } }))[0], 'sppg_id');
  assert.equal(names(titik.titikFields({ mode: 'create', user: { role: 'kepala_sppg' } }))[0], 'nama');
  assert.equal(titik.jarakText('3.50'), '3,5 km');
  assert.equal(titik.jarakText(null), '-');
});

test('Penerima manfaat: tanpa NIK, payload', () => {
  const f = penerima.penerimaFields().find((x) => x.name === 'nama');
  assert.match(f.validate('3201234567890001'), /NIK/);
  assert.equal(f.validate('Rina A.'), '');
  const p = penerima.mapPayload({ titik_layanan_id: '3', nama: 'Rina A.', kelompok_sasaran: 'siswa', kelas_keterangan: '', catatan_alergi: 'Kacang', is_active: 'false' });
  assert.deepEqual(p, { titik_layanan_id: 3, nama: 'Rina A.', kelompok_sasaran: 'siswa', kelas_keterangan: null, catatan_alergi: 'Kacang', is_active: false });
});

test('Pemasok: kontak 8-15 digit, payload', () => {
  const f = pemasok.pemasokFields({ mode: 'update', user: { role: 'staf_gudang' } }).find((x) => x.name === 'kontak');
  assert.equal(f.validate('0812-0000-0000'), '');
  assert.match(f.validate('123'), /8-15/);
  assert.deepEqual(pemasok.mapPayload({ nama: 'Tani Maju', jenis: 'petani', kontak: '', is_active: 'true' }), { nama: 'Tani Maju', jenis: 'petani', kontak: null, is_active: true });
  assert.ok(names(pemasok.pemasokFields({ mode: 'detail', user: {} })).includes('bahan_baku_count'));
  assert.ok(!names(pemasok.pemasokFields({ mode: 'update', user: {} })).includes('bahan_baku_count'));
});

test('Armada: plat dinormalkan, status', () => {
  const p = armada.mapPayload({ plat_nomor: 'b 1234  xy', jenis: 'Mobil box', kapasitas_box: '60', status: 'tersedia' });
  assert.deepEqual(p, { plat_nomor: 'B 1234 XY', jenis: 'Mobil box', kapasitas_box: 60, status: 'tersedia' });
  assert.equal(armada.statusArmadaLabel('servis'), 'Servis');
});

test('Pengemudi: aturan SIM lintas kolom dan kolom opsional di respons', () => {
  const now = new Date(2026, 9, 26);
  assert.match(pengemudi.validateSimDate(null, { no_sim: '123' }, now), /wajib/);
  assert.equal(pengemudi.validateSimDate(null, { no_sim: null }, now), '');
  assert.match(pengemudi.validateSimDate('2026-10-01', { no_sim: '1' }, now), /sebelum hari ini/);
  assert.equal(pengemudi.validateSimDate('2027-10-01', { no_sim: '1' }, now), '');
  const det = pengemudi.pengemudiFields({ mode: 'detail', user: {} });
  assert.equal(det.find((f) => f.name === 'no_sim').onlyIfPresent, true);
  assert.equal(pengemudi.simLabel(null), 'SIM tidak dicatat');
  assert.equal(pengemudi.simBadge('kedaluwarsa'), 'badge-danger');
  assert.deepEqual(pengemudi.mapPayload({ nama: 'Pak Udin', no_hp: '', no_sim: '', sim_berlaku_hingga: '', is_active: 'true' }),
    { nama: 'Pak Udin', no_hp: null, no_sim: null, sim_berlaku_hingga: null, is_active: true });
});

test('Pengguna: kolom bersyarat (unit relawan, SPPG non-admin) dan payload konsisten', () => {
  const f = pengguna.penggunaFields({ mode: 'create' });
  const unit = f.find((x) => x.name === 'unit');
  const sppgF = f.find((x) => x.name === 'sppg_id');
  assert.equal(unit.showIf({ role: 'relawan' }), true);
  assert.equal(unit.showIf({ role: 'akuntan' }), false);
  assert.equal(sppgF.showIf({ role: 'admin' }), false);
  assert.equal(sppgF.showIf({ role: '' }), false);
  assert.equal(sppgF.showIf({ role: 'ahli_gizi' }), true);
  assert.ok(!names(f).includes('is_active'), 'status akun hanya saat ubah');
  assert.ok(names(pengguna.penggunaFields({ mode: 'update' })).includes('is_active'));
  assert.deepEqual(pengguna.mapPayload({ name: 'Siti', role: 'relawan', unit: 'dapur', sppg_id: '1', phone: '0812-0000-1234', is_active: 'true' }, { mode: 'create' }),
    { name: 'Siti', role: 'relawan', unit: 'dapur', sppg_id: 1, phone: '0812-0000-1234' });
  assert.deepEqual(pengguna.mapPayload({ name: 'Adm', role: 'admin', unit: 'dapur', sppg_id: '1', phone: '' }, { mode: 'update' }),
    { name: 'Adm', role: 'admin', unit: null, sppg_id: null, phone: null });
  assert.equal(pengguna.mapPayload({ name: 'Budi', role: 'akuntan', unit: 'dapur', sppg_id: '2' }, { mode: 'update' }).unit, null);
  assert.equal(pengguna.statusLabel('menunggu_telegram'), 'Menunggu Telegram');
});

test('Undangan: payload user_id eksklusif dengan role/unit/sppg_id', () => {
  assert.deepEqual(pengguna.buildInvitePayload({ mode: 'akun', user_id: '12', role: 'relawan', unit: 'dapur', sppg_id: '1' }), { user_id: 12 });
  assert.deepEqual(pengguna.buildInvitePayload({ mode: 'baru', role: 'relawan', unit: 'distribusi', sppg_id: '1', user_id: '12' }), { role: 'relawan', unit: 'distribusi', sppg_id: 1 });
  assert.deepEqual(pengguna.buildInvitePayload({ mode: 'baru', role: 'admin', unit: 'dapur', sppg_id: '1' }), { role: 'admin' });
  assert.deepEqual(pengguna.buildInvitePayload({ mode: 'baru', role: 'akuntan', sppg_id: '3' }), { role: 'akuntan', sppg_id: 3 });
  assert.equal(pengguna.undanganTarget({ tipe: 'pengguna', user_nama: 'Siti' }), 'Siti');
  assert.equal(pengguna.undanganTarget({ tipe: 'pengguna', role: 'relawan', unit: 'dapur' }), 'Relawan · Dapur');
  assert.equal(pengguna.undanganTarget({ tipe: 'pic', titik_layanan_nama: 'SD 1' }), 'PIC SD 1');
  assert.equal(pengguna.undanganLabel('dicabut'), 'Dicabut');
});

// ---------- kesesuaian enum dengan kontrak OpenAPI ----------
const api = (f) => readFileSync(new URL(`../../../docs/api/components/${f}.yaml`, import.meta.url), 'utf8');
const enumOf = (src, key) => {
  const m = src.match(new RegExp(`\\b${key}: \\{[^}]*enum: \\[([^\\]]+)\\]`));
  return m ? m[1].split(',').map((s) => s.trim()) : null;
};
test('enum skema sama dengan docs/api/components', () => {
  const vals = (opts) => opts.map((o) => o.value);
  assert.deepEqual(vals(titik.JENIS), enumOf(api('titik-layanan'), 'jenis'));
  assert.deepEqual(vals(penerima.KELOMPOK), enumOf(api('penerima-manfaat'), 'kelompok_sasaran'));
  assert.deepEqual(vals(pemasok.JENIS_PEMASOK), enumOf(api('pemasok'), 'jenis'));
  assert.deepEqual(vals(armada.STATUS_ARMADA), enumOf(api('armada'), 'status'));
  assert.deepEqual(vals(sppg.STATUS_OPERASIONAL), enumOf(api('sppg'), 'status_operasional'));
  assert.deepEqual(vals(pengguna.STATUS_UNDANGAN), ['aktif', 'terpakai', 'kedaluwarsa', 'dicabut']);
  assert.deepEqual(vals(pengguna.ROLE_OPTIONS), ['admin', 'kepala_sppg', 'ahli_gizi', 'akuntan', 'staf_gudang', 'relawan', 'investor']);
});
