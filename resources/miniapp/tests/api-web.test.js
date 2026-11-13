// Tes klien API web (R04-5): header, credentials, CSRF hanya di memori, 403/401/429, tanpa storage.
// Jalankan: node --test resources/miniapp/tests/
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// Penjaga: akses storage/cookie apa pun dari klien web = tes gagal.
const larang = (nama) => new Proxy({}, {
  get: (_, p) => { throw new Error(`${nama}.${String(p)} tidak boleh dipakai klien web`); },
  set: () => { throw new Error(`${nama} tidak boleh ditulis klien web`); },
});
globalThis.localStorage = larang('localStorage');
globalThis.sessionStorage = larang('sessionStorage');
globalThis.document = {
  get cookie() { throw new Error('document.cookie tidak boleh dibaca'); },
  set cookie(_) { throw new Error('document.cookie tidak boleh ditulis'); },
};

const { buatKlienWeb, ApiError, statusKirimUlang, bacaRetryAfter } = await import('../core/api-web.js');

const HEX1 = 'a'.repeat(64);
const HEX2 = 'b'.repeat(64);
const HEX3 = 'c'.repeat(64);
const USER = { id: 12, name: 'Siti Rahayu', role: 'relawan' };
const resp = (status, body, headers = {}) => ({ ok: status >= 200 && status < 300, status, headers: { get: (k) => headers[k] ?? null }, json: async () => body });
const TANTANGAN = { langkah: 'kode', message: 'ok', tantangan: { id: '01jc', maks_kirim_ulang: 3, maks_percobaan: 5 } };

/** Fetch skrip: "METHOD /path" -> antrean respons (elemen terakhir diulang). Mencatat semua panggilan. */
function pasang(skrip) {
  const calls = [];
  const fetchFn = async (url, init) => {
    const path = url.replace('/api/v1', '');
    const k = `${init.method} ${path}`;
    calls.push({ k, headers: init.headers, credentials: init.credentials, body: init.body });
    const q = skrip[k];
    if (!q || !q.length) throw new Error(`fetch tak terduga: ${k}`);
    const r = q.length > 1 ? q.shift() : q[0];
    if (r instanceof Error) throw r;
    return r;
  };
  return { calls, klien: buatKlienWeb({ fetchFn }) };
}
const csrfOk = (c = HEX1, sampai = '2099-01-01T00:00:00Z') => resp(200, { csrf: c, berlaku_sampai: sampai });
const selesai = (c) => resp(200, { langkah: 'selesai', jenis_sesi: 'web', csrf: c, user: USER });
const me = (c) => resp(200, { user: USER, jenis_sesi: 'web', csrf: c });

test('setiap permintaan (termasuk GET): X-Sigap-Klien web, credentials same-origin, tanpa Authorization', async () => {
  const { calls, klien } = pasang({ 'GET /web/auth/csrf': [csrfOk()], 'GET /auth/sesi': [resp(200, { data: [] })], 'POST /web/auth/masuk': [resp(200, TANTANGAN)] });
  await klien.masuk('siti.dapur', 'Rahasia123x');
  await klien.daftarSesi();
  assert.equal(calls.length, 3);
  for (const c of calls) {
    assert.equal(c.headers['X-Sigap-Klien'], 'web', c.k);
    assert.equal(c.credentials, 'same-origin', c.k);
    assert.equal('Authorization' in c.headers, false, c.k);
  }
});

test('X-CSRF-Token hanya untuk metode pengubah; GET tanpa CSRF', async () => {
  const { calls, klien } = pasang({ 'GET /web/auth/csrf': [csrfOk()], 'POST /web/auth/masuk': [resp(200, TANTANGAN)] });
  await klien.masuk('siti.dapur', 'Rahasia123x');
  assert.equal(calls[0].headers['X-CSRF-Token'], undefined);
  assert.equal(calls[1].headers['X-CSRF-Token'], HEX1);
  assert.deepEqual(JSON.parse(calls[1].body), { identitas: 'siti.dapur', password: 'Rahasia123x' });
});

