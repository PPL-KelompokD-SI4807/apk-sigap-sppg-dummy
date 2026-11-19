// PR-W3: modul Data Pengguna di web. Hak tombol hanya dari peta server (permissions/aksi); peran R* hanya Profil Saya
// (tanpa memanggil daftar); undangan = Salin tautan (tanpa berbagi Telegram); token/tautan undangan tidak pernah masuk URL,
// riwayat hash, storage, console, toast, atau pesan galat; Idempotency-Key sekali per formulir Tambah/Ubah. Data fiktif.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

class Node_ {}
const buatEl = (tag) => {
  const el = new Node_();
  Object.assign(el, {
    tag, attrs: {}, listeners: {}, ls: {}, anak: [], className: '', dataset: {}, style: { cssText: '' }, disabled: false, hidden: false, dihapus: false,
    classList: { add() {}, remove() {}, toggle() {} },
    setAttribute(k, v) { this.attrs[k] = String(v); },
    addEventListener(n, fn) { (this.ls[n] ||= []).push(fn); this.listeners[n] = (...a) => Promise.all(this.ls[n].map((f) => f(...a))); },
    append(...xs) { this.anak.push(...xs); },
    replaceChildren(...xs) { this.anak = [...xs]; },
    insertBefore(n) { this.anak.unshift(n); },
    prepend(n) { this.anak.unshift(n); },
    scrollIntoView() {}, focus() {}, remove() { this.dihapus = true; },
  });
  // Seperti DOM: value selalu string; menulis textContent mengganti seluruh anak.
  let nilai = '';
  let isiTeks = '';
  Object.defineProperty(el, 'value', { get: () => nilai, set: (v) => { nilai = v == null ? '' : String(v); }, enumerable: true });
  Object.defineProperty(el, 'textContent', { get: () => isiTeks, set: (v) => { isiTeks = v == null ? '' : String(v); el.anak = []; }, enumerable: true });
  return el;
};

// Penyadap: semua tulisan storage, console, dan riwayat hash dicatat untuk pemeriksaan kebocoran token.
const tulisanStorage = [];
const mem = () => { const m = new Map(); return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => { tulisanStorage.push(`${k}=${v}`); m.set(k, String(v)); }, removeItem: (k) => m.delete(k) }; };
const tetap = { mainbar: buatEl('div'), mainbtn: buatEl('button'), toasts: buatEl('div') };
const overlays = [];
const docListeners = {};
const winListeners = {};
globalThis.Node = Node_;
globalThis.document = {
  createElement: buatEl, createTextNode: (t) => Object.assign(new Node_(), { teks: String(t) }),
  getElementById: (id) => tetap[id] ?? null, body: { classList: { add() {}, remove() {} }, append: (n) => overlays.push(n) }, title: '',
  addEventListener: (n, f) => { (docListeners[n] ||= new Set()).add(f); },
  removeEventListener: (n, f) => { docListeners[n]?.delete(f); },
};
globalThis.localStorage = mem();
globalThis.sessionStorage = mem();
const riwayatHash = [];
let hashSaatIni = '';
globalThis.location = {
  get hash() { return hashSaatIni; },
  set hash(v) { riwayatHash.push(String(v)); hashSaatIni = String(v); },
  replace(v) { riwayatHash.push(String(v)); hashSaatIni = String(v); },
  pathname: '/web/', search: '',
};
globalThis.window = {
  scrollTo() {}, location: globalThis.location,
  addEventListener: (n, f) => { (winListeners[n] ||= new Set()).add(f); },
  removeEventListener: (n, f) => { winListeners[n]?.delete(f); },
};
globalThis.matchMedia = () => ({ matches: false, addEventListener() {} });
const disalin = [];
let clipboardGagal = false;
Object.defineProperty(globalThis, 'navigator', {
  configurable: true, writable: true,
  value: { clipboard: { writeText: async (t) => { if (clipboardGagal) throw new Error('ditolak'); disalin.push(t); } } },
});
const logKonsol = [];
for (const k of ['log', 'info', 'warn', 'error', 'debug', 'trace']) {
  const asli = console[k].bind(console);
  console[k] = (...a) => { logKonsol.push(a.map(String).join(' ')); if (process.env.DEBUG_TES) asli(...a); };
}

