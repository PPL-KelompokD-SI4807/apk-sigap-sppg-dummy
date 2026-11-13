// Tes issue #77: label sesi (dari User-Agent, tak tepercaya) selalu dirender sebagai teks, bukan HTML.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

class Node_ {}
const buatEl = (tag) => {
  const el = new Node_();
  let teks = '';
  Object.assign(el, {
    tag, attrs: {}, listeners: {}, anak: [], className: '', dataset: {}, style: { cssText: '' },
    setAttribute(k, v) { this.attrs[k] = String(v); },
    addEventListener(n, fn) { this.listeners[n] = fn; },
    append(...xs) { this.anak.push(...xs); },
  });
  // textContent seperti DOM sungguhan: mengganti semua anak dengan SATU text node (tak pernah mengurai HTML).
  Object.defineProperty(el, 'textContent', {
    get: () => teks,
    set: (v) => { teks = String(v); el.anak = [Object.assign(new Node_(), { teks })]; },
  });
  Object.defineProperty(el, 'innerHTML', { get() { throw new Error('innerHTML tidak boleh dibaca'); }, set() { throw new Error('innerHTML tidak boleh ditulis'); } });
  return el;
};
globalThis.Node = Node_;
globalThis.document = { createElement: buatEl, createTextNode: (t) => Object.assign(new Node_(), { teks: String(t) }) };

const { teksLabelSesi, elemenLabelSesi, LABEL_SESI_KOSONG } = await import('../core/sesi-label.js');

const JAHAT = [
  '<img src=x onerror=alert(1)>',
  '<script>alert(document.cookie)</script>',
  '"><svg/onload=alert(1)>',
  'Chrome <b>tebal</b> &amp; Linux',
];

test('elemenLabelSesi: label berisi HTML tampil sebagai teks apa adanya (satu text node, tanpa elemen anak)', () => {
  for (const label of JAHAT) {
    const el = elemenLabelSesi({ perangkat: label }, { kelas: 'rc-title' });
    assert.equal(el.tag, 'span');
    assert.equal(el.className, 'rc-title');
    assert.equal(el.textContent, label.replace(/\s+/g, ' ').trim());
    assert.equal(el.anak.length, 1);
    assert.ok(el.anak[0] instanceof Node_ && 'teks' in el.anak[0], 'anak adalah text node');
    assert.equal(el.anak[0].teks, el.textContent);
    assert.equal(el.anak.some((a) => a.tag), false, 'tidak ada elemen hasil urai HTML');
  }
});

test('teksLabelSesi: fallback, karakter kontrol dibuang, panjang dibatasi, tipe bukan string aman', () => {
  assert.equal(teksLabelSesi(null), LABEL_SESI_KOSONG);
  assert.equal(teksLabelSesi({ perangkat: '' }), LABEL_SESI_KOSONG);
  assert.equal(teksLabelSesi({ perangkat: '  \n\t ' }), LABEL_SESI_KOSONG);
  assert.equal(teksLabelSesi('Firefox · Linux'), 'Firefox · Linux');
  assert.equal(teksLabelSesi('A\u0000B‮c d'), 'A B c d'.replace('B c', 'B c'));
  assert.equal(teksLabelSesi('x'.repeat(500)).length, 120);
  assert.equal(teksLabelSesi({ perangkat: 12345 }), '12345');
  assert.equal(teksLabelSesi({ perangkat: { toString: () => '<i>x</i>' } }), '<i>x</i>');
});

test('layar sesi memakai helper dan tidak menyentuh innerHTML (pemindai statis)', () => {
  const src = readFileSync(fileURLToPath(new URL('../components/sesi-view.js', import.meta.url)), 'utf8');
  assert.match(src, /elemenLabelSesi\(s,/);
  assert.doesNotMatch(src.replace(/\/\/.*$/gm, ''), /innerHTML|outerHTML|insertAdjacentHTML/);
  assert.doesNotMatch(src, /s\.perangkat\s*\|\|/, 'label mentah tidak dirender langsung');
  const helper = readFileSync(fileURLToPath(new URL('../core/sesi-label.js', import.meta.url)), 'utf8').replace(/\/\/.*$/gm, '');
  assert.match(helper, /textContent\s*=/);
  assert.doesNotMatch(helper, /innerHTML|outerHTML|insertAdjacentHTML/);
});
