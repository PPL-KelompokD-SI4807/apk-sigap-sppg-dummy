// Layar ekspor Laporan BGN: pemeriksaan pilihan penandatangan tidak boleh membuat tombol macet; pilihan ikut terkirim.
import test from 'node:test';
import assert from 'node:assert/strict';

class Node_ {}
const buatEl = (tag) => {
  const el = new Node_();
  Object.assign(el, {
    tag, attrs: {}, listeners: {}, anak: [], className: '', dataset: {}, style: { cssText: '' }, value: '', textContent: '', disabled: false, hidden: false,
    setAttribute(k, v) { this.attrs[k] = String(v); },
    addEventListener(n, fn) { this.listeners[n] = fn; },
    append(...xs) { this.anak.push(...xs); },
    replaceChildren(...xs) { this.anak = [...xs]; },
    remove() {},
  });
  return el;
};
const mainbar = buatEl('div');
const mainbtn = buatEl('button');
mainbtn.textContent = '';
globalThis.Node = Node_;
globalThis.document = {
  createElement: buatEl,
  createTextNode: (t) => Object.assign(new Node_(), { teks: String(t) }),
  getElementById: (id) => ({ mainbar, mainbtn }[id] ?? null),
  body: { classList: { add() {}, remove() {} }, append() {} },
};
const mem = () => { const m = new Map(); return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) }; };
globalThis.localStorage = mem();
globalThis.sessionStorage = mem();
globalThis.window = globalThis.window ?? {};
globalThis.matchMedia = () => ({ matches: false, addEventListener() {} });

const panggilan = [];
const resp = (status, body) => ({ ok: status < 300, status, headers: { get: () => null }, json: async () => body });
const KANDIDAT = { data: { penandatangan: {
  pihak_kedua: { nama: null, kandidat: [{ id: 5, nama: 'Ani' }, { id: 6, nama: 'Budi' }] },
  mengetahui: { nama: 'Cici', kandidat: [{ id: 2, nama: 'Cici' }] },
} } };
globalThis.fetch = async (url, init) => {
  const u = new URL(url, 'http://x');
  panggilan.push({ metode: init.method, path: u.pathname.replace('/api/v1', ''), body: init.body ? JSON.parse(init.body) : null });
  if (init.method === 'GET') return resp(200, KANDIDAT);
  return resp(202, { message: 'ok' });
};

const { setUserSesi } = await import('../core/auth.js');
const { layarEkspor } = await import('../modules/laporan-bgn/index.js');
const { salin } = await import('./fixtures/peta.js');

const semua = (n, f) => { if (n instanceof Node_) { f(n); (n.anak || []).forEach((x) => semua(x, f)); } };
const cari = (el, id) => { let hasil = null; semua(el, (n) => { if (n.attrs?.id === id) hasil = n; }); return hasil; };
const teksGalat = (el) => { let t = ''; semua(el, (n) => { if (n.attrs?.role === 'alert' && n.textContent && !n.attrs.id) t = n.textContent; }); return t; };
const tunggu = () => new Promise((r) => setTimeout(r, 20));

async function buka() {
  setUserSesi(salin('akuntan'));
  panggilan.length = 0;
  const el = buatEl('div');
  await layarEkspor({ el, setTitle() {}, params: {}, isStale: () => false });
  await tunggu(); // GET kandidat
  return el;
}

test('kirim tanpa memilih penandatangan: galat "Pilih ... terlebih dahulu", tombol tidak macet, tanpa permintaan POST', async () => {
  const el = await buka();
  assert.ok(cari(el, 'f-akuntan_id'), 'pilihan Pihak Kedua tampil (2 kandidat)');
  assert.equal(cari(el, 'f-kepala_sppg_id'), null, 'satu kandidat = tanpa pilihan');
  await mainbtn.onclick();
  await tunggu();
  assert.match(teksGalat(el), /^Pilih Pihak Kedua \(Akuntan\) terlebih dahulu\.$/);
  assert.equal(mainbtn.disabled, false, 'tombol tidak terkunci');
  assert.notEqual(mainbtn.textContent, 'Memproses...');
  assert.equal(panggilan.filter((p) => p.metode === 'POST').length, 0);
  // Coba lagi tidak diblokir oleh `sibuk` yang macet: galat yang sama muncul lagi.
  el.anak.length && await mainbtn.onclick();
  assert.match(teksGalat(el), /Pilih Pihak Kedua/);
});

test('memilih penandatangan lalu kirim: PDF terkirim dengan akuntan_id', async () => {
  const el = await buka();
  const sel = cari(el, 'f-akuntan_id');
  sel.value = '6';
  sel.listeners.change();
  await mainbtn.onclick();
  await tunggu();
  const post = panggilan.filter((p) => p.metode === 'POST');
  assert.ok(post.length >= 1);
  const pdf = post.find((p) => p.path.endsWith('/pdf'));
  assert.equal(pdf.body.akuntan_id, 6);
  assert.equal('kepala_sppg_id' in pdf.body, false);
  assert.equal(mainbtn.disabled, false);
  assert.equal(mainbtn.textContent, 'Kirim PDF ke Telegram');
});
