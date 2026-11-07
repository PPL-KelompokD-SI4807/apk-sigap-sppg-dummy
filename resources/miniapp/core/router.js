import { h, clear } from './dom.js';
import { getTg, isNative } from './telegram.js';
import { hideMainButton, toast } from './ui.js';

const routes = [];
let outlet = null;
let cleanup = null;
let renderId = 0;
let current = { path: '/', query: new URLSearchParams() };

/**
 * route('/modul/:id', handler, {guard, title})
 * opts.alihan(params) -> tujuan pengalihan (truthy) bila rute ini hanya mengalihkan; dipakai goBack agar tidak loop.
 * handler(ctx) -> boleh async, boleh mengembalikan fungsi pembersih.
 * ctx = {el, params, query, path, setTitle(t), isStale()}
 * guard(params) -> false: pengguna dialihkan ke beranda dengan pesan singkat (bukan layar kosong). Rute yang
 * bergantung pada izin per record memeriksanya lagi setelah record dimuat (lihat crud-module: alihkanKeBeranda).
 */
export function route(pattern, handler, opts = {}) {
  const keys = [];
  const re = new RegExp('^' + pattern.replace(/:[^/]+/g, (m) => { keys.push(m.slice(1)); return '([^/]+)'; }) + '/?$');
  routes.push({ pattern, re, keys, handler, ...opts });
}

/**
 * Hash rute hanya yang berawalan "#/". Hash lain diperlakukan sebagai beranda, termasuk
 * "#tgWebAppData=...&tgWebAppVersion=..." yang dipasang Telegram saat membuka Mini App: hash itu
 * dibaca telegram-web-app.js (initData), jadi router tidak boleh mengubah atau menghapusnya.
 */
export function parseHashString(hash = '') {
  if (!hash.startsWith('#/')) return { path: '/', query: new URLSearchParams() };
  const [path, qs = ''] = hash.slice(1).split('?');
  return { path: path || '/', query: new URLSearchParams(qs) };
}

/**
 * Cek penjaga rute tanpa merender (untuk tes dan pemeriksaan): -> 'tidak-ada' | 'ditolak' | 'boleh'.
 * Rute tanpa guard = 'boleh'.
 */
export function cekAkses(path) {
  const hit = routes.map((r) => ({ r, m: path.match(r.re) })).find((x) => x.m);
  if (!hit) return 'tidak-ada';
  const params = Object.fromEntries(hit.r.keys.map((k, i) => [k, decodeURIComponent(hit.m[i + 1])]));
  return hit.r.guard && !hit.r.guard(params) ? 'ditolak' : 'boleh';
}

/** Alihkan ke beranda dengan pesan singkat (akses langsung ke rute/record yang bukan hak). */
export function alihkanKeBeranda(pesan = 'Halaman itu bukan hak akses Anda.') {
  toast(pesan, 'error', 4000);
  navigate('/', { replace: true });
}

export const adaRuteTerdaftar = (path) => path !== '/' && routes.some((r) => r.re.test(path));

export const parseHash = ()=> parseHashString(location.hash);

export const currentRoute = () => current;

export function navigate(path, { replace = false } = {}) {
  const target = '#' + path;
  if (replace) location.replace(target);
  else location.hash = target;
}

export const parentPath = (path) => {
  const parts = path.split('/').filter(Boolean);
  parts.pop();
  return '/' + parts.join('/');
};

/**
 * Tujuan tombol kembali dari `path`: induk terdekat yang benar-benar bisa ditampilkan. Induk dilewati bila
 * rutenya tidak ada, ditolak penjaga, atau hanya mengalihkan (opsi `alihan` mengembalikan tujuan, mis. daftar SPPG
 * -> detail SPPG sendiri, daftar Jadwal -> tab Distribusi). Tanpa ini, kembali dari tujuan pengalihan memantul
 * ke tujuan yang sama (loop) dan pengguna tidak pernah sampai ke beranda.
 */
export function jalurKembali(path) {
  let p = parentPath(path);
  while (p !== '/') {
    const hit = routes.map((r) => ({ r, m: p.match(r.re) })).find((x) => x.m);
    const params = hit ? Object.fromEntries(hit.r.keys.map((k, i) => [k, decodeURIComponent(hit.m[i + 1])])) : null;
    const bisa = hit && !(hit.r.guard && !hit.r.guard(params)) && !(hit.r.alihan && hit.r.alihan(params));
    if (bisa) return p;
    p = parentPath(p);
  }
  return '/';
}

export function goBack() {
  const { path } = parseHash();
  if (path === '/') return;
  navigate(jalurKembali(path));
}

export function forbiddenPanel(el) {
  clear(el);
  el.append(h('div', { class: 'empty' }, h('h2', {}, 'Akses ditolak'), h('p', { class: 'muted' }, 'Anda tidak punya hak akses.')));
}

function errorPanel(el, e, retry) {
  clear(el);
  if (e?.status === 403) return forbiddenPanel(el);
  el.append(h('div', { class: 'empty' },
    h('h2', {}, 'Gagal memuat'),
    h('p', { class: 'muted' }, e?.message || 'Terjadi kesalahan.'),
    h('button', { class: 'btn', type: 'button', onclick: retry }, 'Coba lagi')));
}

function syncBack(path) {
  const tg = getTg();
  const show = path !== '/';
  if (isNative() && tg.BackButton) show ? tg.BackButton.show() : tg.BackButton.hide();
  const b = document.getElementById('back');
  if (b) b.hidden = isNative() || !show;
}

export async function resolve() {
  const id = ++renderId;
  const { path, query } = parseHash();
  current = { path, query };
  if (cleanup) { try { cleanup(); } catch { /* abaikan */ } cleanup = null; }
  hideMainButton();
  syncBack(path);
  window.scrollTo(0, 0);

  const hit = routes.map((r) => ({ r, m: path.match(r.re) })).find((x) => x.m);
  clear(outlet);
  if (!hit) {
    outlet.append(h('div', { class: 'empty' }, h('h2', {}, 'Halaman tidak ditemukan'), h('a', { class: 'btn', href: '#/' }, 'Ke beranda')));
    return setTitle('SiGAP SPPG');
  }
  const { r, m } = hit;
  const params = Object.fromEntries(r.keys.map((k, i) => [k, decodeURIComponent(m[i + 1])]));
  setTitle(typeof r.title === 'function' ? r.title(params) : r.title || 'SiGAP SPPG');
  if (r.guard && !r.guard(params)) return alihkanKeBeranda();

  const ctx = { el: outlet, params, query, path, setTitle, isStale: () => id !== renderId };
  const run = async () => {
    try {
      const res = await r.handler(ctx);
      if (typeof res === 'function') {
        if (ctx.isStale()) res(); else cleanup = res;
      }
    } catch (e) {
      if (!ctx.isStale() && e?.status !== 401) errorPanel(outlet, e, () => { clear(outlet); run(); });
    }
  };
  await run();
}

export function setTitle(t) {
  document.title = t;
  const el = document.getElementById('apptitle');
  if (el) el.textContent = t;
}

export function startRouter(el) {
  outlet = el;
  const tg = getTg();
  if (isNative() && tg.BackButton) tg.BackButton.onClick(goBack);
  document.getElementById('back')?.addEventListener('click', goBack);
  window.addEventListener('hashchange', resolve);
  return resolve();
}
