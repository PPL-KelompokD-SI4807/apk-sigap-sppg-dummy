// R04-8 PR-W4a: modul Persetujuan Menu di web. Daftar + detail untuk peran yang punya hak baca; keputusan (Setujui/Tolak/Perlu Revisi)
// HANYA bila peta hak server memberi `keputusan: persetujuan-menu` (investor); Ajukan Menu hanya bila aksi.ajukan_menu (ahli gizi).
// Hak tombol dari fixture peta.js (cermin keluaran server). Data fiktif.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
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
  getElementById: (id) => tetap[id] ?? null, body: { classList: { add() {}, remove() {}, }, append: (n) => overlays.push(n) }, title: '',
};
globalThis.localStorage = mem();
globalThis.sessionStorage = mem();
globalThis.location = { hash: '', replace(h) { this.hash = h; } };
globalThis.window = { scrollTo() {}, addEventListener() {}, location: globalThis.location };
globalThis.matchMedia = () => ({ matches: false, addEventListener() {} });

const panggilan = [];
let skrip = [];
const resp = (status, body) => ({ ok: status < 300, status, headers: { get: () => null }, json: async () => body });
const lst = (x) => resp(200, { data: x, meta: { current_page: 1, last_page: 1, total: x.length } });
const rincian = {
  menu: { nama_menu: 'Nasi Ayam Fiktif', gizi_per_porsi: { energi_kkal: 650, protein_g: 25, lemak_g: 18, karbohidrat_g: 90, serat_g: 5 } },
  bahan: [{ nama: 'Beras Fiktif', gram_per_porsi: 120, satuan: 'kg', harga_per_satuan: 12000, pemasok_nama: 'Pemasok Fiktif', pemasok_utama: true, biaya_per_porsi: 1440 }],
  tanggal_acuan: '2026-11-19',
};
let pengajuan = {
  id: 7, menu_id: 3, nama_menu: 'Nasi Ayam Fiktif', nomor: 1, menu_versi: 2, menu_versi_sekarang: 2, kedaluwarsa: false, status: 'diajukan', status_label: 'Diajukan',
  biaya_per_porsi: 12000, jumlah_porsi: 100, total_biaya: 1200000, sumber_porsi: 'jadwal_produksi', diajukan_oleh: 'Ahli Gizi Fiktif', diajukan_pada: '2026-11-18T08:00:00+07:00', rincian,
};
const MENU = [
  { id: 3, nama_menu: 'Menu Draf Fiktif', versi: 2, status_persetujuan: 'draf' },
  { id: 4, nama_menu: 'Menu Diajukan Fiktif', versi: 1, status_persetujuan: 'diajukan' },
  { id: 5, nama_menu: 'Menu Disetujui Fiktif', versi: 1, status_persetujuan: 'disetujui' },
];
globalThis.fetch = async (url, init) => {
  const path = new URL(url, 'http://x').pathname.replace('/api/v1', '');
  panggilan.push({ metode: init.method ?? 'GET', path, headers: init.headers, credentials: init.credentials, body: typeof init.body === 'string' ? JSON.parse(init.body) : init.body ?? null });
  if (!init.method || init.method === 'GET') {
    if (path === '/persetujuan-menu/7') return resp(200, { data: pengajuan });
    if (path === '/persetujuan-menu') return lst([{ ...pengajuan, rincian: undefined }]);
    if (path === '/menu-gizi') return lst(MENU);
    return lst([]);
  }
  return skrip.length ? skrip.shift() : resp(200, { data: { id: 1 } });
};

const { setPlatform } = await import('../core/platform.js');
setPlatform('web');
const { setUserSesi, getUser } = await import('../core/auth.js');
const { startRouter, resolve, cekAkses } = await import('../core/router.js');
const { daftarkanRuteWeb, BACA_WEB, TULIS_WEB } = await import('../rute-web.js');
const { PROFIL, salin } = await import('./fixtures/peta.js');
const { kunciPerIsian } = await import('../modules/persetujuan-menu/logika.js');

