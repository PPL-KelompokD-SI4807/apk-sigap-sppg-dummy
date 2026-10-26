// Tes unit R-06a (Investor + multi-SPPG): node --test resources/miniapp/tests/
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  HEADER_SPPG, terapkanHeaderSppg, perluPilihUlangSppg, tanpaSppg, tentukanSppgAktif,
  setSppgAktif, getSppgAktif, hapusSppgAktif, sppgBelumDimiliki, normalisasiId,
} from '../core/sppg-aktif.js';
import { request, onSppgDiperlukan } from '../core/api.js';
import { perms, userPerms, roleLabel, ROLES, MODULE_ORDER } from '../core/permissions.js';
import { normalisasiAgregat, queryAgregat } from '../core/agregat.js';
import { needsSppg, mapPayload, buildInvitePayload, ROLE_OPTIONS } from '../modules/pengguna/schema.js';

const resp = (status, body) => ({ ok: status < 300, status, headers: { get: () => null }, json: async () => body });

test('header X-SPPG-Id hanya ada bila SPPG aktif dipilih', () => {
  assert.equal(HEADER_SPPG, 'X-SPPG-Id');
  assert.deepEqual(terapkanHeaderSppg({ a: '1' }, 7), { a: '1', 'X-SPPG-Id': '7' });
  assert.deepEqual(terapkanHeaderSppg({}, null), {});
  assert.equal(normalisasiId('abc'), null);
  assert.equal(normalisasiId('0'), null);
  assert.equal(normalisasiId('3'), 3);
});

test('request() mengirim X-SPPG-Id dan galat 409/403 membuang pilihan lalu memberi sinyal', async () => {
  const orig = globalThis.fetch;
  const seen = [];
  let balasan = resp(200, { data: [] });
  globalThis.fetch = async (url, init) => { seen.push(init.headers); return balasan; };
  let sinyal = 0;
  onSppgDiperlukan(() => { sinyal++; });
  try {
    setSppgAktif(5);
    await request('GET', '/bahan-baku');
    assert.equal(seen[0]['X-SPPG-Id'], '5');

    balasan = resp(409, { message: 'x', alasan: 'sppg_aktif_belum_dipilih', langkah: 'pilih_sppg' });
    await assert.rejects(() => request('GET', '/bahan-baku'), (e) => e.status === 409);
    assert.equal(getSppgAktif(), null);
    assert.equal(sinyal, 1);

    setSppgAktif(9);
    balasan = resp(403, { message: 'x', alasan: 'sppg_aktif_ditolak' });
    await assert.rejects(() => request('GET', '/bahan-baku'), (e) => e.status === 403);
    assert.equal(getSppgAktif(), null);
    assert.equal(sinyal, 2);

    hapusSppgAktif();
    balasan = resp(200, {});
    await request('GET', '/x');
    assert.equal(seen.at(-1)['X-SPPG-Id'], undefined);

    // 403 lain: tidak menghapus pilihan, tidak ada sinyal
    setSppgAktif(5);
    balasan = resp(403, { message: 'x' });
    await assert.rejects(() => request('GET', '/x', { silent: true }), (e) => e.message === 'Anda tidak punya hak akses.');
    assert.equal(getSppgAktif(), 5);
    assert.equal(sinyal, 2);
  } finally { globalThis.fetch = orig; hapusSppgAktif(); }
});

test('klasifikasi galat SPPG aktif', () => {
  assert.equal(perluPilihUlangSppg(409, { alasan: 'sppg_aktif_belum_dipilih' }), true);
  assert.equal(perluPilihUlangSppg(403, { alasan: 'sppg_aktif_ditolak' }), true);
  assert.equal(perluPilihUlangSppg(409, { message: 'kunci idempotensi' }), false);
  assert.equal(perluPilihUlangSppg(403, { alasan: 'tanpa_sppg' }), false);
  assert.equal(tanpaSppg(403, { alasan: 'tanpa_sppg' }), true);
  assert.equal(tanpaSppg(403, {}), false);
});

test('tentukanSppgAktif: satu SPPG langsung terpilih, banyak harus memilih', () => {
  const s = (id) => ({ id, kode: `K${id}`, nama: `S${id}`, status_operasional: 'aktif' });
  assert.deepEqual(tentukanSppgAktif({ data: [] }), { langkah: 'kosong' });
  assert.deepEqual(tentukanSppgAktif({ data: [s(4)], wajib_pilih: false }), { langkah: 'siap', id: 4 });
  assert.deepEqual(tentukanSppgAktif({ data: [s(1), s(2)], wajib_pilih: true, sppg_aktif_id: null }), { langkah: 'pilih' });
  assert.deepEqual(tentukanSppgAktif({ data: [s(1), s(2)], wajib_pilih: false, sppg_aktif_id: 2 }, 2), { langkah: 'siap', id: 2 });
  // pilihan tersimpan yang bukan miliknya diabaikan
  assert.deepEqual(tentukanSppgAktif({ data: [s(1), s(2)], wajib_pilih: false, sppg_aktif_id: null }, 99), { langkah: 'pilih' });
});

