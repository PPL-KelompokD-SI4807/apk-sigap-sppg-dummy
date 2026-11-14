// R04-8 PR1: ukuran gzip bundel dijaga relatif terhadap garis dasar (+10%) dan alias transport per mode dibuktikan.
// Garis dasar: tests/fixtures/ukuran-bundel.json. Perbarui dengan UPDATE_UKURAN=1 node --test <berkas ini> (sengaja, saat bundel memang bertambah).
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { tmpdir } from 'node:os';
import { join, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { build } from 'vite';

const REPO = fileURLToPath(new URL('../../../', import.meta.url));
const DASAR = fileURLToPath(new URL('./fixtures/ukuran-bundel.json', import.meta.url));
const BATAS = 1.1;

async function bangun(mode, tambahan = {}) {
  const hasil = await build({
    configFile: join(REPO, 'vite.config.js'), root: undefined, mode, logLevel: 'silent',
    build: { write: false, ...tambahan },
  });
  const out = (Array.isArray(hasil) ? hasil : [hasil]).flatMap((r) => r.output);
  return out.map((o) => ({ nama: o.fileName, isi: o.type === 'chunk' ? o.code : o.source, chunk: o.type === 'chunk' ? o : null }));
}
/** Aset awal = html, css, entri dan chunk yang diimpor statis (tanpa chunk dinamis/lazy). */
function awal(berkas) {
  const peta = new Map(berkas.filter((b) => b.chunk).map((b) => [b.nama, b]));
  const ikut = new Set();
  const kunjungi = (n) => { if (ikut.has(n) || !peta.has(n)) return; ikut.add(n); peta.get(n).chunk.imports.forEach(kunjungi); };
  berkas.filter((b) => b.chunk?.isEntry).forEach((b) => kunjungi(b.nama));
  return berkas.filter((b) => !b.chunk || ikut.has(b.nama));
}
const gz = (berkas) => berkas.reduce((n, b) => n + gzipSync(b.isi).length, 0);

test('ukuran gzip bundel Mini App dan web tidak melebihi garis dasar + 10%', async () => {
  // miniapp = semua berkas; web = aset awal (chunk lazy beranda/registry tidak dihitung); web_total = semua berkas web.
  const web = await bangun('web');
  const ukuran = { miniapp: gz(await bangun('production')), web: gz(awal(web)), web_total: gz(web) };
  if (process.env.UPDATE_UKURAN) { writeFileSync(DASAR, `${JSON.stringify(ukuran, null, 2)}\n`); return; }
  const dasar = JSON.parse(readFileSync(DASAR, 'utf8'));
  for (const k of Object.keys(dasar)) assert.ok(ukuran[k] <= dasar[k] * BATAS, `${k}: ${ukuran[k]} B > ${Math.round(dasar[k] * BATAS)} B (dasar ${dasar[k]})`);
});

test('alias Vite: mode web memakai transport-web (tanpa Bearer/localStorage), mode Mini App memakai api.js', async () => {
  const tmp = mkdtempSync(join(tmpdir(), 'sigap-transport-'));
  const entri = join(tmp, 'entri.js');
  const transport = join(REPO, 'resources/miniapp/core/transport.js').split(sep).join('/');
  writeFileSync(entri, `import { api } from '${transport}';\nwindow.__api = api;\n`);
  try {
    const web = (await bangun('web', { rollupOptions: { input: entri } })).map((b) => b.isi).join('\n');
    assert.ok(!/localStorage|Authorization|sigap_token/.test(web), 'bundel web memuat jejak Bearer/localStorage');
    assert.ok(/X-CSRF-Token/.test(web), 'bundel web tidak memuat klien cookie+CSRF');
    const tel = (await bangun('production', { rollupOptions: { input: entri } })).map((b) => b.isi).join('\n');
    assert.ok(/localStorage/.test(tel) && /Authorization/.test(tel), 'bundel Mini App kehilangan klien Bearer');
    assert.ok(!/X-CSRF-Token/.test(tel), 'bundel Mini App memuat klien web');
  } finally { rmSync(tmp, { recursive: true, force: true }); }
});

test('pindai bundel web: seluruh modul (registry + beranda + komponen) tanpa jejak Telegram/Bearer/localStorage token', async () => {
  const tmp = mkdtempSync(join(tmpdir(), 'sigap-pindai-'));
  const entri = join(tmp, 'entri.js');
  const mini = join(REPO, 'resources/miniapp').split(sep).join('/');
  writeFileSync(entri, [
    `import { registerAllRoutes } from '${mini}/modules/registry.js';`,
    `import { getUser, logout, ubahPassword } from '${mini}/core/auth.js';`,
    `import { api } from '${mini}/core/transport.js';`,
    `import { renderHome } from '${mini}/home.js';`,
    `import '${mini}/components/sesi-view.js';`,
    `import '${mini}/components/sppg-picker.js';`,
    `import '${mini}/components/password-view.js';`,
    `window.__x = [registerAllRoutes, getUser, logout, ubahPassword, api, renderHome];`,
  ].join('\n'));
  try {
    const asli = (await bangun('web')).map((b) => b.isi).join('\n'); // bundel web nyata (web.html)
    const lebar = (await bangun('web', { rollupOptions: { input: entri } })).map((b) => b.isi).join('\n');
    for (const [nama, kode] of [['web.html', asli], ['semua-modul', lebar]]) {
      assert.ok(!/localStorage|sessionStorage/.test(kode), `${nama}: memuat localStorage/sessionStorage`);
      assert.ok(kode.includes('X-SPPG-Id'), `${nama}: header SPPG aktif (memori) tidak terpasang`);
      // Celah yang diketahui: fetchLampiranBlob (components/photo-upload.js) masih memakai Bearer + credentials 'omit' untuk gambar privat.
      // Diperbaiki di PR5 (foto web, T3); saat itu batas ini menjadi 0. Sampai saat itu tidak boleh bertambah dari 1 (registry lazy ikut memuatnya).
      const batasAuth = 1;
      assert.ok((kode.match(/Authorization/g) || []).length <= batasAuth, `${nama}: header Authorization melebihi batas ${batasAuth}`);
      assert.ok(!/telegram-web-app/.test(kode), `${nama}: memuat telegram-web-app`);
      assert.ok(!/sigap_device_id|sigap_token|init_data/.test(kode), `${nama}: memuat device id/token/initData`);
    }
    assert.ok(lebar.length > 100000, 'entri pindai tidak memuat modul kerja');
    assert.ok(/\/auth\/me/.test(lebar) || /muatSesi/.test(lebar), 'adaptor auth web tidak terpasang');
  } finally { rmSync(tmp, { recursive: true, force: true }); }
});
