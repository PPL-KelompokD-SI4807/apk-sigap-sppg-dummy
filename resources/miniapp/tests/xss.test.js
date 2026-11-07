// Tes pengerasan XSS: pembuat elemen h(), parameter rute, dan pemindai statis pola berbahaya.
// Jalankan: node --test resources/miniapp/tests/*.test.js
import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

class Node_ {}
const buatEl = (tag) => {
  const el = new Node_();
  Object.assign(el, {
    tag, attrs: {}, listeners: {}, anak: [], className: '', dataset: {}, style: { cssText: '' },
    setAttribute(k, v) { this.attrs[k] = String(v); },
    addEventListener(n, fn) { this.listeners[n] = fn; },
    append(...xs) { this.anak.push(...xs); },
  });
  return el;
};
globalThis.Node = Node_;
globalThis.document = { createElement: buatEl, createTextNode: (t) => Object.assign(new Node_(), { teks: String(t) }) };

const { h, urlAman } = await import('../core/dom.js');
const { bacaParams } = await import('../core/router.js');

test('anak (data server) selalu menjadi text node, bukan HTML', () => {
  const el = h('div', {}, '<img src=x onerror=alert(1)>');
  assert.equal(el.anak.length, 1);
  assert.equal(el.anak[0].teks, '<img src=x onerror=alert(1)>');
});

test('atribut on* bertipe string ditolak (tak ada handler inline); fungsi tetap jadi listener', () => {
  const fn = () => {};
  const el = h('button', { onclick: 'alert(1)', onMouseOver: 'alert(2)', onfocus: fn });
  assert.deepEqual(Object.keys(el.attrs), []);
  assert.equal(el.listeners.focus, fn);
});

test('srcdoc ditolak', () => {
  assert.equal('srcdoc' in h('iframe', { srcdoc: '<script>alert(1)</script>' }).attrs, false);
});

test('href/src berskema javascript:/vbscript:/data: non-gambar ditolak, termasuk tersamar spasi/kontrol/huruf besar', () => {
  for (const jahat of ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', '  javascript:alert(1)', 'java\tscript:alert(1)', 'java\nscript:alert(1)', '\u0001javascript:alert(1)', 'vbscript:msgbox(1)', 'data:text/html,<script>alert(1)</script>']) {
    assert.equal('href' in h('a', { href: jahat }).attrs, false, jahat);
    assert.equal('src' in h('img', { src: jahat }).attrs, false, jahat);
  }
  assert.equal(urlAman('data:image/svg+xml;base64,AAAA', { gambar: true }), false, 'svg data: ditolak (bisa memuat skrip)');
});

test('URL sah tetap lolos: hash rute, path API, https, blob: dan data:image untuk gambar', () => {
  assert.equal(h('a', { href: '#/bahan-baku/12' }).attrs.href, '#/bahan-baku/12');
  assert.equal(h('a', { href: 'https://t.me/bot?start=abc' }).attrs.href, 'https://t.me/bot?start=abc');
  assert.equal(h('img', { src: 'blob:https://x.test/uuid' }).attrs.src, 'blob:https://x.test/uuid');
  assert.equal(h('img', { src: 'data:image/jpeg;base64,/9j/4AAQ' }).attrs.src, 'data:image/jpeg;base64,/9j/4AAQ');
  assert.equal(urlAman('data:image/png;base64,AAAA', { gambar: false }), false, 'data: hanya boleh untuk src gambar');
});

test('style string diterapkan lewat CSSOM (cssText), bukan atribut style', () => {
  const el = h('span', { style: 'width:40%' });
  assert.equal(el.style.cssText, 'width:40%');
  assert.equal('style' in el.attrs, false);
});

test('atribut biasa dan class tetap bekerja', () => {
  const el = h('input', { class: 'a b', type: 'text', required: true, 'aria-label': 'x' });
  assert.equal(el.className, 'a b');
  assert.deepEqual(el.attrs, { type: 'text', required: '', 'aria-label': 'x' });
});

