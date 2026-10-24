import { defineConfig } from 'vite';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('./resources/miniapp', import.meta.url));
const repo = fileURLToPath(new URL('.', import.meta.url));

// Mini App SiGAP SPPG: root resources/miniapp, keluaran public/miniapp, base /miniapp/
export default defineConfig({
  root,
  base: '/miniapp/',
  publicDir: false,
  build: {
    outDir: fileURLToPath(new URL('./public/miniapp', import.meta.url)),
    emptyOutDir: true,
    target: 'es2020',
    chunkSizeWarningLimit: 300,
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
});
