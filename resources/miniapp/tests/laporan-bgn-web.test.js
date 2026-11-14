// Ekspor Laporan BGN di WEB (R04-8): rute registerWeb per profil, tombol di dalam layar (tanpa bilah bawah), nomor + PDF dengan
// Idempotency-Key, pesan 422 telegram_belum_terhubung, tanpa Authorization / header X-SPPG-Id.
import test from 'node:test';
import assert from 'node:assert/strict';

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
    scrollIntoView() {}, focus() {}, remove() {},
  });
  return el;
};
const bar = { mainbar: buatEl('div'), mainbtn: buatEl('button') };
const mem = () => { const m = new Map(); return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) }; };
globalThis.Node = Node_;
globalThis.document = {
  createElement: buatEl, createTextNode: (t) => Object.assign(new Node_(), { teks: String(t) }),
  getElementById: (id) => bar[id] ?? null, body: { classList: { add() {}, remove() {} }, append() {} }, title: '',
};
globalThis.localStorage = mem();
globalThis.sessionStorage = mem();
globalThis.location = { hash: '', replace(h) { this.hash = h; } };
globalThis.window = { scrollTo() {}, addEventListener() {}, location: globalThis.location };
globalThis.matchMedia = () => ({ matches: false, addEventListener() {} });

const panggilan = [];
let skrip = [];
const resp = (status, body) => ({ ok: status < 300, status, headers: { get: () => null }, json: async () => body });
globalThis.fetch = async (url, init) => {
  const u = new URL(url, 'http://x');
  const path = u.pathname.replace('/api/v1', '');
  panggilan.push({ metode: init.method, path, headers: init.headers, body: init.body ? JSON.parse(init.body) : null });
  if (init.method === 'GET') return resp(200, { data: { penandatangan: { pihak_kedua: { kandidat: [] }, mengetahui: { kandidat: [] } } } });
  return skrip.length ? skrip.shift() : resp(202, { message: 'ok' });
};

const { setPlatform } = await import('../core/platform.js');
setPlatform('web');
const { setUserSesi, getUser } = await import('../core/auth.js');
const { startRouter, resolve, cekAkses } = await import('../core/router.js');
const { daftarkanRuteWeb } = await import('../rute-web.js');
const { default: modul } = await import('../modules/laporan-bgn/index.js');
const { salin, PROFIL } = await import('./fixtures/peta.js');

daftarkanRuteWeb([modul], getUser);

