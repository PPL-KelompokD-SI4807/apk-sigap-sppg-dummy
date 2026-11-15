// R04-8 PR5: foto/lampiran di web. Unggah multipart lewat jalur cookie (X-Sigap-Klien + X-CSRF-Token + Idempotency-Key +
// X-SPPG-Id, tanpa Authorization), progres lewat XHR, CSRF ditolak -> muat ulang dan ulangi sekali dengan kunci yang sama,
// tanpa antrean offline, gambar privat lewat cookie sesi, pesan galat Indonesia. Data fiktif.
import test from 'node:test';
import assert from 'node:assert/strict';

globalThis.localStorage = globalThis.localStorage ?? { getItem: () => null, setItem() {}, removeItem() {} };
globalThis.sessionStorage = globalThis.sessionStorage ?? { getItem: () => null, setItem() {}, removeItem() {} };

const { buatKlienWeb, kirimXhr, kredensialBerkas: kredWebLangsung } = await import('../core/api-web.js');
const web = await import('../core/transport-web.js');
const { setSppgAktif, hapusSppgAktif } = await import('../core/sppg-aktif-web.js');
const { fetchLampiranBlob } = await import('../components/photo-upload.js');
const { kirimDenganAntrean, pesanUnggah } = await import('../components/lampiran-field.js');
const { pesanGalatUnggah, bodyUnggahTertunda } = await import('../core/lampiran-uploader.js');

const resp = (status, body, hdr = {}) => ({ ok: status >= 200 && status < 300, status, headers: { get: (n) => hdr[n] ?? null }, json: async () => body });
const foto = () => new File([new Uint8Array([0xff, 0xd8, 0xff])], 'foto.jpg', { type: 'image/jpeg' });

/** Klien web tiruan dengan sesi aktif (CSRF dari GET /auth/me). */
async function klienSesi({ tulis = [], xhrs = null, csrfBaru = ['CSRF-1'] } = {}) {
  const log = [];
  const antre = [...tulis];
  const csrf = [...csrfBaru];
  const fetchFn = async (url, init) => {
    log.push({ url, ...init });
    if (url.endsWith('/auth/me')) return resp(200, { jenis_sesi: 'web', csrf: csrf.shift() ?? 'CSRF-X', user: { id: 1 } });
    return antre.shift() ?? resp(201, { data: { id: 99 } });
  };
  const k = buatKlienWeb({ fetchFn, xhrFactory: xhrs ? () => xhrs.shift() ?? null : () => null });
  await k.muatSesi();
  return { k, log };
}

function xhrTiruan({ status = 201, body = { data: { id: 5 } }, gagal = false, hdr = {} } = {}) {
  const x = {
    hdr: {}, upload: {},
    open(m, u) { this.metode = m; this.url = u; },
    setRequestHeader(k, v) { this.hdr[k] = v; },
    getResponseHeader(n) { return hdr[n] ?? null; },
    send(b) {
      this.dikirim = b;
      queueMicrotask(() => {
        if (gagal) return this.onerror();
        this.upload.onprogress?.({ lengthComputable: true, loaded: 50, total: 100 });
        this.upload.onprogress?.({ lengthComputable: true, loaded: 100, total: 100 });
        this.status = status;
        this.responseText = JSON.stringify(body);
        this.onload();
      });
    },
  };
  return x;
}

test('unggah multipart (fetch): cookie same-origin + X-Sigap-Klien + X-CSRF-Token + Idempotency-Key + X-SPPG-Id, tanpa Authorization', async () => {
  setSppgAktif(4);
  const { k, log } = await klienSesi();
  const body = bodyUnggahTertunda({ modul: 'keluhan', kategori: 'foto', file: foto(), sppgId: null });
  await k.request('POST', '/lampiran', { body, key: 'kunci-1' });
  const u = log.at(-1);
  assert.equal(u.url, '/api/v1/lampiran');
  assert.equal(u.method, 'POST');
  assert.equal(u.credentials, 'same-origin');
  assert.ok(u.body instanceof FormData, 'multipart');
  assert.equal(u.body.get('modul'), 'keluhan');
  assert.ok(u.body.get('berkas') instanceof Blob);
  assert.equal(u.headers['X-Sigap-Klien'], 'web');
  assert.equal(u.headers['X-CSRF-Token'], 'CSRF-1');
  assert.equal(u.headers['Idempotency-Key'], 'kunci-1');
  assert.equal(u.headers['X-SPPG-Id'], '4');
  assert.equal('Content-Type' in u.headers, false, 'boundary multipart diisi browser');
  assert.equal('Authorization' in u.headers, false);
  assert.ok(!/token|csrf/i.test(u.url), 'tidak ada token di URL');
  hapusSppgAktif();
});

