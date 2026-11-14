// R04-8 PR4a: render smoke layar BACA web data master (sppg, titik-layanan, penerima-manfaat, pemasok, armada, pengemudi)
// per peran (fixture peta.js = cermin keluaran server), memakai router asli. Hak dari peta server; tanpa aksi tulis di web.
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
    remove() {},
  });
  return el;
};
const mem = () => { const m = new Map(); return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) }; };
globalThis.Node = Node_;
globalThis.document = {
  createElement: buatEl, createTextNode: (t) => Object.assign(new Node_(), { teks: String(t) }),
  getElementById: () => null, body: { classList: { add() {}, remove() {} }, append() {} }, title: '',
};
globalThis.localStorage = mem();
globalThis.sessionStorage = mem();
globalThis.location = { hash: '', replace(h) { this.hash = h; } };
globalThis.window = { scrollTo() {}, addEventListener() {}, location: globalThis.location };
globalThis.matchMedia = () => ({ matches: false, addEventListener() {} });

const panggilan = [];
const resp = (status, body) => ({ ok: status < 300, status, headers: { get: () => null }, json: async () => body });
const REC = { id: 1, nama: 'Contoh Fiktif', plat_nomor: 'B 1234 CD', kode: 'S-01', status: 'aktif', status_operasional: 'aktif', kapasitas_porsi_harian: 3000, kapasitas_box: 40, kelompok_sasaran: 'siswa', sim_status: 'berlaku', jenis: 'Minibus' };
globalThis.fetch = async (url, init) => {
  const u = new URL(url, 'http://x');
  const path = u.pathname.replace('/api/v1', '');
  panggilan.push({ metode: init.method, path, headers: init.headers });
  if (init.method !== 'GET') return resp(500, { message: 'tulis tidak boleh dari layar baca web' });
  if (/\/harga$/.test(path)) return resp(200, { data: [] });
  if (path === '/keuangan/ringkasan') return resp(200, { data: { periode: '2026-11', anggaran: 1000000, terpakai: 500000, sisa: 500000, persen: 50, per_kategori: [{ kategori: 'bahan_baku', nominal: 500000, persen: 100 }], jumlah_melebihi_anggaran: 0 } });
  if (path === '/dashboard') return resp(200, { data: { mode: 'sppg', rentang_hari: 7, dibuat_pada: '2026-11-14T08:00:00Z', sppg: [{ id: 1, nama: 'SPPG Contoh' }], widgets: { porsi_hari_ini: { target: 100, realisasi: 90 } } } });
  if (/^\/rekap-harian\/\d+$/.test(path)) return resp(200, { data: { ...REC, tanggal: '2026-11-13', catatan: 'Catatan fiktif', status_kirim: 'terkirim', ringkasan: { porsi: { target: 100, realisasi: 90 }, keuangan: { serapan_persen: 50 }, distribusi: { selesai: 1 } } } });
  if (/\/\d+$/.test(path)) return resp(200, { data: REC });
  return resp(200, { data: [REC], meta: { current_page: 1, last_page: 1, total: 1 } });
};

const { setPlatform } = await import('../core/platform.js');
setPlatform('web');
const { setUserSesi, getUser } = await import('../core/auth.js');
const { startRouter, resolve, cekAkses } = await import('../core/router.js');
const { daftarkanRuteWeb, BACA_WEB } = await import('../rute-web.js');
const { userPerms, dashboardTersedia } = await import('../core/permissions.js');
const { PROFIL, salin } = await import('./fixtures/peta.js');

const dir = fileURLToPath(new URL('../modules/', import.meta.url));
const MODUL = [];
for (const n of readdirSync(dir, { withFileTypes: true }).filter((d) => d.isDirectory() && !d.name.startsWith('_')).map((d) => d.name).sort()) {
  try { readFileSync(`${dir}${n}/index.js`); } catch { continue; }
  MODUL.push(...[(await import(`../modules/${n}/index.js`)).default].flat().filter(Boolean));
}
daftarkanRuteWeb(MODUL, getUser);

