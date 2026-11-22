// R04-8 PR7: SPPG aktif di web. Header X-SPPG-Id hanya dari klien cookie (api-web) dan dari MEMORI; 409/403 SPPG aktif;
// jalur Bearer (Mini App) terpisah dan tidak berubah.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// Penjaga: akses storage apa pun dari modul web = tes gagal.
const larang = (nama) => new Proxy({}, {
  get: (_, p) => { throw new Error(`${nama}.${String(p)} tidak boleh dipakai klien web`); },
  set: () => { throw new Error(`${nama} tidak boleh ditulis klien web`); },
});
globalThis.localStorage = larang('localStorage');
globalThis.sessionStorage = larang('sessionStorage');
globalThis.document = { get cookie() { throw new Error('document.cookie tidak boleh dibaca'); }, set cookie(_) { throw new Error('document.cookie tidak boleh ditulis'); } };

const { buatKlienWeb, ApiError } = await import('../core/api-web.js');
const web = await import('../core/sppg-aktif-web.js');

const HEX = 'a'.repeat(64);
const USER = { id: 12, name: 'Investor Fiktif', role: 'investor', multi_sppg: true };
const resp = (status, body) => ({ ok: status >= 200 && status < 300, status, headers: { get: () => null }, json: async () => body });
const me = resp(200, { user: USER, jenis_sesi: 'web', csrf: HEX });

function pasang(skrip) {
  const calls = [];
  const fetchFn = async (url, init) => {
    const k = `${init.method} ${url.replace('/api/v1', '')}`;
    calls.push({ k, headers: init.headers });
    const q = skrip[k.split('?')[0]];
    if (!q?.length) throw new Error(`fetch tak terduga: ${k}`);
    return q.length > 1 ? q.shift() : q[0];
  };
  return { calls, klien: buatKlienWeb({ fetchFn }) };
}

test('X-SPPG-Id dikirim dari memori pada GET dan permintaan ubah (jalur cookie), tidak pada /web/auth/*; tanpa Authorization', async () => {
  web.hapusSppgAktif();
  const { calls, klien } = pasang({ 'GET /auth/me': [me], 'GET /keluhan': [resp(200, { data: [] })], 'POST /keluhan': [resp(201, { data: {} })], 'GET /web/auth/csrf': [resp(200, { csrf: HEX, berlaku_sampai: '2099-01-01T00:00:00Z' })] });
  await klien.muatSesi();
  await klien.api.get('/keluhan');
  assert.equal('X-SPPG-Id' in calls[1].headers, false, 'tanpa pilihan = tanpa header');
  web.setSppgAktif(3);
  await klien.api.get('/keluhan');
  await klien.api.post('/keluhan', { a: 1 });
  assert.equal(calls[2].headers['X-SPPG-Id'], '3');
  assert.equal(calls[3].headers['X-SPPG-Id'], '3');
  await klien.muatCsrf();
  assert.equal('X-SPPG-Id' in calls[4].headers, false, 'rute /web/auth/* tanpa header');
  for (const c of calls) assert.equal('Authorization' in c.headers, false);
});

test('409 sppg_aktif_belum_dipilih: galat pesan tetap + event sppg-diperlukan', async () => {
  web.hapusSppgAktif();
  const { klien } = pasang({ 'GET /auth/me': [me], 'GET /keluhan': [resp(409, { message: 'Server: pilih dulu SPPG Rahasia', alasan: 'sppg_aktif_belum_dipilih', langkah: 'pilih_sppg' })] });
  await klien.muatSesi();
  let event = 0;
  klien.onSppgDiperlukan(() => { event++; });
  await assert.rejects(klien.api.get('/keluhan'), (e) => e instanceof ApiError && e.status === 409 && e.message === 'Pilih SPPG yang ingin dilihat.' && !e.message.includes('Rahasia'));
  assert.equal(event, 1);
});

test('403 sppg_aktif_ditolak: pesan tetap (tanpa pesan server), pilihan dibuang, event sppg-diperlukan', async () => {
  web.setSppgAktif(9);
  const { klien } = pasang({ 'GET /auth/me': [me], 'GET /keluhan': [resp(403, { message: 'SPPG 9 milik investor lain (Rahasia)', alasan: 'sppg_aktif_ditolak', header: 'X-SPPG-Id' })] });
  await klien.muatSesi();
  let event = 0;
  klien.onSppgDiperlukan(() => { event++; });
  await assert.rejects(klien.api.get('/keluhan'), (e) => e.status === 403 && e.message === 'Pilihan SPPG tidak berlaku. Pilih SPPG yang ingin dilihat.' && !e.message.includes('Rahasia'));
  assert.equal(web.getSppgAktif(), null, 'pilihan ditolak dibuang dari memori');
  assert.equal(event, 1);
  // 403 lain (hak) tidak memicu pemilih SPPG
  const lain = pasang({ 'GET /auth/me': [me], 'GET /keluhan': [resp(403, { message: 'x' })] });
  await lain.klien.muatSesi();
  let e2 = 0;
  lain.klien.onSppgDiperlukan(() => { e2++; });
  await assert.rejects(lain.klien.api.get('/keluhan'));
  assert.equal(e2, 0);
});

test('logout/reset membersihkan SPPG aktif dari memori', async () => {
  web.setSppgAktif(5);
  const { klien } = pasang({ 'GET /auth/me': [me] });
  await klien.muatSesi();
  klien.reset();
  assert.equal(web.getSppgAktif(), null);
});

test('pilihan hanya di memori: tidak ada akses localStorage/sessionStorage (penjaga di atas melempar bila dipakai)', () => {
  web.setSppgAktif(7);
  assert.equal(web.getSppgAktif(), 7);
  assert.equal(web.normalisasiId('abc'), null);
  assert.deepEqual(web.terapkanHeaderSppg({}), { 'X-SPPG-Id': '7' });
  web.hapusSppgAktif();
  assert.deepEqual(web.terapkanHeaderSppg({}), {});
  const src = readFileSync(fileURLToPath(new URL('../core/sppg-aktif-web.js', import.meta.url)), 'utf8').replace(/\/\/.*$/gm, '');
  assert.ok(!/localStorage|sessionStorage|document\.cookie/.test(src));
});

test('Mini App tidak berubah: api.js memakai sppg-aktif.js (sessionStorage), tidak mengimpor versi web; api-web tidak mengimpor versi Mini App', () => {
  const baca = (f) => readFileSync(fileURLToPath(new URL(f, import.meta.url)), 'utf8');
  assert.match(baca('../core/api.js'), /from '\.\/sppg-aktif\.js'/);
  assert.ok(!/sppg-aktif-web/.test(baca('../core/api.js')));
  assert.match(baca('../core/api-web.js'), /from '\.\/sppg-aktif-web\.js'/);
  assert.ok(!/from '\.\/sppg-aktif\.js'/.test(baca('../core/api-web.js')));
});

test('paritas ekspor: sppg-aktif.js (Mini App) dan sppg-aktif-web.js mengekspor nama yang sama', async () => {
  // Mini App membaca sessionStorage saat dimuat (dibungkus try/catch); penjaga di atas melempar -> tertangkap dan diabaikan.
  const mini = await import('../core/sppg-aktif.js');
  assert.deepEqual(Object.keys(web).sort(), Object.keys(mini).sort());
});