const TOKEN = 'TokenUndanganFiktif_Rahasia_1234567890abcdef';
const TAUTAN = `https://t.me/sigap_fiktif_bot?start=${TOKEN}`;

const { setPlatform } = await import('../core/platform.js');
setPlatform('web');
const { setUserSesi, getUser } = await import('../core/auth.js');
const { startRouter, resolve, cekAkses } = await import('../core/router.js');
const { daftarkanRuteWeb, BACA_WEB, TULIS_WEB } = await import('../rute-web.js');
const { userPerms, bolehAksi } = await import('../core/permissions.js');
const { salin, NAMA_PROFIL } = await import('./fixtures/peta.js');
const web = await import('../modules/pengguna/web.js');

// ---- Server tiruan ----
const panggilan = [];
let skrip = [];
const resp = (status, body) => ({ ok: status < 300, status, headers: { get: () => null }, json: async () => body });
const lst = (x) => resp(200, { data: x, meta: { current_page: 1, last_page: 1, total: x.length } });
const relawanBaru = { id: 50, name: 'Siti Fiktif', role: 'relawan', role_label: 'Relawan', unit: 'dapur', sppg_id: 1, sppg_nama: 'SPPG Contoh', phone: '0800-0000-0000', telegram_terhubung: false, telegram_username: null, username: 'siti_fiktif', password_diatur: false, is_active: true, status: 'menunggu_telegram', last_login_at: null };
const investorLain = { id: 60, name: 'Investor Fiktif', role: 'investor', role_label: 'Investor', unit: null, sppg_id: null, phone: null, telegram_terhubung: true, telegram_username: 'inv_fiktif', username: 'inv_fiktif', password_diatur: true, is_active: true, status: 'aktif' };
const rekamDiri = (u) => ({ id: u.id, name: u.name, role: u.role, role_label: u.role_label, unit: u.unit ?? null, sppg_id: u.sppg_id, sppg_nama: u.sppg_name, phone: null, telegram_terhubung: true, telegram_username: 'diri_fiktif', username: u.username, password_diatur: true, is_active: true, status: 'aktif', last_login_at: null });
const sesiSaya = [
  { id: 1, perangkat: 'Chrome di Windows', jenis: 'web', sesi_ini: true, terakhir_dipakai_pada: '2026-11-19T08:00:00+07:00' },
  { id: 2, perangkat: '<img src=x onerror=alert(1)>', jenis: 'miniapp', sesi_ini: false, terakhir_dipakai_pada: '2026-11-18T08:00:00+07:00' },
];
const undangan = [{ id: 3, tipe: 'pengguna', status: 'aktif', user_nama: 'Siti Fiktif', expires_at: '2026-11-22T08:00:00+07:00' }];

globalThis.fetch = async (url, init = {}) => {
  const u = new URL(url, 'http://x');
  const path = u.pathname.replace('/api/v1', '');
  const metode = init.method ?? 'GET';
  panggilan.push({ metode, path, query: u.search, headers: init.headers || {}, body: typeof init.body === 'string' ? JSON.parse(init.body) : init.body ?? null });
  const saya = getUser();
  if (metode === 'GET') {
    if (path === '/pengguna') {
      // Server: R* tidak boleh memanggil daftar (klien tidak boleh sampai ke sini untuk R*).
      if (userPerms(saya, 'pengguna').selfOnly) return resp(403, { message: 'Anda tidak punya hak akses.' });
      return lst([relawanBaru, investorLain, rekamDiri(saya)]);
    }
    const m = path.match(/^\/pengguna\/(\d+)$/);
    if (m) {
      const id = Number(m[1]);
      if (id === saya.id) return resp(200, { data: rekamDiri(saya) });
      if (userPerms(saya, 'pengguna').selfOnly) return resp(403, { message: 'This action is unauthorized.' });
      if (id === 50) return resp(200, { data: relawanBaru });
      if (id === 60) return resp(200, { data: investorLain });
      return resp(404, { message: 'Not found' });
    }
    if (path === '/auth/sesi') return resp(200, { data: sesiSaya });
    if (path === '/pengguna/undangan') return lst(undangan);
    if (path === '/pengguna/60/kepemilikan-sppg') return resp(200, { data: { sppg: [{ id: 1, nama: 'SPPG Contoh', kode: 'SP-01' }] } });
    if (/^\/pengguna\/\d+\/sesi$/.test(path)) return resp(200, { data: [sesiSaya[1]] });
    if (path === '/pengguna/permintaan-reset') return resp(200, { data: [] });
    if (path === '/sppg') return lst([{ id: 1, nama: 'SPPG Contoh' }, { id: 2, nama: 'SPPG Kedua' }]);
    return lst([]);
  }
  return skrip.length ? skrip.shift() : resp(200, { data: { id: 1 } });
};

