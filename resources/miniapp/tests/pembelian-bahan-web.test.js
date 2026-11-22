// R04-8 PR-W4b: modul Pembelian Bahan di web. Staf gudang Tambah/Ubah + foto nota (unggah tertunda lalu lampiran_ids), akuntan
// verifikasi (izin.verifikasi dari server), investor dan peran tanpa hak baca tidak punya akses. Nota privat: tak dimuat/ditampilkan
// untuk peran tak berhak. Hak tombol dari fixture peta.js (cermin keluaran server). Data fiktif.
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
  let v = ''; // seperti DOM: value selalu string
  Object.defineProperty(el, 'value', { get: () => v, set: (x) => { v = x == null ? '' : String(x); }, enumerable: true, configurable: true });
  if (tag === 'canvas') { el.getContext = () => ({ fillRect() {}, drawImage() {}, set fillStyle(_) {} }); el.toBlob = (cb) => cb(new Blob([new Uint8Array(200)], { type: 'image/jpeg' })); }
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
globalThis.createImageBitmap = async () => ({ width: 800, height: 600, close() {} });
let nUrl = 0;
globalThis.URL.createObjectURL = () => `blob:uji-${++nUrl}`;
globalThis.URL.revokeObjectURL = () => {};

const panggilan = [];
let skrip = [];
const resp = (status, body) => ({ ok: status < 300, status, headers: { get: () => null }, json: async () => body });
const lst = (x) => resp(200, { data: x, meta: { current_page: 1, last_page: 1, total: x.length } });
const IZIN_AKUNTAN = { ubah: false, hapus: false, lampirkan: false, verifikasi: true };
const IZIN_GUDANG = { ubah: true, hapus: false, lampirkan: true, verifikasi: false };
let rec = {
  id: 9, sppg_id: 1, tanggal_beli: '2026-11-18', bahan_baku: { id: 1, nama: 'Beras Fiktif', satuan: 'kg' }, pemasok: { id: 2, nama: 'Pemasok Fiktif' },
  jumlah: 50, satuan: 'kg', harga_satuan: 12500, total: 625000, status: 'diajukan', status_label: 'Menunggu verifikasi', versi: 4, alasan_penolakan: null, pengeluaran_id: null,
  dicatat_oleh: { id: 3, name: 'Staf Fiktif' }, diverifikasi_oleh: null, diverifikasi_pada: null,
  lampirans: [{ id: 77, url: '/api/v1/lampiran/77', kategori: 'nota', ukuran: 2048 }],
  harga_referensi: { harga: 12000, berlaku_mulai: '2026-11-01', selisih_persen: 4.17 }, izin: IZIN_AKUNTAN,
};
// Unggah foto memakai XHR (progres): tiruan merekam permintaan seperti fetch dan membalas 201 {data:{id:501}}.
globalThis.XMLHttpRequest = class {
  constructor() { this.hdr = {}; this.upload = {}; }
  open(m, u) { this.metode = m; this.url = u; }
  setRequestHeader(k, v) { this.hdr[k] = v; }
  send(b) {
    panggilan.push({ metode: this.metode, path: new URL(this.url, 'http://x').pathname.replace('/api/v1', ''), headers: this.hdr, body: b });
    queueMicrotask(() => { this.status = 201; this.responseText = JSON.stringify({ data: { id: 501 } }); this.onload(); });
  }
};
globalThis.fetch = async (url, init) => {
  const path = new URL(url, 'http://x').pathname.replace('/api/v1', '');
  panggilan.push({ metode: init.method ?? 'GET', path, headers: init.headers, credentials: init.credentials, body: typeof init.body === 'string' ? JSON.parse(init.body) : init.body ?? null });
  if (/^\/lampiran\/\d+$/.test(path) && (!init.method || init.method === 'GET')) return { ok: true, status: 200, headers: { get: () => null }, blob: async () => new Blob(['x'], { type: 'image/jpeg' }) };
  if (!init.method || init.method === 'GET') {
    if (path === '/pembelian-bahan/9') return resp(200, { data: rec });
    if (path === '/pembelian-bahan') return lst([{ ...rec, harga_referensi: undefined }]);
    if (path === '/bahan-baku') return lst([{ id: 1, nama: 'Beras Fiktif', satuan: 'kg', is_active: true }]);
    if (path === '/pemasok') return lst([{ id: 2, nama: 'Pemasok Fiktif', is_active: true }]);
    return lst([]);
  }
  if (path === '/lampiran' && !skrip.length) return resp(201, { data: { id: 501 } });
  return skrip.length ? skrip.shift() : resp(200, { data: { id: 9 } });
};

