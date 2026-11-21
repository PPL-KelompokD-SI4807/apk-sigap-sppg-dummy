// Uji-peran P12: Rekap Harian web: label tombol, filter tanggal berlabel, dan status kirim yang dimuat ulang saat masih 'menunggu'.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
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
    prepend(n) { this.anak.unshift(n); },
    scrollIntoView() {}, focus() {}, remove() {},
  });
  return el;
};
const mem = () => { const m = new Map(); return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) }; };
const tetap = { mainbar: buatEl('div'), mainbtn: buatEl('button'), toasts: buatEl('div') };
const overlays = [];
globalThis.Node = Node_;
globalThis.document = {
  createElement: buatEl, createTextNode: (t) => Object.assign(new Node_(), { teks: String(t) }),
  getElementById: (id) => tetap[id] ?? null, body: { classList: { add() {}, remove() {} }, append: (n) => overlays.push(n) }, title: '',
};
globalThis.localStorage = mem();
globalThis.sessionStorage = mem();
globalThis.location = { hash: '', replace(h) { this.hash = h; } };
globalThis.window = { scrollTo() {}, addEventListener() {}, location: globalThis.location };
globalThis.matchMedia = () => ({ matches: false, addEventListener() {} });

const panggilan = [];
let skrip = [];
const resp = (status, body) => ({ ok: status < 300, status, headers: { get: () => null }, json: async () => body });
let rekapRec = { id: 4, tanggal: '2026-11-20', status_kirim: 'menunggu', catatan: null, disusun_oleh: null, disusun_pada: '2026-11-20T10:00:00Z', ringkasan: { porsi: { target: 100, realisasi: 90 }, keuangan: { serapan_persen: 50 }, distribusi: { selesai: 1 } } };
globalThis.fetch = async (url, init) => {
  const path = new URL(url, 'http://x').pathname.replace('/api/v1', '');
  panggilan.push({ metode: init.method, path, headers: init.headers });
  if (init.method === 'GET') {
    if (path === '/rekap-harian/4') return resp(200, { data: rekapRec });
    return resp(200, { data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
  }
  return resp(200, { data: { id: 4 } });
};

const { setPlatform } = await import('../core/platform.js');
setPlatform('web');
const { setUserSesi, getUser } = await import('../core/auth.js');
const { startRouter, resolve } = await import('../core/router.js');
const { daftarkanRuteWeb } = await import('../rute-web.js');
const { salin } = await import('./fixtures/peta.js');

const dir = fileURLToPath(new URL('../modules/', import.meta.url));
const MODUL = [];
for (const n of readdirSync(dir, { withFileTypes: true }).filter((d) => d.isDirectory() && !d.name.startsWith('_')).map((d) => d.name).sort()) {
  try { readFileSync(`${dir}${n}/index.js`); } catch { continue; }
  MODUL.push(...[(await import(`../modules/${n}/index.js`)).default].flat().filter(Boolean));
}
daftarkanRuteWeb(MODUL, getUser);

const semua = (n, f) => { if (n instanceof Node_) { f(n); (n.anak || []).forEach((x) => semua(x, f)); } };
const teks = (el) => { const t = []; semua(el, (n) => { if (n.teks != null) t.push(n.teks); if (n.textContent) t.push(n.textContent); }); return t.join(' | '); };
const cari = (el, id) => { let r = null; semua(el, (n) => { if (n.attrs?.id === id) r = n; }); return r; };
const tombol = (el) => { const t = []; semua(el, (n) => { if (n.tag === 'button') t.push(n); }); return t; };
const tautan = (el) => { const t = []; semua(el, (n) => { if (n.attrs?.href) t.push(n.attrs.href); }); return t; };
const outlet = buatEl('main');
await startRouter(outlet);
async function buka(path) {
  panggilan.length = 0;
  location.hash = `#${path}`;
  await resolve();
  await new Promise((r) => setTimeout(r, 15));
  return outlet;
}
const tulis = () => panggilan.filter((p) => p.metode !== 'GET');
const kirim = async () => { await tetap.mainbtn.onclick(); await new Promise((r) => setTimeout(r, 15)); };
function isi(el, nilai) { for (const [id, v] of Object.entries(nilai)) { const i = cari(el, `f-${id}`); assert.ok(i, `bidang ${id} ada`); i.value = v; i.listeners.input?.(); i.listeners.change?.(); } }
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-9a-f][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const kepala = () => setUserSesi(salin('kepala_sppg'));

const { userPerms } = await import('../core/permissions.js');

test('daftar rekap web: tombol "+ Susun Rekap" (sama dengan Mini App) dan dua filter tanggal berlabel', async () => {
  kepala();
  assert.equal(userPerms(getUser(), 'rekap-harian').C, true);
  const el = await buka('/rekap-harian');
  assert.ok(teks(el).includes('+ Susun Rekap'), teks(el));
  assert.ok(!teks(el).includes('+ Tambah'));
  assert.ok(teks(el).includes('Dari tanggal') && teks(el).includes('Sampai tanggal'), 'label filter terlihat');
});

test('detail rekap web: status "menunggu" dimuat ulang otomatis sampai berubah, tanpa muat ulang halaman', async () => {
  kepala();
  rekapRec = { ...rekapRec, status_kirim: 'menunggu' };
  let el = await buka('/rekap-harian/4');
  assert.ok(teks(el).includes('Menunggu dikirim'));
  rekapRec = { ...rekapRec, status_kirim: 'terkirim' };
  await new Promise((r) => setTimeout(r, 3500));
  assert.ok(panggilan.filter((p) => p.path === '/rekap-harian/4').length >= 2, 'GET detail diulang');
  assert.ok(teks(outlet).includes('Terkirim'), 'status diperbarui otomatis');
  rekapRec = { ...rekapRec, status_kirim: 'terkirim' };
  panggilan.length = 0;
  el = await buka('/rekap-harian/4');
  await new Promise((r) => setTimeout(r, 3500));
  assert.equal(panggilan.filter((p) => p.path === '/rekap-harian/4').length, 1, 'status final: tidak memantau');
});
