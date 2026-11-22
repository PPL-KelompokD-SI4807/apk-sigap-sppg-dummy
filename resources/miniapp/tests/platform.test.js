// Tes adapter platform (R04-6): mode telegram tidak berubah, mode web tidak menyentuh Telegram.WebApp.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const tg = { initData: 'query_id=ABC&hash=x', HapticFeedback: { calls: [], notificationOccurred(k) { this.calls.push(k); }, impactOccurred() {} } };
globalThis.window = { Telegram: { WebApp: tg } };

const platform = await import('../core/platform.js');
const telegram = await import('../core/telegram.js');

test('bawaan telegram: getTg dan isNative seperti sebelumnya', () => {
  assert.equal(platform.getPlatform(), 'telegram');
  assert.equal(platform.isWeb(), false);
  assert.equal(telegram.getTg(), tg);
  assert.equal(telegram.isNative(), true);
  telegram.haptic('success');
  assert.deepEqual(tg.HapticFeedback.calls, ['success']);
});

test('mode web: tanpa Telegram.WebApp walau objeknya ada, haptic no-op', () => {
  platform.setPlatform('web');
  assert.equal(platform.isWeb(), true);
  assert.equal(telegram.getTg(), null);
  assert.equal(telegram.isNative(), false);
  telegram.haptic('error');
  assert.deepEqual(tg.HapticFeedback.calls, ['success']);
  platform.setPlatform('telegram');
  assert.equal(telegram.isNative(), true);
});

test('platform tidak dikenal ditolak', () => {
  assert.throws(() => platform.setPlatform('desktop'));
  assert.equal(platform.getPlatform(), 'telegram');
});

test('web.html tanpa skrip Telegram/pihak ketiga; index.html Mini App tetap memuatnya', () => {
  const web = readFileSync(fileURLToPath(new URL('../web.html', import.meta.url)), 'utf8');
  const mini = readFileSync(fileURLToPath(new URL('../index.html', import.meta.url)), 'utf8');
  assert.doesNotMatch(web, /telegram/i);
  assert.equal([...web.matchAll(/<script[^>]*src="([^"]+)"/g)].every((m) => m[1].startsWith('./')), true);
  assert.match(mini, /telegram-web-app\.js/);
});

test('kode web tidak mengimpor api.js (Bearer/localStorage) dan tidak memakai storage/document.cookie', () => {
  for (const f of ['../web-main.js', '../core/api-web.js', '../modules/auth-web/layar.js', '../modules/auth-web/logika.js', '../modules/sesi/layar.js', '../modules/sesi/logika.js']) {
    let src;
    try { src = readFileSync(fileURLToPath(new URL(f, import.meta.url)), 'utf8'); } catch { continue; }
    src = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/\s\/\/ .*$/gm, '');
    assert.doesNotMatch(src, /from ['"][./]*(core\/)?api\.js['"]/, `${f} mengimpor api.js`);
    assert.doesNotMatch(src, /core\/auth\.js/, `${f} mengimpor auth.js`);
    assert.doesNotMatch(src, /localStorage|sessionStorage|document\.cookie|telegram-web-app/, `${f} memakai storage/cookie`);
  }
});