const { setPlatform } = await import('../core/platform.js');
setPlatform('web');
const { setUserSesi, getUser } = await import('../core/auth.js');
const { startRouter, resolve, cekAkses } = await import('../core/router.js');
const { daftarkanRuteWeb, BACA_WEB, TULIS_WEB } = await import('../rute-web.js');
const { PROFIL, salin } = await import('./fixtures/peta.js');

const MODUL = [(await import('../modules/pembelian-bahan/index.js')).default].flat();
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
function isi(el, nilai) { for (const [id, v] of Object.entries(nilai)) { const i = cari(el, `f-${id}`); assert.ok(i, `bidang ${id} ada`); i.value = v; i.listeners.input?.(); i.listeners.change?.(); } }
async function klikKonfirmasi(b, ok = /Ya, /) {
  overlays.length = 0;
  const k = b.listeners.click();
  await new Promise((r) => setTimeout(r, 5));
  tombol(overlays[0]).find((x) => ok.test(teks(x))).listeners.click();
  await k;
  await new Promise((r) => setTimeout(r, 20));
}
async function pilihFoto(el) {
  const input = cari(el, 'f-lampiran-pembelian-bahan');
  assert.ok(input, 'input kamera foto nota');
  input.files = [new File([new Uint8Array([0xff, 0xd8, 0xff, 1, 2, 3])], 'nota.jpg', { type: 'image/jpeg' })];
  input.listeners.change();
  await new Promise((r) => setTimeout(r, 60));
}

test('pembelian-bahan di BACA_WEB dan TULIS_WEB; layar sungguhan (bukan "Segera tersedia di web"), tautan detail', async () => {
  assert.ok(BACA_WEB.has('pembelian-bahan') && TULIS_WEB.has('pembelian-bahan'));
  sebagai('akuntan');
  const el = await buka('/pembelian-bahan');
  assert.ok(!/Segera tersedia/.test(teks(el)));
  assert.match(teks(el), /Beras Fiktif/);
  assert.ok(tautan(el).includes('#/pembelian-bahan/9'));
  assert.ok(!tautan(el).includes('#/pembelian-bahan/baru'), 'akuntan tanpa Tambah');
  assert.ok(panggilan.every((p) => p.metode === 'GET' && !('Authorization' in p.headers)));
});

test('guard mengikuti peta hak server: baca = admin, kepala, akuntan, staf gudang; Tambah/Ubah = staf gudang; investor tidak punya akses', () => {
  const baca = ['admin', 'kepala_sppg', 'akuntan', 'staf_gudang'];
  for (const nama of Object.keys(PROFIL)) {
    setUserSesi(salin(nama));
    assert.equal(cekAkses('/pembelian-bahan'), baca.includes(nama) ? 'boleh' : 'ditolak', nama);
    assert.equal(cekAkses('/pembelian-bahan/9'), baca.includes(nama) ? 'boleh' : 'ditolak', `${nama} detail`);
    assert.equal(cekAkses('/pembelian-bahan/baru'), nama === 'staf_gudang' ? 'boleh' : 'ditolak', `${nama} baru`);
    assert.equal(cekAkses('/pembelian-bahan/9/ubah'), nama === 'staf_gudang' ? 'boleh' : 'ditolak', `${nama} ubah`);
  }
  assert.equal(salin('investor').modules.includes('pembelian-bahan'), false);
});

