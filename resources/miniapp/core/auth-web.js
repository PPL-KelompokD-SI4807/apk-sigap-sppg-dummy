// Adaptor auth mode WEB (R04-8 PR2): pengganti core/auth.js lewat alias Vite pada `vite build --mode web`.
// Sumber pengguna = GET /auth/me (klienWeb.muatSesi) dan hasil login web; sesi = cookie HttpOnly.
// Tanpa initData/Telegram.WebApp, tanpa token dan device id di localStorage, tanpa Authorization. Bentuk ekspor sama
// dengan auth.js agar komponen/modul tidak berubah (dijaga tests/transport-statis.test.js).
// Alur masuk Telegram (login, aktivasi, token, lupa password) tidak ada di web: layar Masuk web = modules/auth-web.
import { klienWeb } from './api-web.js';

export const getUser = () => klienWeb.getUser();
export const getRole = () => getUser()?.role ?? null;
export const isLoggedIn = () => klienWeb.adaSesi() && !!getUser();
export const labelPerangkat = () => 'Web';

/** Muat profil dari GET /auth/me (null bila belum/tidak login). Dipakai titik masuk web setelah halaman dimuat. */
export const muatPengguna = async () => (await klienWeb.muatSesi())?.user ?? null;

const tidakTersedia = () => Promise.reject(new Error('Fitur ini tidak tersedia di versi web.'));
export const login = tidakTersedia;
export const pulihkanSesi = tidakTersedia;
export const masuk = tidakTersedia;
export const aktivasi = tidakTersedia;
export const statusReset = tidakTersedia;
export const ajukanReset = tidakTersedia;
export const lanjutReset = tidakTersedia;
export const verifikasiToken = tidakTersedia;
export const kirimUlangToken = tidakTersedia;

// Sesi (sama kontrak dengan Mini App; klienWeb.api menambah CSRF dan menangani 401).
const unwrapData = (r) => r?.data ?? r;
export const daftarSesi = async () => (await klienWeb.api.get('/auth/sesi')).data ?? [];
export const keluarSemua = () => klienWeb.keluarSemua();
export const hapusSesi = (id) => klienWeb.api.del(`/auth/sesi/${encodeURIComponent(id)}`);
export const ubahPassword = (body) => klienWeb.api.put('/auth/password', body);
export const logout = async () => { try { await klienWeb.logout(); } catch { /* memori tetap dibersihkan */ } };
export const bersihkanSesi = () => klienWeb.reset();

// Admin (scope ditegakkan server).
export const daftarPermintaanReset = async (status = 'menunggu') =>
  (await klienWeb.api.get('/pengguna/permintaan-reset', { status, per_page: 50 })).data ?? [];
export const putuskanReset = async (id, setuju) =>
  unwrapData(await klienWeb.api.post(`/pengguna/permintaan-reset/${encodeURIComponent(id)}/${setuju ? 'setujui' : 'tolak'}`, {}));
export const daftarSesiPengguna = async (userId) => (await klienWeb.api.get(`/pengguna/${encodeURIComponent(userId)}/sesi`)).data ?? [];
export const cabutSesiPengguna = (userId, sesiId) => klienWeb.api.del(`/pengguna/${encodeURIComponent(userId)}/sesi/${encodeURIComponent(sesiId)}`);
export const cabutSemuaSesiPengguna = (userId) => klienWeb.api.del(`/pengguna/${encodeURIComponent(userId)}/sesi`);
