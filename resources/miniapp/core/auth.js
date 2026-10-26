import { api, setToken, getToken, ApiError } from './api.js';
import { getTg } from './telegram.js';

let user = null;
export const getUser = () => user;
export const getRole = () => user?.role ?? null;

const JENIS_KEY = 'sigap_token_jenis';
const DEVICE_KEY = 'sigap_device_id';
const DEVICE_RE = /^[A-Za-z0-9_-]{22,128}$/;

function setJenis(j) {
  try { j ? sessionStorage.setItem(JENIS_KEY, j) : sessionStorage.removeItem(JENIS_KEY); } catch { /* mode privat */ }
}
function getJenis() {
  try { return sessionStorage.getItem(JENIS_KEY); } catch { return null; }
}

let memDeviceId = null;
/** ID perangkat acak (bukan data pribadi) di localStorage; bila storage tak tersedia dipakai selama sesi halaman. */
export function deviceId() {
  try {
    const v = localStorage.getItem(DEVICE_KEY);
    if (v && DEVICE_RE.test(v)) return v;
  } catch { /* abaikan */ }
  if (memDeviceId) return memDeviceId;
  const bytes = new Uint8Array(24);
  crypto.getRandomValues(bytes);
  const id = btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  memDeviceId = id;
  try { localStorage.setItem(DEVICE_KEY, id); } catch { /* abaikan */ }
  return id;
}

export function labelPerangkat() {
  const p = getTg()?.platform;
  return `Telegram Mini App${p ? ` (${p})` : ''}`;
}

function simpanPenuh(res) {
  setToken(res.token);
  setJenis('penuh');
  user = res.user;
}

/**
 * Tukar Telegram initData. Hasil: {langkah:'sesi_aktif'} | {langkah:'masuk'|'aktivasi', akun}.
 * Token pra-login disimpan di klien API (sessionStorage) hanya untuk melanjutkan alur masuk.
 */
export async function login() {
  const tg = getTg();
  const initData = tg?.initData;
  if (!initData) throw new ApiError(0, 'Buka aplikasi ini lewat Telegram.');
  if (getJenis() !== 'penuh') setToken(null); // Bearer hanya bila punya token penuh
  const kirim = () => api.post('/auth/telegram', { init_data: initData, device_id: deviceId() }, { silent: true });
  let res;
  try {
    try {
      res = await kirim();
    } catch (e) {
      if (e.status === 401 && getToken()) { setToken(null); setJenis(null); res = await kirim(); } else throw e;
    }
  } catch (e) {
    // Dev tanpa backend: pengguna tiruan agar cangkang bisa dites. Tidak ada di build produksi.
    if (import.meta.env.DEV && tg.__shim && (e.offline || e.status === 404 || e.status >= 500)) {
      console.warn('[SiGAP] Backend tidak tersedia, memakai pengguna tiruan dev.');
      setToken('dev-token');
      user = { id: 0, name: 'Pengguna Dev', role: tg.initDataUnsafe?.dev_role || 'kepala_sppg', sppg_id: 1, sppg_name: 'SPPG Contoh (dev)' };
      return { langkah: 'sesi_aktif' };
    }
    throw e;
  }
  if (res.langkah === 'sesi_aktif') {
    user = res.user;
    setJenis('penuh');
    return { langkah: 'sesi_aktif' };
  }
  setToken(res.token);
  setJenis('pra_login');
  user = null;
  return { langkah: res.langkah, akun: res.akun ?? {} };
}

const opts = { silent: true };
const dev = () => ({ device_id: deviceId() });

/** Hasil: tantangan (202) atau {selesai:true, user} (200 login penuh langsung). */
function tangani(res) {
  if (res?.token_jenis === 'penuh') { simpanPenuh(res); return { selesai: true, user }; }
  return { selesai: false, tantangan: res.tantangan, message: res.message };
}
export const masuk = async ({ identitas, password }) =>
  tangani(await api.post('/auth/masuk', { identitas, password, label_perangkat: labelPerangkat(), ...dev() }, opts));
export const aktivasi = async (body) =>
  tangani(await api.post('/auth/aktivasi', { ...body, label_perangkat: labelPerangkat(), ...dev() }, opts));
export const resetPassword = async (body) =>
  tangani(await api.post('/auth/reset-password', { ...body, ...dev() }, opts));
export async function verifikasiToken(tantanganId, kode) {
  const res = await api.post('/auth/token/verifikasi', { tantangan_id: tantanganId, kode, ...dev() }, opts);
  simpanPenuh(res);
  return user;
}
export const kirimUlangToken = async (tantanganId) =>
  (await api.post('/auth/token/kirim-ulang', { tantangan_id: tantanganId, ...dev() }, opts)).tantangan;

export const daftarSesi = async () => (await api.get('/auth/sesi')).data ?? [];
export const keluarSemua = () => api.post('/auth/keluar-semua', {}, opts);

/** Keluar dari perangkat ini: cabut token di server (kegagalan jaringan diabaikan), lalu bersihkan lokal. */
export async function logout() {
  try { await api.post('/auth/logout', {}, opts); } catch { /* token tetap dibuang lokal */ }
  bersihkanSesi();
}
export function bersihkanSesi() {
  setToken(null);
  setJenis(null);
  user = null;
}
export const isLoggedIn = () => !!getToken() && !!user;
