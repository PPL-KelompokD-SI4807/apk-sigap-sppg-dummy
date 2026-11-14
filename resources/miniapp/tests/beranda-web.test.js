// R04-8 PR3: render smoke beranda web per peran (fixture peta.js = cermin keluaran server), menu dari peta hak, tanpa fitur Telegram-only.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

class Node_ {}
const buatEl = (tag) => {
  const el = new Node_();
  Object.assign(el, {
    tag, attrs: {}, listeners: {}, anak: [], className: '', dataset: {}, style: { cssText: '' },
    setAttribute(k, v) { this.attrs[k] = String(v); },
    addEventListener(n, fn) { this.listeners[n] = fn; },
    append(...xs) { this.anak.push(...xs); },
  });
  return el;
};
globalThis.Node = Node_;
globalThis.document = { createElement: buatEl, createTextNode: (t) => Object.assign(new Node_(), { teks: String(t) }) };
const mem = () => { const m = new Map(); return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) }; };
globalThis.localStorage = mem();
globalThis.sessionStorage = mem();
globalThis.window = globalThis.window ?? {};

const { renderBerandaWeb, renderSegera, PESAN_SEGERA, PESAN_PETA_RUSAK } = await import('../beranda-web.js');
const { menuUntuk } = await import('../modules/menu.js');
const { PROFIL, salin } = await import('./fixtures/peta.js');

// Metadata modul NYATA (modules/*/index.js), diimpor tanpa memanggil register().
const dir = fileURLToPath(new URL('../modules/', import.meta.url));
const MODUL = [];
for (const n of readdirSync(dir, { withFileTypes: true }).filter((d) => d.isDirectory()).map((d) => d.name).sort()) {
  let ada = true;
  try { readFileSync(`${dir}${n}/index.js`); } catch { ada = false; }
  if (!ada) continue;
  const mod = await import(`../modules/${n}/index.js`);
  MODUL.push(...[mod.default].flat().filter(Boolean));
}

const semua = (n, f) => { if (n instanceof Node_) { f(n); (n.anak || []).forEach((x) => semua(x, f)); } };
const teks = (el) => { const t = []; semua(el, (n) => { if (n.teks != null) t.push(n.teks); }); return t.join(' | '); };
const tautan = (el) => { const t = []; semua(el, (n) => { if (n.attrs?.href) t.push(n.attrs.href); }); return t; };
const render = (user) => { const el = buatEl('div'); renderBerandaWeb(el, { user, menu: menuUntuk(user, MODUL) }); return el; };

test('registry nyata memuat modul dan menu setiap peran sama dengan menuUntuk dari peta server', () => {
  assert.ok(MODUL.length >= 15);
  for (const nama of Object.keys(PROFIL)) {
    const user = salin(nama);
    const href = tautan(render(user));
    for (const m of menuUntuk(user, MODUL)) assert.ok(href.includes(`#/${m.slug}`), `${nama}: menu ${m.slug} hilang`);
    // Tidak ada kartu modul di luar peta: tiap tautan '#/slug' (selain pintasan tetap) berasal dari menu.
    const tetap = new Set(['#/', '#/sesi', '#/dashboard', '#/penerima-manfaat-agregat']);
    const dari = new Set(menuUntuk(user, MODUL).map((m) => `#/${m.slug}`));
    for (const x of href) assert.ok(tetap.has(x) || dari.has(x), `${nama}: tautan tak dikenal ${x}`);
  }
});

test('peran kantor: nama, peran, SPPG, menu modul, dan tautan Sesi dan Browser tampil', () => {
  for (const nama of ['admin', 'kepala_sppg', 'akuntan', 'investor']) {
    const user = salin(nama);
    const t = teks(render(user));
    assert.ok(t.includes(user.name), nama);
    assert.ok(t.includes(user.role_label), nama);
    if (user.sppg_name) assert.ok(t.includes(user.sppg_name), nama);
    assert.ok(t.includes('Sesi dan Browser'), nama);
    assert.ok(tautan(render(user)).filter((x) => x !== '#/sesi').length > 0, nama);
  }
});

test('hak berbeda per peran: akuntan punya keuangan, relawan tanpa unit tidak; investor tanpa jadwal', () => {
  const href = (n) => tautan(render(salin(n)));
  assert.ok(href('akuntan').includes('#/keuangan'));
  assert.ok(!href('relawan_tanpa_unit').includes('#/keuangan'));
  assert.ok(!href('investor').includes('#/jadwal'));
  assert.ok(href('kepala_sppg').includes('#/jadwal'));
});

test('peran lapangan: menu tampil sesuai peta; layar modul = "Segera tersedia di web"', () => {
  for (const nama of ['relawan_dapur', 'relawan_distribusi', 'staf_gudang']) {
    const user = salin(nama);
    assert.ok(tautan(render(user)).length > 1, nama);
  }
  const el = buatEl('div');
  renderSegera(el, 'Bahan Baku');
  assert.ok(teks(el).includes(PESAN_SEGERA));
  assert.equal(PESAN_SEGERA, 'Segera tersedia di web');
});

test('peta hak hilang/rusak: gagal tertutup (tanpa kartu modul, ada peringatan)', () => {
  for (const user of [{ id: 1, name: 'X', role: 'admin' }, { ...salin('admin'), permissions: 'rusak' }, null]) {
    const el = render(user);
    assert.ok(teks(el).includes(PESAN_PETA_RUSAK));
    assert.deepEqual(tautan(el), ['#/sesi']);
  }
});

test('Telegram-only tidak ada di beranda/rute web: draf AI, ?r=, share t.me, undangan, antrean offline, pilih SPPG', () => {
  for (const nama of Object.keys(PROFIL)) {
    const el = render(salin(nama));
    assert.ok(!/draf|t\.me|pilih-sppg|\?r=/i.test(tautan(el).join(' ')), `${nama}: tautan Telegram-only`);
    assert.ok(!/draf offline|Kirim sekarang|Ganti SPPG|Bagikan|Salin tautan/i.test(teks(el)), `${nama}: teks Telegram-only`);
  }
  const web = ['../beranda-web.js', '../rute-web.js', '../web-main.js'].map((f) => readFileSync(new URL(f, import.meta.url), 'utf8')).join('\n');
  assert.ok(!/\/draf\/|parseRuteDeepLink|deeplink|buildShareUrl|invite-dialog|offline-queue|pilih-sppg|getTg/.test(web.replace(/\/\/.*$/gm, '')));
});