test('investor dan peran tanpa hak: membuka detail tidak memanggil API pembelian maupun GET /lampiran (nota tak pernah diminta)', async () => {
  for (const nama of ['investor', 'ahli_gizi', 'relawan_dapur']) {
    sebagai(nama);
    await buka('/pembelian-bahan/9');
    assert.equal(panggilan.filter((p) => p.path.startsWith('/pembelian-bahan') || p.path.startsWith('/lampiran')).length, 0, `${nama}: ${JSON.stringify(panggilan.map((p) => p.path))}`);
  }
});

test('nota privat: pengguna baca-saja atau record bertanda disembunyikan tidak memuat/menampilkan nota (defense in depth)', async () => {
  const u = salin('admin');
  u.baca_saja = true;
  setUserSesi(u);
  let el = await buka('/pembelian-bahan/9');
  assert.match(teks(el), /Foto tidak ditampilkan untuk peran Anda/);
  assert.equal(panggilan.filter((p) => p.path.startsWith('/lampiran')).length, 0);
  sebagai('kepala_sppg');
  const asli = rec;
  rec = { ...rec, lampiran_disembunyikan: true, izin: { ...IZIN_AKUNTAN, verifikasi: false } };
  el = await buka('/pembelian-bahan/9');
  assert.match(teks(el), /Foto tidak ditampilkan untuk peran Anda/);
  assert.equal(panggilan.filter((p) => p.path.startsWith('/lampiran')).length, 0);
  rec = asli;
});

test('detail (akuntan): nota dimuat lewat cookie sesi tanpa token, harga referensi, tombol Setujui/Tolak dari izin.verifikasi; tanpa Ubah/Hapus', async () => {
  sebagai('akuntan');
  rec = { ...rec, status: 'diajukan', izin: IZIN_AKUNTAN };
  const el = await buka('/pembelian-bahan/9');
  const g = panggilan.find((p) => p.path === '/lampiran/77');
  assert.ok(g, 'nota dimuat');
  assert.ok(!('Authorization' in g.headers));
  assert.match(teks(el), /lebih mahal dari harga referensi/);
  assert.deepEqual(tombol(el).map(teks).filter((t) => /^(Setujui|Tolak)$/.test(t)), ['Setujui', 'Tolak']);
  assert.ok(!tombol(el).some((b) => /^Hapus$/.test(teks(b))));
  assert.ok(!tautan(el).some((x) => /\/ubah$/.test(x)));
});

test('verifikasi: Tolak wajib alasan >= 5; POST /verifikasi berisi keputusan+versi(+alasan); kunci sama saat diulang, baru bila isian berubah', async () => {
  sebagai('akuntan');
  const el = await buka('/pembelian-bahan/9');
  const tolak = tombol(el).find((b) => teks(b) === 'Tolak');
  await tolak.listeners.click();
  assert.equal(tulis().length, 0, 'tanpa alasan ditahan klien');
  assert.match(teks(el), /Alasan penolakan wajib diisi/);
  cari(el, 'alasan-verifikasi').value = 'Nota buram';
  skrip = [resp(503, { message: 'x' }), resp(200, { data: { ...rec, status: 'ditolak' } })];
  await klikKonfirmasi(tolak);
  tolak.disabled = false;
  await klikKonfirmasi(tolak);
  const t = tulis().filter((p) => p.path === '/pembelian-bahan/9/verifikasi');
  assert.equal(t.length, 2);
  assert.deepEqual(t[0].body, { keputusan: 'ditolak', versi: 4, alasan: 'Nota buram' });
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.equal(t[0].headers['Idempotency-Key'], t[1].headers['Idempotency-Key']);
  assert.ok(!('Authorization' in t[0].headers));
  skrip = [resp(200, { data: { ...rec, status: 'terverifikasi' } })];
  cari(el, 'alasan-verifikasi').value = '';
  await klikKonfirmasi(tombol(el).find((b) => teks(b) === 'Setujui'));
  const t3 = tulis().filter((p) => p.path === '/pembelian-bahan/9/verifikasi')[2];
  assert.deepEqual(t3.body, { keputusan: 'disetujui', versi: 4 });
  assert.notEqual(t3.headers['Idempotency-Key'], t[0].headers['Idempotency-Key']);
});

