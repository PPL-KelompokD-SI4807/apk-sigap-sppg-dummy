// Tes pemulihan sesi senyap pada 401 (tetap login 90 hari, keputusan pemilik A): node --test resources/miniapp/tests/
import test from 'node:test';
import assert from 'node:assert/strict';

const mem = () => { const m = new Map(); return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) }; };
globalThis.sessionStorage = mem();
globalThis.localStorage = mem();
globalThis.window = { Telegram: { WebApp: { initData: 'query_id=ABC&hash=segar', platform: 'android' } } };

const { request, uploadMultipart, flushQueue, setToken, ApiError, getToken, onSesiBerakhir, onPerluLoginUlang, onAuthRequired } = await import('../core/api.js');
const { verifikasiToken } = await import('../core/auth.js');

const resp = (status, body) => ({ ok: status < 300, status, headers: { get: () => null }, json: async () => body });
const USER = { id: 1, name: 'Budi', role: 'kepala_sppg' };
const expired = { message: 'Sesi Anda telah berakhir. Silakan masuk kembali.', alasan: 'sesi_kedaluwarsa' };

/** Pasang fetch skrip: peta path -> antrean respons. Mengembalikan daftar panggilan. */
function pasangFetch(skrip) {
  const calls = [];
  globalThis.fetch = async (url, init) => {
    const path = url.replace('/api/v1', '');
    calls.push({ path, auth: init.headers.Authorization ?? null, body: init.body ? JSON.parse(init.body) : null });
    const q = skrip[path];
    if (!q || !q.length) throw new Error(`fetch tak terduga: ${path}`);
    return q.length > 1 ? q.shift() : q[0];
  };
  return calls;
}

async function loginPenuh() {
  pasangFetch({ '/auth/token/verifikasi': [resp(200, { token: 'tok-lama', token_jenis: 'penuh', user: USER, expires_at: '2027-02-05T00:00:00Z' })] });
  await verifikasiToken('t1', '123456');
  assert.equal(getToken(), 'tok-lama');
}

const sesiBerakhir = [];
onSesiBerakhir(() => sesiBerakhir.push(1));
const formUlang = [];
onPerluLoginUlang((h) => formUlang.push(h));
const authReq = [];
onAuthRequired(() => authReq.push(1));
const reset = () => { sesiBerakhir.length = 0; formUlang.length = 0; authReq.length = 0; };

const tick = () => new Promise((r) => setTimeout(r, 5));

const MASUK = { langkah: 'masuk', token: 'pra-1', akun: { nama: 'Budi' } };
const paths = (calls) => calls.map((c) => c.path);

for (const [nama, body401] of [['dengan alasan sesi_kedaluwarsa', expired], ['tanpa alasan (token sudah dipangkas)', { message: 'Unauthenticated.' }]]) {
  test(`401 ${nama}: login senyap sekali tanpa Bearer, hasil masuk -> form, request asal tidak diulang`, async () => {
    reset();
    await loginPenuh();
    const calls = pasangFetch({ '/data': [resp(401, body401)], '/auth/telegram': [resp(200, MASUK)] });
    await assert.rejects(request('GET', '/data', { silent: true }), (e) => e.status === 401);
    await tick();
    assert.deepEqual(paths(calls), ['/data', '/auth/telegram']);
    assert.equal(calls[1].auth, null, 'login senyap tanpa Bearer lama');
    assert.equal(calls[1].body.init_data, 'query_id=ABC&hash=segar');
    assert.match(calls[1].body.device_id, /^[A-Za-z0-9_-]{22,128}$/);
    assert.deepEqual(formUlang.map((x) => x.langkah), ['masuk']);
    assert.deepEqual([sesiBerakhir.length, authReq.length], [0, 0]);
    assert.equal(getToken(), 'pra-1');
  });
}

test('initData basi (telegram 401): layar sesi berakhir, tanpa loop pada 401 berikutnya', async () => {
  reset();
  await loginPenuh();
  const calls = pasangFetch({ '/data': [resp(401, expired)], '/auth/telegram': [resp(401, { message: 'initData tidak valid.' })] });
  await assert.rejects(request('GET', '/data', { silent: true }), (e) => e.status === 401);
  await tick();
  assert.equal(sesiBerakhir.length, 1);
  assert.equal(getToken(), null);
  await assert.rejects(request('GET', '/data', { silent: true }), (e) => e.status === 401);
  await tick();
  assert.deepEqual(paths(calls), ['/data', '/auth/telegram', '/data'], 'telegram hanya dipanggil sekali');
  assert.equal(sesiBerakhir.length, 2);
});

