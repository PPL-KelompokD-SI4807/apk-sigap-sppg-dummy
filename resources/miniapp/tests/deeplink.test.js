// Tes unit parser deep link ?r= : node --test resources/miniapp/tests/
import test from 'node:test';
import assert from 'node:assert/strict';
import { parseRuteDeepLink } from '../core/deeplink.js';
import { parseSppgHint } from '../modules/persetujuan-menu/logika.js';

const daftar = ['/persetujuan-menu', '/keuangan/pengeluaran', '/bahan-baku'];
const ada = (p) => daftar.includes(p) || /^\/persetujuan-menu\/[^/]+$/.test(p);

test('rute valid terdaftar diterima', () => {
  assert.equal(parseRuteDeepLink('?r=/persetujuan-menu', ada), '/persetujuan-menu');
  assert.equal(parseRuteDeepLink('?r=%2Fpersetujuan-menu%2F12', ada), '/persetujuan-menu/12');
  assert.equal(parseRuteDeepLink('?r=/bahan-baku/', ada), '/bahan-baku');
});

test('rute tak terdaftar, kosong, atau tanpa r -> null', () => {
  assert.equal(parseRuteDeepLink('?r=/tidak-ada', ada), null);
  assert.equal(parseRuteDeepLink('?r=', ada), null);
  assert.equal(parseRuteDeepLink('?x=1', ada), null);
  assert.equal(parseRuteDeepLink('?r=/persetujuan-menu', undefined), null);
});

test('injeksi ditolak: //, skema, .., huruf besar, spasi, query/hash', () => {
  for (const r of ['//evil.com', '/a//b', 'javascript:alert(1)', 'https://evil.com', '/../x', '/Persetujuan-Menu',
    '/persetujuan-menu?x=1', '/persetujuan-menu#a', '/persetujuan menu', 'persetujuan-menu', '/a\\b', '/%2e%2e/x']) {
    assert.equal(parseRuteDeepLink('?r=' + encodeURIComponent(r), () => true), null, r);
  }
});

test('panjang berlebih ditolak', () => {
  assert.equal(parseRuteDeepLink('?r=/' + 'a'.repeat(81), () => true), null);
  assert.equal(parseRuteDeepLink('?r=/' + 'a'.repeat(80), () => true), '/' + 'a'.repeat(80));
});

test('kombinasi dengan ?sppg tidak saling mengganggu', () => {
  const s = '?r=/persetujuan-menu&sppg=3';
  assert.equal(parseRuteDeepLink(s, ada), '/persetujuan-menu');
  assert.equal(parseSppgHint(s, ''), 3);
  assert.equal(parseRuteDeepLink('?sppg=3', ada), null);
});
