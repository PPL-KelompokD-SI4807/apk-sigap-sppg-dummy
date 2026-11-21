// Banner peringatan jam berangkat jadwal distribusi: teks server sebagai teks, hanya bila terisi.
import test from 'node:test';
import assert from 'node:assert/strict';

class Node_ {}
const buatEl = (tag) => Object.assign(new Node_(), {
  tag, attrs: {}, listeners: {}, anak: [], className: '', dataset: {}, style: { cssText: '' },
  setAttribute(k, v) { this.attrs[k] = String(v); },
  addEventListener(n, fn) { this.listeners[n] = fn; },
  append(...xs) { this.anak.push(...xs); },
});
globalThis.Node = Node_;
globalThis.document = { createElement: buatEl, createTextNode: (t) => Object.assign(new Node_(), { teks: String(t) }) };
globalThis.window = globalThis.window ?? { addEventListener() {}, location: { hash: '' } };

const { bannerPeringatan } = await import('../modules/jadwal/index.js');

test('tanpa peringatan: tidak ada banner', () => {
  assert.equal(bannerPeringatan({ peringatan: null }), null);
  assert.equal(bannerPeringatan({ peringatan: '' }), null);
  assert.equal(bannerPeringatan({}), null);
});

test('peringatan terisi: banner kuning .notice berisi teks (bukan HTML)', () => {
  const el = bannerPeringatan({ peringatan: '<b>Berangkat 06:00</b> sebelum masak' });
  assert.equal(el.className, 'notice');
  assert.equal(el.anak[0].teks, '⚠ <b>Berangkat 06:00</b> sebelum masak');
});
