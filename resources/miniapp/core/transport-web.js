// Transport mode WEB (R04-8 PR1): bentuk ekspor sama dengan transport.js (Mini App), dasarnya api-web.js (cookie HttpOnly + CSRF
// di memori). Tidak menyentuh storage dan tidak memakai Authorization. Dipilih lewat alias Vite saat `--mode web`.
// Catatan cakupan: web tanpa antrean offline (keputusan R04-8): simpan = kirim langsung; foto dikirim langsung (tanpa IndexedDB).
import { klienWeb, ApiError } from './api-web.js';

export { ApiError };
export const api = klienWeb.api;
export const request = klienWeb.request;

export const newKey = () =>
  (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`);

/** Daftar paginate Laravel -> {items, page, lastPage, total}. Sama dengan versi Mini App. */
export async function fetchList(endpoint, { page = 1, search = '', perPage = 15, extra = {} } = {}) {
  const res = await api.get(endpoint, { page, q: search, per_page: perPage, ...extra });
  const items = Array.isArray(res) ? res : res?.data ?? [];
  const meta = res?.meta ?? {};
  return { items, page: meta.current_page ?? page, lastPage: meta.last_page ?? 1, total: meta.total ?? items.length };
}

/** Tanpa antrean offline di web: kirim langsung dengan Idempotency-Key; galat jaringan dilempar ke pemanggil. */
export async function submitWithQueue(method, path, body) {
  const data = await request(method, path, { body, key: newKey() });
  return { queued: false, data };
}

/** Foto/berkas langsung (multipart otomatis bila body berisi Blob). Progres XHR belum ada: dilaporkan selesai di akhir. */
export async function uploadMultipart(path, body, { key, onProgress } = {}) {
  const data = await request('POST', path, { body, key });
  onProgress?.(100);
  return data;
}

/** Tidak ada antrean di web. */
export const flushQueue = async () => 0;

/** Web tidak punya token yang terlihat JS (cookie HttpOnly). Pemanggil harus memakai cookie, bukan header. */
export const getToken = () => null;

// Event: web hanya punya 'sesi-habis'. Nama Mini App dipetakan agar pemanggil tidak berubah.
export const onAuthRequired = (fn) => klienWeb.onSesiHabis(fn);
export const onSesiBerakhir = (fn) => klienWeb.onSesiHabis(fn);
export const onPerluLoginUlang = () => {};
export const onSppgDiperlukan = (fn) => klienWeb.onSppgDiperlukan(fn);