test('matriks investor: baca-saja pada modul ber-R, modul lain tertutup', () => {
  assert.equal(roleLabel('investor'), 'Investor');
  const boleh = ['sppg', 'bahan-baku', 'mutu', 'keuangan', 'keluhan', 'rekap-harian'];
  for (const slug of MODULE_ORDER) {
    const p = perms('investor', slug);
    if (boleh.includes(slug)) assert.deepEqual([p.R, p.C, p.U, p.D], [true, false, false, false], slug);
    else if (slug === 'pengguna') assert.deepEqual([p.R, p.C, p.U, p.D, p.selfOnly], [true, false, false, false, true]);
    else assert.deepEqual([p.R, p.C, p.U, p.D], [false, false, false, false], slug);
  }
  assert.equal(userPerms({ role: 'investor' }, 'penerima-manfaat').R, false);
  // irisan dengan permissions dari server tidak bisa menambah hak
  assert.equal(userPerms({ role: 'investor', permissions: { keuangan: 'CRUD' } }, 'keuangan').C, false);
});

test('agregat penerima manfaat: sel tersamar tampil sebagai penanda, bukan 0', () => {
  const a = normalisasiAgregat({ data: {
    sppg_id: 1, total: null, total_penanda: '<5', aktif: 118, aktif_penanda: null,
    penyamaran: { diterapkan: true, ambang_k: 5, penanda: '<5' },
    per_kelompok_sasaran: [{ kelompok_sasaran: 'siswa', label: 'Siswa', jumlah: 88, penanda: null }, { kelompok_sasaran: 'ibu_hamil', label: 'Ibu Hamil', jumlah: null, penanda: '<5' }],
    per_titik_layanan: [{ titik_layanan_id: 7, nama: 'Posyandu Contoh', jumlah: null, penanda: '<5' }],
  } });
  assert.equal(a.totalTampil, '<5');
  assert.equal(a.aktifTampil, '118');
  assert.equal(a.nonaktif, null);
  assert.equal(a.disamarkan, true);
  assert.deepEqual(a.kelompok.map((r) => [r.tampil, r.persen]), [['88', null], ['<5', null]]);
  assert.equal(a.titik[0].tampil, '<5');
  const b = normalisasiAgregat({ data: { total: 120, aktif: 90, penyamaran: { diterapkan: false }, per_kelompok_sasaran: [{ label: 'Siswa', jumlah: 60 }], per_titik_layanan: [] } });
  assert.equal(b.nonaktif, 30);
  assert.equal(b.disamarkan, false);
  assert.deepEqual(b.kelompok, [{ label: 'Siswa', jumlah: 60, tampil: '60', persen: 50 }]);
  assert.equal(normalisasiAgregat(null).totalTampil, '<5');
  assert.deepEqual(queryAgregat({ role: 'admin' }, 4), { sppg_id: 4 });
  assert.equal(queryAgregat({ role: 'investor' }, 4), undefined);
});

test('agregat: aktif disamarkan tetapi total terlihat -> nonaktif tidak memakai total', () => {
  const a = normalisasiAgregat({ data: { total: 120, aktif: null, aktif_penanda: '<5', penyamaran: { diterapkan: true }, per_kelompok_sasaran: [], per_titik_layanan: [] } });
  assert.equal(a.totalTampil, '120');
  assert.equal(a.aktifTampil, '<5');
  assert.equal(a.nonaktif, null);
  assert.equal(a.nonaktifTampil, '<5');
  assert.notEqual(a.nonaktifTampil, a.totalTampil);
  assert.equal(normalisasiAgregat({ data: { total: 120, aktif: 90 } }).nonaktifTampil, '30');
});

test('fetchLampiranBlob mengirim X-SPPG-Id dan 403 lampiran_disembunyikan tidak menghapus pilihan', async () => {
  const { fetchLampiranBlob } = await import('../components/photo-upload.js');
  setSppgAktif(6);
  let hdr;
  const f = async (_u, init) => { hdr = init.headers; return { ok: false, status: 403, json: async () => ({ alasan: 'lampiran_disembunyikan' }) }; };
  await assert.rejects(() => fetchLampiranBlob('/api/v1/lampiran/1', { fetchFn: f, token: 't' }), /tidak ditampilkan untuk peran Anda/);
  assert.equal(hdr['X-SPPG-Id'], '6');
  assert.equal(getSppgAktif(), 6);
  hapusSppgAktif();
});

test('undangan dan form pengguna untuk investor', () => {
  assert.ok(ROLES.investor);
  assert.ok(ROLE_OPTIONS.some((o) => o.value === 'investor'));
  assert.equal(needsSppg('investor'), false);
  assert.equal(needsSppg('relawan'), true);
  assert.deepEqual(buildInvitePayload({ mode: 'baru', role: 'investor', sppg_id: '3' }), { role: 'investor' });
  assert.equal(mapPayload({ name: 'A', role: 'investor', sppg_id: '3' }, { mode: 'create' }).sppg_id, null);
});

test('daftar SPPG yang belum dimiliki', () => {
  assert.deepEqual(sppgBelumDimiliki([{ id: 1 }, { id: 2 }, { id: 3 }], [{ id: 2 }]).map((s) => s.id), [1, 3]);
  assert.deepEqual(sppgBelumDimiliki(null, null), []);
});

test('lampiran disembunyikan untuk investor atau flag server; legenda penyamaran', async () => {
  const { lampiranDisembunyikan } = await import('../core/lampiran-uploader.js');
  const { LEGENDA_PENYAMARAN } = await import('../core/agregat.js');
  assert.equal(lampiranDisembunyikan({ role: 'investor' }), true);
  assert.equal(lampiranDisembunyikan({ role: 'kepala_sppg' }, { lampiran_disembunyikan: true }), true);
  assert.equal(lampiranDisembunyikan({ role: 'kepala_sppg' }, { lampiran: [] }), false);
  assert.match(LEGENDA_PENYAMARAN, /^<5 = jumlah disamarkan demi privasi/);
});