const semua = (n, f) => { if (n instanceof Node_) { f(n); (n.anak || []).forEach((x) => semua(x, f)); } };
const teks = (el) => { const t = []; semua(el, (n) => { if (n.teks != null) t.push(n.teks); }); return t.join(' | '); };
const tautan = (el) => { const t = []; semua(el, (n) => { if (n.attrs?.href) t.push(n.attrs.href); }); return t; };
const tombol = (el) => { const t = []; semua(el, (n) => { if (n.tag === 'button') t.push(n); }); return t; };

const outlet = buatEl('main');
await startRouter(outlet);
async function buka(path) {
  panggilan.length = 0;
  location.hash = `#${path}`;
  await resolve();
  // Pengalihan (mis. Kepala SPPG ke SPPG sendiri): router memanggil location.replace, resolve ulang.
  for (let i = 0; i < 3 && location.hash !== `#${path}`; i++) { path = location.hash.slice(1); await resolve(); }
  await new Promise((r) => setTimeout(r, 10));
  return outlet;
}
const peran = (nama) => { setUserSesi(salin(nama)); return salin(nama); };
const izinBaca = (user, slug) => userPerms(user, slug === 'pengemudi' ? 'armada' : slug).R;

test('layar baca web: modul data master terdaftar dan guard mengikuti peta hak server per profil', () => {
  assert.deepEqual([...BACA_WEB].sort(), [
    'armada', 'bukti-distribusi', 'dashboard', 'jadwal', 'jadwal/distribusi', 'keluhan', 'keuangan', 'keuangan/pencairan', 'keuangan/pengeluaran',
    'log-produksi', 'mutu', 'notifikasi-perjalanan', 'pemasok', 'penerima-manfaat', 'pengemudi', 'rekap-harian', 'sppg', 'titik-layanan',
  ]);
  for (const nama of Object.keys(PROFIL)) {
    setUserSesi(salin(nama));
    for (const m of MODUL.filter((x) => BACA_WEB.has(x.slug) && x.slug !== 'dashboard')) {
      const harap = userPerms(getUser(), m.permissionSlug ?? m.slug).R ? 'boleh' : 'ditolak';
      assert.equal(cekAkses(`/${m.slug}`), harap, `${nama} ${m.slug}`);
      // Sub-jenis jadwal juga bergantung unit_scope (akses(user)); detailnya diuji lewat render di bawah.
      if (m.slug !== 'keuangan' && !m.slug.startsWith('jadwal')) assert.equal(cekAkses(`/${m.slug}/1`), harap, `${nama} ${m.slug}/1`);
    }
    assert.equal(cekAkses('/dashboard'), dashboardTersedia(getUser()) ? 'boleh' : 'ditolak', `${nama} dashboard`);
  }
});

test('peran kantor: daftar tampil dengan tautan detail, tanpa Tambah; hanya GET', async () => {
  for (const nama of ['admin', 'kepala_sppg', 'akuntan', 'investor']) {
    const user = peran(nama);
    for (const slug of ['penerima-manfaat', 'titik-layanan', 'pemasok', 'armada', 'pengemudi']) {
      if (!izinBaca(user, slug)) continue;
      const el = await buka(`/${slug}`);
      const href = tautan(el);
      assert.ok(href.includes(`#/${slug}/1`), `${nama} ${slug}: tautan detail (${href})`);
      assert.ok(!href.includes(`#/${slug}/baru`), `${nama} ${slug}: tautan Tambah`);
      assert.ok(!/Tambah/.test(teks(el)), `${nama} ${slug}: teks Tambah`);
      assert.ok(panggilan.length > 0 && panggilan.every((p) => p.metode === 'GET'), `${nama} ${slug}: hanya GET`);
    }
  }
});

