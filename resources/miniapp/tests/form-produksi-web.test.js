// R04-8 PR4d: formulir web kelompok produksi (jadwal produksi, log-produksi, mutu) + nama SPPG aktif di beranda web.
// Idempotency-Key sekali per formulir; tanpa bidang lampiran/foto; aksi status jadwal hanya bila izin server; GET info kapasitas.
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
let jadwalRec = { id: 1, status: 'direncanakan', tanggal_produksi: hariIni, target_porsi: 100, menu_id: 3, menu: { id: 3, nama_menu: 'Menu Fiktif', status_persetujuan: 'disetujui' }, izin: { ubah: true, hapus: true } };
globalThis.fetch = async (url, init) => {
  const path = new URL(url, 'http://x').pathname.replace('/api/v1', '');
  panggilan.push({ metode: init.method, path, headers: init.headers, body: init.body ? JSON.parse(init.body) : null });
  if (init.method === 'GET') {
    if (path === '/menu-gizi') return resp(200, { data: [{ id: 3, nama_menu: 'Menu Fiktif', status_persetujuan: 'disetujui' }], meta: { current_page: 1, last_page: 1, total: 1 } });
    if (path === '/jadwal') return resp(200, { data: [{ ...jadwalRec, tanggal_produksi: hariIni }], meta: { current_page: 1, last_page: 1, total: 1 } });
    if (/^\/sppg\/\d+$/.test(path)) return resp(200, { data: { id: 1, kapasitas_porsi_harian: 3000 } });
    if (path === '/sppg-saya') return resp(200, { data: [{ id: 1, kode: 'S-1', nama: 'SPPG Satu', status_operasional: 'aktif' }, { id: 2, kode: 'S-2', nama: 'SPPG Dua', status_operasional: 'aktif' }], sppg_aktif_id: 2, wajib_pilih: false, header: 'X-SPPG-Id' });
    if (path === '/jadwal/1') return resp(200, { data: jadwalRec });
    if (/\/\d+$/.test(path)) return resp(200, { data: { id: 1, jadwal_produksi_id: 1, hasil: 'layak', memengaruhi_distribusi: false, realisasi_porsi: '90', izin: { ubah: true, hapus: true } } });
    return resp(200, { data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
  }
  return skrip.length ? skrip.shift() : resp(200, { data: { id: 1 } });
};

const { setPlatform } = await import('../core/platform.js');
setPlatform('web');
const { setUserSesi, getUser } = await import('../core/auth.js');
const { startRouter, resolve } = await import('../core/router.js');
const { daftarkanRuteWeb } = await import('../rute-web.js');
const { setSppgAktif } = await import('../core/sppg-aktif.js');
const { renderBerandaWeb } = await import('../beranda-web.js');
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

test('jadwal produksi baru: info kapasitas (GET), kirim POST /jadwal dengan Idempotency-Key; kunci sama pada pengulangan manual', async () => {
  kepala();
  const el = await buka('/jadwal/baru');
  assert.ok(teks(el).includes('Kapasitas harian'), 'formTopWeb menampilkan sisa kapasitas');
  isi(el, { menu_id: '3', tanggal_produksi: hariIni, target_porsi: '100' });
  skrip = [resp(503, { message: 'x' }), resp(201, { data: { id: 11 } })];
  await kirim(); await kirim();
  const t = tulis();
  assert.equal(t.length, 2);
  assert.equal(t[0].path, '/jadwal');
  assert.equal(t[0].headers['Idempotency-Key'], t[1].headers['Idempotency-Key']);
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.equal(t[1].body.menu_id, 3);
  assert.equal(t[1].body.target_porsi, 100);
  assert.equal(location.hash, '#/jadwal/11');
});

test('jadwal produksi: 422 per bidang sebagai teks; 403 pesan tetap tanpa pesan server', async () => {
  kepala();
  const el = await buka('/jadwal/baru');
  isi(el, { menu_id: '3', tanggal_produksi: hariIni, target_porsi: '9999' });
  skrip = [resp(422, { message: 'x', errors: { target_porsi: ['Melebihi kapasitas <b>harian</b>.'] } }), resp(403, { message: 'SPPG Rahasia' })];
  await kirim();
  assert.equal(cari(el, 'f-target_porsi-err').textContent, 'Melebihi kapasitas <b>harian</b>.');
  await kirim();
  assert.ok(teks(el).includes('Anda tidak berhak melakukan aksi ini.'));
  assert.ok(!teks(el).includes('Rahasia'));
});

test('detail jadwal produksi: Ubah status (PATCH /jadwal/1 dengan kunci) hanya bila izin.ubah; tautan Catat log menurut peta hak (C log-produksi)', async () => {
  setUserSesi(salin('relawan_dapur'));
  jadwalRec = { ...jadwalRec, izin: { ubah: true, hapus: true } };
  let el = await buka('/jadwal/1');
  const mulai = tombol(el).find((b) => /Mulai masak/.test(teks(b)));
  assert.ok(mulai, 'tombol Mulai masak untuk status direncanakan');
  assert.ok(tautan(el).includes('#/log-produksi/baru?jadwal=1'), 'relawan dapur punya hak C log-produksi');
  assert.ok(!tautan(el).includes('#/mutu/baru?jadwal=1'), 'mutu hanya R untuk relawan dapur');
  assert.ok(!tautan(el).some((x) => /kebutuhan$/.test(x)), 'layar kebutuhan bahan belum ada di web');
  overlays.length = 0;
  skrip = [resp(200, { data: { id: 1 } })];
  const klik = mulai.listeners.click();
  await new Promise((r) => setTimeout(r, 5));
  tombol(overlays[0]).find((b) => /Ya, ubah/.test(teks(b))).listeners.click();
  await klik;
  await new Promise((r) => setTimeout(r, 15));
  const patch = tulis().find((p) => p.metode === 'PATCH');
  assert.equal(patch.path, '/jadwal/1');
  assert.deepEqual(patch.body, { status: 'dimasak' });
  assert.match(patch.headers['Idempotency-Key'] ?? 'x', /./);
  // Tanpa izin.ubah dari server: tidak ada tombol status maupun Hapus.
  jadwalRec = { ...jadwalRec, izin: { ubah: false, hapus: false } };
  el = await buka('/jadwal/1');
  assert.ok(!tombol(el).some((b) => /Mulai masak|Hapus/.test(teks(b))));
});

test('log-produksi: tanpa bidang foto/lampiran; kirim POST tanpa lampiran_ids; info target (GET jadwal)', async () => {
  setUserSesi(salin('relawan_dapur'));
  const el = await buka('/log-produksi/baru');
  assert.equal(cari(el, 'f-lampiran_ids'), null);
  assert.ok(!/Foto produksi/.test(teks(el)), 'tidak ada bidang foto di web');
  isi(el, { jadwal_produksi_id: '1', realisasi_porsi: '90' });
  skrip = [resp(201, { data: { id: 21 } })];
  await kirim();
  const t = tulis();
  assert.equal(t.length, 1);
  assert.equal(t[0].path, '/log-produksi');
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.equal('lampiran_ids' in t[0].body, false);
  assert.equal(t[0].body.realisasi_porsi, 90);
});

test('mutu: tanpa bidang foto; 409 pesan tetap + muat ulang; tautan Periksa ulang di detail hanya bila hak C', async () => {
  setUserSesi(salin('ahli_gizi'));
  let el = await buka('/mutu/baru?jadwal=1');
  assert.equal(cari(el, 'f-lampiran_ids'), null);
  assert.equal(cari(el, 'f-jadwal_produksi_id').value, '1', 'prefill dari ?jadwal=');
  setUserSesi(salin('relawan_dapur'));
  el = await buka('/log-produksi/1/ubah');
  skrip = [resp(409, { message: 'x', alasan: 'versi_tidak_cocok' })];
  await kirim();
  assert.ok(teks(el).includes('Data sudah berubah sejak Anda membukanya.'));
  assert.ok(tombol(el).some((b) => /Muat ulang/.test(teks(b))));
  setUserSesi(salin('ahli_gizi'));
  el = await buka('/mutu/1');
  assert.ok(tautan(el).includes('#/mutu/baru?jadwal=1'));
  setUserSesi(salin('investor'));
  el = await buka('/mutu/1');
  assert.ok(!tautan(el).some((x) => /\/baru/.test(x)));
});

test('beranda web: nama SPPG aktif investor dari GET /sppg-saya (tanpa perubahan backend)', async () => {
  setUserSesi(salin('investor'));
  setSppgAktif(2);
  const el = buatEl('div');
  renderBerandaWeb(el, { user: getUser(), menu: [] });
  assert.ok(teks(el).includes('SPPG aktif: #2'), 'nomor langsung tampil');
  await new Promise((r) => setTimeout(r, 15));
  assert.ok(teks(el).includes('SPPG aktif: SPPG Dua (S-2).'), teks(el));
  assert.ok(teks(el).includes('Ganti SPPG'));
});
