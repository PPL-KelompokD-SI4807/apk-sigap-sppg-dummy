// Skrip KLASIK kecil (bukan modul) yang dimuat sinkron di <head> sebelum web-main.js: menulis data-theme di <html> SEBELUM halaman
// digambar, supaya pengguna tema gelap tidak melihat kilatan putih. CSP web melarang skrip sebaris, jadi ini berkas terpisah
// (diemit apa adanya oleh plugin di vite.config.js). Logikanya HARUS sama dengan core/theme-web.js (tentukanTema); dijaga
// tests/tema-web.test.js. Hanya membaca pilihan tema; tidak menyentuh token/sesi.
(function () {
  try {
    var p = globalThis.localStorage.getItem('sigap_tema_web');
    var gelap = p === 'gelap' || (p !== 'terang' && !!globalThis.matchMedia && globalThis.matchMedia('(prefers-color-scheme: dark)').matches);
    var r = document.documentElement;
    r.setAttribute('data-theme', gelap ? 'dark' : 'light');
    r.style.colorScheme = gelap ? 'dark' : 'light';
  } catch (e) { /* penyimpanan diblokir: theme-web.js menyetel tema setelah modul dimuat */ }
})();
