// Lapisan transport (R04-8 PR1). SATU-SATUNYA pintu modul/komponen ke klien API.
// Bawaan (Mini App Telegram): meneruskan apa adanya dari api.js (Bearer + localStorage). Perilaku TIDAK berubah.
// Build web (`vite build --mode web`): Vite mengganti berkas ini dengan transport-web.js (cookie + CSRF), lihat vite.config.js.
// Aturan: hanya transport*.js, auth.js, main.js (Mini App) dan berkas khusus web (web-main, auth-web, sesi/layar) yang boleh
// mengimpor api.js/api-web.js (dijaga tests/transport-statis.test.js).
export * from './api.js';
// Antrean offline (IndexedDB) hanya di Mini App; transport-web.js mengekspor padanan yang selalu false (web tanpa antrean).
export { enqueue as antreOffline } from './offline-queue.js';
// 401 saat memuat berkas privat: Mini App sudah punya alur pemulihan sesi lewat request(); pemuat berkas tidak memicu apa pun (perilaku tetap).
export const sesiBerkasHabis = () => {};
