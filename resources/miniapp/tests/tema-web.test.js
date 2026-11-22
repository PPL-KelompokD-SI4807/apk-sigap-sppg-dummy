// Tema web siang/malam: dua keadaan (terang/gelap); tanpa pilihan = ikut sistem. Logika, penyimpanan, penulisan ke akar dokumen.
// Jalankan: node --test resources/miniapp/tests/*.test.js
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import {
  PILIHAN, KUNCI, normalisasi, tentukanTema, berikutnya, bacaPilihan, simpanPilihan, terapkanTema, labelTema,
} from '../core/theme-web.js';

const memori = () => { const m = new Map(); return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, String(v)), m }; };
const akar = () => ({ dataset: {}, style: {} });

test('tanpa pilihan (null) mengikuti prefers-color-scheme: gelap -> dark, terang -> light', () => {
  assert.equal(tentukanTema(null, true), 'dark');
  assert.equal(tentukanTema(null, false), 'light');
});

test('pilihan eksplisit menang atas sistem', () => {
  assert.equal(tentukanTema('terang', true), 'light');
  assert.equal(tentukanTema('gelap', false), 'dark');
});

test("nilai 'sistem' lama, asing, atau rusak = belum memilih (null) dan ikut sistem tanpa galat", () => {
  for (const v of ['sistem', 'ungu', '', null, undefined, 42, '<script>']) {
    assert.equal(normalisasi(v), null, String(v));
    assert.equal(tentukanTema(v, true), 'dark');
    assert.equal(tentukanTema(v, false), 'light');
  }
  assert.equal(bacaPilihan(memori()), null);
  assert.equal(bacaPilihan(null), null);
});

test('pilihan tersimpan dibaca kembali; nilai lama/rusak di penyimpanan dianggap belum memilih', () => {
  const g = memori();
  assert.equal(simpanPilihan('gelap', g), true);
  assert.equal(g.m.get(KUNCI), 'gelap');
  assert.equal(bacaPilihan(g), 'gelap');
  g.m.set(KUNCI, 'sistem'); // simpanan versi sebelumnya
  assert.equal(bacaPilihan(g), null);
  g.m.set(KUNCI, '<script>');
  assert.equal(bacaPilihan(g), null);
});

test("'sistem'/null tidak pernah ditulis ke penyimpanan (hanya terang/gelap)", () => {
  const g = memori();
  assert.equal(simpanPilihan('sistem', g), false);
  assert.equal(simpanPilihan(null, g), false);
  assert.equal(g.m.size, 0);
});

test('penyimpanan yang melempar galat (mode privat) tidak merusak: ikut sistem / gagal senyap', () => {
  const rusak = { getItem() { throw new Error('SecurityError'); }, setItem() { throw new Error('QuotaExceededError'); } };
  assert.equal(bacaPilihan(rusak), null);
  assert.equal(simpanPilihan('terang', rusak), false);
});