test('parameter rute: pemisah path, titik-titik, % rusak, dan karakter kontrol ditolak', () => {
  const cocok = (seg) => bacaParams(['id'], [null, seg]);
  assert.deepEqual(cocok('12'), { id: '12' });
  assert.deepEqual(cocok('3f2a-uuid'), { id: '3f2a-uuid' });
  for (const jahat of ['..%2F..%2Fauth%2Fsesi', '%2e%2e', '%252e%252e', '.%2e', '%2e.', '..', '.', 'a%2Fb', 'a%5Cb', 'x%3Fadmin%3D1', 'x%23y',
    '%E0%A4%A', '%00', 'a%0Ab', 'a b', 'a%20b', 'a.b', '%2525', '', 'x'.repeat(129)]) {
    assert.equal(cocok(jahat), null, jahat);
  }
  assert.deepEqual(cocok('x'.repeat(128)), { id: 'x'.repeat(128) });
  assert.deepEqual(cocok('kedaluwarsa_dekat-2'), { id: 'kedaluwarsa_dekat-2' });
  assert.deepEqual(cocok('3F2A9C1E-0B4D-4E7A-9C1D-2B3E4F5A6B7C'), { id: '3F2A9C1E-0B4D-4E7A-9C1D-2B3E4F5A6B7C' });
});

test('rute: encode ganda /jadwal/%252e%252e/kebutuhan tidak menghasilkan params (halaman tidak ditemukan)', () => {
  const m = '/jadwal/%252e%252e/kebutuhan'.match(/^\/jadwal\/([^/]+)\/kebutuhan\/?$/);
  assert.ok(m);
  assert.equal(bacaParams(['id'], m), null);
});

test('semua pemakaian ctx.params dalam templat string memakai encodeURIComponent', () => {
  const temuan = [];
  for (const f of semuaJs(akar)) {
    tanpaKomentar(readFileSync(f, 'utf8')).split('\n').forEach((l, i) => {
      if (/\$\{\s*ctx\.params\.\w+\s*\}/.test(l)) temuan.push(`${relative(akar, f)}:${i + 1}`);
    });
  }
  assert.deepEqual(temuan, []);
});

test('tel: dan mailto: lolos (kasus positif), begitu pula http(s) dan path relatif', () => {
  assert.equal(h('a', { href: 'tel:+628123456789' }).attrs.href, 'tel:+628123456789');
  assert.equal(h('a', { href: 'mailto:admin@sppg.example' }).attrs.href, 'mailto:admin@sppg.example');
  assert.equal(urlAman('tel:112'), true);
  assert.equal(urlAman('mailto:a@b.id?subject=Halo'), true);
  assert.equal(urlAman('/api/v1/lampiran/12'), true);
  assert.equal(urlAman('https://t.me/share/url?url=https%3A%2F%2Fx'), true);
});

// ---- Pemindai statis: pola berbahaya tak boleh muncul di kode aplikasi ----
const akar = fileURLToPath(new URL('..', import.meta.url));
const semuaJs = (dir) => readdirSync(dir).flatMap((n) => {
  const p = join(dir, n);
  if (statSync(p).isDirectory()) return n === 'tests' || n === 'node_modules' ? [] : semuaJs(p);
  return p.endsWith('.js') ? [p] : [];
});
/**
 * Buang komentar (// dan blok) tanpa merusak string: "//" di dalam '...', "..." atau `...` (mis. URL https://)
 * dipertahankan. Pemindai per karakter; cukup untuk kode proyek ini (tanpa regex literal berisi kutip).
 */