const MODUL = [(await import('../modules/persetujuan-menu/index.js')).default];
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
const sebagai = (nama) => setUserSesi(salin(nama));
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-9a-f][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
// Klik tombol lalu setujui dialog konfirmasi (teks "Ya, ...").
async function klikKonfirmasi(b, ok = /Ya, /) {
  overlays.length = 0;
  const k = b.listeners.click();
  await new Promise((r) => setTimeout(r, 5));
  tombol(overlays[0]).find((x) => ok.test(teks(x))).listeners.click();
  await k;
  await new Promise((r) => setTimeout(r, 15));
}

test('persetujuan-menu terdaftar di BACA_WEB dan TULIS_WEB; layar sungguhan, bukan "Segera tersedia di web"', async () => {
  assert.ok(BACA_WEB.has('persetujuan-menu') && TULIS_WEB.has('persetujuan-menu'));
  sebagai('investor');
  const el = await buka('/persetujuan-menu');
  assert.ok(!/Segera tersedia/.test(teks(el)));
  assert.match(teks(el), /Nasi Ayam Fiktif/);
  assert.ok(tautan(el).includes('#/persetujuan-menu/7'));
});

test('guard rute mengikuti peta hak server: hanya admin, kepala, ahli gizi, investor yang punya hak baca', () => {
  const boleh = ['admin', 'kepala_sppg', 'ahli_gizi', 'investor'];
  for (const nama of Object.keys(PROFIL)) {
    setUserSesi(salin(nama));
    const harap = boleh.includes(nama) ? 'boleh' : 'ditolak';
    assert.equal(cekAkses('/persetujuan-menu'), harap, nama);
    assert.equal(cekAkses('/persetujuan-menu/7'), harap, `${nama} detail`);
    // Layar Ajukan hanya untuk aksi.ajukan_menu (ahli gizi).
    assert.equal(cekAkses('/persetujuan-menu/baru'), nama === 'ahli_gizi' ? 'boleh' : 'ditolak', `${nama} baru`);
  }
});

test('detail: investor melihat rincian dan tiga tombol keputusan; tanpa tautan ajukan; hanya GET sebelum diklik', async () => {
  sebagai('investor');
  pengajuan = { ...pengajuan, status: 'diajukan', kedaluwarsa: false };
  const el = await buka('/persetujuan-menu/7');
  assert.match(teks(el), /Gizi per porsi/);
  assert.match(teks(el), /Beras Fiktif/);
  assert.deepEqual(tombol(el).map(teks).filter((t) => /Setujui|Tolak|Perlu Revisi/.test(t)), ['Setujui', 'Tolak', 'Perlu Revisi']);
  assert.ok(!tautan(el).includes('#/persetujuan-menu/baru'));
  assert.ok(panggilan.every((p) => p.metode === 'GET' && !('Authorization' in p.headers)));
});

test('keputusan: Tolak wajib catatan >= 5 karakter; POST dengan versi_menu; kunci Idempotency-Key sama saat diulang, baru bila isian berubah', async () => {
  sebagai('investor');
  const el = await buka('/persetujuan-menu/7');
  const tolak = tombol(el).find((b) => teks(b) === 'Tolak');
  await tolak.listeners.click();
  assert.equal(tulis().length, 0, 'tanpa catatan ditahan klien');
  assert.match(teks(el), /Catatan wajib diisi/);
  cari(el, 'catatan-keputusan').value = 'abc';
  await tolak.listeners.click();
  assert.equal(tulis().length, 0, 'catatan terlalu pendek ditahan klien');
  cari(el, 'catatan-keputusan').value = 'Biaya per porsi terlalu tinggi';
  skrip = [resp(503, { message: 'x' }), resp(200, { data: { ...pengajuan, status: 'ditolak' } })];
  await klikKonfirmasi(tolak);
  tolak.disabled = false;
  await klikKonfirmasi(tolak);
  const t = tulis().filter((p) => p.path === '/persetujuan-menu/7/keputusan');
  assert.equal(t.length, 2);
  assert.deepEqual(t[0].body, { keputusan: 'ditolak', versi_menu: 2, catatan: 'Biaya per porsi terlalu tinggi' });
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.equal(t[0].headers['Idempotency-Key'], t[1].headers['Idempotency-Key'], 'isian sama -> kunci sama');
  assert.ok(!('Authorization' in t[0].headers), 'web tanpa Bearer');
  // Muatan tidak pernah memuat harga/biaya/porsi (dihitung server).
  assert.ok(!Object.keys(t[0].body).some((k) => /biaya|harga|porsi/.test(k)));
  // Isian berbeda (disetujui tanpa catatan) = permintaan baru = kunci baru.
  skrip = [resp(200, { data: { ...pengajuan, status: 'disetujui' } })];
  const setujui = tombol(el).find((b) => teks(b) === 'Setujui');
  cari(el, 'catatan-keputusan').value = '';
  await klikKonfirmasi(setujui);
  const t3 = tulis().filter((p) => p.path === '/persetujuan-menu/7/keputusan')[2];
  assert.deepEqual(t3.body, { keputusan: 'disetujui', versi_menu: 2 });
  assert.notEqual(t3.headers['Idempotency-Key'], t[0].headers['Idempotency-Key']);
});

test('keputusan 409: pesan server (Indonesia) ditampilkan dan layar dimuat ulang', async () => {
  sebagai('investor');
  const el = await buka('/persetujuan-menu/7');
  skrip = [resp(409, { message: 'Pengajuan ini sudah diputuskan.' })];
  await klikKonfirmasi(tombol(el).find((b) => teks(b) === 'Setujui'));
  assert.match(teks(tetap.toasts), /Pengajuan ini sudah diputuskan\./);
  assert.ok(panggilan.filter((p) => p.metode === 'GET' && p.path === '/persetujuan-menu/7').length >= 1, 'dimuat ulang');
});

test('keputusan 403 dari server: pesan hak, tombol aktif kembali', async () => {
  sebagai('investor');
  const el = await buka('/persetujuan-menu/7');
  skrip = [resp(403, { message: 'Forbidden' })];
  const b = tombol(el).find((x) => teks(x) === 'Setujui');
  await klikKonfirmasi(b);
  assert.match(teks(tetap.toasts), /tidak berhak/);
  assert.equal(b.disabled, false);
});

test('hak tombol keputusan dari server: tanpa di status selain diajukan, menu kedaluwarsa, atau peran tanpa keputusan (admin, kepala, ahli gizi)', async () => {
  sebagai('investor');
  for (const ubah of [{ status: 'disetujui' }, { status: 'ditolak' }, { status: 'dibatalkan' }, { kedaluwarsa: true }]) {
    const asli = pengajuan;
    pengajuan = { ...pengajuan, ...ubah };
    const el = await buka('/persetujuan-menu/7');
    assert.ok(!tombol(el).some((b) => /^(Setujui|Tolak|Perlu Revisi)$/.test(teks(b))), JSON.stringify(ubah));
    pengajuan = asli;
  }
  pengajuan = { ...pengajuan, kedaluwarsa: true };
  assert.match(teks(await buka('/persetujuan-menu/7')), /tidak dapat diputuskan/);
  pengajuan = { ...pengajuan, kedaluwarsa: false };
  for (const nama of ['admin', 'kepala_sppg', 'ahli_gizi']) {
    sebagai(nama);
    const el = await buka('/persetujuan-menu/7');
    assert.ok(el.anak.length > 0, `${nama}: detail tampil`);
    assert.ok(!tombol(el).some((b) => /^(Setujui|Tolak|Perlu Revisi)$/.test(teks(b))), `${nama}: tanpa tombol keputusan`);
  }
  // Peta rusak/kosong: fail closed.
  const u = salin('investor');
  u.keputusan = [];
  setUserSesi(u);
  assert.ok(!tombol(await buka('/persetujuan-menu/7')).some((b) => /^Setujui$/.test(teks(b))), 'keputusan kosong -> tanpa tombol');
});

test('daftar: tautan "+ Ajukan Menu" hanya untuk pemegang aksi.ajukan_menu (ahli gizi)', async () => {
  for (const nama of ['admin', 'kepala_sppg', 'investor']) {
    sebagai(nama);
    assert.ok(!tautan(await buka('/persetujuan-menu')).includes('#/persetujuan-menu/baru'), nama);
  }
  sebagai('ahli_gizi');
  assert.ok(tautan(await buka('/persetujuan-menu')).includes('#/persetujuan-menu/baru'));
});

test('ajukan (ahli gizi): hanya menu draf/ditolak/perlu revisi punya tombol; POST /menu-gizi/{id}/ajukan hanya versi + catatan; kunci sama saat diulang', async () => {
  sebagai('ahli_gizi');
  const el = await buka('/persetujuan-menu/baru');
  assert.match(teks(el), /Menu Draf Fiktif/);
  const aju = tombol(el).filter((b) => teks(b) === 'Ajukan ke Investor');
  assert.equal(aju.length, 1, 'hanya menu draf yang dapat diajukan');
  assert.match(teks(el), /Sedang menunggu keputusan investor/);
  assert.match(teks(el), /Sudah disetujui investor/);
  skrip = [resp(503, { message: 'x' }), resp(201, { data: { id: 7 } })];
  await klikKonfirmasi(aju[0]);
  aju[0].disabled = false;
  await klikKonfirmasi(aju[0]);
  const t = tulis();
  assert.equal(t.length, 2);
  assert.equal(t[0].path, '/menu-gizi/3/ajukan');
  assert.deepEqual(t[0].body, { versi: 2 });
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.equal(t[0].headers['Idempotency-Key'], t[1].headers['Idempotency-Key']);
  assert.equal(location.hash, '#/persetujuan-menu/7');
});

test('ajukan 422: bahan tanpa harga ditampilkan sebagai daftar; tombol aktif kembali', async () => {
  sebagai('ahli_gizi');
  const el = await buka('/persetujuan-menu/baru');
  skrip = [resp(422, { message: 'x', errors: {}, bahan_bermasalah: [{ nama: 'Telur Fiktif', masalah: 'Belum ada harga berlaku.' }] })];
  const b = tombol(el).find((x) => teks(x) === 'Ajukan ke Investor');
  await klikKonfirmasi(b);
  assert.match(teks(el), /Telur Fiktif/);
  assert.match(teks(el), /Belum ada harga berlaku/);
  assert.equal(b.disabled, false);
});

test('kunciPerIsian: kunci tetap untuk isian sama, baru bila isian berubah', () => {
  let n = 0;
  const k = kunciPerIsian(() => `k${++n}`);
  assert.equal(k({ a: 1 }), 'k1');
  assert.equal(k({ a: 1 }), 'k1');
  assert.equal(k({ a: 2 }), 'k2');
});

test('sumber modul tanpa innerHTML/insertAdjacentHTML/outerHTML', () => {
  for (const f of ['index.js', 'ajukan-web.js', 'logika.js']) {
    const src = readFileSync(fileURLToPath(new URL(`../modules/persetujuan-menu/${f}`, import.meta.url)), 'utf8');
    assert.ok(!/innerHTML|insertAdjacentHTML|outerHTML/.test(src), f);
  }
});