test('401 dua request bersamaan: satu login senyap', async () => {
  reset();
  await loginPenuh();
  const calls = pasangFetch({ '/a': [resp(401, expired)], '/b': [resp(401, expired)], '/auth/telegram': [resp(200, MASUK)] });
  await Promise.allSettled([request('GET', '/a', { silent: true }), request('GET', '/b', { silent: true })]);
  await tick();
  assert.equal(calls.filter((c) => c.path === '/auth/telegram').length, 1);
  assert.equal(formUlang.length, 1);
});

test('401 terlambat dari request lama tidak menghapus token baru/pra-login', async () => {
  reset();
  await loginPenuh();
  let lepas;
  const calls = [];
  globalThis.fetch = (url) => { calls.push(url); return new Promise((r) => { lepas = () => r(resp(401, expired)); }); };
  const lama = request('GET', '/lambat', { silent: true });
  setToken('pra-baru'); // token berganti (mis. login ulang) saat request lama masih jalan
  lepas();
  await assert.rejects(lama, (e) => e.status === 401);
  await tick();
  assert.equal(getToken(), 'pra-baru');
  assert.equal(calls.length, 1, 'tanpa login senyap');
  assert.deepEqual([sesiBerakhir.length, formUlang.length, authReq.length], [0, 0, 0]);
});

test('unggahan 401: pulih ke form, unggahan tidak diulang otomatis', async () => {
  reset();
  await loginPenuh();
  const calls = pasangFetch({ '/auth/telegram': [resp(200, MASUK)] });
  const kirim = [];
  const xhr = {
    upload: {}, setRequestHeader() {}, open() {},
    send() { kirim.push(1); this.status = 401; this.responseText = JSON.stringify(expired); this.onload(); },
  };
  await assert.rejects(uploadMultipart('/foto', { a: 'b' }, { xhrFactory: () => xhr }), (e) => e.status === 401);
  await tick();
  assert.equal(kirim.length, 1);
  assert.deepEqual(paths(calls), ['/auth/telegram']);
  assert.equal(formUlang.length, 1);
});

test('401 pada logout/keluar-semua/hapus sesi: tanpa login senyap', async () => {
  for (const [method, path] of [['POST', '/auth/logout'], ['POST', '/auth/keluar-semua'], ['DELETE', '/auth/sesi/9']]) {
    reset();
    await loginPenuh();
    const calls = pasangFetch({ [path]: [resp(401, expired)] });
    await assert.rejects(request(method, path, { silent: true }), (e) => e.status === 401);
    await tick();
    assert.deepEqual(paths(calls), [path]);
    assert.equal(authReq.length, 1);
    assert.equal(formUlang.length + sesiBerakhir.length, 0);
  }
});

test('flushQueue tidak jalan dengan token pra-login; 403 perlu_login_penuh menghentikan tanpa menghapus draf', async () => {
  reset();
  await loginPenuh();
  pasangFetch({ '/data': [resp(401, expired)], '/auth/telegram': [resp(200, MASUK)] });
  await assert.rejects(request('GET', '/data', { silent: true }));
  await tick();
  assert.equal(getToken(), 'pra-1'); // token pra-login
  const item = { id: 'k1', method: 'POST', path: '/x', body: {}, label: 'Draf' };
  let terkirim = 0;
  const dihapus = [];
  const deps = { list: async () => [item], remove: async (id) => dihapus.push(id), schedule: false, notify() {} };
  assert.equal(await flushQueue({ ...deps, request: async () => { terkirim++; } }), 0);
  assert.equal(terkirim, 0);

  await loginPenuh();
  const tolak = async () => { throw new ApiError(403, 'x', null, { alasan: 'perlu_login_penuh' }); };
  assert.equal(await flushQueue({ ...deps, request: tolak }), 0);
  assert.deepEqual(dihapus, [], 'draf tidak dihapus');
});
