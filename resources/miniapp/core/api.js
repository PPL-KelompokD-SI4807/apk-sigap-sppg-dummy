import { toast } from './ui.js';
import { enqueue, listQueue, removeItem } from './offline-queue.js';
import { statusMessage } from './errors.js';
import { terapkanHeaderSppg, perluPilihUlangSppg, tanpaSppg, hapusSppgAktif, PESAN_PILIH_ULANG, PESAN_TANPA_SPPG } from './sppg-aktif.js';

const BASE = '/api/v1';
const TOKEN_KEY = 'sigap_token';
// Kunci sessionStorage dari versi lama (token + jenis disimpan per tab). Tidak dipakai ulang: dibuang saja.
const KUNCI_LAMA_SESSION = ['sigap_token', 'sigap_token_jenis'];

// Penyimpanan token (keputusan pemilik: "tetap login 90 hari" terasa di Mini App).
// - Token PENUH disimpan di localStorage (dan memori). Hanya token itu; bukan initData, password, atau data pribadi.
// - Token PRA-LOGIN (langkah verifikasi/password) hanya di memori, tidak pernah dipersistenkan.
// - Semua akses storage dibungkus try/catch (mode privat/diblokir/kuota). Gagal baca = tak ada token; gagal tulis =
//   token tetap hidup di memori selama sesi halaman berjalan.
const TOKEN_RE = /^[!-~]{1,512}$/; // ASCII tercetak tanpa spasi: aman sebagai nilai header Authorization

let token = null;
let jenisToken = null; // 'penuh' | 'pra_login' | null
let tersimpan = false; // true bila token penuh berhasil ditulis ke localStorage

function bacaTersimpan() {
  try {
    const v = localStorage.getItem(TOKEN_KEY);
    if (v && TOKEN_RE.test(v)) return v;
    if (v) localStorage.removeItem(TOKEN_KEY); // nilai rusak/asing
  } catch { /* diblokir / mode privat: anggap tak ada token */ }
  return null;
}
/** Hapus dari localStorage hanya bila isinya token ini (jangan menimpa login baru dari tab lain). */
function hapusTersimpan(nilai) {
  try {
    const v = localStorage.getItem(TOKEN_KEY);
    if (v === null || v === nilai) localStorage.removeItem(TOKEN_KEY);
  } catch { /* abaikan */ }
}
function bersihkanSessionLama() {
  try { for (const k of KUNCI_LAMA_SESSION) sessionStorage.removeItem(k); } catch { /* abaikan */ }
}

/** Hapus token tersimpan TANPA syarat (keluar-semua berhasil: semua token di server sudah dicabut, termasuk milik tab lain). */
export function hapusTokenTersimpan() {
  try { localStorage.removeItem(TOKEN_KEY); } catch { /* abaikan */ }
}

bersihkanSessionLama();
{
  const awal = bacaTersimpan();
  if (awal) { token = awal; jenisToken = 'penuh'; tersimpan = true; }
}

/**
 * Pasang/buang token. `jenis` 'penuh' (default 'pra_login') menentukan persistensi: hanya 'penuh' ke localStorage.
 * Menghapus (t kosong) membuang memori + localStorage (bila itu token kita) + sisa sessionStorage lama.
 */
export function setToken(t, jenis = 'pra_login') {
  const lama = token;
  const lamaPenuh = jenisToken === 'penuh';
  token = t || null;
  jenisToken = token ? (jenis === 'penuh' ? 'penuh' : 'pra_login') : null;
  if (lamaPenuh && lama && lama !== token) hapusTersimpan(lama);
  if (jenisToken === 'penuh') {
    bersihkanSessionLama();
    try { localStorage.setItem(TOKEN_KEY, token); tersimpan = true; } catch { tersimpan = false; }
  } else {
    tersimpan = false;
    if (!token) bersihkanSessionLama();
  }
}
export const getToken = () => token;
/** 'penuh' | 'pra_login' | null. */
export const getJenisToken = () => jenisToken;