const semua = (n, f) => { if (n instanceof Node_) { f(n); (n.anak || []).forEach((x) => semua(x, f)); } };
const teks = (el) => { const t = []; semua(el, (n) => { if (n.teks != null) t.push(n.teks); if (n.textContent) t.push(n.textContent); }); return t.join(' | '); };
const tombol = (el) => { const t = []; semua(el, (n) => { if (n.tag === 'button') t.push(n); }); return t; };
const outlet = buatEl('main');
await startRouter(outlet);
async function buka() {
  panggilan.length = 0;
  location.hash = '#/laporan-bgn';
  await resolve();
  await new Promise((r) => setTimeout(r, 15));
  return outlet;
}
const tulis = () => panggilan.filter((p) => p.metode !== 'GET');
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-9a-f][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const btnKirim = (el) => tombol(el).find((b) => /^Kirim PDF ke Telegram$|^Memproses/.test(teks(b)));

const HARAP = { admin: 'boleh', kepala_sppg: 'boleh', akuntan: 'boleh', investor: 'boleh' };

test('registerWeb: /laporan-bgn boleh untuk admin, kepala_sppg, akuntan, investor; ditolak untuk profil lain', () => {
  for (const nama of Object.keys(PROFIL)) {
    setUserSesi(salin(nama));
    assert.equal(cekAkses('/laporan-bgn'), HARAP[nama] ?? 'ditolak', nama);
  }
});

test('layar tampil di web: tombol kirim di dalam layar, tanpa bilah bawah; hanya GET saat memuat; tanpa Authorization/X-SPPG-Id', async () => {
  setUserSesi(salin('kepala_sppg'));
  const el = await buka();
  assert.ok(btnKirim(el), 'tombol Kirim PDF ke Telegram ada di layar');
  assert.ok(!teks(el).includes('Segera tersedia di web'));
  assert.ok(!bar.mainbtn.onclick, 'bilah bawah tidak dipasang di web');
  assert.ok(panggilan.every((p) => p.metode === 'GET' && !('Authorization' in p.headers) && !('X-SPPG-Id' in p.headers)));
});

test('kirim: nomor LPA lalu PDF masing-masing dengan Idempotency-Key UUID; pesan PDF dikirim ke bot; tombol kembali aktif', async () => {
  setUserSesi(salin('akuntan'));
  const el = await buka();
  const select = [];
  semua(el, (n) => { if (n.attrs?.id === 'f-jenis') select.push(n); });
  select[0].value = '30b'; // bernomor
  await select[0].listeners.change();
  skrip = [resp(201, { data: { nomor: '01/LPA/XI/2026', baru: true } }), resp(202, { message: 'ok' })];
  const b = btnKirim(el);
  await b.listeners.click();
  await new Promise((r) => setTimeout(r, 15));
  const t = tulis();
  assert.equal(t.length, 2);
  assert.ok(t[0].path.endsWith('/nomor') && t[1].path.endsWith('/pdf'));
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.match(t[1].headers['Idempotency-Key'], UUID);
  assert.ok(t.every((p) => !('Authorization' in p.headers) && !('X-SPPG-Id' in p.headers)));
  assert.ok(teks(el).includes('Nomor LPA: 01/LPA/XI/2026'));
  assert.ok(teks(el).includes('PDF dikirim lewat bot Telegram'));
  assert.equal(b.disabled, false);
  assert.equal(b.textContent, 'Kirim PDF ke Telegram');
});

test('investor: tanpa langkah nomor (tanpa aksi terbitkan) dan tanpa opsi 30b/30d-C', async () => {
  setUserSesi(salin('investor'));
  const el = await buka();
  const opsi = [];
  semua(el, (n) => { if (n.tag === 'option' && /Lampiran/.test(teks(n))) opsi.push(teks(n)); });
  assert.ok(opsi.length > 0 && !opsi.some((o) => /30b|30d-C/.test(o)));
  skrip = [resp(202, { message: 'ok' })];
  await btnKirim(el).listeners.click();
  await new Promise((r) => setTimeout(r, 15));
  assert.ok(tulis().every((p) => p.path.endsWith('/pdf')), 'tidak ada POST nomor');
});

test('422 telegram_belum_terhubung (tanpa errors): pesan tetap; tombol tidak macet', async () => {
  setUserSesi(salin('kepala_sppg'));
  const el = await buka();
  skrip = [resp(422, { message: 'Akun milik SPPG Rahasia belum terhubung', code: 'telegram_belum_terhubung' })];
  const b = btnKirim(el);
  await b.listeners.click();
  await new Promise((r) => setTimeout(r, 15));
  assert.ok(teks(el).includes('Akun Anda belum terhubung ke Telegram.'));
  assert.ok(!teks(el).includes('Rahasia'));
  assert.equal(b.disabled, false);
});

test('admin: wajib memilih SPPG; sppg_id masuk badan permintaan (bukan header)', async () => {
  setUserSesi(salin('admin'));
  const el = await buka();
  let kirim = btnKirim(el);
  await kirim.listeners.click();
  assert.ok(teks(el).includes('Pilih SPPG terlebih dahulu.'));
  assert.equal(tulis().length, 0);
  const sel = [];
  semua(el, (n) => { if (n.attrs?.id === 'f-sppg') sel.push(n); });
  sel[0].value = '4';
  await sel[0].listeners.change();
  skrip = [resp(202, { message: 'ok' })];
  await btnKirim(el).listeners.click();
  await new Promise((r) => setTimeout(r, 15));
  const pdf = tulis().find((p) => p.path.endsWith('/pdf'));
  assert.equal(pdf.body.sppg_id, 4);
  assert.ok(!('X-SPPG-Id' in pdf.headers));
});
