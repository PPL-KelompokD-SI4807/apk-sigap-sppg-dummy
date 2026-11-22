// Merek web: logo BGN (img, tanpa atribut style) dan penanda akademik WAJIB di layar Masuk dan di puncak kolom isi setelah masuk.
// Tim tidak berizin memakai lambang BGN; tes ini mencegah penanda terhapus/dikecilkan tanpa sengaja.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

class Node_ {}
const buatEl = (tag) => {
  const el = new Node_();
  Object.assign(el, {
    tag, attrs: {}, listeners: {}, anak: [], className: '', dataset: {}, style: { cssText: '' }, hidden: false,
    setAttribute(k, v) { this.attrs[k] = String(v); },
    addEventListener(n, fn) { this.listeners[n] = fn; },
    append(...xs) { this.anak.push(...xs); },
    replaceChildren(...xs) { this.anak = [...xs]; },
    focus() {},
  });
  return el;
};
globalThis.Node = Node_;
globalThis.document = { createElement: buatEl, createTextNode: (t) => Object.assign(new Node_(), { teks: String(t) }), getElementById: () => null, title: '', body: { classList: { add() {}, remove() {} } } };
globalThis.window = globalThis.window ?? {};

const { tampilkanMasukWeb } = await import('../modules/auth-web/layar.js');
const { TEKS_PENANDA_MASUK, TEKS_PENANDA_DALAM, logoBgn, penandaAkademik } = await import('../core/merek-web.js');

const telusur = (n, f) => { if (n instanceof Node_) { f(n); (n.anak || []).forEach((x) => telusur(x, f)); } };
const teks = (n) => (n.teks ?? '') + (n.anak || []).map(teks).join('');
const cari = (n, f) => { const h = []; telusur(n, (x) => { if (f(x)) h.push(x); }); return h; };

test('layar Masuk memuat penanda akademik tepat setelah subjudul, dan logo BGN sebagai <img> dekoratif', () => {
  const el = buatEl('div');
  tampilkanMasukWeb(el, { klien: {} }).destroy();
  const merek = cari(el, (x) => x.className === 'login-brand')[0];
  assert.ok(merek, 'blok merek tidak ditemukan');
  assert.deepEqual(merek.anak.map(teks).slice(1), ['SiGAP SPPG', 'Sistem Informasi Dapur MBG', 'Proyek akademik. Bukan sistem resmi Badan Gizi Nasional.']);
  assert.equal(TEKS_PENANDA_MASUK, 'Proyek akademik. Bukan sistem resmi Badan Gizi Nasional.');
  const penanda = merek.anak[3];
  assert.match(penanda.className, /penanda-akademik/);
  assert.doesNotMatch(penanda.className, /small|muted/, 'penanda tidak boleh dibuat samar/kecil');
  const img = merek.anak[0];
  assert.equal(img.tag, 'img');
  assert.match(img.className, /logo-besar/);
  assert.equal(img.attrs.alt, '');
  assert.equal(img.attrs['aria-hidden'], 'true');
  assert.equal(img.attrs.width, '56');
  assert.equal(img.attrs.height, '56');
  assert.match(img.attrs.src, /logo-bgn.*\.webp$/);
  assert.equal(img.style.cssText, '', 'CSP: tanpa atribut style');
});

test('logoBgn kecil (bar atas) dan penandaAkademik ringkas (puncak kolom isi)', () => {
  const kecil = logoBgn({ ukuran: 32 });
  assert.equal(kecil.className, 'logo');
  assert.equal(kecil.attrs.width, '32');
  const dalam = penandaAkademik({ dalam: true });
  assert.equal(teks(dalam), TEKS_PENANDA_DALAM);
  assert.match(dalam.className, /penanda-dalam/);
  assert.match(TEKS_PENANDA_DALAM, /Proyek akademik.*bukan sistem resmi BGN/);
});

test('shell-web memasang logo + penanda ringkas; CSS penanda tidak lebih kecil dari .small dan tanpa gradien oranye pada logo', () => {
  const src = readFileSync(fileURLToPath(new URL('../shell-web.js', import.meta.url)), 'utf8');
  assert.match(src, /logoBgn\(/);
  assert.match(src, /penandaAkademik\(\{ dalam: true \}\)/);
  assert.doesNotMatch(src, /ikon\('merek'/);
  const css = readFileSync(fileURLToPath(new URL('../../css/web.css', import.meta.url)), 'utf8');
  const blok = css.match(/body\.web \.penanda-akademik \{([^}]*)\}/)[1];
  const rem = Number(blok.match(/font-size:\s*([\d.]+)rem/)[1]);
  assert.ok(rem >= 0.85, `font penanda ${rem}rem < .small (.85rem)`);
  assert.doesNotMatch(css.match(/body\.web \.logo \{([^}]*)\}/)[1], /gradient/);
});
