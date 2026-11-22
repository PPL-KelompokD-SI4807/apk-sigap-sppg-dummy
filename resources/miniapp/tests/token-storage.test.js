// Tes penyimpanan token: token penuh -> localStorage, pra-login -> hanya memori, fallback, sinkron antar tab.
// Jalankan: node --test resources/miniapp/tests/*.test.js
import test from 'node:test';
import assert from 'node:assert/strict';

const mem = () => {
  const m = new Map();
  return { m, getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) };
};
const rusak = () => ({
  getItem: () => { throw new Error('SecurityError'); },
  setItem: () => { throw new Error('QuotaExceededError'); },
  removeItem: () => { throw new Error('SecurityError'); },
});
const KEY = 'sigap_token';

globalThis.sessionStorage = mem();
globalThis.localStorage = mem();
const handlers = {};
globalThis.window = {
  Telegram: { WebApp: { initData: 'query_id=ABC&hash=x', platform: 'android' } },
  addEventListener: (nama, fn) => { if (!handlers[nama]) handlers[nama] = fn; },
};

const api = await import('../core/api.js');
const auth = await import('../core/auth.js');
const { getToken, getJenisToken, setToken, request, tanganiStorage, onAuthRequired, onTokenDihapusLuar } = api;

const resp = (status, body) => ({ ok: status < 300, status, headers: { get: () => null }, json: async () => body });
const USER = { id: 1, name: 'Budi', role: 'kepala_sppg' };
const PENUH = { token: 'tok-penuh-1', token_jenis: 'penuh', user: USER };
const tick = () => new Promise((r) => setTimeout(r, 5));
const pasangFetch = (skrip) => {
  const calls = [];
  globalThis.fetch = async (url, init) => {
    const path = url.replace('/api/v1', '');
    calls.push({ path, auth: init.headers.Authorization ?? null });
    const q = skrip[path];
    if (!q) throw new Error(`fetch tak terduga: ${path}`);
    return q;
  };
  return calls;
};
let authReq = 0;
let hapusLuar = 0;
onAuthRequired(() => { authReq++; });
onTokenDihapusLuar(() => { hapusLuar++; });
const baru = () => { globalThis.localStorage = mem(); globalThis.sessionStorage = mem(); setToken(null); authReq = 0; hapusLuar = 0; };

async function loginPenuh() {
  pasangFetch({ '/auth/token/verifikasi': resp(200, PENUH) });
  await auth.verifikasiToken('t1', '123456');
}

test('token penuh disimpan di localStorage, bukan sessionStorage', async () => {
  baru();
  await loginPenuh();
  assert.equal(getToken(), 'tok-penuh-1');
  assert.equal(getJenisToken(), 'penuh');
  assert.equal(localStorage.getItem(KEY), 'tok-penuh-1');
  assert.equal(sessionStorage.m.size, 0);
  assert.ok(![...localStorage.m.values()].some((v) => /ABC|hash|password/i.test(v)), 'tanpa initData/password');
});

test('token pra-login hanya di memori: tidak ke localStorage maupun sessionStorage', async () => {
  baru();
  pasangFetch({ '/auth/telegram': resp(200, { langkah: 'masuk', token: 'pra-1', akun: {} }) });
  const hasil = await auth.login();
  assert.equal(hasil.langkah, 'masuk');
  assert.equal(getToken(), 'pra-1');
  assert.equal(getJenisToken(), 'pra_login');
  assert.equal(localStorage.getItem(KEY), null);
  assert.equal([...localStorage.m.values()].includes('pra-1'), false);
  assert.equal(sessionStorage.m.size, 0);
  setToken('pra-2'); // jenis bawaan = pra-login
  assert.equal(localStorage.getItem(KEY), null);
});

test('pra-login tidak menyisakan token penuh yang sebelumnya aktif', async () => {
  baru();
  await loginPenuh();
  setToken('pra-3', 'pra_login');
  assert.equal(localStorage.getItem(KEY), null, 'token penuh lama tak boleh tertinggal di storage');
  assert.equal(getToken(), 'pra-3');
});

test('logout menghapus token dari localStorage dan memori', async () => {
  baru();
  await loginPenuh();
  const calls = pasangFetch({ '/auth/logout': resp(200, {}) });
  await auth.logout();
  assert.equal(calls[0].auth, 'Bearer tok-penuh-1');
  assert.equal(getToken(), null);
  assert.equal(localStorage.getItem(KEY), null);
  assert.equal(auth.isLoggedIn(), false);
});

test('logout tetap membersihkan lokal walau server tak terjangkau', async () => {
  baru();
  await loginPenuh();
  globalThis.fetch = async () => { throw new TypeError('offline'); };
  await auth.logout();
  assert.equal(getToken(), null);
  assert.equal(localStorage.getItem(KEY), null);
});

