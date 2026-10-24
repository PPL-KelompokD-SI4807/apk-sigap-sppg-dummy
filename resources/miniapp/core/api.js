import { toast } from './ui.js';
import { enqueue, listQueue, removeItem } from './offline-queue.js';
import { statusMessage } from './errors.js';

const BASE = '/api/v1';
const TOKEN_KEY = 'sigap_token';

let token = null;
try { token = sessionStorage.getItem(TOKEN_KEY); } catch { /* mode privat */ }

export function setToken(t) {
  token = t || null;
  try { token ? sessionStorage.setItem(TOKEN_KEY, token) : sessionStorage.removeItem(TOKEN_KEY); } catch { /* abaikan */ }
}
export const getToken = () => token;

const bus = new EventTarget();
export const onAuthRequired = (fn) => bus.addEventListener('auth-required', fn);

export class ApiError extends Error {
  constructor(status, message, errors = null, data = null) {
    super(message);
    this.status = status;
    this.errors = errors;
    this.data = data;
  }
  get offline() { return this.status === 0; }
}

export const newKey = () =>
  (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`);

const hasBlob = (obj) => Object.values(obj || {}).some((v) => v instanceof Blob);

function toFormData(obj) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(obj)) {
    if (v == null) continue;
    if (Array.isArray(v)) v.forEach((x) => fd.append(`${k}[]`, x));
    else if (v instanceof Blob) fd.append(k, v, v.name || 'foto.jpg');
    else fd.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : v);
  }
  return fd;
}

/**
 * request(method, path, {query, body, key, silent})
 * body: objek biasa (JSON) atau objek berisi File/Blob (dikirim multipart).
 * key: Idempotency-Key opsional. silent: jangan tampilkan toast otomatis.
 */
export async function request(method, path, { query, body, key, silent = false } = {}) {
  method = method.toUpperCase();
  let url = BASE + path;
  if (query) {
    const qs = new URLSearchParams();
    for (const [k, v] of Object.entries(query)) if (v !== '' && v != null) qs.set(k, v);
    const s = qs.toString();
    if (s) url += `?${s}`;
  }
  const headers = { Accept: 'application/json' };
  if (token) headers.Authorization = `Bearer ${token}`;
  if (key) headers['Idempotency-Key'] = key;

  let payload;
  let verb = method;
  if (body != null) {
    if (hasBlob(body)) {
      payload = toFormData(body);
      if (method === 'PUT' || method === 'PATCH') { payload.append('_method', method); verb = 'POST'; }
    } else {
      payload = JSON.stringify(body);
      headers['Content-Type'] = 'application/json';
    }
  }

  let res;
  try {
    res = await fetch(url, { method: verb, headers, body: payload });
  } catch {
    if (!silent) toast('Tidak ada koneksi internet.', 'error');
    throw new ApiError(0, 'Tidak ada koneksi internet.');
  }

  let data = null;
  if (res.status !== 204) {
    try { data = await res.json(); } catch { data = null; }
  }
  if (res.ok) return data;

  const message = statusMessage(res.status, data?.message);
  if (res.status === 401 && path !== '/auth/telegram') {
    setToken(null);
    bus.dispatchEvent(new Event('auth-required'));
  } else if (res.status === 403) {
    if (!silent) toast('Anda tidak punya hak akses.', 'error');
  } else if (res.status === 422) {
    /* ditampilkan di bawah kolom oleh form-view */
  } else if (res.status >= 500) {
    if (!silent) toast('Terjadi gangguan pada server. Coba lagi nanti.', 'error');
  } else if (!silent && res.status !== 401) {
    toast(message, 'error');
  }
  throw new ApiError(res.status, res.status === 403 ? 'Anda tidak punya hak akses.' : message, data?.errors ?? null, data);
}

export const api = {
  get: (path, query, opts) => request('GET', path, { query, ...opts }),
  post: (path, body, opts) => request('POST', path, { body, ...opts }),
  put: (path, body, opts) => request('PUT', path, { body, ...opts }),
  del: (path, opts) => request('DELETE', path, opts),
};

/** Daftar paginate Laravel -> {items, page, lastPage, total}. */
export async function fetchList(endpoint, { page = 1, search = '', perPage = 15, extra = {} } = {}) {
  const res = await api.get(endpoint, { page, q: search, per_page: perPage, ...extra });
  const items = Array.isArray(res) ? res : res?.data ?? [];
  const meta = res?.meta ?? {};
  return { items, page: meta.current_page ?? page, lastPage: meta.last_page ?? 1, total: meta.total ?? items.length };
}

/**
 * Kirim simpan (POST/PUT). Jika offline, masuk antrian lokal dan hasilnya {queued:true}.
 * Idempotency-Key sama dipakai saat dikirim ulang, sehingga tidak ganda.
 */
export async function submitWithQueue(method, path, body, { label = 'Draf' } = {}) {
  const key = newKey();
  try {
    const data = await request(method, path, { body, key, silent: true });
    return { queued: false, data };
  } catch (e) {
    if (e.offline) {
      const ok = await enqueue({ id: key, method, path, body, label, createdAt: Date.now() });
      if (ok !== false) return { queued: true, data: null };
    }
    if (e.status === 403) toast('Anda tidak punya hak akses.', 'error');
    else if (e.status >= 500) toast('Terjadi gangguan pada server. Coba lagi nanti.', 'error');
    else if (e.status && e.status !== 422 && e.status !== 401) toast(e.message, 'error');
    throw e;
  }
}

let flushing = false;
/** Kirim ulang antrian draf offline. Berhenti bila masih offline atau perlu login. */
export async function flushQueue() {
  if (flushing || !token) return 0;
  flushing = true;
  let sent = 0;
  try {
    for (const item of await listQueue()) {
      try {
        await request(item.method, item.path, { body: item.body, key: item.id, silent: true });
        await removeItem(item.id);
        sent++;
      } catch (e) {
        if (e.offline || e.status === 401) break;
        if (e.status === 422 || (e.status >= 400 && e.status < 500)) {
          await removeItem(item.id);
          toast(`${item.label} offline ditolak server: ${e.message}`, 'error');
        } else break;
      }
    }
  } finally { flushing = false; }
  if (sent) toast(`${sent} draf offline terkirim.`, 'success');
  return sent;
}
