// R04-8 PR5b (perapian hasil tinjauan T3 #130): investor tidak pernah memanggil GET /lampiran di detail keluhan dan mutu,
// object URL dicabut saat render ulang rute yang sama, 401 pada gambar privat memicu sesi-habis di web (Mini App tanpa efek).
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

class Node_ {}
const buatEl = (tag) => {
  const el = new Node_();
  Object.assign(el, {
    tag, attrs: {}, listeners: {}, anak: [], className: '', dataset: {}, style: { cssText: '' }, value: '', textContent: '', disabled: false, hidden: false,
    classList: { add() {}, remove() {}, toggle() {} },
    setAttribute(k, v) { this.attrs[k] = String(v); },
    addEventListener(n, fn) { this.listeners[n] = fn; },
    append(...xs) { this.anak.push(...xs); },
    replaceChildren(...xs) { this.anak = [...xs]; },
    insertBefore(n) { this.anak.unshift(n); },
    remove() {},
  });
  return el;
};
const mem = () => { const m = new Map(); return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) }; };
globalThis.Node = Node_;
globalThis.document = {
  createElement: buatEl, createTextNode: (t) => Object.assign(new Node_(), { teks: String(t) }),
  getElementById: (id) => ({ mainbar: buatEl('div'), mainbtn: buatEl('button') }[id] ?? null), body: { classList: { add() {}, remove() {} }, append() {} }, title: '',
};
globalThis.localStorage = mem();
globalThis.sessionStorage = mem();
globalThis.location = { hash: '', replace(h) { this.hash = h; } };
globalThis.window = { scrollTo() {}, addEventListener() {}, location: globalThis.location };
globalThis.matchMedia = () => ({ matches: false, addEventListener() {} });

const log = [];
const resp = (status, body) => ({ ok: status < 300, status, headers: { get: () => null }, json: async () => body });
const LAMPIRAN = [{ id: 5, url: '/api/v1/lampiran/5', kategori: 'foto', ukuran: 2048 }];
const REC = { id: 1, nama: 'Contoh Fiktif', kategori: 'keamanan_pangan', status: 'baru', prioritas: 'biasa', lampiran: LAMPIRAN };
globalThis.fetch = async (url, init = {}) => {
  const u = new URL(url, 'http://x');
  const path = u.pathname.replace('/api/v1', '');
  log.push({ metode: init.method ?? 'GET', path });
  if (/^\/lampiran\/\d+$/.test(path)) return { ok: true, status: 200, headers: { get: () => null }, blob: async () => ({ type: 'image/jpeg' }) };
  if (/\/\d+$/.test(path)) return resp(200, { data: REC });
  return resp(200, { data: [REC], meta: { current_page: 1, last_page: 1, total: 1 } });
};

const { setPlatform } = await import('../core/platform.js');
setPlatform('web');
const { setUserSesi, getUser } = await import('../core/auth.js');
const { startRouter, resolve } = await import('../core/router.js');
const { daftarkanRuteWeb } = await import('../rute-web.js');
const { PROFIL, salin } = await import('./fixtures/peta.js');
const { trackUrl, jumlahUrlTerlacak, bersihkanUrlTerlacak } = await import('../core/url-objek.js');
const { fetchLampiranBlob } = await import('../components/photo-upload.js');
const { buatKlienWeb } = await import('../core/api-web.js');

const MODUL = [];
for (const n of ['keluhan', 'mutu']) MODUL.push(...[(await import(`../modules/${n}/index.js`)).default].flat().filter(Boolean));
daftarkanRuteWeb(MODUL, getUser);

const semua = (n, f) => { if (n instanceof Node_) { f(n); (n.anak || []).forEach((x) => semua(x, f)); } };
const teks = (el) => { const t = []; semua(el, (n) => { if (n.teks != null) t.push(n.teks); }); return t.join(' | '); };
const outlet = buatEl('main');
await startRouter(outlet);
async function buka(path) {
  log.length = 0;
  location.hash = `#${path}`;
  await resolve();
  await new Promise((r) => setTimeout(r, 20));
  return outlet;
}