test('verifikasi 409 (status berubah): pesan Indonesia dan layar dimuat ulang', async () => {
  sebagai('akuntan');
  const el = await buka('/pembelian-bahan/9');
  skrip = [resp(409, { message: 'conflict', alasan: 'status_tidak_valid' })];
  await klikKonfirmasi(tombol(el).find((b) => teks(b) === 'Setujui'));
  assert.match(teks(tetap.toasts), /Status data sudah berubah/);
  assert.ok(!/conflict/.test(teks(tetap.toasts)));
  assert.ok(panggilan.some((p) => p.metode === 'GET' && p.path === '/pembelian-bahan/9'), 'dimuat ulang');
});

test('tanpa izin.verifikasi (pencatat sendiri, admin, status final): tanpa tombol; akuntan diberi tahu; terverifikasi menaut ke pengeluaran hanya bila hak keuangan', async () => {
  sebagai('akuntan');
  rec = { ...rec, status: 'diajukan', izin: { ...IZIN_AKUNTAN, verifikasi: false } };
  let el = await buka('/pembelian-bahan/9');
  assert.ok(!tombol(el).some((b) => /^(Setujui|Tolak)$/.test(teks(b))));
  assert.match(teks(el), /tidak dapat Anda verifikasi/);
  sebagai('admin');
  rec = { ...rec, izin: { ubah: false, hapus: false, lampirkan: false, verifikasi: true } }; // admin tak pernah memegang keputusan: peta hak menang di sisi server, klien tetap mengikuti izin
  el = await buka('/pembelian-bahan/9');
  assert.ok(el.anak.length > 0);
  rec = { ...rec, status: 'terverifikasi', pengeluaran_id: 31, izin: { ubah: false, hapus: false, lampirkan: false, verifikasi: false } };
  sebagai('akuntan');
  el = await buka('/pembelian-bahan/9');
  assert.match(teks(el), /Pembelian terverifikasi/);
  assert.ok(tautan(el).includes('#/keuangan/pengeluaran/31'));
  sebagai('staf_gudang');
  el = await buka('/pembelian-bahan/9');
  assert.ok(!tautan(el).includes('#/keuangan/pengeluaran/31'), 'staf gudang tanpa hak keuangan: tanpa tautan');
  assert.ok(!tautan(el).some((x) => /\/ubah$/.test(x)));
  rec = { ...rec, status: 'diajukan', pengeluaran_id: null, izin: IZIN_AKUNTAN };
});

test('staf gudang: Tambah muncul di daftar; Simpan tanpa foto nota ditahan; foto diunggah dulu ke POST /lampiran (tertunda) lalu lampiran_ids dikirim', async () => {
  sebagai('staf_gudang');
  assert.ok(tautan(await buka('/pembelian-bahan')).includes('#/pembelian-bahan/baru'));
  const el = await buka('/pembelian-bahan/baru');
  assert.match(teks(el), /Foto nota/);
  isi(el, { bahan_baku_id: '1', pemasok_id: '2', jumlah: '50', harga_satuan: '12500' });
  semua(el, (n) => { if (n.tag === 'form') n.listeners.input?.(); });
  assert.match(teks(el), /Perkiraan total: Rp\s?625\.000/);
  await kirim();
  assert.equal(tulis().length, 0, 'tanpa nota tidak terkirim');
  assert.match(teks(el), /Foto nota wajib/);
  await pilihFoto(el);
  const up = tulis().filter((p) => p.path === '/lampiran');
  assert.equal(up.length, 1, `unggah nota: ${JSON.stringify(panggilan.map((p) => p.path))}`);
  assert.match(up[0].headers['Idempotency-Key'], UUID);
  assert.ok(!('Authorization' in up[0].headers));
  assert.equal(up[0].body.get('modul'), 'pembelian-bahan');
  assert.equal(up[0].body.get('kategori'), 'nota');
  assert.ok(!up[0].body.has('lampiranable_id'), 'unggahan tertunda tanpa induk');
  skrip = [resp(503, { message: 'x' }), resp(201, { data: { id: 9 } })];
  await kirim();
  await kirim();
  const t = tulis().filter((p) => p.path === '/pembelian-bahan');
  assert.equal(t.length, 2);
  assert.equal(t[0].metode, 'POST');
  assert.deepEqual(t[1].body, { bahan_baku_id: 1, pemasok_id: 2, tanggal_beli: t[1].body.tanggal_beli, jumlah: 50, harga_satuan: 12500, catatan: null, lampiran_ids: [501] });
  assert.ok(!('total' in t[1].body) && !('status' in t[1].body) && !('ai_draft_id' in t[1].body));
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.equal(t[0].headers['Idempotency-Key'], t[1].headers['Idempotency-Key'], 'kunci sama pada pengulangan');
  assert.equal(location.hash, '#/pembelian-bahan/9');
});

