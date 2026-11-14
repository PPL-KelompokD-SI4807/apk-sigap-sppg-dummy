// R04-8 PR7: rute /ganti-sppg di web (router asli): hanya investor multi-SPPG, pilih SPPG, ganti SPPG memuat ulang beranda.
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
    scrollIntoView() {}, focus() {}, remove() {},
  });
  return el;
};
const mem = () => { const m = new Map(); return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) }; };
globalThis.Node = Node_;
globalThis.document = {
  createElement: buatEl, createTextNode: (t) => Object.assign(new Node_(), { teks: String(t) }),
  getElementById: () => null, body: { classList: { add() {}, remove() {} }, append() {} }, title: '',
};
globalThis.localStorage = mem();
globalThis.sessionStorage = mem();
globalThis.location = { hash: '', replace(h) { this.hash = h; } };
globalThis.window = { scrollTo() {}, addEventListener() {}, location: globalThis.location };
globalThis.matchMedia = () => ({ matches: false, addEventListener() {} });

const SPPG = [{ id: 1, kode: 'S-1', nama: 'SPPG Satu', status_operasional: 'aktif' }, { id: 2, kode: 'S-2', nama: 'SPPG Dua', status_operasional: 'aktif' }];
let respSaya = { data: SPPG, sppg_aktif_id: null, wajib_pilih: true, header: 'X-SPPG-Id' };
const resp = (status, body) => ({ ok: status < 300, status, headers: { get: () => null }, json: async () => body });
globalThis.fetch = async (url) => (new URL(url, 'http://x').pathname.endsWith('/sppg-saya') ? resp(200, respSaya) : resp(200, { data: [] }));

const { setPlatform } = await import('../core/platform.js');
setPlatform('web');
const { setUserSesi, getUser } = await import('../core/auth.js');
const { startRouter, resolve, cekAkses, route } = await import('../core/router.js');
const { daftarkanRuteWeb } = await import('../rute-web.js');
const { getSppgAktif, hapusSppgAktif } = await import('../core/sppg-aktif.js');
const { salin } = await import('./fixtures/peta.js');

let berandaDirender = 0;
route('/', () => { berandaDirender++; });
daftarkanRuteWeb([], getUser);

const semua = (n, f) => { if (n instanceof Node_) { f(n); (n.anak || []).forEach((x) => semua(x, f)); } };
const teks = (el) => { const t = []; semua(el, (n) => { if (n.teks != null) t.push(n.teks); }); return t.join(' | '); };
const tombol = (el) => { const t = []; semua(el, (n) => { if (n.tag === 'button') t.push(n); }); return t; };
const outlet = buatEl('main');
await startRouter(outlet);
const tunggu = () => new Promise((r) => setTimeout(r, 15));

test('/ganti-sppg hanya untuk investor multi-SPPG (peta server: multi_sppg)', () => {
  for (const nama of ['admin', 'kepala_sppg', 'akuntan', 'ahli_gizi']) { setUserSesi(salin(nama)); assert.equal(cekAkses('/ganti-sppg'), 'ditolak', nama); }
  setUserSesi(salin('investor'));
  assert.equal(cekAkses('/ganti-sppg'), 'boleh');
});

test('investor melihat daftar SPPG dan memilih satu: pilihan tersimpan, beranda dimuat ulang', async () => {
  setUserSesi(salin('investor'));
  hapusSppgAktif();
  location.hash = '#/ganti-sppg';
  await resolve(); await tunggu();
  const pilihan = tombol(outlet).filter((b) => /SPPG (Satu|Dua)/.test(teks(b)));
  assert.equal(pilihan.length, 2);
  const sebelum = berandaDirender;
  pilihan[1].listeners.click();
  await tunggu();
  assert.equal(getSppgAktif(), 2);
  assert.equal(location.hash, '#/');
  assert.ok(berandaDirender > sebelum, 'ganti SPPG memuat ulang layar (beranda dirender ulang)');
});

test('tanpa SPPG sama sekali: pesan tetap, tanpa pilihan', async () => {
  setUserSesi(salin('investor'));
  respSaya = { data: [], sppg_aktif_id: null, wajib_pilih: false, header: 'X-SPPG-Id' };
  hapusSppgAktif();
  location.hash = '#/ganti-sppg';
  await resolve(); await tunggu();
  assert.ok(teks(outlet).includes('Akun Anda belum ditautkan ke SPPG mana pun.'));
});
