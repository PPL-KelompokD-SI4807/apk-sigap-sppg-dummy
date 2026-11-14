import { defineConfig } from 'vite';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('./resources/miniapp', import.meta.url));
const repo = fileURLToPath(new URL('.', import.meta.url));

// Web build menamai web.html menjadi index.html agar nginx cukup menyajikan direktori /web/.
const webIndeks = () => ({
  name: 'sigap-web-index',
  enforce: 'post',
  generateBundle: {
    order: 'post',
    handler(_, bundle) {
      const html = bundle['web.html'];
      if (html) { html.fileName = 'index.html'; }
    },
  },
});

// R04-8: pada build web, `core/transport.js` dan `core/auth.js` (Bearer/initData, Mini App) diganti `core/transport-web.js` dan `core/auth-web.js` (cookie + CSRF, GET /auth/me).
const transportWeb = () => ({
  name: 'sigap-transport-web',
  enforce: 'pre',
  resolveId(source) {
    if (/(^|\/)core\/transport\.js$/.test(source) || /^\.\/transport\.js$/.test(source)) {
      return fileURLToPath(new URL('./resources/miniapp/core/transport-web.js', import.meta.url));
    }
    if (/(^|\/)core\/auth\.js$/.test(source)) {
      return fileURLToPath(new URL('./resources/miniapp/core/auth-web.js', import.meta.url));
    }
    return null;
  },
});

// Dua keluaran dari satu sumber:
//   `vite build`            -> Mini App: entri index.html, keluaran public/miniapp, base /miniapp/ (tidak berubah)
//   `vite build --mode web` -> Web (R04-6): entri web.html, keluaran public/web, base /web/ (tanpa telegram-web-app.js)
export default defineConfig(({ mode }) => {
  const web = mode === 'web';
  return {
    root,
    base: web ? '/web/' : '/miniapp/',
    publicDir: false,
    plugins: web ? [transportWeb(), webIndeks()] : [],
    build: {
      outDir: fileURLToPath(new URL(web ? './public/web' : './public/miniapp', import.meta.url)),
      emptyOutDir: true,
      target: 'es2020',
      // Web: tanpa pembungkus preload (helper + peta dependensi) agar aset awal kecil; chunk lazy dimuat saat dibutuhkan.
      modulePreload: web ? false : undefined,
      chunkSizeWarningLimit: 300,
      rollupOptions: web ? { input: fileURLToPath(new URL('./resources/miniapp/web.html', import.meta.url)) } : undefined,
    },
    server: {
      host: true,
      port: 5173,
      fs: { allow: [repo] },
      // Backend Laravel (php artisan serve) pada port 8000, ubah lewat VITE_API_PROXY
      proxy: {
        '/api': { target: process.env.VITE_API_PROXY || 'http://localhost:8000', changeOrigin: true },
      },
    },
  };
});
