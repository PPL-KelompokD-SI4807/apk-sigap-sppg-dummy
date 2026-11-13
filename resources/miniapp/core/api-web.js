// Klien API mode WEB (R04-5): login penuh lewat cookie HttpOnly + header X-Sigap-Klien/X-CSRF-Token.
// Terpisah dari api.js (klien Mini App, Bearer) dan SENGAJA tidak mengimpornya: api.js membaca/menulis token di
// localStorage saat dimuat, sedangkan modul ini tidak boleh menyentuh storage apa pun.
//
// Aturan keras:
// - Tanpa header Authorization. Cookie sesi (__Host-sigap_sesi) HttpOnly: JS tidak pernah melihatnya.
// - Token CSRF hanya di variabel modul (memori). Tidak ke localStorage/sessionStorage/document.cookie (dijaga tes).
// - credentials 'same-origin' dan X-Sigap-Klien: web pada SEMUA permintaan (termasuk GET).
// - 403 csrf_ditolak: muat ulang CSRF lalu ulangi SATU kali. 401: sesi habis (tanpa login senyap: tak ada initData).
// - 429: tidak diulang otomatis; ApiError.retryAfter (detik) memuat Retry-After.
import { statusMessage } from './errors.js';

const BASE = '/api/v1';
export const HEADER_KLIEN = 'X-Sigap-Klien';
export const NILAI_KLIEN = 'web';
const JEDA_KIRIM_ULANG_DETIK = 60;
const MAKS_KIRIM_ULANG = 3;
const PESAN_SESI_HABIS = 'Sesi Anda telah berakhir. Silakan masuk kembali.';
const PESAN_CSRF = 'Permintaan ditolak. Muat ulang halaman lalu coba lagi.';
const PESAN_OFFLINE = 'Tidak ada koneksi internet.';

export class ApiError extends Error {
  constructor(status, message, errors = null, data = null) {
    super(message);
    this.status = status;
    this.errors = errors;
    this.data = data;
    this.retryAfter = null; // detik, hanya 429/503 dengan Retry-After
  }
  get offline() { return this.status === 0; }
  get alasan() { return this.data?.alasan ?? null; }
}

const hasBlob = (obj) => Object.values(obj || {}).some((v) => typeof Blob !== 'undefined' && v instanceof Blob);

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

/** Retry-After dalam detik (angka bulat positif) atau null. Tanggal HTTP tidak dipakai server ini. */
export function bacaRetryAfter(res) {
  const n = Number(res?.headers?.get?.('Retry-After'));
  return Number.isFinite(n) && n > 0 ? Math.ceil(n) : null;
}

/** Hitung mundur kirim ulang kode: klien menghitung sendiri (jeda 60 detik, maks 3 kali). Murni, mudah diuji. */
export function statusKirimUlang({ terkirimSebanyak = 0, terakhirMs, sekarangMs = Date.now() } = {}) {
  const sisa = Math.max(0, MAKS_KIRIM_ULANG - terkirimSebanyak);
  const tunggu = terakhirMs == null ? 0 : Math.max(0, Math.ceil((terakhirMs + JEDA_KIRIM_ULANG_DETIK * 1000 - sekarangMs) / 1000));
  return { sisa, tungguDetik: tunggu, boleh: sisa > 0 && tunggu === 0 };
}

/**
 * Buat klien web. `fetchFn` dan `base` hanya untuk tes; produksi memakai instans bawaan di bawah.
 * Status: 'kosong' (belum ada CSRF) | 'pra' (CSRF pra-login) | 'sesi' (login penuh; CSRF terikat sesi).
 */
