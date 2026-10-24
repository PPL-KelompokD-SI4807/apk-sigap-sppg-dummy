import { h, clear } from './dom.js';
import { getTg, isNative } from './telegram.js';
import { hideMainButton } from './ui.js';

const routes = [];
let outlet = null;
let cleanup = null;
let renderId = 0;
let current = { path: '/', query: new URLSearchParams() };

/**
 * route('/modul/:id', handler, {guard, title})
 * handler(ctx) -> boleh async, boleh mengembalikan fungsi pembersih.
 * ctx = {el, params, query, path, setTitle(t), isStale()}
 * guard() -> false menampilkan "Anda tidak punya hak akses".
 */
export function route(pattern, handler, opts = {}) {
  const keys = [];
  const re = new RegExp('^' + pattern.replace(/:[^/]+/g, (m) => { keys.push(m.slice(1)); return '([^/]+)'; }) + '/?$');
  routes.push({ pattern, re, keys, handler, ...opts });
}

export function parseHash() {
  const raw = location.hash.replace(/^#/, '') || '/';
  const [path, qs = ''] = raw.split('?');
  return { path: path || '/', query: new URLSearchParams(qs) };
}

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

export function goBack() {
  const { path } = parseHash();
  if (path === '/') return;
  navigate(parentPath(path));
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
  if (r.guard && !r.guard(params)) return forbiddenPanel(outlet);

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