test('detail: rincian tampil; tanpa Ubah, Hapus, undangan PIC, dan tombol catat harga; hanya GET', async () => {
  for (const nama of ['admin', 'kepala_sppg', 'akuntan', 'investor']) {
    const user = peran(nama);
    for (const slug of ['penerima-manfaat', 'titik-layanan', 'pemasok', 'armada', 'pengemudi', 'sppg']) {
      if (!izinBaca(user, slug)) continue;
      const el = await buka(`/${slug}/1`);
      assert.ok(el.anak.length > 0, `${nama} ${slug}: rincian kosong`);
      assert.ok(!tautan(el).some((x) => /\/(ubah|baru|mutasi)$/.test(x)), `${nama} ${slug}: tautan tulis`);
      assert.ok(!tombol(el).some((b) => /Hapus|Ubah|Undang|Catat|Tambah|Simpan/i.test(teks(b))), `${nama} ${slug}: tombol tulis`);
      assert.ok(panggilan.every((p) => p.metode === 'GET'), `${nama} ${slug}: hanya GET`);
    }
  }
});

const OPERASIONAL = ['jadwal', 'jadwal/distribusi', 'log-produksi', 'mutu', 'notifikasi-perjalanan', 'bukti-distribusi', 'keluhan', 'keuangan/pencairan', 'keuangan/pengeluaran', 'rekap-harian'];
const slugIzin = (s) => (s.startsWith('jadwal') ? 'jadwal' : s.startsWith('keuangan') ? 'keuangan' : s);

test('operasional: daftar tampil dengan tautan detail tanpa Tambah/Berangkat/Susun; hanya GET (peran kantor)', async () => {
  for (const nama of ['admin', 'kepala_sppg', 'akuntan', 'investor']) {
    const user = peran(nama);
    for (const slug of OPERASIONAL) {
      const izin = slugIzin(slug);
      if (!userPerms(user, izin).R) { assert.equal(cekAkses(`/${slug}`), 'ditolak', `${nama} ${slug}`); continue; }
      if (slug.startsWith('jadwal') && !userPerms(user, 'jadwal').R) continue;
      const el = await buka(`/${slug}`);
      assert.ok(tautan(el).includes(`#/${slug}/1`), `${nama} ${slug}: tautan detail (${tautan(el)})`);
      assert.ok(!tautan(el).some((x) => /\/(baru|terakhir|ringkasan)$/.test(x)), `${nama} ${slug}: tautan tulis/terakhir ${tautan(el)}`);
      assert.ok(!/\+ (Tambah|Berangkat|Pencairan|Pengeluaran|Bukti)/.test(teks(el)), `${nama} ${slug}: tombol tambah`);
      assert.ok(panggilan.every((p) => p.metode === 'GET' && !('Authorization' in p.headers)), `${nama} ${slug}: hanya GET tanpa Authorization`);
    }
  }
});

test('operasional: detail tampil tanpa tombol tulis, tanpa tautan Ubah/Catat/Periksa, tanpa foto privat', async () => {
  for (const nama of ['admin', 'kepala_sppg', 'akuntan', 'investor']) {
    const user = peran(nama);
    for (const slug of OPERASIONAL) {
      if (!userPerms(user, slugIzin(slug)).R) continue;
      const el = await buka(`/${slug}/1`);
      assert.ok(el.anak.length > 0, `${nama} ${slug}: kosong`);
      assert.ok(!tautan(el).some((x) => /\/(ubah|baru|mutasi|kebutuhan)$|\/baru\?/.test(x)), `${nama} ${slug}: tautan tulis ${tautan(el)}`);
      assert.ok(!tombol(el).some((b) => /Hapus|Ubah|Simpan|Kirim ulang|Berangkat|Tiba|Setujui|Tolak|Batalkan|Mulai|Selesai/i.test(teks(b))), `${nama} ${slug}: tombol tulis`);
      assert.ok(panggilan.every((p) => p.metode === 'GET' && !('Authorization' in p.headers)), `${nama} ${slug}: hanya GET tanpa Authorization`);
    }
  }
});