test('staf gudang ubah (ditolak akuntan): peringatan alasan, nota tersimpan dimuat lewat cookie, PUT membawa versi record; tanpa unggah baru cukup', async () => {
  sebagai('staf_gudang');
  rec = { ...rec, status: 'ditolak', alasan_penolakan: 'Nota buram', izin: IZIN_GUDANG };
  let el = await buka('/pembelian-bahan/9');
  assert.match(teks(el), /Ditolak akuntan/);
  assert.match(teks(el), /Ubah data lalu simpan/);
  assert.ok(!tombol(el).some((b) => /^(Setujui|Tolak)$/.test(teks(b))), 'pencatat tanpa verifikasi');
  el = await buka('/pembelian-bahan/9/ubah');
  assert.match(teks(el), /Ditolak akuntan: Nota buram/);
  assert.ok(panggilan.some((p) => p.path === '/lampiran/77'), 'nota tersimpan dimuat (cookie)');
  assert.equal(cari(el, 'f-bahan_baku_id').value, '1', 'bahan terpilih dari record (objek bersarang)');
  assert.equal(cari(el, 'f-pemasok_id').value, '2', 'pemasok terpilih dari record (objek bersarang)');
  skrip = [resp(200, { data: { id: 9 } })];
  isi(el, { jumlah: '60' });
  await kirim();
  const t = tulis();
  assert.equal(t.length, 1);
  assert.equal(t[0].metode, 'PUT');
  assert.equal(t[0].path, '/pembelian-bahan/9');
  assert.equal(t[0].body.versi, 4);
  assert.equal(t[0].body.jumlah, 60);
  assert.ok(!('lampiran_ids' in t[0].body), 'nota lama dipertahankan');
  rec = { ...rec, status: 'diajukan', alasan_penolakan: null, izin: IZIN_AKUNTAN };
});

test('pembelian terverifikasi: Ubah ditolak tanpa izin.ubah walau akses langsung', async () => {
  sebagai('staf_gudang');
  rec = { ...rec, status: 'terverifikasi', izin: { ubah: false, hapus: false, lampirkan: false, verifikasi: false } };
  const el = await buka('/pembelian-bahan/9/ubah');
  assert.equal(cari(el, 'f-jumlah'), null, 'formulir ubah tidak tampil');
  rec = { ...rec, status: 'diajukan', izin: IZIN_AKUNTAN };
});

test('sumber modul tanpa innerHTML/insertAdjacentHTML/outerHTML', () => {
  for (const f of ['modules/pembelian-bahan/index.js', 'modules/pembelian-bahan/logika.js', 'core/kunci-idempoten.js']) {
    const src = readFileSync(fileURLToPath(new URL(`../${f}`, import.meta.url)), 'utf8');
    assert.ok(!/innerHTML|insertAdjacentHTML|outerHTML/.test(src), f);
  }
});
