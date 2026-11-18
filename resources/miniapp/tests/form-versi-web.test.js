// Kunci versi (PR #138): jadwal produksi/distribusi, log-produksi, mutu mengirim `versi` record saat ubah (PUT) dan aksi status (PATCH);
// 409 versi_tidak_cocok menampilkan pesan + tombol Muat ulang tanpa menimpa isian pengguna.
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
const khusus = {};
globalThis.fetch = async (url, init) => {
  const path = new URL(url, 'http://x').pathname.replace('/api/v1', '');
  panggilan.push({ metode: init.method, path, headers: init.headers, body: init.body ? JSON.parse(init.body) : null });
  if (init.method === 'GET') {
    if (path === '/menu-gizi') return resp(200, { data: [{ id: 3, nama_menu: 'Menu Fiktif', status_persetujuan: 'disetujui' }], meta: { current_page: 1, last_page: 1, total: 1 } });
    if (path === '/jadwal') return resp(200, { data: [{ ...jadwalRec, tanggal_produksi: hariIni }], meta: { current_page: 1, last_page: 1, total: 1 } });
    if (/^\/sppg\/\d+$/.test(path)) return resp(200, { data: { id: 1, kapasitas_porsi_harian: 3000 } });
    if (path === '/sppg-saya') return resp(200, { data: [{ id: 1, kode: 'S-1', nama: 'SPPG Satu', status_operasional: 'aktif' }, { id: 2, kode: 'S-2', nama: 'SPPG Dua', status_operasional: 'aktif' }], sppg_aktif_id: 2, wajib_pilih: false, header: 'X-SPPG-Id' });
    if (path === '/jadwal/1') return resp(200, { data: jadwalRec });
    if (khusus[path]) return resp(200, { data: khusus[path] });
    if (/\/\d+$/.test(path)) return resp(200, { data: { id: 1, jadwal_produksi_id: 1, hasil: 'layak', memengaruhi_distribusi: false, realisasi_porsi: '90', izin: { ubah: true, hapus: true } } });
    return resp(200, { data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
  }
  return skrip.length ? skrip.shift() : resp(200, { data: { id: 1 } });
};

const { setPlatform } = await import('../core/platform.js');
setPlatform('web');
const { setUserSesi, getUser } = await import('../core/auth.js');
const { jadwalTabBoleh } = await import('../core/permissions.js');
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

const PESAN = 'Data sudah berubah. Muat ulang lalu ubah kembali.';
const izin = { ubah: true, hapus: true };
const konfirmasiStatus = async (el, label) => {
  const b = tombol(el).find((x) => label.test(teks(x)));
  assert.ok(b, `tombol ${label}`);
  overlays.length = 0;
  const klik = b.listeners.click();
  await new Promise((r) => setTimeout(r, 5));
  tombol(overlays[0]).find((x) => /Ya, ubah/.test(teks(x))).listeners.click();
  await klik;
  await new Promise((r) => setTimeout(r, 15));
};

test('ubah (PUT) log-produksi dan mutu menyertakan versi dari record yang dimuat', async () => {
  setUserSesi(salin('relawan_dapur'));
  khusus['/log-produksi/1'] = { id: 1, jadwal_produksi_id: 1, realisasi_porsi: '90', versi: 4, izin };
  let el = await buka('/log-produksi/1/ubah');
  skrip = [resp(200, { data: { id: 1 } })];
  await kirim();
  let put = tulis().find((p) => p.metode === 'PUT');
  assert.equal(put.path, '/log-produksi/1');
  assert.equal(put.body.versi, 4);
  setUserSesi(salin('ahli_gizi'));
  khusus['/mutu/1'] = { id: 1, jadwal_produksi_id: 1, hasil: 'layak', kebersihan_dapur: 'true', kebersihan_petugas: 'true', rasa_baik: 'true', aroma_baik: 'true', tampilan_baik: 'true', sampel_disimpan: 'false', memengaruhi_distribusi: false, versi: 7, izin };
  el = await buka('/mutu/1/ubah');
  skrip = [resp(200, { data: { id: 1 } })];
  await kirim();
  put = tulis().find((p) => p.metode === 'PUT');
  assert.equal(put.path, '/mutu/1');
  assert.equal(put.body.versi, 7);
});

test('409 versi_tidak_cocok pada ubah: pesan + tombol Muat ulang; isian pengguna tidak ditimpa; Muat ulang memuat ulang record', async () => {
  setUserSesi(salin('relawan_dapur'));
  khusus['/log-produksi/1'] = { id: 1, jadwal_produksi_id: 1, realisasi_porsi: '90', versi: 4, izin };
  const el = await buka('/log-produksi/1/ubah');
  isi(el, { realisasi_porsi: '77' });
  skrip = [resp(409, { message: 'Log produksi sudah berubah.', alasan: 'versi_tidak_cocok', versi_server: 5 })];
  await kirim();
  assert.ok(teks(el).includes(PESAN));
  assert.equal(cari(el, 'f-realisasi_porsi').value, '77', 'isian pengguna tetap');
  const muat = tombol(el).find((b) => teks(b).trim() === 'Muat ulang');
  assert.ok(muat, 'tombol Muat ulang');
  khusus['/log-produksi/1'] = { ...khusus['/log-produksi/1'], realisasi_porsi: '95', versi: 5 };
  panggilan.length = 0;
  await muat.listeners.click();
  await new Promise((r) => setTimeout(r, 15));
  assert.ok(panggilan.some((p) => p.metode === 'GET' && p.path === '/log-produksi/1'), 'record dimuat ulang');
  skrip = [resp(200, { data: { id: 1 } })];
  await kirim();
  assert.equal(tulis().find((p) => p.metode === 'PUT').body.versi, 5, 'ubah berikutnya memakai versi baru');
});

test('aksi status PATCH jadwal produksi dan distribusi menyertakan versi', async () => {
  setUserSesi(salin('relawan_dapur'));
  jadwalRec = { ...jadwalRec, status: 'direncanakan', versi: 3, izin };
  let el = await buka('/jadwal/1');
  skrip = [resp(200, { data: { id: 1 } })];
  await konfirmasiStatus(el, /Mulai masak/);
  let patch = tulis().find((p) => p.metode === 'PATCH');
  assert.deepEqual(patch.body, { status: 'dimasak', versi: 3 });
  setUserSesi(salin('kepala_sppg'));
  khusus['/jadwal/distribusi/7'] = { id: 7, status: 'menunggu', versi: 2, layak_distribusi: true, titik_layanan: { id: 1, nama: 'Titik Fiktif' }, izin };
  el = await buka('/jadwal/distribusi/7');
  const b = tombol(el).find((x) => /Siap|Berangkat/i.test(teks(x)) && !x.disabled);
  assert.ok(b, 'tombol aksi distribusi');
  {
    overlays.length = 0;
    skrip = [resp(200, { data: { id: 7 } })];
    const klik = b.listeners.click();
    await new Promise((r) => setTimeout(r, 5));
    tombol(overlays[0]).find((x) => /Ya, ubah/.test(teks(x))).listeners.click();
    await klik;
    await new Promise((r) => setTimeout(r, 15));
    patch = tulis().find((p) => p.metode === 'PATCH');
    assert.equal(patch.path, '/jadwal/distribusi/7');
    assert.equal(patch.body.versi, 2);
  }
});

test('aksi status 409 versi_tidak_cocok: pesan + Muat ulang, tanpa memuat ulang diam-diam', async () => {
  setUserSesi(salin('relawan_dapur'));
  jadwalRec = { ...jadwalRec, status: 'direncanakan', versi: 3, izin };
  const el = await buka('/jadwal/1');
  skrip = [resp(409, { message: 'Jadwal produksi sudah berubah.', alasan: 'versi_tidak_cocok', versi_server: 4 })];
  panggilan.length = 0;
  await konfirmasiStatus(el, /Mulai masak/);
  assert.ok(teks(el).includes(PESAN));
  assert.equal(panggilan.filter((p) => p.metode === 'GET').length, 0, 'tidak ada muat ulang otomatis');
  const muat = tombol(el).find((b) => teks(b).trim() === 'Muat ulang');
  assert.ok(muat);
  jadwalRec = { ...jadwalRec, versi: 4 };
  await muat.listeners.click();
  await new Promise((r) => setTimeout(r, 15));
  assert.ok(panggilan.some((p) => p.metode === 'GET' && p.path === '/jadwal/1'));
});
