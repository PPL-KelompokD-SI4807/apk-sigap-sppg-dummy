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

// Dua keluaran dari satu sumber:
//   `vite build`            -> Mini App: entri index.html, keluaran public/miniapp, base /miniapp/ (tidak berubah)
//   `vite build --mode web` -> Web (R04-6): entri web.html, keluaran public/web, base /web/ (tanpa telegram-web-app.js)
export default defineConfig(({ mode }) => {
  const web = mode === 'web';
  return {
    root,
    base: web ? '/web/' : '/miniapp/',
    publicDir: false,
    plugins: web ? [webIndeks()] : [],
    build: {
      outDir: fileURLToPath(new URL(web ? './public/web' : './public/miniapp', import.meta.url)),
      emptyOutDir: true,
      target: 'es2020',
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