function tanpaKomentar(src) {
  let out = '';
  let kutip = null;
  for (let i = 0; i < src.length; i++) {
    const c = src[i];
    const n = src[i + 1];
    if (kutip) {
      out += c;
      if (c === '\\') { out += n ?? ''; i++; } else if (c === kutip) kutip = null;
      continue;
    }
    if (c === "'" || c === '"' || c === '`') { kutip = c; out += c; continue; }
    if (c === '/' && n === '/') { while (i < src.length && src[i] !== '\n') i++; out += '\n'; continue; }
    if (c === '/' && n === '*') {
      i += 2;
      while (i < src.length && !(src[i] === '*' && src[i + 1] === '/')) { if (src[i] === '\n') out += '\n'; i++; }
      i++;
      continue;
    }
    out += c;
  }
  return out;
}

const DILARANG = [
  [/\.innerHTML\b|\binnerHTML\s*=/, 'innerHTML'],
  [/\.outerHTML\b/, 'outerHTML'],
  [/\[\s*['"`](inner|outer)HTML['"`]\s*\]/, "el['innerHTML']"],
  [/insertAdjacentHTML/, 'insertAdjacentHTML'],
  [/document\.write(ln)?\s*\(/, 'document.write'],
  [/\beval\s*\(|\[\s*['"`]eval['"`]\s*\]/, 'eval'],
  [/new\s+Function\s*\(/, 'new Function'],
  [/setAttribute\(\s*['"`]on/i, "setAttribute('on...')"],
  [/setTimeout\(\s*['"`]/, 'setTimeout(string)'],
  [/setInterval\(\s*['"`]/, 'setInterval(string)'],
  [/createContextualFragment|DOMParser|\.srcdoc\b/, 'parser HTML'],
  [/\blocation(\.href)?\s*=(?!=)/, 'location ='],
  [/\blocation\.(assign|replace)\s*\(/, 'location.assign/replace('],
  [/window\.open\s*\(/, 'window.open'],
];
// Pengecualian terdokumentasi: router.navigate memakai location.replace('#' + path) (hanya hash internal).
const DIIZINKAN = [{ berkas: 'core/router.js', pola: /location\.replace\(target\)/ }];

test('kode aplikasi bebas pola injeksi HTML/skrip (innerHTML, eval, document.write, location =, dst.)', () => {
  const temuan = [];
  for (const f of semuaJs(akar)) {
    const rel = relative(akar, f).replaceAll('\\', '/');
    tanpaKomentar(readFileSync(f, 'utf8')).split('\n').forEach((kode, i) => {
      for (const [re, nama] of DILARANG) {
        if (re.test(kode) && !DIIZINKAN.some((d) => d.berkas === rel && d.pola.test(kode))) temuan.push(`${rel}:${i + 1} ${nama}`);
      }
    });
  }
  assert.deepEqual(temuan, []);
});

test('pemindai: tanpaKomentar mempertahankan "//" di dalam string dan membuang komentar sungguhan', () => {
  assert.match(tanpaKomentar("const u = 'https://x.test'; el.innerHTML = 1; // komentar"), /innerHTML = 1/);
  assert.match(tanpaKomentar("const u = 'https://x.test/a'; // komentar"), /https:\/\/x\.test\/a/);
  assert.doesNotMatch(tanpaKomentar('a(); // el.innerHTML = 1\nb();'), /innerHTML/);
  assert.doesNotMatch(tanpaKomentar('/* eval( */ x();'), /eval/);
  assert.match(tanpaKomentar('x = `//${y}`; eval(z)'), /eval\(z\)/);
});

test("pemindai: pola baru terdeteksi (location =, location.replace(, el['innerHTML'])", () => {
  const kena = (kode) => DILARANG.some(([re]) => re.test(tanpaKomentar(kode)));
  assert.equal(kena('location = url;'), true);
  assert.equal(kena('window.location.href = u;'), true);
  assert.equal(kena('location.replace(u)'), true);
  assert.equal(kena("el['innerHTML'] = x"), true);
  assert.equal(kena('el["outerHTML"]'), true);
  assert.equal(kena("location.hash = '#/x'"), false);
  assert.equal(kena('if (location == null) {}'), false);
});
