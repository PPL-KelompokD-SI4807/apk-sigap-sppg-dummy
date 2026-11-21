// R04-8 PR4e: formulir web distribusi (jadwal distribusi, notifikasi perjalanan, bukti distribusi).
// Idempotency-Key sekali per formulir; aksi status hanya bila izin server; bukti: Tambah + foto sejak PR5 (multipart jalur cookie).
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
const dist = { id: 5, status: 'siap', layak_distribusi: true, tanggal_distribusi: hariIni, jumlah_porsi: 80, jadwal_produksi_id: 1, titik_layanan_id: 2, titik_layanan: { id: 2, nama: 'Titik Fiktif' }, izin: { ubah: true, hapus: true } };
let distRec = dist;
let perjRec = { id: 7, status: 'berangkat', titik_layanan: { id: 2, nama: 'Titik Fiktif' }, izin: { ubah_status: true }, waktu_berangkat: new Date().toISOString(), estimasi_tiba: new Date().toISOString() };
const buktiRec = { id: 9, jadwal_distribusi_id: 5, porsi_dikirim: 80, porsi_diterima: 80, nama_pic: 'Ibu Fiktif', jadwal_distribusi: { titik_layanan: { nama: 'Titik Fiktif' } }, izin: { ubah: true, hapus: true } };
const lst = (x) => resp(200, { data: x, meta: { current_page: 1, last_page: 1, total: x.length } });
globalThis.fetch = async (url, init) => {
  const path = new URL(url, 'http://x').pathname.replace('/api/v1', '');
  panggilan.push({ metode: init.method, path, headers: init.headers, body: init.body ? JSON.parse(init.body) : null });
  if (init.method === 'GET') {
    if (path === '/jadwal/distribusi') return lst([distRec]);
    if (path === '/jadwal/distribusi/5') return resp(200, { data: distRec });
    if (path === '/jadwal/produksi-ringkas') return lst([{ id: 1, tanggal_produksi: hariIni, nama_menu: 'Menu Fiktif', status: 'direncanakan' }]);
    if (path === '/jadwal') return lst([{ id: 1, target_porsi: 100, layak_distribusi: true, tanggal_produksi: hariIni }]);
    if (path === '/jadwal/1') return resp(200, { data: { id: 1, target_porsi: 100, distribusi_dialokasikan: 0, layak_distribusi: true } });
    if (path === '/armada') return lst([{ id: 3, plat_nomor: 'B 1 FIK', status: 'aktif' }]);
    if (path === '/pengemudi') return lst([{ id: 4, nama: 'Supir Fiktif', status: 'aktif' }]);
    if (path === '/titik-layanan') return lst([{ id: 2, nama: 'Titik Fiktif' }]);
    if (path === '/notifikasi-perjalanan/7') return resp(200, { data: perjRec });
    if (path === '/notifikasi-perjalanan') return lst([perjRec]);
    if (path === '/bukti-distribusi/9') return resp(200, { data: buktiRec });
    if (path === '/bukti-distribusi') return lst([buktiRec]);
    return lst([]);
  }
  if (path === '/notifikasi-perjalanan/pratinjau') return resp(200, { data: { estimasi_tiba: new Date().toISOString(), estimasi_menit: 20, estimasi_sumber: 'jarak', waktu_berangkat: new Date().toISOString(), pesan: 'Pesan fiktif' } });
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
const kepala = () => setUserSesi(salin('kepala_sppg'));


test('TULIS_WEB memuat tiga modul distribusi', async () => {
  const { TULIS_WEB } = await import('../rute-web.js');
  for (const m of ['jadwal/distribusi', 'notifikasi-perjalanan', 'bukti-distribusi']) assert.ok(TULIS_WEB.has(m), m);
});

test('jadwal distribusi baru: info sisa porsi (GET), POST /jadwal/distribusi dengan Idempotency-Key tetap pada pengulangan', async () => {
  kepala();
  const el = await buka('/jadwal/distribusi/baru');
  await new Promise((r) => setTimeout(r, 20)); // opsi dropdown relasi dimuat async
  isi(el, { jadwal_produksi_id: '1', titik_layanan_id: '2', jumlah_porsi: '50' });
  semua(el, (n) => { if (n.tag === 'form') n.listeners.change?.(); }); // fake DOM tidak menggelembung
  await new Promise((r) => setTimeout(r, 20));
  assert.ok(teks(el).includes('sisa dapat dialokasikan'), teks(el));
  skrip = [resp(503, { message: 'x' }), resp(201, { data: { id: 5 } })];
  await kirim(); await kirim();
  const t = tulis();
  assert.equal(t.length, 2);
  assert.equal(t[0].path, '/jadwal/distribusi');
  assert.equal(t[0].headers['Idempotency-Key'], t[1].headers['Idempotency-Key']);
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.equal(t[1].body.jumlah_porsi, 50);
  assert.equal(location.hash, '#/jadwal/distribusi/5');
});

test('detail jadwal distribusi: Ubah status PATCH hanya bila izin.ubah; tautan Catat bukti hanya bila flag server + hak Tambah bukti', async () => {
  kepala();
  distRec = { ...dist, izin: { ubah: true, hapus: true, catat_bukti: true } };
  let el = await buka('/jadwal/distribusi/5');
  const btn = tombol(el).find((b) => /Berangkat/i.test(teks(b)));
  assert.ok(btn, 'tombol transisi dari status siap');
  assert.ok(tautan(el).some((x) => x === '#/bukti-distribusi/baru?jadwal=5'), 'tautan Catat bukti ke formulir web dengan prefill jadwal');
  distRec = { ...dist, izin: { ubah: true, hapus: true, catat_bukti: false } };
  assert.ok(!tautan(await buka('/jadwal/distribusi/5')).some((x) => /bukti-distribusi\/baru/.test(x)), 'tanpa flag server tidak ada tautan');
  distRec = { ...dist, izin: { ubah: true, hapus: true, catat_bukti: true } };
  overlays.length = 0;
  skrip = [resp(200, { data: { id: 5 } })];
  const klik = btn.listeners.click();
  await new Promise((r) => setTimeout(r, 5));
  tombol(overlays[0]).find((b) => /Ya, ubah/.test(teks(b))).listeners.click();
  await klik;
  await new Promise((r) => setTimeout(r, 15));
  const patch = tulis().find((p) => p.metode === 'PATCH');
  assert.equal(patch.path, '/jadwal/distribusi/5');
  assert.deepEqual(patch.body, { status: 'berangkat' });
  distRec = { ...dist, izin: { ubah: false, hapus: false } };
  el = await buka('/jadwal/distribusi/5');
  assert.ok(!tombol(el).some((b) => /Berangkat|Hapus/i.test(teks(b))));
  distRec = dist;
});

test('notifikasi perjalanan: prefill jadwal, pratinjau estimasi, POST dengan kunci sekali; tanpa Ubah/Hapus', async () => {
  kepala();
  const el = await buka('/notifikasi-perjalanan/baru?jadwal=5');
  assert.equal(cari(el, 'f-jadwal_distribusi_id').value, '5', 'prefill ?jadwal=');
  skrip = [resp(201, { data: { id: 7 } })];
  overlays.length = 0;
  const k = kirim();
  await new Promise((r) => setTimeout(r, 10));
  if (overlays[0]) tombol(overlays[0]).find((b) => /Berangkat/.test(teks(b)))?.listeners.click();
  await k;
  await new Promise((r) => setTimeout(r, 15));
  const t = tulis().filter((p) => p.path === '/notifikasi-perjalanan');
  assert.equal(t.length, 1);
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.equal(t[0].body.jadwal_distribusi_id, 5);
  const d = await buka('/notifikasi-perjalanan/7');
  assert.ok(!tautan(d).some((x) => /ubah$/.test(x)), 'tidak ada Ubah');
  assert.ok(!tombol(d).some((b) => /Hapus/.test(teks(b))));
});

test('detail perjalanan: tombol status hanya bila izin.ubah_status', async () => {
  kepala();
  let el = await buka('/notifikasi-perjalanan/7');
  assert.ok(tombol(el).some((b) => /Tiba|Batal/i.test(teks(b))), 'ada tombol transisi');
  perjRec = { ...perjRec, izin: { ubah_status: false } };
  el = await buka('/notifikasi-perjalanan/7');
  assert.ok(!tombol(el).some((b) => /Tiba|Batalkan/i.test(teks(b))));
});

test('bukti distribusi (PR5): Tambah web aktif bila peta hak memberi C; foto serah terima wajib (tanpa foto tidak dikirim)', async () => {
  setUserSesi(salin('relawan_distribusi'));
  const { userPerms } = await import('../core/permissions.js');
  assert.equal(userPerms(getUser(), 'bukti-distribusi').C, true, 'fixture: relawan distribusi berhak C');
  let el = await buka('/bukti-distribusi');
  assert.ok(tautan(el).includes('#/bukti-distribusi/baru'), 'tautan Tambah ada');
  el = await buka('/bukti-distribusi/baru');
  assert.ok(cari(el, 'f-porsi_diterima'), 'formulir tambah tampil');
  const foto = cari(el, 'f-foto');
  assert.ok(foto, 'kolom foto serah terima ada');
  assert.equal(foto.attrs?.capture ?? foto.capture, 'environment');
  assert.ok(cari(el, 'f-berita_acara'), 'kolom berita acara ada');
  await new Promise((r) => setTimeout(r, 20));
  isi(el, { jadwal_distribusi_id: '5', porsi_diterima: '80', nama_pic: 'Ibu Fiktif' });
  await kirim();
  assert.equal(tulis().length, 0, 'tanpa foto: validasi klien menahan kiriman');
  assert.match(teks(el), /Foto serah terima wajib diisi/);
  assert.equal(teks(el).match(/Foto serah terima wajib diisi/g).length, 1, 'pesan wajib foto tampil sekali (tanpa duplikat di status foto)');
  setUserSesi(salin('kepala_sppg'));
  el = await buka('/bukti-distribusi');
  // Tambah murni dari peta hak server: profil tanpa C pada bukti-distribusi tidak mendapat tautan.
  assert.equal(tautan(el).includes('#/bukti-distribusi/baru'), userPerms(getUser(), 'bukti-distribusi').C);
});

test('bukti distribusi: Ubah tanpa foto baru (PUT JSON) mengikuti izin server; foto lama tidak dikirim ulang', async () => {
  setUserSesi(salin('relawan_distribusi'));
  const el = await buka('/bukti-distribusi/9/ubah');
  assert.ok(cari(el, 'f-foto'), 'kolom ganti foto tersedia');
  isi(el, { porsi_diterima: '80', nama_pic: 'Ibu Fiktif' });
  skrip = [resp(200, { data: { id: 9 } })];
  await kirim();
  const t = tulis();
  assert.equal(t.length, 1);
  assert.equal(t[0].metode, 'PUT');
  assert.equal(t[0].path, '/bukti-distribusi/9');
  assert.equal('foto' in t[0].body, false);
});
