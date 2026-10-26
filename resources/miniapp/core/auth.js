import { api, setToken, getToken, ApiError } from './api.js';
import { getTg } from './telegram.js';

let user = null;
export const getUser = () => user;
export const getRole = () => user?.role ?? null;

/** Tukar Telegram initData ke token Sanctum. */
export async function login() {
  const tg = getTg();
  const initData = tg?.initData;
  if (!initData) throw new ApiError(0, 'Buka aplikasi ini lewat Telegram.');
  try {
    const res = await api.post('/auth/telegram', { init_data: initData }, { silent: true });
    setToken(res.token);
    user = res.user;
  } catch (e) {
    // Dev tanpa backend: pengguna tiruan agar cangkang bisa dites. Tidak ada di build produksi.
    if (import.meta.env.DEV && tg.__shim && (e.offline || e.status === 404 || e.status >= 500)) {
      console.warn('[SiGAP] Backend tidak tersedia, memakai pengguna tiruan dev.');
      setToken('dev-token');
      user = { id: 0, name: 'Pengguna Dev', role: tg.initDataUnsafe?.dev_role || 'kepala_sppg', sppg_id: 1, sppg_name: 'SPPG Contoh (dev)' };
    } else throw e;
  }
  return user;
}

export function logout() {
  setToken(null);
  user = null;
}
export const isLoggedIn = () => !!getToken() && !!user;
