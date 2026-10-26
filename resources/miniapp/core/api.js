import { toast } from './ui.js';
import { enqueue, listQueue, removeItem } from './offline-queue.js';
import { statusMessage } from './errors.js';
import { terapkanHeaderSppg, perluPilihUlangSppg, tanpaSppg, hapusSppgAktif, PESAN_PILIH_ULANG, PESAN_TANPA_SPPG } from './sppg-aktif.js';

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
export const onSppgDiperlukan = (fn) => bus.addEventListener('sppg-diperlukan', fn);

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
  terapkanHeaderSppg(headers);

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
  const retry = Number(res.headers?.get?.('Retry-After'));
  if (retry > 0 && data && typeof data === 'object') data.retry_after = retry;

  throw failFromResponse(res.status, data, path, silent);
}

/**
 * Efek samping + ApiError untuk respons non-2xx (dipakai request() dan uploadMultipart()):
 * 401 -> hapus token + minta login ulang; 403/5xx/lainnya -> toast kecuali silent; 422 ditampilkan oleh form.
 */
function failFromResponse(status, data, path, silent) {
  const message = statusMessage(status, data?.message);
  if (status === 401 && path !== '/auth/telegram') {
    setToken(null);
    bus.dispatchEvent(new Event('auth-required'));
  } else if (status === 403 && data?.alasan === 'perlu_login_penuh') {
    // Token pra-login/lama di rute data: ulangi alur masuk.
    setToken(null);
    bus.dispatchEvent(new Event('auth-required'));
  } else if (perluPilihUlangSppg(status, data)) {
    // Pilihan SPPG aktif belum ada / ditolak server: buang pilihan lokal, arahkan ke pemilih SPPG.
    hapusSppgAktif();
    bus.dispatchEvent(new Event('sppg-diperlukan'));
    return new ApiError(status, PESAN_PILIH_ULANG, null, data);
  } else if (tanpaSppg(status, data)) {
    if (!silent) toast(PESAN_TANPA_SPPG, 'error');
    return new ApiError(status, PESAN_TANPA_SPPG, null, data);
  } else if (status === 403) {
    if (!silent) toast('Anda tidak punya hak akses.', 'error');
  } else if (status === 422) {
    /* ditampilkan di bawah kolom oleh form-view */
  } else if (status >= 500) {
    if (!silent) toast('Terjadi gangguan pada server. Coba lagi nanti.', 'error');
  } else if (!silent && status !== 401) {
    toast(message, 'error');
  }
  return new ApiError(status, status === 403 && data?.alasan !== 'perlu_login_penuh' ? 'Anda tidak punya hak akses.' : message, data?.errors ?? null, data);
}

/**
 * Unggah multipart dengan progres (XMLHttpRequest; fetch belum punya progres unggah).
 * uploadMultipart(path, body, {key, onProgress(0..100), xhrFactory}) -> data JSON; galat -> ApiError
 * yang sama dengan request() (status 0 = offline). Selalu silent: pemanggil menampilkan galatnya sendiri.
 */
export function uploadMultipart(path, body, { key, onProgress, xhrFactory = () => new XMLHttpRequest() } = {}) {
  return new Promise((resolve, reject) => {
    const xhr = xhrFactory();
    xhr.open('POST', BASE + path);
    xhr.setRequestHeader('Accept', 'application/json');
    if (token) xhr.setRequestHeader('Authorization', `Bearer ${token}`);
    if (key) xhr.setRequestHeader('Idempotency-Key', key);
    for (const [k, v] of Object.entries(terapkanHeaderSppg({}))) xhr.setRequestHeader(k, v);
    if (xhr.upload) {
      xhr.upload.onprogress = (e) => {
        if (onProgress && e.lengthComputable && e.total > 0) onProgress(Math.min(100, Math.round((e.loaded / e.total) * 100)));
      };
    }
    xhr.onerror = () => reject(new ApiError(0, 'Tidak ada koneksi internet.'));
    xhr.ontimeout = () => reject(new ApiError(0, 'Tidak ada koneksi internet.'));
    xhr.onload = () => {
      let data = null;
      if (xhr.status !== 204) { try { data = JSON.parse(xhr.responseText); } catch { data = null; } }
      if (xhr.status >= 200 && xhr.status < 300) { onProgress?.(100); resolve(data); } else reject(failFromResponse(xhr.status, data, path, true));
    };
    xhr.send(toFormData(body));
  });
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
let retryTimer = null;

const RETRIABLE = new Set([409, 429]); // 409: kunci sama masih diproses; 429: kena batas laju. Bukan penolakan data.
export const FLUSH_BACKOFF_MS = [1000, 2000, 4000];
export const FLUSH_RETRY_LATER_MS = 60000;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Kirim ulang antrian draf offline. Berhenti bila masih offline atau perlu login.
 * 409/429 tidak membuang draf: dicoba ulang dengan backoff, lalu tetap di antrian dan dijadwalkan
 * ulang. 429 menghentikan putaran ini (item lain akan kena batas yang sama); 409 lanjut ke item berikut.
 * Penolakan 4xx lain (mis. 422) membuang draf karena data memang ditolak server.
 * `deps` hanya untuk tes; sebagai event listener argumen pertamanya Event dan diabaikan.
 */
export async function flushQueue(deps = {}) {
  const {
    request: send = request, list = listQueue, remove = removeItem, sleep = wait, authed = () => !!token, notify = toast,
  } = deps;
  if (flushing || !authed()) return 0;
  flushing = true;
  let sent = 0;
  let tertunda = 0;
  try {
    outer: for (const item of await list()) {
      for (let attempt = 0; ; attempt++) {
        try {
          await send(item.method, item.path, { body: item.body, key: item.id, silent: true });
          await remove(item.id);
          sent++;
          break;
        } catch (e) {
          if (e.offline || e.status === 401) break outer;
          if (RETRIABLE.has(e.status)) {
            if (attempt < FLUSH_BACKOFF_MS.length) { await sleep(FLUSH_BACKOFF_MS[attempt]); continue; }
            tertunda++;
            if (e.status === 429) break outer;
            break;
          }
          if (e.status >= 400 && e.status < 500) {
            await remove(item.id);
            notify(`${item.label} offline ditolak server: ${e.message}`, 'error');
            break;
          }
          break outer; // 5xx / tidak dikenal: coba lagi pada kesempatan berikut
        }
      }
    }
  } finally { flushing = false; }
  if (sent) notify(`${sent} draf offline terkirim.`, 'success');
  if (tertunda && deps.schedule !== false && !retryTimer) {
    retryTimer = setTimeout(() => { retryTimer = null; flushQueue(); }, FLUSH_RETRY_LATER_MS);
    retryTimer.unref?.();
  }
  return sent;
}
