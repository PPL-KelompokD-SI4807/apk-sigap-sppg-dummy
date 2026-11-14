// Lapisan transport (R04-8 PR1). SATU-SATUNYA pintu modul/komponen ke klien API.
// Bawaan (Mini App Telegram): meneruskan apa adanya dari api.js (Bearer + localStorage). Perilaku TIDAK berubah.
// Build web (`vite build --mode web`): Vite mengganti berkas ini dengan transport-web.js (cookie + CSRF), lihat vite.config.js.
// Aturan: hanya transport*.js, auth.js, main.js (Mini App) dan berkas khusus web (web-main, auth-web, sesi/layar) yang boleh
// mengimpor api.js/api-web.js (dijaga tests/transport-statis.test.js).
export * from './api.js';