test('alur lengkap masuk -> kode: CSRF pra diganti CSRF sesi, user di memori, tanpa menyentuh storage/cookie', async () => {
  const { calls, klien } = pasang({
    'GET /web/auth/csrf': [csrfOk(HEX1)],
    'POST /web/auth/masuk': [resp(200, TANTANGAN)],
    'POST /web/auth/kode': [selesai(HEX2)],
    'PUT /pengguna/5': [resp(200, { data: {} })],
  });
  await klien.masuk('siti.dapur', 'Rahasia123x');
  assert.equal(klien.adaSesi(), false);
  const r = await klien.kirimKode('01jc', '482915', true);
  assert.equal(r.langkah, 'selesai');
  assert.deepEqual(JSON.parse(calls[2].body), { tantangan_id: '01jc', kode: '482915', ingat_browser: true });
  assert.equal(klien.adaSesi(), true);
  assert.equal(klien.getCsrf(), HEX2);
  assert.equal(klien.getUser().id, 12);
  await klien.api.put('/pengguna/5', { name: 'x' });
  assert.equal(calls[3].headers['X-CSRF-Token'], HEX2, 'rute login penuh memakai CSRF sesi');
});

test('CSRF pra tidak dimuat ulang bila masih berlaku; kedaluwarsa -> dimuat ulang; panggilan serentak dipadukan', async () => {
  let t = Date.parse('2026-11-13T00:00:00Z');
  const calls = [];
  const fetchFn = async (url, init) => {
    calls.push(`${init.method} ${url}`);
    if (url.endsWith('/web/auth/csrf')) return csrfOk(HEX1, '2026-11-13T00:15:00Z');
    return resp(200, TANTANGAN);
  };
  const klien = buatKlienWeb({ fetchFn, sekarang: () => t });
  await Promise.all([klien.masuk('a', 'b'), klien.masuk('a', 'b')]);
  assert.equal(calls.filter((c) => c.includes('/csrf')).length, 1, 'satu GET csrf untuk dua permintaan serentak');
  await klien.masuk('a', 'b');
  assert.equal(calls.filter((c) => c.includes('/csrf')).length, 1);
  t += 16 * 60 * 1000;
  await klien.masuk('a', 'b');
  assert.equal(calls.filter((c) => c.includes('/csrf')).length, 2);
});

test('403 csrf_ditolak pada rute sesi: muat ulang CSRF lewat /auth/me, ulangi SATU kali', async () => {
  const { calls, klien } = pasang({
    'GET /auth/me': [me(HEX1), me(HEX3)],
    'POST /bahan-baku': [resp(403, { alasan: 'csrf_ditolak' }), resp(201, { data: { id: 1 } })],
  });
  await klien.muatSesi();
  const r = await klien.api.post('/bahan-baku', { nama: 'Beras' });
  assert.deepEqual(r, { data: { id: 1 } });
  assert.deepEqual(calls.map((c) => c.k), ['GET /auth/me', 'POST /bahan-baku', 'GET /auth/me', 'POST /bahan-baku']);
  assert.equal(calls[1].headers['X-CSRF-Token'], HEX1);
  assert.equal(calls[3].headers['X-CSRF-Token'], HEX3);
});

test('403 csrf_ditolak dua kali: tidak mengulang lebih dari sekali, galat dilempar', async () => {
  const { calls, klien } = pasang({
    'GET /auth/me': [me(HEX1)],
    'POST /bahan-baku': [resp(403, { alasan: 'csrf_ditolak', message: 'x' })],
  });
  await klien.muatSesi();
  await assert.rejects(klien.api.post('/bahan-baku', {}), (e) => e instanceof ApiError && e.status === 403 && e.alasan === 'csrf_ditolak');
  assert.equal(calls.filter((c) => c.k === 'POST /bahan-baku').length, 2);
});