const bus = new EventTarget();
export const onAuthRequired = (fn) => bus.addEventListener('auth-required', fn);
export const onSppgDiperlukan = (fn) => bus.addEventListener('sppg-diperlukan', fn);
/** Sesi tak bisa dipulihkan senyap (initData basi): layar "sesi berakhir" yang jelas. */
export const onSesiBerakhir = (fn) => bus.addEventListener('sesi-berakhir', fn);
/** Pemulihan senyap mengembalikan langkah masuk/aktivasi: fn menerima {langkah, akun}. */
export const onPerluLoginUlang = (fn) => bus.addEventListener('perlu-login-ulang', (e) => fn(e.detail.hasil));
/** Token dihapus dari tab/WebView lain (mis. logout di sana): memori tab ini sudah dibersihkan; pemakai membersihkan statusnya. */
export const onTokenDihapusLuar = (fn) => bus.addEventListener('token-dihapus-luar', fn);

/**
 * Sinkron antar tab/WebView lewat event `storage`: bila token penuh yang tersimpan dihapus di tab lain (logout,
 * keluar-semua, 401), tab ini ikut keluar. Perubahan ke token LAIN (login baru di tab lain) tidak diadopsi.
 *
 * Mencegah sesi yatim: yang dihapus tab lain adalah `oldValue`. Bila nilai itu BUKAN token tab ini (mis. tab B
 * login ulang dan menimpa, lalu keluar), tab ini bukan pemegang nilai yang dihapus; sesinya di server masih sah
 * dan tab ini tetap masuk, daripada dibuang lokal sambil meninggalkan token hidup di server. Bila nilai yang dihapus
 * sama dengan token tab ini, server sudah mencabutnya (logout/keluar-semua di tab lain), jadi cukup keluar lokal.
 * Bila nilainya tak diketahui (`clear()`: key null), tab ini mencabut tokennya di server secara best-effort sebelum
 * membuangnya. Diekspor untuk tes.
 */
