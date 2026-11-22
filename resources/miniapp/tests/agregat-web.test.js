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
const AGREGAT = { data: {
  sppg_id: 1, total: 120, aktif: 100, penyamaran: { diterapkan: true, penanda: '<5' },
  per_kelompok_sasaran: [{ kelompok_sasaran: 'siswa', label: 'Siswa', jumlah: 80, nama: 'JANGAN-TAMPIL', nik: '0000000000000000', alamat: 'Jl Rahasia' }, { label: 'Ibu Hamil', jumlah: null, penanda: '<5' }],
  per_titik_layanan: [{ titik_layanan_id: 7, nama: 'Posyandu Contoh', jumlah: 40, alamat: 'Jl Rahasia' }],
  penerima: [{ nama: 'JANGAN-TAMPIL', nik: '0000000000000000' }],
} };
globalThis.fetch = async (url, init) => {
  const path = new URL(url, 'http://x').pathname.replace('/api/v1', '');
  panggilan.push({ metode: init.method, path });
  if (path === '/penerima-manfaat/agregat') return resp(200, AGREGAT);
  return resp(200, { data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
};

const { setPlatform } = await import('../core/platform.js');
setPlatform('web');
const { setUserSesi, getUser } = await import('../core/auth.js');
const { startRouter, resolve, cekAkses } = await import('../core/router.js');
const { daftarkanRuteWeb } = await import('../rute-web.js');
const { agregatBoleh } = await import('../core/permissions.js');
const { PROFIL, salin } = await import('./fixtures/peta.js');

daftarkanRuteWeb([], getUser);
const semua = (n, f) => { if (n instanceof Node_) { f(n); (n.anak || []).forEach((x) => semua(x, f)); } };
const teks = (el) => { const t = []; semua(el, (n) => { if (n.teks != null) t.push(n.teks); }); return t.join(' | '); };
const outlet = buatEl('main');
await startRouter(outlet);
async function buka(path) {
  panggilan.length = 0;
  location.hash = `#${path}`;
  await resolve();
  await new Promise((r) => setTimeout(r, 20));
  return outlet;
}

test('agregat penerima manfaat di web: guard mengikuti peta server, hanya GET, tanpa data pribadi', async () => {
  let dicoba = 0;
  for (const nama of Object.keys(PROFIL)) {
    setUserSesi(salin(nama));
    const u = getUser();
    const boleh = agregatBoleh(u, 'penerima-manfaat');
    assert.equal(cekAkses('/penerima-manfaat-agregat'), boleh ? 'boleh' : 'ditolak', nama);
    if (!boleh) continue;
    dicoba++;
    const el = await buka('/penerima-manfaat-agregat');
    const t = teks(el);
    assert.match(t, /Siswa/);
    assert.match(t, /<5/);
    assert.match(t, /Posyandu Contoh/);
    assert.ok(!/JANGAN-TAMPIL|0000000000000000|Rahasia/.test(t), 'tidak ada data pribadi');
    assert.ok(!t.includes('Segera tersedia'));
    assert.ok(panggilan.every((p) => p.metode === 'GET'));
  }
  assert.ok(dicoba > 0, 'minimal satu profil berhak (investor)');
});

test('web-main mendaftarkan /sesi dan layar sesi memakai klien web', async () => {
  const { readFileSync } = await import('node:fs');
  const wm = readFileSync(fileURLToPath(new URL('../web-main.js', import.meta.url)), 'utf8');
  assert.match(wm, /route\('\/sesi'/);
  assert.match(wm, /renderSesiWeb/);
});
