// R04-8 PR1: hanya lapisan transport (dan berkas Telegram-only/web-only yang terdaftar) boleh mengimpor klien API mentah.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('..', import.meta.url));
const IZIN = new Set([
  'core/transport.js', 'core/transport-web.js', 'core/auth-web.js',
  'core/auth.js', 'main.js', // Mini App (login initData + Bearer)
  'web-main.js', 'modules/auth-web/layar.js', 'modules/sesi/layar.js', // khusus web (api-web langsung)
]);
const POLA = /(?:from\s*|import\s*\(\s*|import\s+)['"][^'"]*\/(?:api|api-web)\.js(?:\?[^'"]*)?['"]/;

function semua(dir) {
  return readdirSync(dir).flatMap((n) => {
    const p = join(dir, n);
    if (n === 'tests' || n === 'node_modules') return [];
    return statSync(p).isDirectory() ? semua(p) : p.endsWith('.js') ? [p] : [];
  });
}

test('tidak ada impor core/api.js atau api-web.js di luar daftar izin', () => {
  const langgar = semua(ROOT)
    .map((p) => relative(ROOT, p).split(sep).join('/'))
    .filter((r) => !IZIN.has(r) && POLA.test(readFileSync(join(ROOT, r), 'utf8')));
  assert.deepEqual(langgar, []);
});

test('transport.js dan transport-web.js mengekspor nama yang dipakai modul', async () => {
  globalThis.sessionStorage = globalThis.sessionStorage ?? { getItem: () => null, setItem() {}, removeItem() {} };
  globalThis.localStorage = globalThis.localStorage ?? { getItem: () => null, setItem() {}, removeItem() {} };
  const pakai = new Set();
  for (const p of semua(ROOT)) {
    const src = readFileSync(p, 'utf8');
    for (const m of src.matchAll(/import\s*\{([^}]*)\}\s*from\s*'[^']*core\/transport\.js'|import\s*\{([^}]*)\}\s*from\s*'\.\/transport\.js'/g)) {
      (m[1] ?? m[2]).split(',').map((s) => s.trim().split(/\s+as\s+/)[0]).filter(Boolean).forEach((n) => pakai.add(n));
    }
  }
  assert.ok(pakai.size > 5);
  const tel = await import('../core/transport.js');
  const web = await import('../core/transport-web.js');
  for (const n of pakai) {
    assert.ok(n in tel, `transport.js tidak mengekspor ${n}`);
    assert.ok(n in web, `transport-web.js tidak mengekspor ${n}`);
  }
});

test('auth.js dan auth-web.js mengekspor nama yang dipakai modul/komponen', async () => {
  globalThis.sessionStorage = globalThis.sessionStorage ?? { getItem: () => null, setItem() {}, removeItem() {} };
  globalThis.localStorage = globalThis.localStorage ?? { getItem: () => null, setItem() {}, removeItem() {} };
  const pakai = new Set();
  for (const p of semua(ROOT)) {
    if (relative(ROOT, p).split(sep).join('/') === 'core/auth-web.js') continue;
    const src = readFileSync(p, 'utf8');
    for (const m of src.matchAll(/import\s*\{([^}]*)\}\s*from\s*'[^']*core\/auth\.js'/g)) {
      m[1].split(',').map((s) => s.trim().split(/\s+as\s+/)[0]).filter(Boolean).forEach((n) => pakai.add(n));
    }
  }
  assert.ok(pakai.has('getUser') && pakai.has('logout'));
  const tel = await import('../core/auth.js');
  const web = await import('../core/auth-web.js');
  for (const n of pakai) {
    assert.ok(n in tel, `auth.js tidak mengekspor ${n}`);
    assert.ok(n in web, `auth-web.js tidak mengekspor ${n}`);
  }
});