test('bersihkanSesi (keluar-semua / hapus sesi ini) menghapus token', async () => {
  baru();
  await loginPenuh();
  auth.bersihkanSesi();
  assert.equal(getToken(), null);
  assert.equal(localStorage.getItem(KEY), null);
});

test('401 pada keluar-semua / hapus sesi: token terhapus dari storage', async () => {
  for (const [method, path] of [['POST', '/auth/keluar-semua'], ['DELETE', '/auth/sesi/9']]) {
    baru();
    await loginPenuh();
    pasangFetch({ [path]: resp(401, { message: 'Unauthenticated.' }) });
    await assert.rejects(request(method, path, { silent: true }), (e) => e.status === 401);
    await tick();
    assert.equal(localStorage.getItem(KEY), null);
    assert.equal(getToken(), null);
    assert.equal(authReq, 1);
  }
});

test('401 pada rute data: token penuh dihapus dari storage; pemulihan senyap menghasilkan pra-login (memori saja)', async () => {
  baru();
  await loginPenuh();
  pasangFetch({ '/data': resp(401, { message: 'Unauthenticated.' }), '/auth/telegram': resp(200, { langkah: 'masuk', token: 'pra-9', akun: {} }) });
  await assert.rejects(request('GET', '/data', { silent: true }), (e) => e.status === 401);
  await tick();
  assert.equal(localStorage.getItem(KEY), null);
  assert.equal(getToken(), 'pra-9');
  assert.equal(sessionStorage.m.size, 0);
});

test('403 perlu_login_penuh membuang token dari storage', async () => {
  baru();
  await loginPenuh();
  pasangFetch({ '/data': resp(403, { message: 'x', alasan: 'perlu_login_penuh' }) });
  await assert.rejects(request('GET', '/data', { silent: true }));
  assert.equal(localStorage.getItem(KEY), null);
  assert.equal(getToken(), null);
});

test('setToken(null) tidak menghapus token login baru dari tab lain', async () => {
  baru();
  await loginPenuh();
  localStorage.setItem(KEY, 'tok-dari-tab-lain'); // tab lain login ulang (menimpa)
  setToken(null);
  assert.equal(localStorage.getItem(KEY), 'tok-dari-tab-lain');
});

test('fallback: localStorage melempar di semua operasi -> tidak error, token hidup di memori selama sesi', async () => {
  baru();
  globalThis.localStorage = rusak();
  globalThis.sessionStorage = rusak();
  await loginPenuh();
  assert.equal(getToken(), 'tok-penuh-1');
  assert.equal(auth.isLoggedIn(), true);
  const calls = pasangFetch({ '/data': resp(200, { ok: 1 }) });
  await request('GET', '/data');
  assert.equal(calls[0].auth, 'Bearer tok-penuh-1');
  auth.bersihkanSesi(); // tidak melempar
  assert.equal(getToken(), null);
});

test('fallback: tulis gagal (kuota) tetapi baca ok -> tetap login di memori; event storage tab lain diabaikan', async () => {
  baru();
  const ls = mem();
  ls.setItem = () => { throw new Error('QuotaExceededError'); };
  globalThis.localStorage = ls;
  await loginPenuh();
  assert.equal(getToken(), 'tok-penuh-1');
  assert.equal(ls.m.has(KEY), false);
  tanganiStorage({ key: KEY, newValue: null });
  assert.equal(getToken(), 'tok-penuh-1', 'token memori-saja tidak ikut terhapus oleh event tab lain');
  assert.equal(authReq, 0);
});

test('startup: localStorage rusak/diblokir -> modul dimuat tanpa error, tak ada token', async () => {
  globalThis.localStorage = rusak();
  globalThis.sessionStorage = rusak();
  const m = await import('../core/api.js?start=rusak');
  assert.equal(m.getToken(), null);
  assert.equal(m.getJenisToken(), null);
});

test('startup: token penuh dipulihkan dari localStorage sebagai sesi penuh', async () => {
  globalThis.localStorage = mem();
  localStorage.setItem(KEY, 'tok-tersimpan-9');
  globalThis.sessionStorage = mem();
  const m = await import('../core/api.js?start=ada');
  assert.equal(m.getToken(), 'tok-tersimpan-9');
  assert.equal(m.getJenisToken(), 'penuh');
});

test('startup: nilai rusak di localStorage diabaikan dan dibuang', async () => {
  globalThis.localStorage = mem();
  localStorage.setItem(KEY, 'ada spasi dan\nbaris');
  globalThis.sessionStorage = mem();
  const m = await import('../core/api.js?start=rusakisi');
  assert.equal(m.getToken(), null);
  assert.equal(localStorage.getItem(KEY), null);
});