test('unggah dengan progres (XHR): header jalur cookie sama, progres 0-100, JSON dikembalikan', async () => {
  const x = xhrTiruan();
  const { k } = await klienSesi({ xhrs: [x] });
  const pct = [];
  const data = await k.request('POST', '/lampiran', { body: { modul: 'mutu', kategori: 'foto', berkas: foto() }, key: 'kunci-2', onProgress: (p) => pct.push(p) });
  assert.deepEqual(data, { data: { id: 5 } });
  assert.equal(x.metode, 'POST');
  assert.equal(x.url, '/api/v1/lampiran');
  assert.equal(x.hdr['X-Sigap-Klien'], 'web');
  assert.equal(x.hdr['X-CSRF-Token'], 'CSRF-1');
  assert.equal(x.hdr['Idempotency-Key'], 'kunci-2');
  assert.equal('Authorization' in x.hdr, false);
  assert.ok(x.dikirim instanceof FormData);
  assert.deepEqual(pct, [50, 100]);
});

test('403 csrf_ditolak pada unggah: CSRF dimuat ulang (/auth/me) lalu diulang SEKALI dengan Idempotency-Key yang sama', async () => {
  const { k, log } = await klienSesi({ tulis: [resp(403, { alasan: 'csrf_ditolak' }), resp(201, { data: { id: 7 } })], csrfBaru: ['CSRF-1', 'CSRF-2'] });
  const data = await k.request('POST', '/lampiran', { body: { modul: 'keluhan', kategori: 'foto', berkas: foto() }, key: 'kunci-3' });
  assert.equal(data.data.id, 7);
  const unggah = log.filter((l) => l.url === '/api/v1/lampiran');
  assert.equal(unggah.length, 2);
  assert.equal(unggah[0].headers['X-CSRF-Token'], 'CSRF-1');
  assert.equal(unggah[1].headers['X-CSRF-Token'], 'CSRF-2');
  assert.equal(unggah[0].headers['Idempotency-Key'], unggah[1].headers['Idempotency-Key']);
});

test('galat unggah web: 413/422/429/403/404/401 menjadi ApiError dengan pesan Indonesia; 429 membawa Retry-After', async () => {
  const kasus = [
    [resp(413, null), 413, /5 MB/],
    [resp(422, { message: 'The berkas field must be an image.', errors: { berkas: ['Hanya gambar JPEG, PNG, atau WebP yang diizinkan.'] } }), 422, /JPEG, PNG, atau WebP/],
    [resp(429, { message: 'Too Many Attempts.' }, { 'Retry-After': '30' }), 429, /Tunggu sebentar/],
    [resp(403, { message: 'This action is unauthorized.' }), 403, /hak/],
    [resp(404, { message: 'Not Found' }), 404, /tidak ditemukan/],
    [resp(401, { message: 'Unauthenticated.' }), 401, /Sesi Anda telah berakhir/],
  ];
  for (const [r, status, pola] of kasus) {
    const { k } = await klienSesi({ tulis: [r] });
    const e = await k.request('POST', '/lampiran', { body: { modul: 'keluhan', kategori: 'foto', berkas: foto() }, key: 'k' }).catch((x) => x);
    assert.equal(e.status, status);
    assert.match(pesanUnggah(e), pola, `pesanUnggah ${status}`);
    assert.match(pesanGalatUnggah(e), pola, `pesanGalatUnggah ${status}`);
    assert.ok(!/unauthorized|Too Many|Not Found|must be/i.test(pesanUnggah(e)), `tanpa pesan Inggris (${status})`);
    if (status === 429) assert.equal(e.retryAfter, 30);
  }
  const csrf = { status: 403, data: { alasan: 'csrf_ditolak' } };
  assert.match(pesanUnggah(csrf), /Muat ulang halaman/);
});

