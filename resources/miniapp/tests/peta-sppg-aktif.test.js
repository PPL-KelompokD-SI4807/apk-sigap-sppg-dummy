// Uji-peran P9: investor multi-SPPG. Peta hak (aksi.*) dihitung server per SPPG aktif, jadi GET /auth/me harus dimuat ulang
// DENGAN header X-SPPG-Id setelah SPPG dipilih; menu Ekspor Laporan BGN dihitung ulang dari peta baru (gagal tertutup bila gagal).
import test from 'node:test';
import assert from 'node:assert/strict';
import { setUserSesi, getUser } from '../core/auth.js';
import { setSppgAktif, getSppgAktif, hapusSppgAktif } from '../core/sppg-aktif.js';
import { muatSppgSaya } from '../components/sppg-picker.js';
import { menuUntuk } from '../modules/menu.js';
import laporanBgn from '../modules/laporan-bgn/index.js';
import { PROFIL, salin } from './fixtures/peta.js';

const resp = (status, body) => ({ ok: status < 300, status, headers: { get: () => null }, json: async () => body });

function investorTanpaSppgAktif() {
  const u = salin('investor');
  u.multi_sppg = true;
  u.sppg_aktif_id = null;
  u.aksi = { ...u.aksi, lihat_laporan_bgn_agregat: false, lihat_laporan_bgn: false };
  return u;
}

test('investor multi-SPPG: setelah SPPG aktif ada, /auth/me dimuat ulang dengan X-SPPG-Id dan menu Laporan BGN muncul', async () => {
  assert.ok(PROFIL.investor || salin('investor'), 'fixture investor ada');
  const asli = globalThis.fetch;
  const panggilan = [];
  globalThis.fetch = async (url, init) => {
    const path = new URL(url, 'http://x').pathname.replace('/api/v1', '');
    panggilan.push({ path, header: init.headers['X-SPPG-Id'] });
    if (path === '/sppg-saya') return resp(200, { data: [{ id: 5, nama: 'A', kode: 'A' }, { id: 6, nama: 'B', kode: 'B' }], wajib_pilih: false });
    if (path === '/auth/me') {
      const u = salin('investor');
      u.multi_sppg = true;
      u.sppg_aktif_id = init.headers['X-SPPG-Id'] ? Number(init.headers['X-SPPG-Id']) : null;
      u.aksi = { ...u.aksi, lihat_laporan_bgn_agregat: !!init.headers['X-SPPG-Id'], lihat_laporan_bgn: false };
      return resp(200, { user: u });
    }
    return resp(200, {});
  };
  try {
    setUserSesi(investorTanpaSppgAktif());
    const sebelum = menuUntuk(getUser(), [laporanBgn]).map((m) => m.slug);
    assert.ok(!sebelum.includes('laporan-bgn'), 'peta awal tanpa header: menu Laporan BGN belum ada');
    setSppgAktif(5);
    const hasil = await muatSppgSaya();
    assert.equal(hasil.langkah, 'siap');
    const me = panggilan.find((p) => p.path === '/auth/me');
    assert.ok(me, 'GET /auth/me dipanggil ulang');
    assert.equal(me.header, '5', 'dengan header X-SPPG-Id SPPG aktif');
    assert.equal(getUser().sppg_aktif_id, 5);
    assert.ok(menuUntuk(getUser(), [laporanBgn]).map((m) => m.slug).includes('laporan-bgn'), 'menu dihitung ulang dari peta baru');
  } finally { globalThis.fetch = asli; hapusSppgAktif(); }
});

test('investor: /auth/me gagal (500) setelah pilih SPPG -> peta lama dipertahankan, menu Laporan BGN tetap tersembunyi (gagal tertutup)', async () => {
  const asli = globalThis.fetch;
  globalThis.fetch = async (url) => {
    const path = new URL(url, 'http://x').pathname.replace('/api/v1', '');
    if (path === '/sppg-saya') return resp(200, { data: [{ id: 5, nama: 'A', kode: 'A' }, { id: 6, nama: 'B', kode: 'B' }], wajib_pilih: false });
    return resp(500, { message: 'x' });
  };
  try {
    setUserSesi(investorTanpaSppgAktif());
    setSppgAktif(5);
    const hasil = await muatSppgSaya();
    assert.equal(hasil.langkah, 'siap');
    assert.equal(getSppgAktif(), 5);
    assert.ok(!menuUntuk(getUser(), [laporanBgn]).map((m) => m.slug).includes('laporan-bgn'));
  } finally { globalThis.fetch = asli; hapusSppgAktif(); }
});
