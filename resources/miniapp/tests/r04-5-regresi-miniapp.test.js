// Regresi Mini App (R04-5): jalur Bearer tidak berubah dan cookie sesi web tidak pernah ikut terkirim dari klien
// Mini App (api.js memakai credentials 'omit'; header X-Sigap-Klien/X-CSRF-Token hanya milik api-web.js).
// Login senyap PR #55 dijaga tes yang ada: sesi-pulih.test.js dan token-storage.test.js.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const mem = () => { const m = new Map(); return { m, getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) }; };
globalThis.sessionStorage = mem();
globalThis.localStorage = mem();
globalThis.window = { Telegram: { WebApp: { initData: 'query_id=ABC&hash=segar', platform: 'android' } } };

const { request, uploadMultipart, setToken, tanganiStorage } = await import('../core/api.js');
const { fetchLampiranBlob } = await import('../components/photo-upload.js');

const resp = (status, body) => ({ ok: status < 300, status, headers: { get: () => null }, json: async () => body });
function tangkapFetch(balas = () => resp(200, { ok: true })) {
  const calls = [];
  globalThis.fetch = async (url, init) => { calls.push({ url, init }); return balas(url, init); };
  return calls;
}
const adaHeaderWeb = (h = {}) => Object.keys(h).some((k) => /^(x-sigap-klien|x-csrf-token)$/i.test(k));

test('request() dengan token: Bearer tetap dipasang, credentials omit, tanpa header web', async () => {
  setToken('tok-penuh', 'penuh');
  const calls = tangkapFetch();
  await request('GET', '/bahan-baku', { silent: true });
  await request('POST', '/bahan-baku', { body: { nama: 'Beras' }, key: 'k1', silent: true });
  assert.equal(calls.length, 2);
  for (const { init } of calls) {
    assert.equal(init.credentials, 'omit');
    assert.equal(init.headers.Authorization, 'Bearer tok-penuh');
    assert.equal(adaHeaderWeb(init.headers), false);
  }
  assert.equal(calls[1].init.headers['Idempotency-Key'], 'k1');
  assert.equal(calls[1].init.headers['Content-Type'], 'application/json');
});

test('request() tanpa token (mis. /auth/telegram): tanpa Authorization, tetap credentials omit', async () => {
  setToken(null);
  const calls = tangkapFetch();
  await request('POST', '/auth/telegram', { body: { init_data: 'x' }, silent: true });
  assert.equal(calls[0].init.credentials, 'omit');
  assert.equal('Authorization' in calls[0].init.headers, false);
  assert.equal(adaHeaderWeb(calls[0].init.headers), false);
});

test('pencabutan token lintas tab (tanganiStorage) memakai Bearer + credentials omit', async () => {
  setToken('tok-penuh-2', 'penuh');
  const calls = tangkapFetch();
  localStorage.removeItem('sigap_token'); // tab lain mengosongkan storage
  tanganiStorage({ key: null }); // clear() di tab lain: nilai lama tak diketahui -> cabut di server best-effort
  await new Promise((r) => setTimeout(r, 5));
  const keluar = calls.find((c) => c.url.endsWith('/auth/logout'));
  assert.ok(keluar, 'POST /auth/logout dikirim');
  assert.equal(keluar.init.credentials, 'omit');
  assert.equal(keluar.init.headers.Authorization, 'Bearer tok-penuh-2');
  assert.equal(adaHeaderWeb(keluar.init.headers), false);
});

test('unggah multipart (XHR): hanya Bearer; tak ada header web (XHR tak bisa omit cookie sesama origin, cookie inert tanpa X-Sigap-Klien)', async () => {
  setToken('tok-penuh-3', 'penuh');
  const set = {};
  const xhr = { open() {}, setRequestHeader: (k, v) => { set[k] = v; }, upload: {}, send() { this.status = 200; this.responseText = '{"ok":true}'; this.onload(); } };
  await uploadMultipart('/lampiran', { foto: new Blob(['x'], { type: 'image/jpeg' }) }, { xhrFactory: () => xhr });
  assert.equal(set.Authorization, 'Bearer tok-penuh-3');
  assert.equal(adaHeaderWeb(set), false);
});

test('fetchLampiranBlob: Bearer + credentials omit', async () => {
  let init;
  const blob = new Blob(['x'], { type: 'image/png' });
  await fetchLampiranBlob('/api/v1/lampiran/12', { token: 'T0K', fetchFn: async (_u, i) => { init = i; return { ok: true, status: 200, blob: async () => blob }; } });
  assert.equal(init.credentials, 'omit');
  assert.equal(init.headers.Authorization, 'Bearer T0K');
  assert.equal(adaHeaderWeb(init.headers), false);
});

// ---- Pemindai statis: kode Mini App tidak boleh membawa jalur web ----
const akar = fileURLToPath(new URL('..', import.meta.url));
const semuaJs = (dir) => readdirSync(dir).flatMap((n) => {
  const p = join(dir, n);
  if (statSync(p).isDirectory()) return n === 'tests' || n === 'node_modules' ? [] : semuaJs(p);
  return p.endsWith('.js') ? [p] : [];
});

test('seluruh fetch( di kode Mini App memakai credentials omit; tak ada include/same-origin kecuali api-web.js', () => {
  const temuan = [];
  for (const f of semuaJs(akar)) {
    const rel = relative(akar, f).replaceAll('\\', '/');
    const src = readFileSync(f, 'utf8');
    if (rel === 'core/api-web.js') continue;
    if (/credentials:\s*'(include|same-origin)'/.test(src)) temuan.push(`${rel}: credentials include/same-origin`);
    if (/X-Sigap-Klien|X-CSRF-Token/i.test(src.replace(/\/\/.*$/gm, '').replace(/\/\*[\s\S]*?\*\//g, ''))) temuan.push(`${rel}: header web di luar api-web.js`);
    for (const m of src.matchAll(/\bfetch\(([^;]*?)\)(?:\.catch|;|\s*$)/gm)) {
      if (rel === 'components/photo-upload.js' && /\.\.\.a\)/.test(m[0])) continue; // pembungkus fetchFn bawaan: opsi dari pemanggil
      if (!/credentials:\s*'omit'/.test(m[0])) temuan.push(`${rel}: fetch tanpa credentials omit -> ${m[0].slice(0, 60)}`);
    }
  }
  assert.deepEqual(temuan, []);
});

test('rantai Mini App (main.js, api.js, auth.js) tidak mengimpor api-web.js: mode Telegram tak berubah', () => {
  for (const rel of ['main.js', 'core/api.js', 'core/auth.js']) {
    assert.doesNotMatch(readFileSync(join(akar, rel), 'utf8'), /from\s+'[^']*api-web\.js'/, rel);
  }
});
