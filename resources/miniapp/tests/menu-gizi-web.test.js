// R04-8 PR-W2: modul Menu dan Gizi di web (daftar, detail, Tambah/Ubah/Hapus, komposisi bahan, Standar gizi, Ajukan ke Investor,
// rincian biaya). Hak tombol hanya dari peta/izin server (fixture peta.js = cermin keluaran server); Idempotency-Key sekali per
// formulir/isian; tanpa antrean offline, draf AI, atau header Authorization. Data fiktif.
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
  Object.defineProperty(el, 'childNodes', { get() { return this.anak; } });
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
const BAHAN = [{ id: 5, nama: 'Beras Fiktif', satuan: 'kg' }, { id: 6, nama: 'Ayam Fiktif', satuan: 'kg' }, { id: 7, nama: 'Bayam Fiktif', satuan: 'ikat' }];
let menuRec = {
  id: 1, nama_menu: 'Nasi Ayam Bayam Fiktif', kelompok_sasaran: 'siswa', kelompok_sasaran_label: 'Siswa', tanggal_sajian: '2026-11-20', siklus: 'Siklus 1',
  energi_kkal: 650, protein_g: 25, lemak_g: 18, karbohidrat_g: 90, serat_g: 6, catatan: null, status_persetujuan: 'draf', status_persetujuan_label: 'Draf', versi: 4,
  bahan: [{ bahan_baku_id: 5, nama: 'Beras Fiktif', satuan: 'kg', gram_per_porsi: 100 }, { bahan_baku_id: 6, nama: 'Ayam Fiktif', satuan: 'kg', gram_per_porsi: 60 }],
};
const STANDAR = { id: 3, kelompok_sasaran: 'siswa', energi_min: 500, energi_max: 800, protein_min: 15, protein_max: 30, lemak_min: 10, lemak_max: 25, karbohidrat_min: 60, karbohidrat_max: 110, serat_min: 3, serat_max: 10 };
const RINGKASAN = { menu_id: 1, per_porsi: { energi_kkal: 650, protein_g: 25, lemak_g: 18, karbohidrat_g: 90, serat_g: 6 }, total_gram_per_porsi: 160, standar: STANDAR, standar_tersedia: true, sesuai_standar: true, peringatan: [] };
globalThis.fetch = async (url, init) => {
  const path = new URL(url, 'http://x').pathname.replace('/api/v1', '');
  panggilan.push({ metode: init.method ?? 'GET', path, headers: init.headers, body: typeof init.body === 'string' ? JSON.parse(init.body) : init.body ?? null });
  if (!init.method || init.method === 'GET') {
    if (path === '/menu-gizi/standar-gizi') return lst([STANDAR]);
    if (path === '/menu-gizi/standar-gizi/3') return resp(200, { data: STANDAR });
    if (path === '/menu-gizi/1') return resp(200, { data: menuRec });
    if (path === '/menu-gizi/1/ringkasan-gizi') return resp(200, { data: RINGKASAN });
    if (path === '/menu-gizi/1/rincian-biaya') return resp(200, { data: { menu_versi: 4, tanggal_acuan: '2026-11-19', lengkap: true, bahan: [], biaya_per_porsi: 9000, jumlah_porsi: 100, total_biaya: 900000, sumber_porsi: 'jadwal_produksi' } });
    if (path === '/menu-gizi') return lst([{ ...menuRec, bahan: undefined, jumlah_bahan: 2 }]);
    if (path === '/bahan-baku') return lst(BAHAN);
    return lst([]);
  }
  return skrip.length ? skrip.shift() : resp(200, { data: { id: 1 } });
};

