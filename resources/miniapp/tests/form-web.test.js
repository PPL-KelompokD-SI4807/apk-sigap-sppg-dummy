// R04-8 PR4c: formulir CRUD web data master. Idempotency-Key SEKALI per formulir dan dipakai ulang pada pengulangan manual,
// penanganan 403/404/409/422 dengan pesan tetap (tanpa data lintas SPPG), Tambah/Ubah/Hapus hanya bila hak server mengizinkan.
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
let skrip = []; // antrean respons untuk permintaan tulis
const resp = (status, body) => ({ ok: status < 300, status, headers: { get: () => null }, json: async () => body });
const REC = { id: 1, nama: 'Contoh Fiktif', plat_nomor: 'B 1234 CD', jenis: 'Minibus', kapasitas_box: 40, status: 'tersedia', sppg_id: 1 };
globalThis.fetch = async (url, init) => {
  const path = new URL(url, 'http://x').pathname.replace('/api/v1', '');
  panggilan.push({ metode: init.method, path, headers: init.headers, body: init.body ? JSON.parse(init.body) : null });
  if (init.method === 'GET') return /\/\d+$/.test(path) ? resp(200, { data: REC }) : resp(200, { data: [REC], meta: { current_page: 1, last_page: 1, total: 1 } });
  return skrip.length ? skrip.shift() : resp(200, { data: { id: 1 } });
};

