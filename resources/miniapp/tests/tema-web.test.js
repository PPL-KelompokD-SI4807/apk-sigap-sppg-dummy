// Tema web siang/malam: logika pemilihan (sistem/terang/gelap), penyimpanan, dan penulisan ke akar dokumen.
// Jalankan: node --test resources/miniapp/tests/*.test.js
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import {
  PILIHAN, KUNCI, normalisasi, tentukanTema, berikutnya, bacaPilihan, simpanPilihan, terapkanTema, labelPilihan,
} from '../core/theme-web.js';

const memori = () => { const m = new Map(); return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, String(v)), m }; };
const akar = () => ({ dataset: {}, style: {} });

test('sistem mengikuti prefers-color-scheme: gelap -> dark, terang -> light', () => {
  assert.equal(tentukanTema('sistem', true), 'dark');
  assert.equal(tentukanTema('sistem', false), 'light');
});

test('pilihan eksplisit menang atas sistem', () => {
  assert.equal(tentukanTema('terang', true), 'light');
  assert.equal(tentukanTema('gelap', false), 'dark');
});

test('nilai asing/rusak dianggap sistem; bawaan tanpa simpanan = sistem', () => {
  assert.equal(normalisasi('ungu'), 'sistem');
  assert.equal(normalisasi(null), 'sistem');
  assert.equal(tentukanTema('ungu', true), 'dark');
  assert.equal(bacaPilihan(memori()), 'sistem');
  assert.equal(bacaPilihan(null), 'sistem');
});

test('pilihan tersimpan dibaca kembali; nilai rusak di penyimpanan diabaikan', () => {
  const g = memori();
  assert.equal(simpanPilihan('gelap', g), true);
  assert.equal(g.m.get(KUNCI), 'gelap');
  assert.equal(bacaPilihan(g), 'gelap');
  g.m.set(KUNCI, '<script>');
  assert.equal(bacaPilihan(g), 'sistem');
});

test('penyimpanan yang melempar galat (mode privat) tidak merusak: kembali ke sistem / gagal senyap', () => {
  const rusak = { getItem() { throw new Error('SecurityError'); }, setItem() { throw new Error('QuotaExceededError'); } };
  assert.equal(bacaPilihan(rusak), 'sistem');
  assert.equal(simpanPilihan('terang', rusak), false);
});

test('putaran sakelar: sistem -> terang -> gelap -> sistem', () => {
  assert.deepEqual(PILIHAN, ['sistem', 'terang', 'gelap']);
  assert.equal(berikutnya('sistem'), 'terang');
  assert.equal(berikutnya('terang'), 'gelap');
  assert.equal(berikutnya('gelap'), 'sistem');
  assert.equal(labelPilihan('terang'), 'Siang');
  assert.equal(labelPilihan('gelap'), 'Malam');
});

test('terapkanTema menulis data-theme (light|dark), pilihan, dan color-scheme di akar', () => {
  const a = akar();
  assert.equal(terapkanTema('sistem', { root: a, gelapSistem: true }), 'dark');
  assert.deepEqual([a.dataset.theme, a.dataset.temaPilihan, a.style.colorScheme], ['dark', 'sistem', 'dark']);
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
  for (const simpanan of [null, 'sistem', 'terang', 'gelap', 'rusak']) {
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
    assert.equal(pilihanSaatIni(), 'sistem');
    assert.equal(tulis, 0, 'tanggapiStorage tidak boleh menulis ke penyimpanan (cegah loop)');
  } finally { delete globalThis.localStorage; }
});