// ---- Pemasangan rute ----
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
const cariTag = (el, tag) => { let r = null; semua(el, (n) => { if (!r && n.tag === tag) r = n; }); return r; };
const tombol = (el) => { const t = []; semua(el, (n) => { if (n.tag === 'button') t.push(n); }); return t; };
const tautan = (el) => { const t = []; semua(el, (n) => { if (n.attrs?.href) t.push(n.attrs.href); }); return t; };
const tunggu = (ms = 15) => new Promise((r) => setTimeout(r, ms));
const outlet = buatEl('main');
await startRouter(outlet);
async function buka(path) {
  panggilan.length = 0;
  location.hash = `#${path}`;
  await resolve();
  for (let i = 0; i < 3 && location.hash !== `#${path}`; i++) { path = location.hash.slice(1); await resolve(); } // pengalihan (R* -> profil)
  await tunggu();
  return outlet;
}
const tulis = () => panggilan.filter((p) => p.metode !== 'GET');
function isi(el, nilai) {
  for (const [id, v] of Object.entries(nilai)) { const i = cari(el, `f-${id}`); assert.ok(i, `bidang ${id} ada`); i.value = v; i.listeners.input?.(); i.listeners.change?.(); }
  cariTag(el, 'form')?.listeners.change?.({ target: {} }); // showIf (unit/SPPG bergantung peran)
}
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-9a-f][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const sebagai = (nama) => { setUserSesi(salin(nama)); return getUser(); };
const PERAN_R_BINTANG = NAMA_PROFIL.filter((n) => salin(n).permissions?.pengguna === 'R*');
const dialogTerakhir = () => overlays.filter((o) => o.attrs?.role === 'dialog').at(-1);
async function konfirmasi(btn, okTeks) {
  overlays.length = 0;
  const k = btn.listeners.click();
  await tunggu(5);
  await tombol(overlays.at(-1)).find((x) => teks(x) === okTeks).listeners.click();
  await k;
  await tunggu();
}
/** Token tidak boleh ada di URL/riwayat, storage, console, toast, judul, atau body permintaan selain respons server. */
function tanpaJejakToken(label) {
  assert.ok(!riwayatHash.some((x) => x.includes(TOKEN)), `${label}: token di riwayat hash`);
  assert.ok(!tulisanStorage.some((x) => x.includes(TOKEN)), `${label}: token di storage`);
  assert.ok(!logKonsol.some((x) => x.includes(TOKEN)), `${label}: token di console`);
  assert.ok(!teks(tetap.toasts).includes(TOKEN), `${label}: token di toast`);
  assert.ok(!String(document.title).includes(TOKEN), `${label}: token di judul`);
  assert.ok(!panggilan.some((p) => p.path.includes(TOKEN) || p.query.includes(TOKEN) || JSON.stringify(p.body ?? '').includes(TOKEN)), `${label}: token dikirim ulang ke server`);
}
const responsUndangan = (extra = {}) => resp(201, { data: { id: 9, tipe: 'pengguna', status: 'aktif', user_id: 50, expires_at: new Date(Date.now() + 72 * 3600000).toISOString(), token: TOKEN, deep_link: TAUTAN, ...extra } });

// ---- Tes ----
test('pengguna terdaftar di BACA_WEB dan TULIS_WEB; guard rute mengikuti peta hak server per profil', () => {
  assert.ok(BACA_WEB.has('pengguna') && TULIS_WEB.has('pengguna'));
  for (const nama of NAMA_PROFIL) {
    const u = sebagai(nama);
    const p = userPerms(u, 'pengguna');
    const exp = (b) => (b ? 'boleh' : 'ditolak');
    assert.equal(cekAkses('/pengguna'), exp(p.R), `${nama} daftar/profil`);
    assert.equal(cekAkses('/pengguna/7'), exp(p.R), `${nama} detail`);
    assert.equal(cekAkses('/pengguna/baru'), exp(p.C), `${nama} baru`);
    assert.equal(cekAkses('/pengguna/7/ubah'), exp(p.U), `${nama} ubah`);
    assert.equal(cekAkses('/pengguna/undangan'), exp(bolehAksi(u, 'undang_pengguna')), `${nama} undangan`);
    assert.equal(cekAkses('/pengguna/undangan/baru'), exp(bolehAksi(u, 'undang_pengguna')), `${nama} undangan baru`);
    assert.equal(cekAkses('/pengguna/permintaan-reset'), exp(bolehAksi(u, 'reset_password_pengguna')), `${nama} permintaan reset`);
    assert.equal(cekAkses('/pengguna/7/sesi'), exp(bolehAksi(u, 'reset_password_pengguna')), `${nama} sesi pengguna`);
  }
  // Pengaman eksplisit: hanya Admin yang punya rute tulis/undangan menurut snapshot server.
  sebagai('admin');
  assert.equal(cekAkses('/pengguna/undangan/baru'), 'boleh');
  for (const nama of ['kepala_sppg', 'relawan_dapur', 'investor']) {
    sebagai(nama);
    for (const r of ['/pengguna/baru', '/pengguna/7/ubah', '/pengguna/undangan', '/pengguna/permintaan-reset', '/pengguna/7/sesi']) assert.equal(cekAkses(r), 'ditolak', `${nama} ${r}`);
  }
});

test('Profil Saya (semua peran R*): daftar TIDAK dipanggil; akun, peran, dan perangkat/sesi sendiri; tanpa aksi tulis; hanya GET', async () => {
  assert.ok(PERAN_R_BINTANG.length >= 6, 'snapshot memuat peran R*');
  for (const nama of PERAN_R_BINTANG) {
    const u = sebagai(nama);
    const el = await buka('/pengguna');
    assert.equal(location.hash, `#/pengguna/${u.id}`, `${nama}: dialihkan ke profil sendiri`);
    assert.ok(!panggilan.some((p) => p.path === '/pengguna'), `${nama}: GET /pengguna (viewAny) tidak boleh dipanggil`);
    assert.ok(panggilan.some((p) => p.path === `/pengguna/${u.id}`), `${nama}: profil sendiri dimuat`);
    assert.ok(panggilan.some((p) => p.path === '/auth/sesi'), `${nama}: sesi sendiri dimuat`);
    assert.equal(document.title, 'Profil Saya', nama);
    const t = teks(el);
    for (const harap of ['Akun login', u.username, 'Sudah diatur', u.role_label, 'Perangkat dan sesi', 'Chrome di Windows', 'Sesi ini']) assert.ok(t.includes(harap), `${nama}: "${harap}"`);
    assert.ok(t.includes('<img src=x onerror=alert(1)>'), `${nama}: label sesi dirender sebagai teks`);
    assert.ok(tautan(el).includes('#/sesi'), `${nama}: tautan kelola sesi`);
    assert.ok(!tombol(el).some((b) => /Hapus|Reset|Cabut|undangan/i.test(teks(b))), `${nama}: tombol tulis`);
    assert.ok(!tautan(el).some((x) => /\/pengguna\/(baru|undangan|permintaan-reset)|\/ubah$|\/sesi$/.test(x) && x !== '#/sesi'), `${nama}: tautan admin`);
    assert.equal(tetap.mainbar.hidden, true, `${nama}: tanpa tombol Ubah`);
    assert.ok(panggilan.every((p) => p.metode === 'GET'), `${nama}: hanya GET`);
  }
});

test('R*: record pengguna lain -> 403 server ditangani (panel Akses ditolak), tanpa memanggil daftar', async () => {
  for (const nama of ['relawan_dapur', 'investor', 'akuntan']) {
    sebagai(nama);
    const el = await buka('/pengguna/50');
    assert.ok(teks(el).includes('Akses ditolak'), `${nama}: panel 403`);
    assert.ok(!teks(el).includes('Siti Fiktif'), `${nama}: data pengguna lain tidak tampil`);
    assert.ok(!teks(el).includes('unauthorized'), `${nama}: pesan server mentah tidak tampil`);
    assert.ok(!panggilan.some((p) => p.path === '/pengguna'), nama);
  }
});

test('Kepala SPPG (R): daftar tampil tanpa Tambah/undangan/reset; detail tanpa Hapus/Ubah/kredensial', async () => {
  sebagai('kepala_sppg');
  let el = await buka('/pengguna');
  const href = tautan(el);
  assert.ok(panggilan.some((p) => p.path === '/pengguna'), 'daftar dipanggil (hak R)');
  assert.ok(href.includes('#/pengguna/50'));
  assert.ok(!href.some((x) => /baru|undangan|permintaan-reset/.test(x)), href.join(' '));
  el = await buka('/pengguna/50');
  assert.ok(teks(el).includes('Siti Fiktif'));
  assert.ok(!tombol(el).some((b) => /Hapus|Reset|Cabut|undangan/i.test(teks(b))));
  assert.equal(tetap.mainbar.hidden, true, 'tanpa Ubah');
  assert.ok(panggilan.every((p) => p.metode === 'GET'));
});

test('Admin: daftar memuat Tambah, Kelola undangan, Permintaan reset (aksi server); tanpa share t.me', async () => {
  sebagai('admin');
  const el = await buka('/pengguna');
  const href = tautan(el);
  for (const h of ['#/pengguna/baru', '#/pengguna/undangan', '#/pengguna/permintaan-reset', '#/pengguna/50']) assert.ok(href.includes(h), h);
  assert.ok(!href.some((x) => /t\.me|draft=/.test(x)));
  assert.ok(panggilan.every((p) => p.metode === 'GET'));
});

test('Admin Tambah: POST /pengguna dengan Idempotency-Key UUID yang sama saat diulang; body mengikuti mapPayload', async () => {
  sebagai('admin');
  const el = await buka('/pengguna/baru');
  isi(el, { name: 'Budi Fiktif', role: 'akuntan', phone: '0800-1111-2222' });
  isi(el, { sppg_id: '1' });
  skrip = [resp(503, { message: 'x' }), resp(201, { data: { id: 77 } })];
  await tetap.mainbtn.onclick(); await tunggu();
  await tetap.mainbtn.onclick(); await tunggu();
  const t = tulis();
  assert.equal(t.length, 2);
  assert.equal(t[0].metode, 'POST');
  assert.equal(t[0].path, '/pengguna');
  assert.match(t[0].headers['Idempotency-Key'], UUID);
  assert.equal(t[0].headers['Idempotency-Key'], t[1].headers['Idempotency-Key']);
  assert.deepEqual(t[1].body, { name: 'Budi Fiktif', role: 'akuntan', unit: null, sppg_id: 1, phone: '0800-1111-2222' });
  assert.ok(!('Authorization' in t[0].headers));
  assert.equal(location.hash, '#/pengguna/77');
});

test('Admin Ubah: PUT /pengguna/{id} dengan is_active boolean dan Idempotency-Key; 422 per kolom', async () => {
  sebagai('admin');
  const el = await buka('/pengguna/50/ubah');
  assert.equal(cari(el, 'f-name').value, 'Siti Fiktif');
  isi(el, { name: 'Siti Fiktif Dua', is_active: 'false' });
  skrip = [resp(422, { message: 'Data tidak valid.', errors: { name: ['Nama sudah dipakai.'] } })];
  await tetap.mainbtn.onclick(); await tunggu();
  assert.equal(cari(el, 'f-name-err').textContent, 'Nama sudah dipakai.');
  skrip = [resp(200, { data: { id: 50 } })];
  await tetap.mainbtn.onclick(); await tunggu();
  const t = tulis();
  assert.equal(t.length, 2);
  assert.equal(t[1].metode, 'PUT');
  assert.equal(t[1].path, '/pengguna/50');
  assert.equal(t[1].body.is_active, false);
  assert.equal(t[1].body.name, 'Siti Fiktif Dua');
  assert.equal(t[0].headers['Idempotency-Key'], t[1].headers['Idempotency-Key']);
});

test('Admin detail akun sendiri: Ubah ada, Hapus tidak (rowPerms), Akun login + sesi sendiri, tanpa Reset password', async () => {
  const u = sebagai('admin');
  const el = await buka(`/pengguna/${u.id}`);
  assert.equal(tetap.mainbar.hidden, false, 'Ubah dari hak U server');
  assert.ok(!tombol(el).some((b) => /^Hapus$/.test(teks(b))), 'tidak bisa menghapus akun sendiri');
  assert.ok(!tombol(el).some((b) => /Reset password/.test(teks(b))));
  assert.ok(teks(el).includes('Akun login') && teks(el).includes('Perangkat dan sesi'));
});

test('Admin detail pengguna lain: Hapus (DELETE), Reset password dengan teks tetap (respons server tidak dirender), Cabut sesi', async () => {
  sebagai('admin');
  let el = await buka('/pengguna/50');
  assert.ok(teks(el).includes('Kredensial login'));
  assert.ok(teks(el).includes('Password: belum diatur'));
  assert.ok(tautan(el).includes('#/pengguna/50/sesi'));
  skrip = [resp(200, { message: 'PESAN_SERVER_JANGAN_TAMPIL', password: 'SandiSementara123' })];
  await konfirmasi(tombol(el).find((b) => teks(b) === 'Reset password'), 'Reset');
  let t = tulis();
  assert.equal(t.length, 1);
  assert.equal(t[0].path, '/pengguna/50/reset-password');
  assert.deepEqual(t[0].body, {});
  assert.ok(teks(tetap.toasts).includes(web.PESAN_RESET_BERHASIL));
  assert.ok(!teks(tetap.toasts).includes('PESAN_SERVER_JANGAN_TAMPIL') && !teks(el).includes('SandiSementara123'), 'password apa pun dari respons tidak dirender');
  assert.ok(!logKonsol.some((x) => x.includes('SandiSementara123')));
  el = await buka('/pengguna/50');
  skrip = [resp(403, { message: 'This action is unauthorized.' })];
  await konfirmasi(tombol(el).find((b) => teks(b) === 'Cabut semua sesi'), 'Cabut');
  t = tulis();
  assert.equal(t[0].metode, 'DELETE');
  assert.equal(t[0].path, '/pengguna/50/sesi');
  assert.ok(teks(el).includes('Anda tidak berhak melakukan aksi ini.'), '403 -> pesan tetap Indonesia');
  el = await buka('/pengguna/50');
  await konfirmasi(tombol(el).find((b) => teks(b) === 'Hapus'), 'Hapus');
  t = tulis();
  assert.equal(t[0].metode, 'DELETE');
  assert.equal(t[0].path, '/pengguna/50');
});

test('Admin: sesi pengguna lain dan permintaan reset memakai rute web; kepemilikan SPPG investor hanya bila aksi server', async () => {
  sebagai('admin');
  let el = await buka('/pengguna/50/sesi');
  assert.ok(panggilan.some((p) => p.path === '/pengguna/50/sesi'));
  assert.ok(teks(el).includes('Cabut semua sesi pengguna'));
  el = await buka('/pengguna/permintaan-reset');
  assert.ok(panggilan.some((p) => p.path === '/pengguna/permintaan-reset'));
  el = await buka('/pengguna/60');
  assert.ok(teks(el).includes('SPPG yang dimiliki'));
  assert.ok(panggilan.some((p) => p.path === '/pengguna/60/kepemilikan-sppg'));
  assert.ok(!tombol(el).some((b) => /undangan/i.test(teks(b))), 'akun aktif: tanpa tombol undangan');
  const u = getUser();
  setUserSesi({ ...u, aksi: { ...u.aksi, kelola_kepemilikan_sppg: false, reset_password_pengguna: false } });
  el = await buka('/pengguna/60');
  assert.ok(!teks(el).includes('SPPG yang dimiliki'), 'aksi.kelola_kepemilikan_sppg false');
  assert.ok(!teks(el).includes('Kredensial login'), 'aksi.reset_password_pengguna false');
});

test('undangan dari detail: POST tanpa Idempotency-Key; dialog Salin tautan (tanpa Bagikan/t.me/share); tautan hilang saat ditutup; tanpa jejak token', async () => {
  sebagai('admin');
  const el = await buka('/pengguna/50');
  const btn = tombol(el).find((b) => teks(b) === 'Buat tautan undangan Telegram');
  assert.ok(btn);
  overlays.length = 0;
  skrip = [responsUndangan()];
  const k = btn.listeners.click();
  await tunggu();
  const t = tulis();
  assert.equal(t.length, 1);
  assert.equal(t[0].path, '/pengguna/undangan');
  assert.deepEqual(t[0].body, { user_id: 50 });
  assert.ok(!('Idempotency-Key' in t[0].headers), 'respons memuat token: tidak boleh masuk cache idempotensi');
  const dlg = dialogTerakhir();
  assert.ok(dlg, 'dialog tampil');
  assert.ok(teks(dlg).includes(TAUTAN), 'tautan tampil sekali');
  assert.ok(/72 jam/.test(teks(dlg)));
  const label = tombol(dlg).map(teks);
  assert.ok(label.includes('Salin tautan') && label.includes('Tutup'));
  assert.ok(!label.some((x) => /Bagikan/i.test(x)));
  assert.ok(!tautan(dlg).length, 'tanpa tautan berbagi');
  await tombol(dlg).find((b) => teks(b) === 'Salin tautan').listeners.click();
  assert.deepEqual(disalin.splice(0), [TAUTAN]);
  assert.ok(teks(tetap.toasts).includes('Tautan disalin'));
  await tombol(dlg).find((b) => teks(b) === 'Tutup').listeners.click();
  await k;
  assert.equal(dlg.dihapus, true, 'dialog dilepas dari DOM');
  assert.equal(cari(dlg, 'invite-link').textContent, '', 'tautan dikosongkan');
  assert.ok(!teks(dlg).includes(TOKEN));
  assert.ok(!teks(outlet).includes(TOKEN), 'token tidak tertinggal di halaman');
  assert.equal(btn.disabled, false);
  assert.ok(!docListeners.keydown?.size, 'pendengar Escape dilepas');
  tanpaJejakToken('detail');
});

test('formulir undangan web: payload akun baru, dialog, lalu kembali ke daftar undangan (replace); tanpa jejak token', async () => {
  sebagai('admin');
  const el = await buka('/pengguna/undangan/baru?draft=00000000-0000-4000-8000-000000000000');
  assert.ok(!panggilan.some((p) => p.path.startsWith('/ai-drafts')), 'draf AI tidak dimuat di web');
  isi(el, { mode: 'baru', role: 'relawan' });
  isi(el, { unit: 'dapur', sppg_id: '1' });
  overlays.length = 0;
  skrip = [responsUndangan()];
  const k = tetap.mainbtn.onclick();
  await tunggu();
  const t = tulis();
  assert.equal(t.length, 1);
  assert.deepEqual(t[0].body, { role: 'relawan', unit: 'dapur', sppg_id: 1 });
  assert.ok(!('Idempotency-Key' in t[0].headers));
  const dlg = dialogTerakhir();
  assert.ok(teks(dlg).includes(TAUTAN));
  assert.equal(location.hash.includes('/pengguna/undangan/baru'), true, 'belum pindah selama dialog terbuka');
  // Escape menutup dialog.
  for (const f of [...(docListeners.keydown || [])]) f({ key: 'Escape' });
  await k;
  await tunggu();
  assert.equal(dlg.dihapus, true);
  assert.equal(location.hash, '#/pengguna/undangan');
  tanpaJejakToken('formulir');
});

test('formulir undangan: 422 per kolom (tanpa dialog); 500 -> pesan tetap; tautan tak sah -> tanpa tautan', async () => {
  sebagai('admin');
  let el = await buka('/pengguna/undangan/baru');
  isi(el, { mode: 'baru', role: 'akuntan' });
  isi(el, { sppg_id: '2' });
  overlays.length = 0;
  skrip = [resp(422, { message: 'Data tidak valid.', errors: { role: ['Peran tidak valid.'] } })];
  await tetap.mainbtn.onclick(); await tunggu();
  assert.equal(cari(el, 'f-role-err').textContent, 'Peran tidak valid.');
  assert.equal(dialogTerakhir(), undefined);
  skrip = [resp(500, { message: `Galat ${TOKEN}` })];
  await tetap.mainbtn.onclick(); await tunggu();
  assert.ok(teks(el).includes('Terjadi gangguan pada server. Coba lagi nanti.'));
  assert.ok(!teks(el).includes(TOKEN), 'pesan galat server mentah tidak dirender');
  el = await buka('/pengguna/undangan/baru');
  isi(el, { mode: 'akun' });
  isi(el, { user_id: '50' });
  overlays.length = 0;
  skrip = [responsUndangan({ deep_link: 'javascript:alert(1)' })];
  const k = tetap.mainbtn.onclick();
  await tunggu();
  const dlg = dialogTerakhir();
  assert.ok(teks(dlg).includes(web.PESAN_TANPA_TAUTAN));
  assert.ok(!teks(dlg).includes('javascript:'));
  assert.ok(!tombol(dlg).some((b) => teks(b) === 'Salin tautan'));
  await tombol(dlg).find((b) => teks(b) === 'Tutup').listeners.click();
  await k;
  tanpaJejakToken('galat');
});

test('dialog undangan tertutup saat pindah halaman (hashchange); gagal salin -> petunjuk manual tanpa tautan di toast', async () => {
  overlays.length = 0;
  clipboardGagal = true;
  const pendengarAwal = winListeners.hashchange?.size ?? 0; // router juga mendengarkan hashchange
  const p = web.tampilkanTautanWeb(web.ringkasUndangan({ data: { token: TOKEN, deep_link: TAUTAN, expires_at: new Date(Date.now() + 3600000).toISOString() } }), { title: 'Uji' });
  const dlg = dialogTerakhir();
  await tombol(dlg).find((b) => teks(b) === 'Salin tautan').listeners.click();
  assert.ok(teks(tetap.toasts).includes('Ctrl+C'));
  clipboardGagal = false;
  for (const f of [...(winListeners.hashchange || [])]) f();
  await p;
  assert.equal(dlg.dihapus, true);
  assert.equal(cari(dlg, 'invite-link').textContent, '');
  assert.equal(winListeners.hashchange?.size ?? 0, pendengarAwal, 'pendengar hashchange dialog dilepas');
  tanpaJejakToken('hashchange');
});

test('ringkasUndangan: hanya {link, jam}; token mentah dan kolom lain dibuang; tautan non-t.me ditolak', () => {
  const r = web.ringkasUndangan({ data: { token: TOKEN, deep_link: TAUTAN, expires_at: new Date(Date.now() + 2 * 3600000).toISOString(), user_id: 5 } });
  assert.deepEqual(Object.keys(r).sort(), ['jam', 'link']);
  assert.equal(r.link, TAUTAN);
  assert.equal(r.jam, 2);
  for (const buruk of ['http://t.me/bot?start=abc', 'https://evil.example/t.me/bot?start=abc', 'https://t.me/bot?start=abc&x=1', null, 5]) {
    assert.deepEqual(web.ringkasUndangan({ data: { token: TOKEN, deep_link: buruk } }), { link: null, jam: null }, String(buruk));
  }
});

test('daftar undangan web: Cabut memakai DELETE setelah konfirmasi; tanpa tautan/token', async () => {
  sebagai('admin');
  const el = await buka('/pengguna/undangan');
  assert.ok(teks(el).includes('Siti Fiktif'));
  assert.ok(tautan(el).includes('#/pengguna/undangan/baru'));
  skrip = [resp(204, null)];
  await konfirmasi(tombol(el).find((b) => teks(b) === 'Cabut'), 'Cabut');
  const t = tulis();
  assert.equal(t[0].metode, 'DELETE');
  assert.equal(t[0].path, '/pengguna/undangan/3');
});

test('statis: modul pengguna tanpa console.*, storage, innerHTML, atau berbagi Telegram di jalur web', () => {
  const baca = (f) => readFileSync(new URL(`../modules/pengguna/${f}`, import.meta.url), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/\s\/\/ .*$/gm, '');
  for (const f of ['index.js', 'schema.js', 'web.js']) {
    const src = baca(f);
    assert.ok(!/console\./.test(src), `${f}: console`);
    assert.ok(!/localStorage|sessionStorage|indexedDB/.test(src), `${f}: storage`);
    assert.ok(!/innerHTML|outerHTML|insertAdjacentHTML/.test(src), `${f}: innerHTML`);
  }
  const w = baca('web.js');
  assert.ok(!/invite-dialog|shareLink|buildShareUrl|openTelegramLink|navigator\.share|getTg/.test(w), 'web.js: berbagi Telegram');
  assert.ok(!/Idempotency|newKey/.test(w), 'web.js: undangan tanpa Idempotency-Key');
  assert.ok(!/ai-drafts|draft/.test(w), 'web.js: tanpa draf AI');
  assert.ok(!/location\.(hash|href|search)|history\./.test(w), 'web.js: tautan tidak pernah ditulis ke URL/riwayat');
});

test('tanpa console sepanjang alur web pengguna', () => {
  assert.deepEqual(logKonsol.filter((x) => /pengguna|undangan|token|password|sandi/i.test(x)), []);
});
