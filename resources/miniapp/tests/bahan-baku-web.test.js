// R04-8 PR-W1: modul Bahan Baku di web. Daftar, detail, stok menipis, kedaluwarsa dekat, Tambah/Ubah/Hapus (staf gudang, admin) dan
// mutasi stok (hanya pemegang aksi.catat_mutasi_stok; riwayat hanya aksi.lihat_mutasi_stok === true). Hak tombol dari fixture peta.js
// (cermin keluaran server). Idempotency-Key sekali per formulir/isian. Tanpa antrean offline, tanpa header Authorization. Data fiktif.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

class Node_ {}
const buatEl = (tag) => {
  const el = new Node_();
  Object.assign(el, {
    tag, attrs: {}, listeners: {}, anak: [], className: '', dataset: {}, style: { cssText: '' }, textContent: '', disabled: false, hidden: false,
    classList: { add() {}, remove() {}, toggle() {} },
    setAttribute(k, v) { this.attrs[k] = String(v); },
    addEventListener(n, fn) { this.listeners[n] = fn; },
    append(...xs) { this.anak.push(...xs); },
    replaceChildren(...xs) { this.anak = [...xs]; },
    insertBefore(n) { this.anak.unshift(n); },
    prepend(n) { this.anak.unshift(n); },
    scrollIntoView() {}, focus() {}, remove() {},
  });
  let v = ''; // seperti DOM: value selalu string
  Object.defineProperty(el, 'value', { get: () => v, set: (x) => { v = x == null ? '' : String(x); }, enumerable: true, configurable: true });
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
const lst = (x) => resp(200, { data: x, meta: { current_page: 1, last_page: 1, total: x.length } });
const BERAS = {
  id: 1, sppg_id: 1, pemasok_id: 2, pemasok: { id: 2, nama: 'Pemasok Fiktif' }, kode: 'BRS-01', nama: 'Beras Fiktif', kategori: 'karbohidrat', satuan: 'kg',
  stok: 40, stok_minimum: 100, kedaluwarsa_terdekat: '2026-11-20', stok_menipis: true, akan_kedaluwarsa: true,
};
const GULA = { ...BERAS, id: 3, nama: 'Gula Fiktif', kode: 'GLA-01', stok: 500, stok_minimum: 10, stok_menipis: false, akan_kedaluwarsa: false, kedaluwarsa_terdekat: null };
globalThis.fetch = async (url, init) => {
  const u = new URL(url, 'http://x');
  const path = u.pathname.replace('/api/v1', '');
  panggilan.push({ metode: init.method ?? 'GET', path, query: u.search, headers: init.headers, credentials: init.credentials, body: typeof init.body === 'string' ? JSON.parse(init.body) : init.body ?? null });
  if (!init.method || init.method === 'GET') {
    if (path === '/bahan-baku/1') return resp(200, { data: BERAS });
    if (path === '/bahan-baku/3') return resp(200, { data: GULA });
    if (path === '/bahan-baku/stok-menipis') return lst([BERAS]);
    if (path === '/bahan-baku/kedaluwarsa-dekat') return lst([BERAS]);
    if (path === '/bahan-baku') return lst([BERAS, GULA]);
    if (path === '/bahan-baku/1/batch') return resp(200, { data: [{ id: 5, tanggal_masuk: '2026-11-10', tanggal_kedaluwarsa: '2026-11-20', sisa: 40, sisa_hari: 1, jumlah_awal: 60 }], stok_tanpa_batch: 0 });
    if (path === '/bahan-baku/1/mutasi') return lst([{ id: 8, jenis: 'masuk', jumlah: 60, tanggal_mutasi: '2026-11-10', pencatat: 'Staf Fiktif', catatan: 'Kiriman pagi' }]);
    if (path === '/pemasok') return lst([{ id: 2, nama: 'Pemasok Fiktif', is_active: true }]);
    if (path === '/sppg') return lst([{ id: 1, nama: 'SPPG Contoh' }]);
    if (path === '/jadwal') return lst([{ id: 12, status: 'direncanakan', tanggal_produksi: '2026-11-22', target_porsi: 300, menu: { nama_menu: 'Menu Fiktif' } }]);
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
const { pesanGalatMutasi, hasilTakPasti } = await import('../modules/bahan-baku/mutasi-web.js');
const { kunciPerIsian } = await import('../core/kunci-idempoten.js');

const MODUL = [(await import('../modules/bahan-baku/index.js')).default].flat();
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
  await new Promise((r) => setTimeout(r, 20));
  return outlet;
}
const tulis = () => panggilan.filter((p) => p.metode !== 'GET');
const sebagai = (nama) => setUserSesi(salin(nama));
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-9a-f][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const kirim = async () => { await tetap.mainbtn.onclick(); await new Promise((r) => setTimeout(r, 25)); };
// Tiruan DOM tanpa bubbling: setelah mengisi kolom, picu juga pendengar tingkat <form> (showIf menyembunyikan/menampilkan kolom).
const formDi = (el) => { let f = null; semua(el, (n) => { if (n.tag === 'form') f = n; }); return f; };
function isi(el, nilai) {
  for (const [id, v] of Object.entries(nilai)) {
    const i = cari(el, `f-${id}`); assert.ok(i, `bidang ${id} ada`);
    i.value = v; i.listeners.input?.(); i.listeners.change?.();
    formDi(el)?.listeners.change?.({ target: i });
  }
}
async function klikKonfirmasi(b, ok = /Hapus|Ya, /) {
  overlays.length = 0;
  const k = b.listeners.click();
  await new Promise((r) => setTimeout(r, 5));
  tombol(overlays[0]).find((x) => ok.test(teks(x))).listeners.click();
  await k;
  await new Promise((r) => setTimeout(r, 20));
}

test('bahan-baku di BACA_WEB dan TULIS_WEB; layar sungguhan (bukan "Segera tersedia di web")', async () => {
  assert.ok(BACA_WEB.has('bahan-baku') && TULIS_WEB.has('bahan-baku'));
  sebagai('kepala_sppg');
  const el = await buka('/bahan-baku');
  assert.ok(!/Segera tersedia/.test(teks(el)));
  assert.match(teks(el), /Beras Fiktif/);
  assert.match(teks(el), /Stok menipis/);
  assert.ok(tautan(el).includes('#/bahan-baku/1'));
  for (const h of ['#/bahan-baku/stok-menipis', '#/bahan-baku/kedaluwarsa-dekat']) assert.ok(tautan(el).includes(h), `tab ${h}`);
});

test('guard mengikuti peta hak server: baca = semua peran bermodul; Tambah/Ubah = staf gudang dan admin; mutasi = aksi.catat_mutasi_stok', () => {
  const baca = ['admin', 'kepala_sppg', 'ahli_gizi', 'akuntan', 'staf_gudang', 'investor'];
  const tulisPeran = ['admin', 'staf_gudang'];
  for (const nama of Object.keys(PROFIL)) {
    setUserSesi(salin(nama));
    for (const p of ['/bahan-baku', '/bahan-baku/1', '/bahan-baku/stok-menipis', '/bahan-baku/kedaluwarsa-dekat']) {
      assert.equal(cekAkses(p), baca.includes(nama) ? 'boleh' : 'ditolak', `${nama} ${p}`);
    }
    assert.equal(cekAkses('/bahan-baku/baru'), tulisPeran.includes(nama) ? 'boleh' : 'ditolak', `${nama} baru`);
    assert.equal(cekAkses('/bahan-baku/1/ubah'), tulisPeran.includes(nama) ? 'boleh' : 'ditolak', `${nama} ubah`);
    assert.equal(cekAkses('/bahan-baku/1/mutasi'), tulisPeran.includes(nama) ? 'boleh' : 'ditolak', `${nama} mutasi`);
  }
});

test('peran baca-saja (investor, ahli gizi): daftar tanpa Tambah; detail tanpa Ubah/Hapus/Catat mutasi; TIDAK memanggil API mutasi; hanya GET', async () => {
  for (const nama of ['investor', 'ahli_gizi']) {
    sebagai(nama);
    let el = await buka('/bahan-baku');
    assert.ok(!tautan(el).includes('#/bahan-baku/baru'), `${nama} tanpa Tambah`);
    el = await buka('/bahan-baku/1');
    assert.match(teks(el), /Batch/);
    assert.ok(!/Riwayat mutasi/.test(teks(el)), nama);
    assert.ok(!tautan(el).some((x) => /\/mutasi$|\/ubah$/.test(x)), nama);
    assert.ok(!tombol(el).some((b) => /^Hapus$/.test(teks(b))), nama);
    assert.equal(panggilan.filter((p) => /\/mutasi/.test(p.path)).length, 0, `${nama}: ${JSON.stringify(panggilan.map((p) => p.path))}`);
    assert.ok(panggilan.every((p) => p.metode === 'GET' && !('Authorization' in p.headers)));
  }
});

test('kepala SPPG dan akuntan: riwayat mutasi tampil (aksi.lihat_mutasi_stok) tetapi tanpa Catat mutasi/Ubah/Hapus', async () => {
  for (const nama of ['kepala_sppg', 'akuntan']) {
    sebagai(nama);
    const el = await buka('/bahan-baku/1');
    assert.match(teks(el), /Riwayat mutasi terbaru/);
    assert.match(teks(el), /Kiriman pagi/);
    assert.ok(panggilan.some((p) => p.path === '/bahan-baku/1/mutasi'));
    assert.ok(!tautan(el).some((x) => /\/mutasi$/.test(x)), nama);
    assert.ok(!tombol(el).some((b) => /^Hapus$/.test(teks(b))));
  }
});

test('aksi.lihat_mutasi_stok false (walau bisa mencatat mutasi): riwayat tidak dirender dan API riwayat tidak dipanggil (fail closed)', async () => {
  const u = salin('staf_gudang');
  u.aksi.lihat_mutasi_stok = false;
  setUserSesi(u);
  const el = await buka('/bahan-baku/1');
  assert.ok(!/Riwayat mutasi/.test(teks(el)));
  assert.equal(panggilan.filter((p) => p.path === '/bahan-baku/1/mutasi').length, 0);
  assert.ok(tautan(el).includes('#/bahan-baku/1/mutasi'), 'tautan Catat mutasi mengikuti aksi.catat_mutasi_stok');
});

test('stok menipis dan kedaluwarsa dekat: GET endpoint khusus, penanda tampil', async () => {
  sebagai('staf_gudang');
  let el = await buka('/bahan-baku/stok-menipis');
  assert.ok(panggilan.some((p) => p.path === '/bahan-baku/stok-menipis'));
  assert.match(teks(el), /Beras Fiktif/);
  assert.match(teks(el), /Stok menipis/);
  el = await buka('/bahan-baku/kedaluwarsa-dekat');
  assert.ok(panggilan.some((p) => p.path === '/bahan-baku/kedaluwarsa-dekat'));
  assert.match(teks(el), /Kedaluwarsa dekat/);
});

test('detail (staf gudang): peringatan, batch, riwayat, tautan Catat mutasi/Ubah, tombol Hapus; foto/innerHTML tidak dipakai', async () => {
  sebagai('staf_gudang');
  const el = await buka('/bahan-baku/1');
  assert.match(teks(el), /Stok menipis: 40 kg/);
  assert.match(teks(el), /Dipakai dulu/);
  assert.match(teks(el), /Riwayat mutasi terbaru/);
  assert.ok(tautan(el).includes('#/bahan-baku/1/mutasi'));
  assert.ok(tombol(el).some((b) => /^Hapus$/.test(teks(b))));
  assert.ok(panggilan.every((p) => !('Authorization' in p.headers)));
});

test('Tambah (staf gudang): POST /bahan-baku dengan Idempotency-Key tetap pada pengulangan; muatan benar; tanpa SPPG (sppg_id dari sesi)', async () => {
  sebagai('staf_gudang');
  let el = await buka('/bahan-baku');
  assert.ok(tautan(el).includes('#/bahan-baku/baru'));
  el = await buka('/bahan-baku/baru');
  assert.ok(!cari(el, 'f-sppg_id'), 'staf gudang punya SPPG sendiri');
  isi(el, { nama: 'Telur Fiktif', kode: 'TLR-01', kategori: 'protein_hewani', satuan: 'butir', stok_minimum: '200', pemasok_id: '2' });
  skrip = [resp(503, { message: 'x' }), resp(201, { data: { ...BERAS, id: 9, nama: 'Telur Fiktif' } })];
  await kirim(); await kirim();
  const t = tulis();
  assert.equal(t.length, 2);
  assert.equal(t[0].path, '/bahan-baku');
  assert.deepEqual(t[0].body, { nama: 'Telur Fiktif', kode: 'TLR-01', kategori: 'protein_hewani', satuan: 'butir', stok_minimum: 200, pemasok_id: 2 });
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.equal(t[0].headers['Idempotency-Key'], t[1].headers['Idempotency-Key']);
  assert.ok(!('Authorization' in t[0].headers));
  assert.equal(location.hash, '#/bahan-baku/9');
});

test('Tambah (admin): kolom SPPG wajib karena admin tanpa SPPG sendiri', async () => {
  sebagai('admin');
  const el = await buka('/bahan-baku/baru');
  assert.ok(cari(el, 'f-sppg_id'));
});

test('Tambah: validasi klien menahan isian kosong (tanpa permintaan); 422 server ditampilkan per kolom', async () => {
  sebagai('staf_gudang');
  const el = await buka('/bahan-baku/baru');
  await kirim();
  assert.equal(tulis().length, 0);
  isi(el, { nama: 'Telur Fiktif', kategori: 'protein_hewani', satuan: 'butir' });
  skrip = [resp(422, { message: 'x', errors: { kode: ['Kode sudah dipakai di SPPG ini.'] } })];
  await kirim();
  assert.equal(tulis().length, 1);
  assert.match(teks(el), /Kode sudah dipakai/);
});

test('Ubah: PUT /bahan-baku/{id}; stok tidak dikirim; nilai awal dari record', async () => {
  sebagai('staf_gudang');
  const el = await buka('/bahan-baku/1/ubah');
  assert.equal(cari(el, 'f-nama').value, 'Beras Fiktif');
  assert.equal(cari(el, 'f-pemasok_id').value, '2');
  isi(el, { stok_minimum: '150' });
  skrip = [resp(200, { data: { ...BERAS, stok_minimum: 150 } })];
  await kirim();
  const t = tulis();
  assert.equal(t.length, 1);
  assert.equal(t[0].metode, 'PUT');
  assert.equal(t[0].path, '/bahan-baku/1');
  assert.equal(t[0].body.stok_minimum, 150);
  assert.ok(!('stok' in t[0].body));
  assert.equal(location.hash, '#/bahan-baku/1');
});

test('Hapus: konfirmasi lalu DELETE; 422 (masih dipakai menu) ditampilkan dan tetap di layar', async () => {
  sebagai('staf_gudang');
  let el = await buka('/bahan-baku/1');
  const hapus = tombol(el).find((b) => /^Hapus$/.test(teks(b)));
  skrip = [resp(422, { message: 'x', errors: { bahan_baku: ['Bahan baku masih dipakai pada komposisi menu. Lepaskan dari menu terlebih dahulu.'] } })];
  await klikKonfirmasi(hapus);
  assert.equal(tulis()[0].metode, 'DELETE');
  assert.match(teks(tetap.toasts), /masih dipakai pada komposisi menu/);
  el = await buka('/bahan-baku/1');
  skrip = [resp(204, null)];
  await klikKonfirmasi(tombol(el).find((b) => /^Hapus$/.test(teks(b))));
  assert.equal(tulis().length, 1);
  assert.equal(location.hash, '#/bahan-baku');
});

test('mutasi masuk: POST /bahan-baku/{id}/mutasi, kunci tetap saat diulang dengan isian sama, kunci baru bila isian berubah; tanpa Authorization', async () => {
  sebagai('staf_gudang');
  const el = await buka('/bahan-baku/1/mutasi');
  assert.match(teks(el), /Stok saat ini 40 kg/);
  isi(el, { jenis: 'masuk', jumlah: '25', harga_satuan: '13000', tanggal_kedaluwarsa: '2027-01-15' });
  skrip = [resp(503, { message: 'x' }), resp(201, { data: { id: 99 }, bahan_baku: { ...BERAS, stok: 65 } })];
  await kirim();
  assert.match(teks(el), /Mutasi mungkin sudah tercatat/, 'hasil tak pasti: periksa stok sebelum kirim ulang');
  await kirim();
  const t = tulis();
  assert.equal(t.length, 2);
  assert.equal(t[0].path, '/bahan-baku/1/mutasi');
  assert.equal(t[0].body.jenis, 'masuk');
  assert.equal(t[0].body.jumlah, 25);
  assert.equal(t[0].body.harga_satuan, 13000);
  assert.equal(t[0].body.tanggal_kedaluwarsa, '2027-01-15');
  assert.ok(!('ai_draft_id' in t[0].body));
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.equal(t[0].headers['Idempotency-Key'], t[1].headers['Idempotency-Key']);
  assert.ok(!('Authorization' in t[0].headers));
  assert.equal(location.hash, '#/bahan-baku/1');
  assert.match(teks(tetap.toasts), /Stok sekarang 65 kg/);
});

test('mutasi: isian berubah setelah galat = kunci idempotensi baru', async () => {
  sebagai('staf_gudang');
  const el = await buka('/bahan-baku/1/mutasi');
  isi(el, { jenis: 'masuk', jumlah: '10' });
  skrip = [resp(503, { message: 'x' }), resp(201, { data: { id: 1 }, bahan_baku: BERAS })];
  await kirim();
  isi(el, { jumlah: '12' });
  await kirim();
  const t = tulis();
  assert.equal(t.length, 2);
  assert.notEqual(t[0].headers['Idempotency-Key'], t[1].headers['Idempotency-Key']);
});

test('mutasi keluar: validasi klien (melebihi stok, tanpa alasan/jadwal) menahan kirim; penyesuaian wajib alasan', async () => {
  sebagai('staf_gudang');
  const el = await buka('/bahan-baku/1/mutasi');
  isi(el, { jenis: 'keluar', jumlah: '999' });
  await kirim();
  assert.equal(tulis().length, 0);
  assert.match(teks(el), /melebihi stok tersedia/);
  isi(el, { jenis: 'penyesuaian', jumlah: '-2', catatan: '' });
  await kirim();
  assert.equal(tulis().length, 0);
  assert.match(teks(el), /Alasan penyesuaian wajib diisi/);
  isi(el, { jenis: 'keluar', jumlah: '5', jadwal_produksi_id: '12' });
  skrip = [resp(201, { data: { id: 1 }, bahan_baku: { ...BERAS, stok: 35 } })];
  await kirim();
  assert.equal(tulis().length, 1);
  assert.equal(tulis()[0].body.jadwal_produksi_id, 12);
  assert.ok(!('pemasok_id' in tulis()[0].body));
});

test('mutasi 422 stok negatif dari server tampil per kolom; 409 menawarkan muat ulang', async () => {
  sebagai('staf_gudang');
  let el = await buka('/bahan-baku/1/mutasi');
  isi(el, { jenis: 'masuk', jumlah: '5' });
  skrip = [resp(422, { message: 'x', errors: { jumlah: ['Stok tidak boleh negatif.'] } })];
  await kirim();
  assert.match(teks(el), /Stok tidak boleh negatif/);
  el = await buka('/bahan-baku/1/mutasi');
  isi(el, { jenis: 'masuk', jumlah: '5' });
  skrip = [resp(404, { message: 'x' })];
  await kirim();
  assert.ok(tombol(el).some((b) => /Muat ulang/.test(teks(b))));
});

test('mutasi: peran tanpa aksi.catat_mutasi_stok tidak membuka layar dan tidak memanggil API', async () => {
  for (const nama of ['kepala_sppg', 'akuntan', 'ahli_gizi', 'investor']) {
    sebagai(nama);
    await buka('/bahan-baku/1/mutasi');
    assert.equal(panggilan.filter((p) => /\/mutasi/.test(p.path)).length, 0, nama);
    assert.equal(tulis().length, 0, nama);
  }
});

test('pesanGalatMutasi dan kunciPerIsian: hasil tak pasti tidak mengundang kirim ulang buta; kunci bentrok dijelaskan', () => {
  assert.equal(hasilTakPasti({ status: 0 }), true);
  assert.equal(hasilTakPasti({ status: 503 }), true);
  assert.equal(hasilTakPasti({ status: 422 }), false);
  assert.match(pesanGalatMutasi({ status: 0 }).pesan, /periksa stok/);
  assert.match(pesanGalatMutasi({ status: 422, errors: { 'Idempotency-Key': ['x'] } }).pesan, /sebelumnya mungkin sudah tercatat/);
  assert.equal(pesanGalatMutasi({ status: 404 }).muatUlang, true);
  let n = 0;
  const k = kunciPerIsian(() => `k${++n}`);
  assert.equal(k({ a: 1 }), 'k1');
  assert.equal(k({ a: 1 }), 'k1');
  assert.equal(k({ a: 2 }), 'k2');
});

test('modul bahan-baku tidak memakai innerHTML dan web tidak mengimpor Authorization/Telegram di mutasi-web', () => {
  const dir = fileURLToPath(new URL('../modules/bahan-baku/', import.meta.url));
  for (const f of ['index.js', 'schema.js', 'mutasi-web.js']) assert.ok(!/innerHTML|insertAdjacentHTML/.test(readFileSync(`${dir}${f}`, 'utf8')), f);
  const web = readFileSync(`${dir}mutasi-web.js`, 'utf8');
  assert.ok(!/Authorization|Telegram|muatDraf|offline-queue/.test(web.replace(/\/\/.*$/gm, '')));
});