test('web tanpa antrean offline: jaringan putus -> ApiError status 0 dilempar (tidak disimpan lokal)', async () => {
  const { k } = await klienSesi({ xhrs: [xhrTiruan({ gagal: true })] });
  const e = await k.request('POST', '/lampiran', { body: { modul: 'mutu', kategori: 'foto', berkas: foto() }, key: 'k', onProgress: () => {} }).catch((x) => x);
  assert.equal(e.status, 0);
  assert.equal(await web.antreOffline({ id: 'k' }), false);
  const send = async () => { throw Object.assign(new Error('offline'), { status: 0 }); };
  await assert.rejects(kirimDenganAntrean({ path: '/lampiran', body: {}, key: 'k' }, { request: send, enqueue: web.antreOffline }), (x) => x.status === 0);
  assert.match(pesanUnggah({ status: 0 }), /Coba lagi/);
});

test('transport-web: uploadMultipart memakai klien cookie (bukan Bearer) dan melapor 100 di akhir', async () => {
  const log = [];
  const asli = globalThis.fetch;
  globalThis.fetch = async (url, init) => {
    log.push({ url, ...init });
    if (url.endsWith('/auth/me')) return resp(200, { jenis_sesi: 'web', csrf: 'CSRF-T', user: { id: 2 } });
    return resp(201, { data: { id: 12 } });
  };
  try {
    const { klienWeb } = await import('../core/api-web.js');
    await klienWeb.muatSesi();
    const pct = [];
    const r = await web.uploadMultipart('/lampiran', { modul: 'keluhan', kategori: 'foto', berkas: foto() }, { key: 'kunci-t', onProgress: (p) => pct.push(p) });
    assert.equal(r.data.id, 12);
    const u = log.at(-1);
    assert.equal(u.headers['X-CSRF-Token'], 'CSRF-T');
    assert.equal(u.headers['Idempotency-Key'], 'kunci-t');
    assert.equal(u.credentials, 'same-origin');
    assert.equal('Authorization' in u.headers, false);
    assert.equal(pct.at(-1), 100);
    klienWeb.reset();
  } finally { globalThis.fetch = asli; }
});

test('gambar privat di web: cookie sesi (same-origin) + X-Sigap-Klien + X-SPPG-Id, tanpa Authorization dan tanpa token di URL', async () => {
  assert.equal(web.kredensialBerkas, kredWebLangsung);
  setSppgAktif(8);
  let seen;
  const blob = new Blob(['x'], { type: 'image/jpeg' });
  const fetchFn = async (url, init) => { seen = { url, init }; return { ok: true, status: 200, blob: async () => blob }; };
  const { getSppgAktif } = await import('../core/sppg-aktif-web.js');
  assert.equal(await fetchLampiranBlob('/api/v1/lampiran/12', { fetchFn, kredensial: web.kredensialBerkas(), sppgId: getSppgAktif() }), blob);
  assert.equal(seen.url, '/api/v1/lampiran/12');
  assert.equal(seen.init.credentials, 'same-origin');
  assert.equal(seen.init.headers['X-Sigap-Klien'], 'web');
  assert.equal(seen.init.headers['X-SPPG-Id'], '8');
  assert.equal('Authorization' in seen.init.headers, false);
  // 401 (cookie habis) -> pesan sesi Indonesia; URL eksternal ditolak sebelum fetch.
  await assert.rejects(() => fetchLampiranBlob('/api/v1/lampiran/1', { fetchFn: async () => ({ ok: false, status: 401 }), kredensial: web.kredensialBerkas() }), /Sesi Anda telah berakhir/);
  await assert.rejects(() => fetchLampiranBlob('https://evil.example/api/v1/lampiran/1', { fetchFn, kredensial: web.kredensialBerkas() }), /tidak valid/);
  hapusSppgAktif();
});

test('kirimXhr: galat jaringan/batal dilempar; header dipasang apa adanya', async () => {
  const x = xhrTiruan({ gagal: true });
  await assert.rejects(kirimXhr(() => x, '/api/v1/lampiran', { method: 'POST', headers: { A: '1' }, body: new FormData() }));
  assert.equal(x.hdr.A, '1');
});
