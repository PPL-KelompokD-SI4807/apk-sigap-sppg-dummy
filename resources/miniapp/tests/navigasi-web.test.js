// Navigasi kerangka web: butir dibangun dari menu + pintasan (tanpa daftar tetap), jadi peran bermodul sedikit melihat sedikit butir.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { susunButir, butirAktif, pathDariHash, inisial } from '../core/navigasi-web.js';
import { PETA_IKON } from '../core/ikon-web.js';
import { menuUntuk } from '../modules/menu.js';
import { pintasanWeb } from '../beranda-web.js';
import { PROFIL, salin } from './fixtures/peta.js';

globalThis.localStorage = globalThis.localStorage ?? { getItem: () => null, setItem() {}, removeItem() {} };
const dir = fileURLToPath(new URL('../modules/', import.meta.url));
const MODUL = [];
for (const n of readdirSync(dir, { withFileTypes: true }).filter((d) => d.isDirectory()).map((d) => d.name).sort()) {
  try { readFileSync(`${dir}${n}/index.js`); } catch { continue; }
  const mod = await import(`../modules/${n}/index.js`);
  MODUL.push(...[mod.default].flat().filter(Boolean));
}
const butirUntuk = (user) => susunButir({ menu: menuUntuk(user, MODUL), pintasan: pintasanWeb(user) });

test('butir = Beranda + pintasan + menu peran; tanpa href ganda; Beranda pertama', () => {
  for (const nama of Object.keys(PROFIL)) {
    const user = salin(nama);
    const b = butirUntuk(user);
    assert.equal(b[0].href, '#/');
    assert.equal(new Set(b.map((x) => x.href)).size, b.length, `${nama}: href ganda`);
    const hrefs = [...pintasanWeb(user).map((x) => x.href), ...menuUntuk(user, MODUL).map((m) => '#/' + m.slug)];
    const harap = 1 + new Set(hrefs).size;
    assert.equal(b.length, harap, `${nama}: jumlah butir tidak sama dengan menu + pintasan`);
  }
});

test('peran dengan sedikit modul otomatis melihat sedikit butir (tidak ada daftar tetap)', () => {
  const jumlah = (n) => butirUntuk(salin(n)).length;
  assert.ok(jumlah('relawan_tanpa_unit') < jumlah('kepala_sppg'));
  assert.ok(jumlah('relawan_tanpa_unit') < jumlah('admin'));
  const tanpaUnit = butirUntuk(salin('relawan_tanpa_unit')).map((x) => x.href);
  assert.ok(!tanpaUnit.includes('#/keuangan'));
});

test('peta hak rusak: gagal tertutup, hanya Beranda', () => {
  const b = susunButir({ menu: menuUntuk({ id: 1, role: 'admin' }, MODUL), pintasan: pintasanWeb(null) });
  assert.deepEqual(b.map((x) => x.href), ['#/']);
});

test('setiap modul menu punya ikon SVG atau cadangan emoji (tidak pernah kosong)', () => {
  for (const nama of Object.keys(PROFIL)) {
    for (const b of butirUntuk(salin(nama))) assert.ok(PETA_IKON[b.kunci || 'beranda'] || b.emoji, `${nama}: ${b.href} tanpa ikon maupun emoji`);
  }
});

test('butirAktif: persis, induk terdekat, sesi, beranda, tak dikenal', () => {
  const b = butirUntuk(salin('kepala_sppg'));
  assert.equal(butirAktif('/', b), '');
  assert.equal(butirAktif('/jadwal', b), 'jadwal');
  assert.equal(butirAktif('/jadwal/12', b), 'jadwal');
  assert.equal(butirAktif('/keuangan/pencairan', b), 'keuangan');
  assert.equal(butirAktif('/sesi', b), 'sesi');
  assert.equal(butirAktif('/tidak-ada', b), null);
  assert.equal(butirAktif('/penerima-manfaat-agregat', susunButir({ menu: [{ slug: 'penerima-manfaat', title: 'P', icon: 'x' }], pintasan: [{ href: '#/penerima-manfaat-agregat', judul: 'A', ikon: 'y' }] })), 'penerima-manfaat-agregat');
});

test('pathDariHash dan inisial', () => {
  assert.equal(pathDariHash('#/jadwal/distribusi?x=1'), '/jadwal/distribusi');
  assert.equal(pathDariHash('#tgWebAppData=x'), '/');
  assert.equal(pathDariHash(''), '/');
  assert.equal(inisial('Dewi Lestari'), 'DL');
  assert.equal(inisial('Raka'), 'RA');
  assert.equal(inisial('  '), '?');
  assert.equal(inisial(null), '?');
});
