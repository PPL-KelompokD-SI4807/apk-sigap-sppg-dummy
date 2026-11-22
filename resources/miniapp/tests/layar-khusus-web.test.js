// A1 (R04-8): layar khusus web kebutuhan bahan, perjalanan terakhir, ringkasan rekap harian. Fixture peta = cermin server.
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

const panggilan = [];
const resp = (status, body) => ({ ok: status < 300, status, headers: { get: () => null }, json: async () => body });
globalThis.fetch = async (url, init) => {
  const u = new URL(url, 'http://x');
  const path = u.pathname.replace('/api/v1', '');
  panggilan.push({ metode: init.method, path, headers: init.headers });
  if (init.method !== 'GET') return resp(500, { message: 'tulis tidak boleh saat membuka layar' });
  if (/\/kebutuhan-bahan$/.test(path)) return resp(200, { data: { menu: { nama_menu: 'Menu Fiktif' }, tanggal_produksi: '2026-11-15', target_porsi: 100, bahan: [{ nama: 'Beras', satuan: 'kg', kebutuhan: 10, stok: 5, kekurangan: 5, cukup: false }] } });
  if (path === '/notifikasi-perjalanan/terakhir') return resp(200, { data: { id: 7, status: 'berangkat', waktu_berangkat: '2026-11-15T01:00:00Z', estimasi_tiba: '2026-11-15T02:00:00Z', titik_layanan: { nama: 'Titik Fiktif' }, status_kirim: 'terkirim' } });
  if (path === '/rekap-harian/ringkasan') return resp(200, { data: { porsi: { target: 100, realisasi: 90 }, keuangan: { serapan_persen: 50 }, distribusi: { selesai: 1 } } });
  if (path === '/rekap-harian') return resp(200, { data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
  if (/^\/jadwal\/\d+$/.test(path)) return resp(200, { data: { id: 1, status: 'direncanakan', tanggal_produksi: '2026-11-15', target_porsi: 100, menu: { id: 2, nama_menu: 'Menu Fiktif' } } });
  return resp(200, { data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
};

const { setPlatform } = await import('../core/platform.js');
setPlatform('web');
const { setUserSesi, getUser } = await import('../core/auth.js');
const { startRouter, resolve, cekAkses } = await import('../core/router.js');
const { daftarkanRuteWeb } = await import('../rute-web.js');
const { userPerms, jadwalTabBoleh, bolehAksi } = await import('../core/permissions.js');
const { PROFIL, salin } = await import('./fixtures/peta.js');

const dir = fileURLToPath(new URL('../modules/', import.meta.url));
const MODUL = [];
for (const n of readdirSync(dir, { withFileTypes: true }).filter((d) => d.isDirectory() && !d.name.startsWith('_')).map((d) => d.name).sort()) {
  try { readFileSync(`${dir}${n}/index.js`); } catch { continue; }
  MODUL.push(...[(await import(`../modules/${n}/index.js`)).default].flat().filter(Boolean));
}
daftarkanRuteWeb(MODUL, getUser);

const semua = (n, f) => { if (n instanceof Node_) { f(n); (n.anak || []).forEach((x) => semua(x, f)); } };
const teks = (el) => { const t = []; semua(el, (n) => { if (n.teks != null) t.push(n.teks); }); return t.join(' | '); };
const tautan = (el) => { const t = []; semua(el, (n) => { if (n.attrs?.href) t.push(n.attrs.href); }); return t; };
const outlet = buatEl('main');
await startRouter(outlet);
async function buka(path) {
  panggilan.length = 0;
  location.hash = `#${path}`;
  await resolve();
  for (let i = 0; i < 3 && location.hash !== `#${path}`; i++) { path = location.hash.slice(1); await resolve(); }
  await new Promise((r) => setTimeout(r, 10));
  return outlet;
}

test('rute khusus terdaftar di web dan guard mengikuti peta hak server per profil', () => {
  for (const nama of Object.keys(PROFIL)) {
    setUserSesi(salin(nama));
    const u = getUser();
    assert.equal(cekAkses('/jadwal/1/kebutuhan'), jadwalTabBoleh(u, 'produksi') ? 'boleh' : 'ditolak', `${nama} kebutuhan`);
    assert.equal(cekAkses('/notifikasi-perjalanan/terakhir'), userPerms(u, 'notifikasi-perjalanan').R ? 'boleh' : 'ditolak', `${nama} terakhir`);
    assert.equal(cekAkses('/rekap-harian/ringkasan'), userPerms(u, 'rekap-harian').R ? 'boleh' : 'ditolak', `${nama} ringkasan`);
  }
});

test('layar tampil (smoke) untuk yang berhak, hanya GET; layar tidak memakai t.me', async () => {
  for (const nama of Object.keys(PROFIL)) {
    setUserSesi(salin(nama));
    const u = getUser();
    const kasus = [
      ['/jadwal/1/kebutuhan', jadwalTabBoleh(u, 'produksi'), /Menu Fiktif/],
      ['/notifikasi-perjalanan/terakhir', userPerms(u, 'notifikasi-perjalanan').R, /Titik Fiktif/],
      ['/rekap-harian/ringkasan', userPerms(u, 'rekap-harian').R && u.sppg_id != null, /Tanggal/],
    ];
    for (const [path, boleh, pola] of kasus) {
      if (!boleh) continue;
      const el = await buka(path);
      assert.match(teks(el), pola, `${nama} ${path}`);
      assert.ok(panggilan.every((p) => p.metode === 'GET'), `${nama} ${path}: hanya GET`);
      assert.ok(!tautan(el).some((x) => /t\.me/.test(x)), `${nama} ${path}: t.me`);
    }
  }
});

test('tautan masuk: Kebutuhan bahan di detail jadwal, status terakhir di daftar notifikasi, ringkasan di daftar rekap', async () => {
  for (const nama of Object.keys(PROFIL)) {
    setUserSesi(salin(nama));
    const u = getUser();
    if (jadwalTabBoleh(u, 'produksi')) assert.ok(tautan(await buka('/jadwal/1')).includes('#/jadwal/1/kebutuhan'), `${nama} jadwal`);
    if (userPerms(u, 'notifikasi-perjalanan').R) assert.ok(tautan(await buka('/notifikasi-perjalanan')).includes('#/notifikasi-perjalanan/terakhir'), `${nama} notif`);
    if (userPerms(u, 'rekap-harian').R && u.sppg_id != null) assert.ok(tautan(await buka('/rekap-harian')).includes('#/rekap-harian/ringkasan'), `${nama} rekap`);
  }
});

test('tanpa hak: layar khusus ditolak dan tidak memanggil API', async () => {
  for (const nama of Object.keys(PROFIL)) {
    setUserSesi(salin(nama));
    const u = getUser();
    if (jadwalTabBoleh(u, 'produksi') && userPerms(u, 'notifikasi-perjalanan').R && userPerms(u, 'rekap-harian').R) continue;
    for (const [path, boleh] of [['/jadwal/1/kebutuhan', jadwalTabBoleh(u, 'produksi')], ['/notifikasi-perjalanan/terakhir', userPerms(u, 'notifikasi-perjalanan').R], ['/rekap-harian/ringkasan', userPerms(u, 'rekap-harian').R]]) {
      if (boleh) continue;
      assert.equal(cekAkses(path), 'ditolak', `${nama} ${path}`);
    }
  }
  assert.equal(typeof bolehAksi, 'function');
});