test('403 csrf_ditolak pada /web/auth/masuk: muat ulang /web/auth/csrf lalu ulangi sekali', async () => {
  const { calls, klien } = pasang({
    'GET /web/auth/csrf': [csrfOk(HEX1), csrfOk(HEX2)],
    'POST /web/auth/masuk': [resp(403, { alasan: 'csrf_ditolak' }), resp(200, TANTANGAN)],
  });
  const r = await klien.masuk('a', 'b');
  assert.equal(r.langkah, 'kode');
  assert.equal(calls[3].headers['X-CSRF-Token'], HEX2);
});

test('403 csrf_ditolak pada /web/auth/kode: CSRF dimuat ulang tetapi TIDAK diulang (tantangan terikat id pra lama)', async () => {
  const { calls, klien } = pasang({
    'GET /web/auth/csrf': [csrfOk(HEX1), csrfOk(HEX2)],
    'POST /web/auth/masuk': [resp(200, TANTANGAN)],
    'POST /web/auth/kode': [resp(403, { alasan: 'csrf_ditolak' })],
  });
  await klien.masuk('a', 'b');
  await assert.rejects(klien.kirimKode('01jc', '482915'), (e) => e.status === 403 && e.perluMasukUlang === true);
  assert.equal(calls.filter((c) => c.k === 'POST /web/auth/kode').length, 1);
  assert.equal(klien.getCsrf(), HEX2, 'CSRF baru siap untuk alur masuk ulang');
});

test('401 pada rute login penuh: event sesi-habis, memori dibersihkan, tanpa login senyap', async () => {
  const { calls, klien } = pasang({ 'GET /auth/me': [me(HEX1)], 'GET /bahan-baku': [resp(401, { message: 'Unauthenticated.' })] });
  let habis = 0;
  klien.onSesiHabis(() => habis++);
  await klien.muatSesi();
  assert.equal(klien.adaSesi(), true);
  await assert.rejects(klien.api.get('/bahan-baku'), (e) => e.status === 401 && /berakhir/i.test(e.message));
  assert.equal(habis, 1);
  assert.equal(klien.adaSesi(), false);
  assert.equal(klien.getCsrf(), null);
  assert.equal(klien.getUser(), null);
  assert.equal(calls.some((c) => c.k.includes('/auth/telegram')), false);
});

test('muatSesi: 401 -> null tanpa event; respons Bearer (tanpa jenis_sesi web) tidak dianggap sesi web', async () => {
  const a = pasang({ 'GET /auth/me': [resp(401, {})] });
  let habis = 0;
  a.klien.onSesiHabis(() => habis++);
  assert.equal(await a.klien.muatSesi(), null);
  assert.equal(habis, 0);
  const b = pasang({ 'GET /auth/me': [resp(200, { user: USER })] });
  assert.equal(await b.klien.muatSesi(), null);
  assert.equal(b.klien.adaSesi(), false);
});

test('429: ApiError membawa retryAfter (detik) dan tidak diulang otomatis', async () => {
  const { calls, klien } = pasang({ 'GET /web/auth/csrf': [csrfOk()], 'POST /web/auth/masuk': [resp(429, { message: 'Too Many Attempts.' }, { 'Retry-After': '42' })] });
  await assert.rejects(klien.masuk('a', 'b'), (e) => e.status === 429 && e.retryAfter === 42 && /Terlalu banyak/.test(e.message));
  assert.equal(calls.filter((c) => c.k === 'POST /web/auth/masuk').length, 1);
  assert.equal(bacaRetryAfter(resp(429, {}, { 'Retry-After': '0' })), null);
  assert.equal(bacaRetryAfter(resp(429, {})), null);
});

test('422 kode salah diteruskan apa adanya (seragam, tanpa pembeda); offline -> status 0', async () => {
  const { klien } = pasang({
    'GET /web/auth/csrf': [csrfOk()],
    'POST /web/auth/kode': [resp(422, { message: 'Kode salah atau kedaluwarsa.', errors: { kode: ['Kode salah atau kedaluwarsa.'] }, alasan: 'kode_tidak_valid' })],
  });
  await assert.rejects(klien.kirimKode('01jc', '000000'), (e) => e.status === 422 && e.alasan === 'kode_tidak_valid' && e.errors.kode[0] === 'Kode salah atau kedaluwarsa.');
  assert.equal(klien.adaSesi(), false);
  const off = pasang({ 'GET /auth/sesi': [new TypeError('Failed to fetch')] });
  await assert.rejects(off.klien.daftarSesi(), (e) => e.offline === true && e.status === 0);
});