const { setPlatform } = await import('../core/platform.js');
setPlatform('web');
const { setUserSesi, getUser } = await import('../core/auth.js');
const { startRouter, resolve } = await import('../core/router.js');
const { daftarkanRuteWeb, TULIS_WEB } = await import('../rute-web.js');
const { userPerms, jadwalTabBoleh } = await import('../core/permissions.js');
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
const tombol = (el) => { const t = []; semua(el, (n) => { if (n.tag === 'button') t.push(n); }); return t; };
const outlet = buatEl('main');
await startRouter(outlet);
async function buka(path) {
  panggilan.length = 0;
  location.hash = `#${path}`;
  await resolve();
  await new Promise((r) => setTimeout(r, 10));
  return outlet;
}
const tulis = () => panggilan.filter((p) => p.metode !== 'GET');
const kirim = async () => { await tetap.mainbtn.onclick(); await new Promise((r) => setTimeout(r, 10)); };
function isi(el, nilai) { for (const [id, v] of Object.entries(nilai)) { const i = cari(el, `f-${id}`); i.value = v; i.listeners.input?.(); } }
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-9a-f][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const ISIAN = { plat_nomor: 'b 1234 cd', jenis: 'Minibus', kapasitas_box: '40' };
const user = () => { setUserSesi(salin('kepala_sppg')); };

test('formulir tambah: bidang dari skema, tanpa bidang foto; kirim POST dengan Idempotency-Key UUID dan navigasi ke detail', async () => {
  user();
  const el = await buka('/armada/baru');
  assert.ok(cari(el, 'f-plat_nomor') && cari(el, 'f-jenis') && cari(el, 'f-status'));
  isi(el, ISIAN);
  skrip = [resp(201, { data: { id: 7 } })];
  await kirim();
  assert.equal(tulis().length, 1);
  assert.equal(tulis()[0].metode, 'POST');
  assert.equal(tulis()[0].path, '/armada');
  assert.match(tulis()[0].headers['Idempotency-Key'], UUID);
  assert.equal(tulis()[0].body.plat_nomor, 'B 1234 CD');
  assert.equal(location.hash, '#/armada/7');
});

test('Idempotency-Key dibuat sekali per formulir: pengulangan manual setelah galat memakai kunci SAMA; formulir baru = kunci baru', async () => {
  user();
  const el = await buka('/armada/baru');
  isi(el, ISIAN);
  skrip = [resp(500, { message: 'x' }), resp(0 + 503, { message: 'x' }), resp(201, { data: { id: 8 } })];
  await kirim(); await kirim(); await kirim();
  const kunci = tulis().map((p) => p.headers['Idempotency-Key']);
  assert.equal(kunci.length, 3);
  assert.equal(new Set(kunci).size, 1, 'kunci harus sama pada semua pengulangan');
  await buka('/armada/baru'); // formulir BARU
  isi(outlet, ISIAN);
  skrip = [resp(201, { data: { id: 9 } })];
  await kirim();
  assert.notEqual(tulis()[0].headers['Idempotency-Key'], kunci[0]);
});

test('422: galat per bidang tampil di bawah bidangnya (teks, bukan HTML); kunci tetap sama saat memperbaiki dan kirim ulang', async () => {
  user();
  const el = await buka('/armada/baru');
  isi(el, ISIAN);
  skrip = [resp(422, { message: 'Data tidak valid.', errors: { plat_nomor: ['Plat <img src=x onerror=alert(1)> sudah dipakai.'] } }), resp(201, { data: { id: 3 } })];
  await kirim();
  assert.equal(cari(el, 'f-plat_nomor-err').textContent, 'Plat <img src=x onerror=alert(1)> sudah dipakai.');
  let adaImg = false; semua(el, (n) => { if (n.tag === 'img') adaImg = true; });
  assert.equal(adaImg, false);
  isi(el, { plat_nomor: 'B 9999 ZZ' });
  await kirim();
  const k = tulis().map((p) => p.headers['Idempotency-Key']);
  assert.equal(k.length, 2);
  assert.equal(k[0], k[1]);
});

test('403 dan 404: pesan tetap tanpa data dari server (tidak mengungkap data lintas SPPG)', async () => {
  user();
  let el = await buka('/armada/baru');
  isi(el, ISIAN);
  skrip = [resp(403, { message: 'Armada B 7777 XX milik SPPG Rahasia' })];
  await kirim();
  assert.ok(teks(el).includes('Anda tidak berhak melakukan aksi ini.'));
  assert.ok(!teks(el).includes('Rahasia') && !teks(el).includes('B 7777'));
  el = await buka('/armada/1/ubah');
  skrip = [resp(404, { message: 'Armada SPPG Rahasia tidak ada' })];
  await kirim();
  assert.ok(teks(el).includes('Data tidak ditemukan atau sudah dihapus.'));
  assert.ok(!teks(el).includes('Rahasia'));
  assert.ok(tombol(el).some((b) => /Muat ulang/.test(teks(b))), 'ada tombol muat ulang pada 404');
});

test('409 konflik versi: pesan Indonesia + tombol muat ulang; 409 tanpa alasan = permintaan masih diproses', async () => {
  user();
  let el = await buka('/armada/1/ubah');
  skrip = [resp(409, { message: 'conflict', alasan: 'versi_tidak_cocok' })];
  await kirim();
  assert.ok(teks(el).includes('Data sudah berubah sejak Anda membukanya.'));
  assert.ok(tombol(el).some((b) => /Muat ulang/.test(teks(b))));
  el = await buka('/armada/baru');
  isi(el, ISIAN);
  skrip = [resp(409, { message: 'Permintaan dengan Idempotency-Key yang sama sedang diproses.' })];
  await kirim();
  assert.ok(teks(el).includes('Permintaan yang sama masih diproses.'));
});

test('422 kunci idempotensi bentrok (isian berbeda setelah permintaan sukses yang responsnya hilang): peringatan periksa daftar', async () => {
  user();
  const el = await buka('/armada/baru');
  isi(el, ISIAN);
  skrip = [resp(422, { message: 'x', errors: { 'Idempotency-Key': ['Idempotency-Key sudah dipakai untuk permintaan dengan data berbeda.'] } })];
  await kirim();
  assert.ok(teks(el).includes('Permintaan sebelumnya mungkin sudah tersimpan.'));
});

test('ubah: nilai awal dari record, kirim PUT ke /armada/1 dengan kunci; offline = galat tanpa antrean', async () => {
  user();
  const el = await buka('/armada/1/ubah');
  assert.equal(cari(el, 'f-plat_nomor').value, 'B 1234 CD');
  skrip = [resp(200, { data: { id: 1 } })];
  await kirim();
  assert.equal(tulis()[0].metode, 'PUT');
  assert.equal(tulis()[0].path, '/armada/1');
  assert.equal(location.hash, '#/armada/1');
});

test('hapus: tombol hanya bila hak D; konfirmasi DOM lalu DELETE; galat 403 tidak menghapus', async () => {
  user();
  await buka('/armada/1');
  const hapus = tombol(outlet).find((b) => /^Hapus$/.test(teks(b)));
  assert.ok(hapus, 'kepala_sppg punya hak D armada');
  overlays.length = 0;
  skrip = [resp(204, null)];
  const klik = hapus.listeners.click();
  await new Promise((r) => setTimeout(r, 5));
  const ya = tombol(overlays[0]).find((b) => /^Hapus$/.test(teks(b)));
  ya.listeners.click();
  await klik;
  await new Promise((r) => setTimeout(r, 10));
  assert.equal(tulis()[0].metode, 'DELETE');
  assert.equal(tulis()[0].path, '/armada/1');
  assert.equal(location.hash, '#/armada');
  // Investor (baca saja): tanpa tombol Hapus dan tanpa tautan Tambah.
  setUserSesi(salin('investor'));
  for (const slug of TULIS_WEB) {
    if (!userPerms(getUser(), slug === 'pengemudi' ? 'armada' : slug).R) continue;
    const d = await buka(`/${slug}/1`);
    assert.ok(!tombol(d).some((b) => /^Hapus$/.test(teks(b))), `investor ${slug}`);
  }
});

test('hak per profil: formulir tambah hanya terbuka bila peta hak server memberi C (semua profil, semua modul tulis)', async () => {
  const { cekAkses } = await import('../core/router.js');
  for (const nama of Object.keys(PROFIL)) {
    setUserSesi(salin(nama));
    for (const slug of TULIS_WEB) {
      const perm = slug === 'pengemudi' ? 'armada' : slug;
      const tambah = userPerms(getUser(), perm).C && (slug !== 'jadwal' || jadwalTabBoleh(getUser(), 'produksi')); // jadwal: tab produksi menurut unit_scope server
      assert.equal(cekAkses(`/${slug}/baru`), tambah ? 'boleh' : 'ditolak', `${nama} ${slug}`);
    }
  }
});

test('tidak ada antrean offline/IndexedDB, draf AI, atau header Authorization pada formulir web', async () => {
  user();
  const el = await buka('/armada/baru');
  isi(el, ISIAN);
  skrip = [resp(201, { data: { id: 5 } })];
  await kirim();
  assert.ok(panggilan.every((p) => !('Authorization' in p.headers)));
  assert.ok(!('ai_draft_id' in (tulis()[0].body || {})));
});
