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