test('sakelar dua keadaan: dark -> terang, light -> gelap; label Siang/Malam; tanpa kata Sistem', () => {
  assert.deepEqual(PILIHAN, ['terang', 'gelap']);
  assert.equal(berikutnya('dark'), 'terang');
  assert.equal(berikutnya('light'), 'gelap');
  assert.equal(labelTema('light'), 'Siang');
  assert.equal(labelTema('dark'), 'Malam');
  const sumber = ['../components/sakelar-tema-web.js', '../core/theme-web.js'].map((f) => readFileSync(fileURLToPath(new URL(f, import.meta.url)), 'utf8').replace(/\/\/.*$/gm, '').replace(/\/\*[\s\S]*?\*\//g, ''));
  for (const k of sumber) assert.doesNotMatch(k, /Sistem/, 'kata "Sistem" tidak boleh ada di teks antarmuka');
});

test('terapkanTema menulis data-theme (light|dark), pilihan, dan color-scheme di akar', () => {
  const a = akar();
  assert.equal(terapkanTema(null, { root: a, gelapSistem: true }), 'dark');
  assert.deepEqual([a.dataset.theme, a.dataset.temaPilihan, a.style.colorScheme], ['dark', 'otomatis', 'dark']);
  assert.equal(terapkanTema('terang', { root: a, gelapSistem: true }), 'light');
  assert.deepEqual([a.dataset.theme, a.dataset.temaPilihan, a.style.colorScheme], ['light', 'terang', 'light']);
});

test('jalur Telegram tidak disentuh: core/theme.js tak diimpor kode web, theme-web tak diimpor main.js (Mini App)', () => {
  const baca = (f) => readFileSync(fileURLToPath(new URL(f, import.meta.url)), 'utf8').replace(/\/\/.*$/gm, '');
  assert.doesNotMatch(baca('../web-main.js'), /core\/theme\.js/);
  assert.doesNotMatch(baca('../main.js'), /theme-web|sakelar-tema/);
  assert.match(baca('../main.js'), /core\/theme\.js/);
});

// ---- tema-awal.js (skrip klasik sinkron anti-kedip) harus sama dengan tentukanTema() ----
import vm from 'node:vm';
const SKRIP = readFileSync(fileURLToPath(new URL('../tema-awal.js', import.meta.url)), 'utf8');
function jalankanSkrip({ simpanan, gelapSistem, rusak = false }) {
  const root = { attrs: {}, style: {}, setAttribute(k, v) { this.attrs[k] = v; } };
  const gudang = rusak ? { getItem() { throw new Error('SecurityError'); } } : { getItem: (k) => (k === KUNCI ? simpanan : null) };
  vm.runInNewContext(SKRIP, { document: { documentElement: root }, globalThis: { localStorage: gudang, matchMedia: () => ({ matches: gelapSistem }) } });
  return root;
}

test('tema-awal.js: hasil sama dengan tentukanTema untuk semua kombinasi pilihan x sistem', () => {
  for (const simpanan of [null, 'sistem', 'terang', 'gelap', 'rusak', '']) {
    for (const gelapSistem of [true, false]) {
      const r = jalankanSkrip({ simpanan, gelapSistem });
      assert.equal(r.attrs['data-theme'], tentukanTema(simpanan, gelapSistem), `${simpanan}/${gelapSistem}`);
      assert.equal(r.style.colorScheme, r.attrs['data-theme']);
    }
  }
});

test('tema-awal.js: penyimpanan diblokir -> tidak melempar galat dan tidak menulis apa pun (theme-web.js menyusul)', () => {
  const r = jalankanSkrip({ rusak: true, gelapSistem: true });
  assert.deepEqual(r.attrs, {});
});

test('web.html memuat tema-awal.js sinkron (tanpa type=module/defer/async) SEBELUM web-main.js', () => {
  const html = readFileSync(fileURLToPath(new URL('../web.html', import.meta.url)), 'utf8');
  const tag = html.match(/<script[^>]*tema-awal\.js[^>]*>/)[0];
  assert.doesNotMatch(tag, /type=|defer|async/);
  assert.ok(html.indexOf('tema-awal.js') < html.indexOf('web-main.js'));
  assert.ok(html.indexOf('tema-awal.js') < html.indexOf('<body'));
});

// ---- W-01: jalur skrip klasik harus absolut (alamat "/" dilayani lewat rewrite ke /web/index.html, jalur relatif -> 404) ----
const skripKlasik = (html) => [...html.matchAll(/<script\b([^>]*)>/gi)].map((m) => m[1]).filter((a) => !/\btype\s*=\s*["']?module/i.test(a));
const srcDari = (atribut) => (/\bsrc\s*=\s*["']?([^"'\s>]+)/i.exec(atribut) || [])[1];
const relatif = (src) => src !== undefined && !/^(\/(?!\/)|https?:)/i.test(src);

test('web.html: skrip klasik (non-module) tidak boleh berjalur relatif', () => {
  const html = readFileSync(fileURLToPath(new URL('../web.html', import.meta.url)), 'utf8');
  const klasik = skripKlasik(html).map(srcDari).filter((s) => s !== undefined);
  assert.ok(klasik.length >= 1, 'tema-awal.js tidak ditemukan sebagai skrip klasik');
  for (const src of klasik) assert.ok(!relatif(src), `skrip klasik berjalur relatif: ${src} (diminta ke /${src.replace(/^\.\//, '')} saat alamat "/")`);
  assert.ok(klasik.includes('/web/tema-awal.js'));
});

test('penjaga jalur relatif mengenali pelanggaran (./x.js, x.js, ../x.js) dan menerima /web/x.js, https://x', () => {
  for (const s of ['./tema-awal.js', 'tema-awal.js', '../tema-awal.js']) assert.ok(relatif(s), s);
  for (const s of ['/web/tema-awal.js', 'https://cdn.example/x.js']) assert.ok(!relatif(s), s);
  assert.deepEqual(skripKlasik('<script type="module" src="./a.js"></script><script src="./b.js"></script>').map(srcDari), ['./b.js']);
});

test('build web: index.html hasil build tidak memuat skrip klasik berjalur relatif dan tema-awal.js ikut diemit', async () => {
  const { build } = await import('vite');
  const REPO = fileURLToPath(new URL('../../../', import.meta.url));
  const hasil = await build({ configFile: `${REPO}vite.config.js`, root: undefined, mode: 'web', logLevel: 'silent', build: { write: false } });
  const out = (Array.isArray(hasil) ? hasil : [hasil]).flatMap((r) => r.output);
  const indeks = out.find((o) => o.fileName === 'index.html');
  assert.ok(indeks, 'index.html tidak diemit');
  const src = skripKlasik(String(indeks.source)).map(srcDari).filter((s) => s !== undefined);
  assert.ok(src.length >= 1);
  for (const s of src) assert.ok(!relatif(s), `build memuat skrip klasik berjalur relatif: ${s}`);
  assert.ok(src.includes('/web/tema-awal.js'));
  assert.ok(out.some((o) => o.fileName === 'tema-awal.js'), 'tema-awal.js tidak diemit');
});

// ---- W-05: tema sinkron antar tab lewat peristiwa storage ----
import { tanggapiStorage, initTemaWeb, pilihanSaatIni } from '../core/theme-web.js';

test('storage: perubahan kunci tema dari tab lain diterapkan tanpa menulis balik; kunci lain diabaikan', () => {
  const g = memori();
  let tulis = 0;
  const asli = g.setItem; g.setItem = (...a) => { tulis++; return asli(...a); };
  globalThis.localStorage = g;
  try {
    g.m.set(KUNCI, 'terang');
    initTemaWeb();
    assert.equal(pilihanSaatIni(), 'terang');
    g.m.set(KUNCI, 'gelap'); // tab lain menulis
    assert.equal(tanggapiStorage({ key: 'kunci_lain', newValue: 'x' }), false);
    assert.equal(pilihanSaatIni(), 'terang');
    assert.equal(tanggapiStorage({ key: KUNCI, newValue: 'gelap' }), true);
    assert.equal(pilihanSaatIni(), 'gelap');
    assert.equal(tanggapiStorage({ key: KUNCI, newValue: 'gelap' }), false, 'nilai sama tidak diterapkan ulang');
    g.m.delete(KUNCI); // clear()/removeItem dari tab lain
    assert.equal(tanggapiStorage({ key: null }), true);
    assert.equal(pilihanSaatIni(), null);
    g.m.set(KUNCI, 'sistem'); // nilai lama dari tab versi sebelumnya: tetap belum memilih, tanpa galat
    assert.equal(tanggapiStorage({ key: KUNCI, newValue: 'sistem' }), false);
    assert.equal(pilihanSaatIni(), null);
    assert.equal(tulis, 0, 'tanggapiStorage tidak boleh menulis ke penyimpanan (cegah loop)');
  } finally { delete globalThis.localStorage; }
});
