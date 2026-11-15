// R04-8 PR4f: formulir web keuangan (pencairan, pengeluaran), keluhan, rekap harian.
// Hak tombol hanya dari peta/izin server; Idempotency-Key sekali per formulir; tanpa foto/lampiran (PR5).
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
const hariIni = new Date().toISOString().slice(0, 10);
const periode = hariIni.slice(0, 7);
let pengRec = { id: 11, tanggal: hariIni, kategori: 'bahan_baku', uraian: 'Beli beras fiktif', nominal: 500000, versi: 3, status_persetujuan: 'diajukan', riwayat_persetujuan: [], izin: { ubah: true, hapus: true, lampirkan: true, putuskan: false } };
const pencRec = { id: 21, periode, sumber_dana: 'Dana Fiktif', nominal: 1000000, tanggal_cair: hariIni };
const kelRec = { id: 31, kategori: 'rasa', prioritas: 'rendah', status: 'baru', sumber: 'orang_tua', deskripsi: 'Nasi kurang hangat', titik_layanan: { nama: 'Titik Fiktif' } };
const rekapRec = { id: 41, tanggal: hariIni, status_kirim: 'terkirim', catatan: 'Catatan lama', ringkasan: { porsi: { target: 100, realisasi: 90 }, keuangan: { serapan_persen: 50 } } };
const lst = (x) => resp(200, { data: x, meta: { current_page: 1, last_page: 1, total: x.length } });
globalThis.fetch = async (url, init) => {
  const path = new URL(url, 'http://x').pathname.replace('/api/v1', '');
  panggilan.push({ metode: init.method, path, headers: init.headers, body: init.body ? JSON.parse(init.body) : null });
  if (init.method === 'GET') {
    if (path === '/keuangan/pengeluaran/11') return resp(200, { data: pengRec });
    if (path === '/keuangan/pengeluaran') return lst([pengRec]);
    if (path === '/keuangan/pencairan/21') return resp(200, { data: pencRec });
    if (path === '/keuangan/pencairan') return lst([pencRec]);
    if (path === '/keuangan/ringkasan') return resp(200, { data: { periode, anggaran: 1000000, terpakai: 200000, sisa: 800000, persen: 20, per_kategori: [] } });
    if (path === '/keluhan/31') return resp(200, { data: kelRec });
    if (path === '/keluhan/ringkasan') return resp(200, { data: { total: 1, per_status: {}, kritis_terbuka: 0 } });
    if (path === '/keluhan') return lst([kelRec]);
    if (path === '/rekap-harian/41') return resp(200, { data: rekapRec });
    if (path === '/rekap-harian') return lst([rekapRec]);
    return lst([]);
  }
  return skrip.length ? skrip.shift() : resp(200, { data: { id: 1 } });
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
const sebagai = (nama) => setUserSesi(salin(nama));

test('TULIS_WEB memuat keuangan/pencairan, keuangan/pengeluaran, keluhan, rekap-harian', async () => {
  const { TULIS_WEB } = await import('../rute-web.js');
  for (const m of ['keuangan/pencairan', 'keuangan/pengeluaran', 'keluhan', 'rekap-harian']) assert.ok(TULIS_WEB.has(m), m);
});

test('pencairan baru (akuntan): POST dengan Idempotency-Key tetap pada pengulangan, tanpa bukti/foto', async () => {
  sebagai('akuntan');
  const el = await buka('/keuangan/pencairan/baru');
  assert.equal(cari(el, 'f-bukti'), null);
  isi(el, { periode, sumber_dana: 'Dana Fiktif', nominal: '1000000', tanggal_cair: hariIni });
  skrip = [resp(503, { message: 'x' }), resp(201, { data: { id: 21 } })];
  await kirim(); await kirim();
  const t = tulis();
  assert.equal(t.length, 2);
  assert.equal(t[0].path, '/keuangan/pencairan');
  assert.equal(t[0].headers['Idempotency-Key'], t[1].headers['Idempotency-Key']);
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.equal(t[1].body.nominal, 1000000);
  assert.equal('bukti' in t[1].body, false);
  assert.equal(location.hash, '#/keuangan/pencairan/21');
});

test('pengeluaran baru (akuntan): POST tanpa nota; ubah mengirim versi dari record (PUT)', async () => {
  sebagai('akuntan');
  let el = await buka('/keuangan/pengeluaran/baru');
  assert.equal(cari(el, 'f-nota'), null);
  isi(el, { tanggal: hariIni, kategori: 'bahan_baku', uraian: 'Beli beras fiktif', nominal: '500000' });
  skrip = [resp(201, { data: { id: 11 } })];
  await kirim();
  let t = tulis();
  assert.equal(t.length, 1);
  assert.equal(t[0].path, '/keuangan/pengeluaran');
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.equal('nota' in t[0].body, false);
  el = await buka('/keuangan/pengeluaran/11/ubah');
  assert.ok(teks(el).includes('mengajukan ulang'), 'info ajukan ulang (formTop)');
  isi(el, { uraian: 'Beli beras fiktif 2', nominal: '500000' }); // input rupiah kustom tidak terisi ulang oleh DOM tiruan
  skrip = [resp(200, { data: { id: 11 } })];
  await kirim();
  t = tulis();
  assert.equal(t[0].metode, 'PUT');
  assert.equal(t[0].path, '/keuangan/pengeluaran/11');
  assert.equal(t[0].body.versi, 3);
});

test('detail pengeluaran: Hapus mengikuti izin record; tanpa panel keputusan; pembelian terkunci', async () => {
  sebagai('akuntan');
  let el = await buka('/keuangan/pengeluaran/11');
  assert.ok(tombol(el).some((b) => /^Hapus$/.test(teks(b))));
  const asli = pengRec;
  pengRec = { ...pengRec, izin: { ubah: false, hapus: false, lampirkan: false, putuskan: true }, pembelian_bahan_id: 5 };
  el = await buka('/keuangan/pengeluaran/11');
  assert.ok(!tombol(el).some((b) => /Hapus|Setujui|Tolak/.test(teks(b))), 'tanpa Hapus dan tanpa aksi keputusan di web');
  assert.ok(teks(el).includes('pembelian bahan'));
  el = await buka('/keuangan/pengeluaran/11/ubah');
  assert.equal(cari(el, 'f-uraian'), null, 'ubah ditolak tanpa izin.ubah');
  pengRec = asli;
});

test('hapus pencairan (akuntan) memakai DELETE setelah konfirmasi', async () => {
  sebagai('akuntan');
  const el = await buka('/keuangan/pencairan/21');
  overlays.length = 0;
  const klik = tombol(el).find((b) => /^Hapus$/.test(teks(b))).listeners.click();
  await new Promise((r) => setTimeout(r, 5));
  tombol(overlays[0]).find((b) => /Hapus/.test(teks(b))).listeners.click();
  await klik;
  await new Promise((r) => setTimeout(r, 15));
  assert.equal(tulis()[0].metode, 'DELETE');
  assert.equal(tulis()[0].path, '/keuangan/pencairan/21');
});

test('keuangan: admin, kepala, investor baca saja; tautan Tambah di ringkasan hanya untuk akuntan', async () => {
  for (const nama of ['admin', 'kepala_sppg', 'investor']) {
    sebagai(nama);
    let el = await buka('/keuangan/pengeluaran');
    assert.ok(!tautan(el).includes('#/keuangan/pengeluaran/baru'), `${nama} daftar`);
    el = await buka('/keuangan/pengeluaran/11');
    assert.ok(!tombol(el).some((b) => /Hapus/.test(teks(b))), `${nama} detail`);
    el = await buka('/keuangan?sppg_id=1');
    assert.ok(!tautan(el).includes('#/keuangan/pencairan/baru'), `${nama} ringkasan`);
  }
  sebagai('akuntan');
  const el = await buka('/keuangan');
  assert.ok(tautan(el).includes('#/keuangan/pencairan/baru'));
  assert.ok(tautan(el).includes('#/keuangan/pengeluaran/baru'));
});

test('keluhan baru (relawan): POST tanpa foto/lampiran_ids', async () => {
  sebagai('relawan_dapur');
  const el = await buka('/keluhan/baru');
  assert.equal(cari(el, 'f-lampiran_ids'), null);
  isi(el, { sumber: 'orang_tua', kategori: 'rasa', deskripsi: 'Nasi kurang hangat' });
  skrip = [resp(201, { data: { id: 31 } })];
  await kirim();
  const t = tulis();
  assert.equal(t.length, 1);
  assert.equal(t[0].path, '/keluhan');
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.equal(t[0].body.deskripsi, 'Nasi kurang hangat');
  assert.equal('lampiran_ids' in t[0].body, false);
  assert.equal(location.hash, '#/keluhan/31');
});

test('keluhan: kepala tindak lanjut (PUT status+tindak_lanjut) tanpa Tambah/Hapus; admin boleh Hapus; investor baca', async () => {
  sebagai('kepala_sppg');
  let el = await buka('/keluhan/31');
  assert.ok(tautan(el).includes('#/keluhan/31/ubah'), 'tautan Proses keluhan');
  assert.ok(!tombol(el).some((b) => /^Hapus$/.test(teks(b))));
  el = await buka('/keluhan/baru');
  assert.equal(cari(el, 'f-deskripsi'), null, 'kepala tidak punya Tambah');
  el = await buka('/keluhan/31/ubah');
  isi(el, { status: 'diproses', tindak_lanjut: 'Dicek ke dapur' });
  skrip = [resp(200, { data: { id: 31 } })];
  await kirim();
  const t = tulis();
  assert.equal(t[0].metode, 'PUT');
  assert.equal(t[0].path, '/keluhan/31');
  assert.deepEqual(Object.keys(t[0].body).sort(), ['status', 'tindak_lanjut']);
  sebagai('admin');
  el = await buka('/keluhan/31');
  assert.ok(tombol(el).some((b) => /^Hapus$/.test(teks(b))));
  sebagai('investor');
  el = await buka('/keluhan/31');
  assert.ok(!tombol(el).some((b) => /^Hapus$/.test(teks(b))));
  assert.ok(!tautan(el).includes('#/keluhan/31/ubah'));
});

test('rekap harian: susun rekap hanya kepala (POST); peran lain tanpa tautan Tambah', async () => {
  sebagai('kepala_sppg');
  const el = await buka('/rekap-harian/baru');
  isi(el, { tanggal: hariIni, catatan: 'Catatan fiktif' });
  skrip = [resp(201, { data: { id: 41 } })];
  await kirim();
  const t = tulis();
  assert.equal(t.length, 1);
  assert.equal(t[0].metode, 'POST');
  assert.equal(t[0].path, '/rekap-harian');
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.equal(location.hash, '#/rekap-harian/41');
  for (const nama of ['admin', 'ahli_gizi', 'akuntan', 'investor']) {
    sebagai(nama);
    const l = await buka('/rekap-harian');
    assert.ok(!tautan(l).includes('#/rekap-harian/baru'), nama);
  }
});

test('detail rekap: catatan (PATCH) dan kirim ulang hanya bila aksi.kirim_ulang_rekap', async () => {
  sebagai('kepala_sppg');
  let el = await buka('/rekap-harian/41');
  assert.ok(cari(el, 'f-catatan'), 'editor catatan');
  assert.ok(tombol(el).some((b) => /Kirim ulang/.test(teks(b))));
  assert.ok(tautan(el).some((x) => /rekap-harian\/baru\?tanggal=/.test(x)));
  assert.ok(!tombol(el).some((b) => /^Hapus$/.test(teks(b))));
  const simpan = tombol(el).find((b) => /Simpan catatan/.test(teks(b)));
  cari(el, 'f-catatan').value = 'Catatan baru';
  skrip = [resp(200, { data: { id: 41 } })];
  await simpan.listeners.click();
  await new Promise((r) => setTimeout(r, 15));
  const patch = tulis().find((p) => p.metode === 'PATCH');
  assert.equal(patch.path, '/rekap-harian/41');
  assert.deepEqual(patch.body, { catatan: 'Catatan baru' });
  for (const nama of ['admin', 'ahli_gizi', 'akuntan', 'investor']) {
    sebagai(nama);
    el = await buka('/rekap-harian/41');
    assert.ok(!cari(el, 'f-catatan') && !tombol(el).some((b) => /Kirim ulang|Simpan catatan/.test(teks(b))), nama);
    assert.ok(!tautan(el).some((x) => /baru\?tanggal=/.test(x)), nama);
  }
});
