// Tes navigasi kembali (semua peran) dan input kamera/galeri. Jalankan: node --test resources/miniapp/tests/*.test.js
import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, existsSync, readFileSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join } from 'node:path';
import { NAMA_PROFIL, salin } from './fixtures/peta.js';
import { setUserSesi } from '../core/auth.js';
import { route, cekAkses, jalurKembali } from '../core/router.js';

const akar = join(dirname(fileURLToPath(import.meta.url)), '..');
const rootModul = join(akar, 'modules');
const MODUL = [];
{
  const seen = new Set();
  for (const nama of readdirSync(rootModul, { withFileTypes: true }).filter((d) => d.isDirectory() && existsSync(join(rootModul, d.name, 'index.js'))).map((d) => d.name).sort()) {
    const mod = await import(pathToFileURL(join(rootModul, nama, 'index.js')).href);
    for (const m of [mod.default].flat().filter(Boolean)) { if (!seen.has(m.slug)) { seen.add(m.slug); MODUL.push(m); } }
  }
}
MODUL.forEach((m) => m.register?.());
route('/', () => {}, {});

test('kembali dari layar mana pun berakhir di beranda dalam langkah terbatas, tanpa loop, untuk semua peran', () => {
  assert.equal(NAMA_PROFIL.length >= 8, true);
  for (const peran of NAMA_PROFIL) {
    setUserSesi(salin(peran));
    for (const m of MODUL) {
      for (const sub of ['', '/baru', '/7', '/7/ubah', '/7/kebutuhan', '/7/sesi']) {
        let p = `/${m.slug}${sub}`;
        const jejak = new Set();
        for (let i = 0; p !== '/'; i++) {
          assert.ok(i < 10 && !jejak.has(p), `${peran} ${m.slug}${sub}: loop kembali di ${p}`);
          jejak.add(p);
          const next = jalurKembali(p);
          assert.notEqual(next, p, `${peran}: kembali dari ${p} tidak bergerak`);
          if (next !== '/') assert.equal(cekAkses(next), 'boleh', `${peran}: induk ${next} dari ${p} harus bisa dibuka`);
          p = next;
        }
      }
    }
  }
});

test('kasus laporan: Data SPPG (detail sendiri) dan Jadwal (relawan distribusi) kembali ke beranda, bukan memantul', () => {
  for (const peran of NAMA_PROFIL) {
    setUserSesi(salin(peran));
    const u = salin(peran);
    if (cekAkses('/sppg') === 'boleh' && u.sppg_id) {
      const t = jalurKembali(`/sppg/${u.sppg_id}`);
      assert.ok(t === '/' || cekAkses(t) === 'boleh', peran);
      assert.notEqual(t, '/sppg', `${peran}: /sppg hanya mengalihkan ke detail, tidak boleh jadi tujuan kembali`);
    }
  }
  setUserSesi(salin('relawan_distribusi'));
  if (cekAkses('/jadwal/distribusi') === 'boleh') assert.equal(jalurKembali('/jadwal/distribusi'), '/');
});

test('detail menuju daftar bila daftar bisa dibuka (peran dengan hak daftar)', () => {
  setUserSesi(salin('admin'));
  assert.equal(jalurKembali('/bahan-baku/7'), '/bahan-baku');
  assert.equal(jalurKembali('/bahan-baku/baru'), '/bahan-baku');
});

test('input kamera: capture=environment tanpa multiple; galeri tanpa capture (multiple hanya di galeri)', () => {
  const src = readFileSync(join(akar, 'components', 'photo-upload.js'), 'utf8');
  const cam = src.match(/const cam = h\('input'[^\n]*\n/)[0];
  const gal = src.match(/const gal = h\('input'[^\n]*\n/)[0];
  assert.match(cam, /capture: 'environment'/);
  assert.doesNotMatch(cam, /multiple/);
  assert.match(cam, /accept: 'image\/\*'/);
  assert.doesNotMatch(gal, /capture/);
  assert.match(gal, /multiple: max > 1/);
  assert.match(src, /'Ambil foto'/);
  assert.match(src, /'Pilih dari galeri'/);
});