test('investor: detail keluhan dan mutu tidak pernah memanggil GET /lampiran (foto tidak ditampilkan)', async () => {
  assert.ok(PROFIL.investor);
  for (const slug of ['keluhan', 'mutu']) {
    setUserSesi(salin('investor'));
    const el = await buka(`/${slug}/1`);
    assert.ok(log.some((p) => p.path === `/${slug}/1`), `${slug}: detail dimuat`);
    assert.equal(log.filter((p) => p.path.startsWith('/lampiran')).length, 0, `${slug}: tanpa GET /lampiran (${JSON.stringify(log)})`);
    assert.match(teks(el), /Foto tidak ditampilkan untuk peran Anda/, `${slug}: pesan peran`);
  }
});

test('kontrol: peran yang berhak (kepala_sppg) memuat gambar lewat GET /lampiran/{id} pada detail keluhan', async () => {
  const URLs = [];
  globalThis.URL.createObjectURL = (b) => { const u = `blob:uji-${URLs.length}`; URLs.push(u); return u; };
  globalThis.URL.revokeObjectURL = () => {};
  setUserSesi(salin('kepala_sppg'));
  await buka('/keluhan/1');
  assert.ok(log.some((p) => p.path === '/lampiran/5'), 'pemilik/pengelola memuat foto');
  bersihkanUrlTerlacak();
});

test('router mencabut object URL terlacak di awal render, juga saat rute yang sama dirender ulang', async () => {
  const dicabut = [];
  globalThis.URL.revokeObjectURL = (u) => dicabut.push(u);
  setUserSesi(salin('kepala_sppg'));
  await buka('/keluhan/1');
  bersihkanUrlTerlacak();
  dicabut.length = 0;
  trackUrl('blob:a'); trackUrl('blob:b');
  assert.equal(jumlahUrlTerlacak(), 2);
  await resolve(); // render ulang rute yang sama (hash tidak berubah, tanpa hashchange)
  assert.ok(dicabut.includes('blob:a') && dicabut.includes('blob:b'), `dicabut: ${dicabut}`);
  bersihkanUrlTerlacak(); // render ulang memuat gambar lagi (URL baru terlacak); bersihkan agar tes lain bersih
});

test('pemegang foto mencabut URL saat Hapus baris gagal dan saat state.remove (sumber)', () => {
  const src = readFileSync(fileURLToPath(new URL('../components/lampiran-field.js', import.meta.url)), 'utf8');
  assert.match(src, /lepasUrl\(url\); row\.el\.remove\(\)/, 'panel: Hapus baris gagal mencabut URL');
  assert.match(src, /const gone = state\.remove\(it\.key\); lepasUrl\(/, 'kolom form: state.remove mencabut URL');
  const router = readFileSync(fileURLToPath(new URL('../core/router.js', import.meta.url)), 'utf8');
  assert.match(router, /bersihkanUrlTerlacak\(\)/);
});

test('401 saat memuat gambar privat memanggil onSesiHabis dan tetap melempar pesan per gambar (status 401)', async () => {
  let n = 0;
  const f = async () => ({ ok: false, status: 401, json: async () => ({}) });
  await assert.rejects(
    () => fetchLampiranBlob('/api/v1/lampiran/1', { fetchFn: f, token: 't', onSesiHabis: () => { n += 1; } }),
    (e) => /Sesi Anda telah berakhir/.test(e.message) && e.status === 401,
  );
  assert.equal(n, 1);
  // 404 dan 403 biasa tidak memicu sesi habis
  for (const status of [403, 404]) {
    await assert.rejects(() => fetchLampiranBlob('/api/v1/lampiran/1', { fetchFn: async () => ({ ok: false, status, json: async () => ({}) }), token: 't', onSesiHabis: () => { n += 1; } }));
  }
  assert.equal(n, 1);
});

test('web: sesiBerkasHabis memicu event sesi-habis sekali saat sesi aktif; Mini App no-op', async () => {
  const fetchFn = async (url) => (url.endsWith('/auth/me')
    ? resp(200, { jenis_sesi: 'web', csrf: 'C', user: { id: 1 } })
    : resp(200, {}));
  const k = buatKlienWeb({ fetchFn });
  let kali = 0;
  k.onSesiHabis(() => { kali += 1; });
  k.sesiHabisDariBerkas();
  assert.equal(kali, 0, 'tanpa sesi: tidak ada event');
  await k.muatSesi();
  assert.equal(k.adaSesi(), true);
  k.sesiHabisDariBerkas();
  k.sesiHabisDariBerkas();
  assert.equal(kali, 1, 'sekali; sesi sudah dilupakan setelahnya');
  assert.equal(k.adaSesi(), false);
  const tel = await import('../core/transport.js');
  assert.equal(tel.sesiBerkasHabis(), undefined);
});