export function tanganiStorage(e) {
  if (!e || (e.key !== null && e.key !== TOKEN_KEY)) return;
  if (jenisToken !== 'penuh' || !tersimpan) return;
  if (e.key === TOKEN_KEY && e.oldValue && e.oldValue !== token) return; // bukan pemegang nilai yang dihapus
  let sekarang = null;
  try { sekarang = localStorage.getItem(TOKEN_KEY); } catch { return; }
  if (sekarang) return; // masih ada (sama atau diganti login baru)
  if (!(e.key === TOKEN_KEY && e.oldValue === token)) cabutDiServer(token);
  token = null;
  jenisToken = null;
  tersimpan = false;
  bus.dispatchEvent(new Event('token-dihapus-luar'));
  bus.dispatchEvent(new Event('auth-required'));
}
/** Cabut token di server tanpa efek samping klien (tanpa toast/401 handler); kegagalan diabaikan. */
function cabutDiServer(t) {
  try {
    fetch(`${BASE}/auth/logout`, { method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${t}` }, keepalive: true, credentials: 'omit' }).catch(() => {});
  } catch { /* abaikan */ }
}
if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') window.addEventListener('storage', tanganiStorage);

// Pemulihan sesi pada 401 (keputusan pemilik A): buang token, lalu SATU kali POST /auth/telegram dengan initData
// dari Telegram.WebApp. initData TIDAK segar: nilainya sama sejak Mini App dibuka, sehingga setelah +-1 jam server
// menolaknya (401) dan pemulihan berakhir di layar "Sesi berakhir" (perilaku yang diterima). Panggilan itu tanpa
// Bearer, jadi hasilnya hanya `masuk`/`aktivasi` (tampilkan form) atau galat; request asal TIDAK diulang otomatis.
// Pemulih didaftarkan auth.js; mengembalikan null bila tak berlaku (mis. token pra-login).
let pemulih = null;
let cekTokenPenuh = null;
let sedangPulih = false;
let pemulihanTerakhir = null; // hasil gagal/berlanjut ke form; mencegah loop sampai login penuh berhasil
export const setPemulihSesi = (fn, cekPenuh) => { pemulih = fn; cekTokenPenuh = cekPenuh ?? null; };
export const resetPemulihan = () => { pemulihanTerakhir = null; };
const tokenPenuh = () => !!token && (cekTokenPenuh ? cekTokenPenuh() : true);

// Rute yang gagal 401 berarti sesi memang sudah hilang: tanpa login senyap (pemanggil membersihkan sesi lokal).
const TANPA_PEMULIHAN = (method, path) =>
  path === '/auth/logout' || path === '/auth/keluar-semua' || (method === 'DELETE' && path.startsWith('/auth/sesi'));

/**
 * 401 dari request yang dikirim dengan `tokenKirim`. Diabaikan bila token sekarang sudah berbeda (401 terlambat dari
 * request lama tidak boleh menghapus token baru/pra-login), sekaligus mencegah pemulihan ganda untuk request bersamaan.
 */
function tanganiSesi401(tokenKirim, method, path) {
  if (token !== tokenKirim) return;
  const penuh = tokenPenuh(); // dibaca sebelum token dibuang (jenis ikut terhapus bersama token)
  setToken(null);
  if (!pemulih || TANPA_PEMULIHAN(method, path)) { bus.dispatchEvent(new Event('auth-required')); return; }
  if (pemulihanTerakhir) { kirimHasilPemulihan(pemulihanTerakhir); return; }
  if (sedangPulih) return;
  sedangPulih = true;
  (async () => {
    let r;
    try {
      const hasil = penuh ? await pemulih() : null;
      r = hasil === null ? { tipe: 'umum' } : { tipe: 'form', hasil };
    } catch (e) {
      r = e?.status === 401 ? { tipe: 'berakhir' } : { tipe: 'umum' };
    } finally { sedangPulih = false; }
    if (r.tipe !== 'umum') pemulihanTerakhir = r;
    kirimHasilPemulihan(r);
  })();
}

function kirimHasilPemulihan(r) {
  if (r.tipe === 'berakhir') bus.dispatchEvent(new Event('sesi-berakhir'));
  else if (r.tipe === 'form') bus.dispatchEvent(new CustomEvent('perlu-login-ulang', { detail: { hasil: r.hasil } }));
  else bus.dispatchEvent(new Event('auth-required'));
}

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
  const tokenKirim = token;
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
    // credentials 'omit': klien Mini App hanya memakai Bearer. Cookie sesi web (__Host-sigap_sesi, satu origin) tidak
    // boleh ikut terkirim; jalur cookie hanya milik api-web.js (R04-5).
    res = await fetch(url, { method: verb, headers, body: payload, credentials: 'omit' });
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

  throw failFromResponse(res.status, data, path, silent, { tokenKirim, method });
}

/**
 * Efek samping + ApiError untuk respons non-2xx (dipakai request() dan uploadMultipart()):
 * 401 -> hapus token + minta login ulang; 403/5xx/lainnya -> toast kecuali silent; 422 ditampilkan oleh form.
 */
function failFromResponse(status, data, path, silent, { tokenKirim, method = 'POST' } = {}) {
  const message = statusMessage(status, data?.message);
  if (status === 401 && path !== '/auth/telegram') {
    tanganiSesi401(tokenKirim, method, path);
  } else if (status === 403 && data?.alasan === 'perlu_login_penuh') {
    // Token pra-login/lama di rute data: ulangi alur masuk (kecuali token sudah berganti sejak request dikirim).
    if (token === tokenKirim) { setToken(null); bus.dispatchEvent(new Event('auth-required')); }
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
    const tokenKirim = token;
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
      if (xhr.status >= 200 && xhr.status < 300) { onProgress?.(100); resolve(data); } else reject(failFromResponse(xhr.status, data, path, true, { tokenKirim, method: 'POST' }));
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
    request: send = request, list = listQueue, remove = removeItem, sleep = wait, authed = tokenPenuh, notify = toast,
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
          if (e.offline || e.status === 401 || e.data?.alasan === 'perlu_login_penuh') break outer;
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