export function buatKlienWeb({ fetchFn = (...a) => fetch(...a), base = BASE, sekarang = () => Date.now() } = {}) {
  let mode = 'kosong';
  let csrf = null;
  let csrfBerlakuSampai = null; // ms epoch; hanya untuk CSRF pra-login
  let user = null;
  let muatCsrfJalan = null; // dedup: satu GET /web/auth/csrf sekaligus (tiap panggilan membatalkan yang lama di server)
  const bus = new EventTarget();

  const lupakan = () => { mode = 'kosong'; csrf = null; csrfBerlakuSampai = null; user = null; };
  const sesiHabis = () => { lupakan(); bus.dispatchEvent(new Event('sesi-habis')); };

  async function kirim(method, path, { query, body, key, csrfPakai } = {}) {
    let url = base + path;
    if (query) {
      const qs = new URLSearchParams();
      for (const [k, v] of Object.entries(query)) if (v !== '' && v != null) qs.set(k, v);
      const s = qs.toString();
      if (s) url += `?${s}`;
    }
    const headers = { Accept: 'application/json', [HEADER_KLIEN]: NILAI_KLIEN };
    const mengubah = method !== 'GET' && method !== 'HEAD';
    if (mengubah && csrfPakai) headers['X-CSRF-Token'] = csrfPakai;
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
      res = await fetchFn(url, { method: verb, headers, body: payload, credentials: 'same-origin', cache: 'no-store' });
    } catch {
      throw new ApiError(0, PESAN_OFFLINE);
    }
    let data = null;
    if (res.status !== 204) { try { data = await res.json(); } catch { data = null; } }
    return { res, data };
  }

  function galat(res, data) {
    const status = res.status;
    const pesan = status === 401 ? PESAN_SESI_HABIS : status === 403 && data?.alasan === 'csrf_ditolak' ? PESAN_CSRF : statusMessage(status, data?.message);
    const e = new ApiError(status, pesan, data?.errors ?? null, data);
    e.retryAfter = bacaRetryAfter(res);
    return e;
  }

  /** GET /web/auth/csrf: CSRF pra-login (+ cookie pra HttpOnly dari server). Hanya di memori. */
  function muatCsrfPra() {
    if (muatCsrfJalan) return muatCsrfJalan;
    muatCsrfJalan = (async () => {
      const { res, data } = await kirim('GET', '/web/auth/csrf');
      if (!res.ok || typeof data?.csrf !== 'string') throw galat(res, data);
      mode = 'pra';
      csrf = data.csrf;
      const t = Date.parse(data.berlaku_sampai);
      csrfBerlakuSampai = Number.isNaN(t) ? null : t;
      return csrf;
    })().finally(() => { muatCsrfJalan = null; });
    return muatCsrfJalan;
  }

  /** Setelah halaman dimuat ulang: CSRF sesi + profil dari GET /auth/me. 401 -> null (belum/tidak login). */
  async function muatSesi() {
    const { res, data } = await kirim('GET', '/auth/me');
    if (res.status === 401) { lupakan(); return null; }
    if (!res.ok) throw galat(res, data);
    if (data?.jenis_sesi !== 'web' || typeof data?.csrf !== 'string') { lupakan(); return null; }
    mode = 'sesi';
    csrf = data.csrf;
    csrfBerlakuSampai = null;
    user = data.user ?? null;
    return data;
  }

  const csrfPraKedaluwarsa = () => csrfBerlakuSampai != null && csrfBerlakuSampai <= sekarang();
  async function pastikanCsrf(jalurPra) {
    if (mode === 'sesi') return csrf;
    if (jalurPra && (mode !== 'pra' || !csrf || csrfPraKedaluwarsa())) return muatCsrfPra();
    return csrf;
  }

  /**
   * Muat ulang CSRF: rute login penuh (atau mode sesi) -> /auth/me (CSRF terikat sesi); rute /web/auth/* ->
   * /web/auth/csrf (CSRF pra-login). true bila berhasil; tanpa sesi sah -> event 'sesi-habis' dan false.
   */
  async function segarkanCsrf(jalurPra) {
    if (jalurPra && mode !== 'sesi') { await muatCsrfPra(); return true; }
    if ((await muatSesi()) != null) return true;
    sesiHabis();
    return false;
  }

  /**
   * request(method, path, {query, body, key}) -> data JSON. `jalurPra` = rute /web/auth/* (CSRF pra-login).
   * 401 pada rute sesi -> event 'sesi-habis'. 403 csrf_ditolak -> muat ulang CSRF, ulangi satu kali.
   * Pengecualian: /web/auth/kode dan /kode/kirim-ulang tidak diulang otomatis karena tantangan terikat id pra-login
   * lama; CSRF tetap dimuat ulang dan galat dilempar dengan `perluMasukUlang = true`.
   */
  async function request(method, path, opts = {}) {
    method = method.toUpperCase();
    const jalurPra = path.startsWith('/web/auth/');
    const terikatTantangan = path === '/web/auth/kode' || path === '/web/auth/kode/kirim-ulang';
    const mengubah = method !== 'GET' && method !== 'HEAD';
    if (mengubah) await pastikanCsrf(jalurPra);
    let { res, data } = await kirim(method, path, { ...opts, csrfPakai: csrf });
    if (res.status === 403 && data?.alasan === 'csrf_ditolak' && mengubah) {
      let segar = false;
      try { segar = await segarkanCsrf(jalurPra); } catch { /* gagal memuat: lempar galat asli */ }
      if (segar && !terikatTantangan) ({ res, data } = await kirim(method, path, { ...opts, csrfPakai: csrf }));
      else if (segar) { const e = galat(res, data); e.perluMasukUlang = true; throw e; }
    }
    if (res.ok) return data;
    if (res.status === 401 && !jalurPra) sesiHabis();
    throw galat(res, data);
  }

  const api = {
    get: (path, query, opts) => request('GET', path, { query, ...opts }),
    post: (path, body, opts) => request('POST', path, { body, ...opts }),
    put: (path, body, opts) => request('PUT', path, { body, ...opts }),
    del: (path, opts) => request('DELETE', path, opts),
  };

  // ---- Alur masuk (kontrak R04-2) ----
  const masuk = (identitas, password) => request('POST', '/web/auth/masuk', { body: { identitas, password } });
  /** Sukses: sesi aktif, CSRF diganti CSRF sesi dari respons. Gagal apa pun -> ApiError 422 `alasan: kode_tidak_valid`. */
  async function kirimKode(tantanganId, kode, ingatBrowser = false) {
    const data = await request('POST', '/web/auth/kode', { body: { tantangan_id: tantanganId, kode, ingat_browser: !!ingatBrowser } });
    if (data?.langkah === 'selesai' && typeof data.csrf === 'string') {
      mode = 'sesi';
      csrf = data.csrf;
      csrfBerlakuSampai = null;
      user = data.user ?? null;
    }
    return data;
  }
  const kirimUlangKode = (tantanganId) => request('POST', '/web/auth/kode/kirim-ulang', { body: { tantangan_id: tantanganId } });

  // ---- Sesi (kontrak /auth/sesi di openapi.yaml) ----
  /** Keluar: server mencabut sesi dan cookie; memori selalu dibersihkan, juga bila 401/offline. */
  async function logout() {
    try { return await request('POST', '/auth/logout', { body: {} }); }
    catch (e) { if (e?.status !== 401) throw e; return null; }
    finally { lupakan(); }
  }
  const daftarSesi = () => request('GET', '/auth/sesi');
  /** DELETE /auth/sesi/{id}; bila itu sesi ini (`sesi_ini: true`) memori dibersihkan. */
  async function cabutSesi(id) {
    const r = await request('DELETE', `/auth/sesi/${encodeURIComponent(id)}`);
    if (r?.sesi_ini) lupakan();
    return r;
  }
  async function keluarSemua() {
    const r = await request('POST', '/auth/keluar-semua', { body: {} });
    lupakan();
    return r;
  }

  return {
    request, api, masuk, kirimKode, kirimUlangKode, muatCsrf: muatCsrfPra, muatSesi, logout, daftarSesi, cabutSesi, keluarSemua,
    getUser: () => user,
    adaSesi: () => mode === 'sesi',
    /** Hanya untuk tes/diagnostik: nilai CSRF di memori. */
    getCsrf: () => csrf,
    /** Event 'sesi-habis': 401 pada rute login penuh; pemakai mengarahkan ke layar Masuk. */
    onSesiHabis: (fn) => bus.addEventListener('sesi-habis', fn),
    reset: lupakan,
  };
}

export const klienWeb = buatKlienWeb();
export const {
  api, request, masuk, kirimKode, kirimUlangKode, muatCsrf, muatSesi, logout, daftarSesi, cabutSesi, keluarSemua,
  getUser, adaSesi, onSesiHabis,
} = klienWeb;