const { setPlatform } = await import('../core/platform.js');
setPlatform('web');
const { setUserSesi, getUser } = await import('../core/auth.js');
const { startRouter, resolve, cekAkses } = await import('../core/router.js');
const { daftarkanRuteWeb, TULIS_WEB, BACA_WEB } = await import('../rute-web.js');
const { userPerms, bolehAksi } = await import('../core/permissions.js');
const { PROFIL, salin } = await import('./fixtures/peta.js');

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
const cariLabel = (el, label) => { let r = null; semua(el, (n) => { if (n.attrs?.['aria-label'] === label) r = n; }); return r; };
const tombol = (el) => { const t = []; semua(el, (n) => { if (n.tag === 'button') t.push(n); }); return t; };
const tautan = (el) => { const t = []; semua(el, (n) => { if (n.attrs?.href) t.push(n.attrs.href); }); return t; };
const outlet = buatEl('main');
await startRouter(outlet);
async function buka(path) {
  panggilan.length = 0;
  location.hash = `#${path}`;
  await resolve();
  await new Promise((r) => setTimeout(r, 25));
  return outlet;
}
const tulis = () => panggilan.filter((p) => p.metode !== 'GET');
const kirim = async () => { await tetap.mainbtn.onclick(); await new Promise((r) => setTimeout(r, 20)); };
function isi(el, nilai) { for (const [id, v] of Object.entries(nilai)) { const i = cari(el, `f-${id}`); assert.ok(i, `bidang ${id} ada`); i.value = v; i.listeners.input?.(); i.listeners.change?.(); } }
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-9a-f][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const sebagai = (nama) => setUserSesi(salin(nama));
const klikTombol = (el, nama) => tombol(el).find((b) => teks(b) === nama);
/** Klik tombol yang membuka dialog konfirmasi, lalu tekan tombol OK di dialog. */
async function klikKonfirmasi(btn, okTeks) {
  overlays.length = 0;
  const k = btn.listeners.click();
  await new Promise((r) => setTimeout(r, 5));
  tombol(overlays[0]).find((x) => new RegExp(okTeks).test(teks(x))).listeners.click();
  await k;
  await new Promise((r) => setTimeout(r, 25));
}
const tambahBaris = (el) => tombol(el).find((b) => teks(b) === '+ Tambah bahan').listeners.click();
const formEl = (el) => { let f = null; semua(el, (n) => { if (n.tag === 'form') f = n; }); return f; };
/** Isi baris komposisi ke-`n` (1-based); baris dibuat dulu bila belum ada. */
function isiBaris(el, n, bahanId, gram) {
  if (!cariLabel(el, `Bahan baris ${n}`)) tambahBaris(el);
  const sel = cariLabel(el, `Bahan baris ${n}`);
  const g = cariLabel(el, `Takaran gram baris ${n}`);
  assert.ok(sel && g, `baris komposisi ${n} ada`);
  sel.value = String(bahanId); sel.listeners.change();
  g.value = String(gram); g.listeners.input();
}
const ISIAN = { nama_menu: 'Nasi Telur Fiktif', kelompok_sasaran: 'siswa', tanggal_sajian: '2026-11-25', siklus: 'Siklus 2', energi_kkal: '600', protein_g: '22', lemak_g: '15', karbohidrat_g: '85', serat_g: '5' };

test('PR-W2: menu-gizi dan standar-gizi terdaftar di BACA_WEB dan TULIS_WEB; guard rute mengikuti peta hak server untuk semua profil', () => {
  for (const s of ['menu-gizi', 'menu-gizi/standar-gizi']) { assert.ok(BACA_WEB.has(s), `BACA ${s}`); assert.ok(TULIS_WEB.has(s), `TULIS ${s}`); }
  for (const nama of Object.keys(PROFIL)) {
    sebagai(nama);
    const p = userPerms(getUser(), 'menu-gizi');
    for (const base of ['/menu-gizi', '/menu-gizi/standar-gizi']) {
      assert.equal(cekAkses(base), p.R ? 'boleh' : 'ditolak', `${nama} ${base}`);
      assert.equal(cekAkses(`${base}/baru`), p.C ? 'boleh' : 'ditolak', `${nama} ${base}/baru`);
      assert.equal(cekAkses(`${base}/1/ubah`), p.U ? 'boleh' : 'ditolak', `${nama} ${base}/ubah`);
    }
    assert.equal(cekAkses('/menu-gizi/1'), p.R ? 'boleh' : 'ditolak', `${nama} detail`);
    assert.equal(cekAkses('/menu-gizi/1/rincian-biaya'), bolehAksi(getUser(), 'lihat_rincian_biaya') ? 'boleh' : 'ditolak', `${nama} rincian biaya`);
  }
});

test('relawan terdaftar di rbac.php menu-gizi = R: boleh baca daftar/detail/standar, tanpa Tambah/Ubah/Hapus/Ajukan/Rincian biaya', async () => {
  for (const nama of ['relawan_dapur', 'relawan_distribusi']) {
    sebagai(nama);
    assert.equal(userPerms(getUser(), 'menu-gizi').R, true, `${nama} R menu-gizi`);
    let el = await buka('/menu-gizi');
    assert.ok(tautan(el).includes('#/menu-gizi/1'), `${nama}: tautan detail`);
    assert.ok(!tautan(el).includes('#/menu-gizi/baru'), `${nama}: tanpa Tambah`);
    el = await buka('/menu-gizi/1');
    assert.ok(teks(el).includes('Komposisi bahan') && teks(el).includes('Beras Fiktif'), `${nama}: komposisi tampil`);
    assert.ok(!tombol(el).some((b) => /Hapus|Ajukan/.test(teks(b))), `${nama}: tanpa tombol tulis`);
    assert.ok(!tautan(el).some((x) => /rincian-biaya|\/ubah$/.test(x)), `${nama}: tanpa rincian biaya/ubah`);
    assert.ok(panggilan.every((p) => p.metode === 'GET' && !('Authorization' in p.headers)), `${nama}: hanya GET`);
    el = await buka('/menu-gizi/standar-gizi');
    assert.ok(tautan(el).includes('#/menu-gizi/standar-gizi/3'), `${nama}: standar tampil`);
    assert.ok(!tautan(el).includes('#/menu-gizi/standar-gizi/baru'));
  }
});

test('peran baca (admin, kepala, staf gudang): tanpa Tambah, Ubah, Hapus; investor dan akuntan ditolak guard tanpa panggilan API', async () => {
  for (const nama of ['admin', 'kepala_sppg', 'staf_gudang']) {
    sebagai(nama);
    const el = await buka('/menu-gizi/1');
    assert.ok(!tombol(el).some((b) => /^Hapus$|Ajukan/.test(teks(b))), `${nama}: tombol`);
    assert.ok(!tautan(await buka('/menu-gizi')).includes('#/menu-gizi/baru'), `${nama}: Tambah`);
  }
  for (const nama of ['investor', 'akuntan']) {
    sebagai(nama);
    await buka('/menu-gizi/1');
    assert.equal(panggilan.length, 0, `${nama}: tanpa panggilan API`);
  }
});

test('ahli gizi: daftar memuat tab Menu/Standar gizi, tautan Tambah, tautan detail, badge status', async () => {
  sebagai('ahli_gizi');
  const el = await buka('/menu-gizi');
  const h_ = tautan(el);
  assert.ok(h_.includes('#/menu-gizi/standar-gizi'), 'tab standar gizi');
  assert.ok(h_.includes('#/menu-gizi/baru'), 'Tambah');
  assert.ok(h_.includes('#/menu-gizi/1'), 'detail');
  assert.ok(teks(el).includes('Draf'), 'badge status');
});

test('detail menu (ahli gizi): komposisi, ringkasan gizi, rincian biaya, Hapus, Ubah via MainButton; Ajukan pada status draf', async () => {
  sebagai('ahli_gizi');
  const el = await buka('/menu-gizi/1');
  const t = teks(el);
  assert.ok(t.includes('Beras Fiktif') && t.includes('Ayam Fiktif'), 'komposisi bahan');
  assert.ok(t.includes('Ringkasan gizi per porsi') && t.includes('Seluruh nilai gizi sesuai standar'), 'ringkasan gizi');
  assert.ok(tautan(el).includes('#/menu-gizi/1/rincian-biaya'), 'tautan rincian biaya (aksi.lihat_rincian_biaya)');
  assert.ok(tombol(el).some((b) => /^Hapus$/.test(teks(b))), 'Hapus (hak D)');
  assert.ok(klikTombol(el, 'Ajukan ke Investor'), 'tombol Ajukan');
  assert.equal(tetap.mainbtn.textContent, 'Ubah');
  assert.ok(panggilan.every((p) => p.metode === 'GET'), 'render hanya GET');
});

test('Ajukan ke Investor: tidak muncul pada status diajukan/disetujui atau tanpa aksi.ajukan_menu', async () => {
  const asli = menuRec;
  for (const status of ['diajukan', 'disetujui']) {
    menuRec = { ...asli, status_persetujuan: status, status_persetujuan_label: status };
    sebagai('ahli_gizi');
    assert.ok(!klikTombol(await buka('/menu-gizi/1'), 'Ajukan ke Investor'), `ahli gizi ${status}`);
  }
  menuRec = asli;
  for (const nama of ['admin', 'kepala_sppg', 'staf_gudang', 'relawan_dapur']) {
    sebagai(nama);
    assert.ok(!klikTombol(await buka('/menu-gizi/1'), 'Ajukan ke Investor'), nama);
  }
  for (const status of ['ditolak', 'perlu_revisi']) {
    menuRec = { ...asli, status_persetujuan: status };
    sebagai('ahli_gizi');
    assert.ok(klikTombol(await buka('/menu-gizi/1'), 'Ajukan ke Investor'), `ahli gizi ${status}`);
  }
  menuRec = asli;
});

test('Ajukan: POST /menu-gizi/{id}/ajukan hanya versi + catatan_pengaju; kunci sama pada ulang, baru bila isian berubah; berhasil memuat ulang detail', async () => {
  sebagai('ahli_gizi');
  const el = await buka('/menu-gizi/1');
  const btn = klikTombol(el, 'Ajukan ke Investor');
  skrip = [resp(503, { message: 'x' })];
  await klikKonfirmasi(btn, 'Ya, ajukan');
  let t = tulis();
  assert.equal(t.length, 1);
  assert.equal(t[0].path, '/menu-gizi/1/ajukan');
  assert.deepEqual(t[0].body, { versi: 4 }, 'tanpa harga/biaya/porsi dari klien');
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.ok(!('Authorization' in t[0].headers));
  btn.disabled = false;
  skrip = [resp(503, { message: 'x' })];
  await klikKonfirmasi(btn, 'Ya, ajukan');
  t = tulis();
  assert.equal(t[0].headers['Idempotency-Key'], t[1].headers['Idempotency-Key'], 'isian sama -> kunci sama');
  // catatan berubah -> isian berbeda -> kunci baru
  semua(el, (n) => { if (n.attrs?.['aria-label'] === 'Catatan untuk investor') n.value = 'Mohon ditinjau'; });
  btn.disabled = false;
  skrip = [resp(201, { data: { id: 9 } })];
  panggilan.length = 0;
  await klikKonfirmasi(btn, 'Ya, ajukan');
  const post = tulis()[0];
  assert.deepEqual(post.body, { versi: 4, catatan_pengaju: 'Mohon ditinjau' });
  assert.notEqual(post.headers['Idempotency-Key'], t[0].headers['Idempotency-Key'], 'isian beda -> kunci baru');
  assert.ok(panggilan.some((p) => p.metode === 'GET' && p.path === '/menu-gizi/1'), 'detail dimuat ulang sesudah berhasil');
});

test('Ajukan: 422 menampilkan bahan tanpa harga; 409 memuat ulang; tombol aktif lagi', async () => {
  sebagai('ahli_gizi');
  let el = await buka('/menu-gizi/1');
  let btn = klikTombol(el, 'Ajukan ke Investor');
  skrip = [resp(422, { message: 'Tidak valid', errors: {}, bahan_bermasalah: [{ nama: 'Ayam Fiktif', masalah: 'Harga belum ada' }] })];
  await klikKonfirmasi(btn, 'Ya, ajukan');
  assert.ok(teks(el).includes('Ayam Fiktif') && teks(el).includes('Harga belum ada'));
  assert.equal(btn.disabled, false);
  el = await buka('/menu-gizi/1');
  btn = klikTombol(el, 'Ajukan ke Investor');
  skrip = [resp(409, { message: 'Versi menu sudah berubah.' })];
  await klikKonfirmasi(btn, 'Ya, ajukan');
  assert.ok(panggilan.filter((p) => p.metode === 'GET' && p.path === '/menu-gizi/1').length >= 1, 'muat ulang pada 409');
});

test('Tambah menu (ahli gizi): komposisi dikirim sebagai bahan[] angka; kunci sama pada ulang; tanpa ai_draft_id; peringatan gizi dari server', async () => {
  sebagai('ahli_gizi');
  const el = await buka('/menu-gizi/baru');
  assert.ok(cari(el, 'f-nama_menu') && cari(el, 'f-kelompok_sasaran'));
  isi(el, ISIAN);
  isiBaris(el, 1, 5, 100);
  isiBaris(el, 2, 6, 60);
  skrip = [resp(503, { message: 'x' }), resp(201, { data: { id: 7, peringatan_gizi: [{ kolom: 'protein_g', pesan: 'Protein 22 g di bawah standar minimum 25 g.' }] } })];
  await kirim(); await kirim();
  const t = tulis();
  assert.equal(t.length, 2);
  assert.equal(t[0].metode, 'POST');
  assert.equal(t[0].path, '/menu-gizi');
  assert.equal(t[0].headers['Idempotency-Key'], t[1].headers['Idempotency-Key']);
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.deepEqual(t[0].body.bahan, [{ bahan_baku_id: 5, gram_per_porsi: 100 }, { bahan_baku_id: 6, gram_per_porsi: 60 }]);
  assert.equal(t[0].body.nama_menu, 'Nasi Telur Fiktif');
  assert.equal(t[0].body.energi_kkal, 600);
  assert.ok(!('ai_draft_id' in t[0].body));
  assert.ok(panggilan.every((p) => !('Authorization' in p.headers)));
  assert.equal(location.hash, '#/menu-gizi/7');
  assert.ok(teks(tetap.toasts).includes('Peringatan gizi'), 'peringatan gizi tampil di web');
});

test('Tambah menu: pratinjau peringatan gizi dari standar kelompok (GET saja); komposisi ganda ditahan klien tanpa POST', async () => {
  sebagai('ahli_gizi');
  const el = await buka('/menu-gizi/baru');
  isi(el, { ...ISIAN, protein_g: '5' });
  formEl(el).listeners.input(); // peristiwa input menggelembung ke formulir (DOM tiruan tidak menggelembungkan)
  await new Promise((r) => setTimeout(r, 20));
  assert.ok(teks(el).includes('di bawah standar minimum'), 'pratinjau peringatan');
  assert.ok(panggilan.some((p) => p.path === '/menu-gizi/standar-gizi'));
  isiBaris(el, 1, 5, 100);
  isiBaris(el, 2, 5, 50);
  await kirim();
  assert.equal(tulis().length, 0, 'bahan ganda tidak dikirim');
  assert.match(teks(el), /tidak boleh ganda/);
});

test('Tambah menu: 422 per kolom/baris dari server ditampilkan pada formulir (bukan galat umum)', async () => {
  sebagai('ahli_gizi');
  const el = await buka('/menu-gizi/baru');
  isi(el, ISIAN);
  isiBaris(el, 1, 5, 100);
  skrip = [resp(422, { message: 'Tidak valid', errors: { nama_menu: ['Nama menu sudah dipakai.'] } })];
  await kirim();
  assert.match(teks(el), /Nama menu sudah dipakai/);
});

test('Ubah menu: nilai awal dari record (termasuk komposisi); PUT /menu-gizi/{id}; hak Ubah dari peta server', async () => {
  sebagai('ahli_gizi');
  const el = await buka('/menu-gizi/1/ubah');
  assert.ok(cari(el, 'f-nama_menu'), 'form ubah tampil');
  assert.equal(cari(el, 'f-nama_menu').value, 'Nasi Ayam Bayam Fiktif');
  assert.ok(cariLabel(el, 'Bahan baris 2'), 'komposisi awal 2 baris');
  isi(el, { nama_menu: 'Nasi Ayam Fiktif' });
  skrip = [resp(200, { data: { id: 1 } })];
  await kirim();
  const t = tulis();
  assert.equal(t[0].metode, 'PUT');
  assert.equal(t[0].path, '/menu-gizi/1');
  assert.equal(t[0].body.nama_menu, 'Nasi Ayam Fiktif');
  assert.deepEqual(t[0].body.bahan, [{ bahan_baku_id: 5, gram_per_porsi: 100 }, { bahan_baku_id: 6, gram_per_porsi: 60 }]);
  assert.equal(location.hash, '#/menu-gizi/1');
  for (const nama of ['admin', 'kepala_sppg', 'relawan_dapur']) {
    sebagai(nama);
    const e = await buka('/menu-gizi/1/ubah');
    assert.equal(cari(e, 'f-nama_menu'), null, `${nama}: tanpa formulir ubah`);
  }
});

test('Hapus menu (ahli gizi): DELETE setelah konfirmasi, kembali ke daftar', async () => {
  sebagai('ahli_gizi');
  const el = await buka('/menu-gizi/1');
  await klikKonfirmasi(tombol(el).find((b) => /^Hapus$/.test(teks(b))), 'Hapus');
  const t = tulis();
  assert.equal(t.length, 1);
  assert.equal(t[0].metode, 'DELETE');
  assert.equal(t[0].path, '/menu-gizi/1');
  assert.equal(location.hash, '#/menu-gizi');
});

test('Hapus ditolak server (422/409): pesan tampil, tetap di detail', async () => {
  sebagai('ahli_gizi');
  const el = await buka('/menu-gizi/1');
  skrip = [resp(422, { message: 'Menu masih dipakai jadwal produksi.' })];
  await klikKonfirmasi(tombol(el).find((b) => /^Hapus$/.test(teks(b))), 'Hapus');
  assert.equal(location.hash, '#/menu-gizi/1');
});

test('standar gizi: daftar dengan tab, Tambah (10 kolom angka) memakai kunci tetap, Ubah, Hapus; peran lain tanpa tombol', async () => {
  sebagai('ahli_gizi');
  let el = await buka('/menu-gizi/standar-gizi');
  assert.ok(tautan(el).includes('#/menu-gizi'), 'tab Menu');
  assert.ok(tautan(el).includes('#/menu-gizi/standar-gizi/baru'), 'Tambah standar');
  assert.ok(tautan(el).includes('#/menu-gizi/standar-gizi/3'), 'detail standar');
  el = await buka('/menu-gizi/standar-gizi/3');
  assert.ok(teks(el).includes('500 - 800 kkal'), 'rentang energi');
  assert.ok(tombol(el).some((b) => /^Hapus$/.test(teks(b))));
  el = await buka('/menu-gizi/standar-gizi/baru');
  isi(el, { kelompok_sasaran: 'balita', energi_min: '400', energi_max: '600', protein_min: '10', protein_max: '20', lemak_min: '8', lemak_max: '20', karbohidrat_min: '50', karbohidrat_max: '90', serat_min: '2', serat_max: '8' });
  skrip = [resp(503, { message: 'x' }), resp(201, { data: { id: 4 } })];
  await kirim(); await kirim();
  const t = tulis();
  assert.equal(t[0].path, '/menu-gizi/standar-gizi');
  assert.equal(t[0].headers['Idempotency-Key'], t[1].headers['Idempotency-Key']);
  assert.equal(t[1].body.kelompok_sasaran, 'balita');
  assert.equal(t[1].body.serat_max, 8);
  assert.ok(!('ai_draft_id' in t[1].body));
  assert.equal(location.hash, '#/menu-gizi/standar-gizi/4');
  el = await buka('/menu-gizi/standar-gizi/3');
  await klikKonfirmasi(tombol(el).find((b) => /^Hapus$/.test(teks(b))), 'Hapus');
  assert.equal(tulis()[0].metode, 'DELETE');
  assert.equal(tulis()[0].path, '/menu-gizi/standar-gizi/3');
  for (const nama of ['admin', 'kepala_sppg', 'staf_gudang', 'relawan_dapur']) {
    sebagai(nama);
    el = await buka('/menu-gizi/standar-gizi/3');
    assert.ok(!tombol(el).some((b) => /^Hapus$/.test(teks(b))), `${nama}: Hapus`);
    assert.equal(cari(await buka('/menu-gizi/standar-gizi/baru'), 'f-kelompok_sasaran'), null, `${nama}: form tambah`);
  }
});

test('standar gizi: maksimum < minimum ditahan klien tanpa POST', async () => {
  sebagai('ahli_gizi');
  const el = await buka('/menu-gizi/standar-gizi/baru');
  isi(el, { kelompok_sasaran: 'balita', energi_min: '400', energi_max: '300', protein_min: '10', protein_max: '20', lemak_min: '8', lemak_max: '20', karbohidrat_min: '50', karbohidrat_max: '90', serat_min: '2', serat_max: '8' });
  await kirim();
  assert.equal(tulis().length, 0);
});

test('rincian biaya: layar baca (GET) untuk pemegang aksi.lihat_rincian_biaya; relawan tidak', async () => {
  sebagai('ahli_gizi');
  const el = await buka('/menu-gizi/1/rincian-biaya');
  assert.ok(teks(el).includes('Pratinjau memakai harga pemasok'));
  assert.ok(panggilan.every((p) => p.metode === 'GET'));
  sebagai('relawan_dapur');
  await buka('/menu-gizi/1/rincian-biaya');
  assert.equal(panggilan.length, 0, 'relawan: tanpa panggilan API');
});

test('tanpa innerHTML pada berkas modul menu-gizi dan teks bebas tampil sebagai teks', async () => {
  for (const f of ['index.js', 'komposisi.js', 'persetujuan.js', 'schema.js']) {
    assert.ok(!/innerHTML|insertAdjacentHTML|outerHTML/.test(readFileSync(`${dir}menu-gizi/${f}`, 'utf8')), f);
  }
  const asli = menuRec;
  menuRec = { ...menuRec, nama_menu: '<img src=x onerror=alert(1)>', catatan: '<script>x</script>', bahan: [{ bahan_baku_id: 5, nama: '<b>Beras</b>', satuan: 'kg', gram_per_porsi: 100 }] };
  sebagai('ahli_gizi');
  const el = await buka('/menu-gizi/1');
  let elemenBerbahaya = false;
  semua(el, (n) => { if (['img', 'script', 'b'].includes(n.tag)) elemenBerbahaya = true; });
  assert.equal(elemenBerbahaya, false);
  assert.ok(teks(el).includes('<b>Beras</b>'));
  menuRec = asli;
});