test('keuangan: ringkasan anggaran tampil tanpa tombol tambah; dashboard tampil (admin, kepala, akuntan, investor)', async () => {
  for (const nama of ['admin', 'kepala_sppg', 'akuntan', 'investor']) {
    const user = peran(nama);
    if (userPerms(user, 'keuangan').R) {
      const el = await buka('/keuangan?periode=2026-11&sppg_id=1');
      location.hash = '#/keuangan';
      const t = teks(await buka('/keuangan'));
      assert.ok(!/\+ (Pencairan|Tambah)/.test(t) && !tautan(el).includes('#/keuangan/pencairan/baru'), `${nama}: tombol tambah keuangan`);
      assert.ok(panggilan.every((p) => p.metode === 'GET'), `${nama}: keuangan hanya GET`);
    }
    if (dashboardTersedia(user)) {
      const el = await buka('/dashboard');
      assert.ok(el.anak.length > 0, `${nama}: dashboard`);
      assert.ok(panggilan.every((p) => p.metode === 'GET'), `${nama}: dashboard hanya GET`);
    }
  }
});

test('rute tulis tidak ada di web: /ubah tidak terdaftar; /baru ditolak penjaga', () => {
  peran('admin');
  for (const slug of BACA_WEB) {
    assert.equal(cekAkses(`/${slug}/1/ubah`), 'tidak-ada', `${slug}/ubah`);
    assert.equal(cekAkses(`/${slug}/baru`), ['keuangan', 'dashboard'].includes(slug) ? 'tidak-ada' : 'ditolak', `${slug}/baru`);
  }
});

test('modul tanpa layar baca tetap "Segera tersedia di web"; modul baca tidak', async () => {
  peran('kepala_sppg');
  assert.ok(teks(await buka('/bahan-baku')).includes('Segera tersedia di web'));
  assert.ok(!teks(await buka('/penerima-manfaat')).includes('Segera tersedia di web'));
  for (const slug of ['menu-gizi', 'bahan-baku', 'pembelian-bahan', 'persetujuan-menu']) assert.ok(teks(await buka(`/${slug}`)).includes('Segera tersedia di web'), slug);
});

test('Telegram-only tidak ada di layar baca web: draf AI, undangan, share t.me, header Authorization', async () => {
  peran('admin');
  for (const slug of ['titik-layanan', 'penerima-manfaat', 'pemasok']) {
    const el = await buka(`/${slug}/1`);
    assert.ok(!/t\.me|draf|undang/i.test(tautan(el).join(' ') + tombol(el).map(teks).join(' ')), slug);
    assert.ok(panggilan.every((p) => !('Authorization' in p.headers)), `${slug}: Authorization`);
  }
});

test('menu dan guard rute konsisten: semua kartu beranda web (modul + pintasan) lolos guard rutenya, tiap profil', async () => {
  const { menuUntuk } = await import('../modules/menu.js');
  const { pintasanWeb } = await import('../beranda-web.js');
  for (const nama of Object.keys(PROFIL)) {
    setUserSesi(salin(nama));
    const user = getUser();
    for (const m of menuUntuk(user, MODUL)) assert.equal(cekAkses(`/${m.slug}`), 'boleh', `${nama}: kartu ${m.slug} ditolak guard`);
    for (const p of pintasanWeb(user)) assert.equal(cekAkses(p.href.slice(1)), 'boleh', `${nama}: pintasan ${p.href} ditolak guard`);
  }
  // Laporan BGN: tanpa baris matriks, gerbangnya aksi.* (bukan userPerms).
  setUserSesi(salin('admin'));
  assert.equal(cekAkses('/laporan-bgn'), 'boleh');
  setUserSesi(salin('ahli_gizi'));
  assert.equal(cekAkses('/laporan-bgn'), 'ditolak');
});