test('logout: memori dibersihkan walau server 401 atau jaringan putus', async () => {
  const a = pasang({ 'GET /auth/me': [me(HEX1)], 'POST /auth/logout': [resp(200, { message: 'Berhasil keluar.' })] });
  await a.klien.muatSesi();
  await a.klien.logout();
  assert.equal(a.klien.getCsrf(), null);
  const b = pasang({ 'GET /auth/me': [me(HEX1)], 'POST /auth/logout': [resp(401, {})] });
  await b.klien.muatSesi();
  assert.equal(await b.klien.logout(), null);
  assert.equal(b.klien.adaSesi(), false);
  const c = pasang({ 'GET /auth/me': [me(HEX1)], 'POST /auth/logout': [new TypeError('x')] });
  await c.klien.muatSesi();
  await assert.rejects(c.klien.logout(), (e) => e.offline);
  assert.equal(c.klien.getCsrf(), null);
});

test('cabutSesi sesi_ini membersihkan memori; sesi lain tidak', async () => {
  const { klien } = pasang({
    'GET /auth/me': [me(HEX1)],
    'DELETE /auth/sesi/7': [resp(200, { message: 'ok', sesi_ini: false })],
    'DELETE /auth/sesi/60': [resp(200, { message: 'ok', sesi_ini: true })],
  });
  await klien.muatSesi();
  await klien.cabutSesi(7);
  assert.equal(klien.adaSesi(), true);
  await klien.cabutSesi(60);
  assert.equal(klien.adaSesi(), false);
});

test('statusKirimUlang: jeda 60 detik dan maks 3 kali dihitung klien', () => {
  const t0 = 1_000_000;
  assert.deepEqual(statusKirimUlang({ terkirimSebanyak: 0, terakhirMs: undefined, sekarangMs: t0 }), { sisa: 3, tungguDetik: 0, boleh: true });
  assert.deepEqual(statusKirimUlang({ terkirimSebanyak: 1, terakhirMs: t0, sekarangMs: t0 + 10_000 }), { sisa: 2, tungguDetik: 50, boleh: false });
  assert.equal(statusKirimUlang({ terkirimSebanyak: 1, terakhirMs: t0, sekarangMs: t0 + 60_000 }).boleh, true);
  assert.equal(statusKirimUlang({ terkirimSebanyak: 3, terakhirMs: t0, sekarangMs: t0 + 999_000 }).boleh, false);
});

test('kirim ulang kode memakai CSRF pra dan badan {tantangan_id}', async () => {
  const { calls, klien } = pasang({ 'GET /web/auth/csrf': [csrfOk()], 'POST /web/auth/kode/kirim-ulang': [resp(200, TANTANGAN)] });
  await klien.kirimUlangKode('01jc');
  assert.deepEqual(JSON.parse(calls[1].body), { tantangan_id: '01jc' });
  assert.equal(calls[1].headers['X-CSRF-Token'], HEX1);
});

test('berkas klien web tidak menyentuh storage/cookie, Authorization, atau api.js (pemindai statis)', () => {
  const src = readFileSync(fileURLToPath(new URL('../core/api-web.js', import.meta.url)), 'utf8')
    .split('\n').filter((l) => !/^\s*\/\//.test(l)).join('\n');
  const dilarang = [
    [/localStorage/, 'localStorage'], [/sessionStorage/, 'sessionStorage'], [/document\.cookie/, 'document.cookie'],
    [/indexedDB/, 'indexedDB'], [/Authorization/, 'Authorization'], [/from '\.\/api\.js'/, 'impor api.js'],
    [/credentials:\s*'include'/, 'credentials include'],
  ];
  for (const [re, nama] of dilarang) assert.doesNotMatch(src, re, nama);
  assert.match(src, /credentials: 'same-origin'/);
});