test('migrasi: token lama di sessionStorage tidak dipakai dan dihapus', async () => {
  globalThis.localStorage = mem();
  globalThis.sessionStorage = mem();
  sessionStorage.setItem('sigap_token', 'tok-lama-session');
  sessionStorage.setItem('sigap_token_jenis', 'penuh');
  sessionStorage.setItem('sigap_sppg_aktif', '3'); // kunci lain tak disentuh
  const m = await import('../core/api.js?start=migrasi');
  assert.equal(m.getToken(), null);
  assert.equal(sessionStorage.getItem('sigap_token'), null);
  assert.equal(sessionStorage.getItem('sigap_token_jenis'), null);
  assert.equal(sessionStorage.getItem('sigap_sppg_aktif'), '3');
  assert.equal(localStorage.getItem(KEY), null);
});

test('storage event terdaftar di window; logout di tab lain mengeluarkan tab ini', async () => {
  baru();
  assert.equal(typeof handlers.storage, 'function', 'api.js memasang listener storage');
  await loginPenuh();
  localStorage.removeItem(KEY); // tab lain keluar
  handlers.storage({ key: KEY, newValue: null, oldValue: 'tok-penuh-1' });
  assert.equal(getToken(), null);
  assert.equal(getJenisToken(), null);
  assert.equal(authReq, 1);
  assert.equal(hapusLuar, 1);
  assert.equal(auth.getUser(), null);
  assert.equal(auth.isLoggedIn(), false);
});

test('storage event: clear() di tab lain (key null) mengeluarkan dan mencabut token di server (best-effort)', async () => {
  baru();
  await loginPenuh();
  const calls = pasangFetch({ '/auth/logout': resp(200, {}) });
  localStorage.m.clear();
  tanganiStorage({ key: null, newValue: null });
  assert.equal(getToken(), null);
  assert.equal(authReq, 1);
  assert.deepEqual(calls.map((c) => [c.path, c.auth]), [['/auth/logout', 'Bearer tok-penuh-1']]);
});

test('storage event: nilai yang dihapus = token tab ini (logout di tab lain) -> keluar lokal tanpa panggilan server', async () => {
  baru();
  await loginPenuh();
  const calls = pasangFetch({ '/auth/logout': resp(200, {}) });
  localStorage.removeItem(KEY);
  tanganiStorage({ key: KEY, newValue: null, oldValue: 'tok-penuh-1' });
  assert.equal(getToken(), null);
  assert.equal(authReq, 1);
  assert.equal(calls.length, 0, 'server sudah mencabutnya');
});

test('storage event: nilai yang dihapus BUKAN token tab ini -> tab ini tetap masuk (tidak ada sesi yatim)', async () => {
  baru();
  await loginPenuh(); // tab ini memegang tok-penuh-1
  const calls = pasangFetch({ '/auth/logout': resp(200, {}) });
  localStorage.removeItem(KEY); // tab lain sempat menimpa dengan tok-B lalu keluar
  tanganiStorage({ key: KEY, newValue: null, oldValue: 'tok-B' });
  assert.equal(getToken(), 'tok-penuh-1');
  assert.equal(getJenisToken(), 'penuh');
  assert.equal(authReq, 0);
  assert.equal(calls.length, 0);
});

test('keluarSemua berhasil: kunci localStorage dihapus tanpa syarat (juga bila berisi token tab lain)', async () => {
  baru();
  await loginPenuh();
  localStorage.setItem(KEY, 'tok-tab-lain');
  pasangFetch({ '/auth/keluar-semua': resp(200, { jumlah_sesi: 2 }) });
  await auth.keluarSemua();
  assert.equal(localStorage.getItem(KEY), null);
});

test('keluarSemua gagal (500): kunci tidak dihapus', async () => {
  baru();
  await loginPenuh();
  pasangFetch({ '/auth/keluar-semua': resp(500, { message: 'x' }) });
  await assert.rejects(auth.keluarSemua());
  assert.equal(localStorage.getItem(KEY), 'tok-penuh-1');
});

test('login(): 403 dari /auth/telegram (akun nonaktif/tak terdaftar) membuang token tersimpan', async () => {
  baru();
  await loginPenuh();
  pasangFetch({ '/auth/telegram': resp(403, { message: 'Akun tidak terdaftar.' }) });
  await assert.rejects(auth.login(), (e) => e.status === 403);
  assert.equal(getToken(), null);
  assert.equal(localStorage.getItem(KEY), null);
  assert.equal(auth.getUser(), null);
});

test('storage event: kunci lain, atau token diganti login baru, tidak mengeluarkan tab ini', async () => {
  baru();
  await loginPenuh();
  tanganiStorage({ key: 'sigap_device_id', newValue: null });
  assert.equal(getToken(), 'tok-penuh-1');
  localStorage.setItem(KEY, 'tok-baru-tab-lain');
  tanganiStorage({ key: KEY, newValue: 'tok-baru-tab-lain' });
  assert.equal(getToken(), 'tok-penuh-1');
  assert.equal(authReq, 0);
});

test('storage event: token pra-login (memori) tidak terpengaruh', async () => {
  baru();
  setToken('pra-5');
  tanganiStorage({ key: KEY, newValue: null });
  assert.equal(getToken(), 'pra-5');
  assert.equal(authReq, 0);
});
