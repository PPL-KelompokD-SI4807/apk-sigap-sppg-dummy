# ADR: Web dan Mini App berbagi satu origin: risiko diterima untuk tenggat 22 Nov 2026

- Tanggal: 2026-11-15
- Status: **Diterima dengan syarat** (risiko diterima untuk 22 Nov 2026; syarat = tindak lanjut pasca-tenggat di bawah)
- Tier: T3 (autentikasi, sesi, CSRF, CSP, keputusan arsitektur)
- Fitur terkait: No 1 Data Pengguna (Login Akun); kanal web R-04
- Acuan: ADR `2026-11-09-akses-web.md` (keputusan 3, web di akar domain), temuan S1 tinjauan keamanan R04-11, `docker/nginx/snippets/headers-miniapp.conf`.

## Konteks

Web (`/`) dan Mini App (`/miniapp/`) dilayani dari satu origin. Akibatnya:

- `GET /api/v1/auth/me` dengan header `X-Sigap-Klien: web` mengembalikan CSRF sesi web (`AuthController.php:72-77`).
- Cookie `__Host-sigap_sesi` (SameSite=Strict) ikut terkirim pada permintaan same-site dan pemeriksaan Origin lolos (`SesiWeb.php:259-273`).
- Maka XSS di `/miniapp/` yang dibuka sebagai halaman utama, di peramban yang juga punya sesi web (mis. Admin 30 hari), dapat mengambil CSRF lalu menjalankan aksi tulis atas nama sesi web.
- Arah sebaliknya: XSS di web dapat membaca token Bearer Mini App di `localStorage` (`core/api.js:24,64`).
- Mini App lebih rentan: CSP baru menegakkan `script-src`; `default-src` dan `connect-src` masih Report-Only, dan `style-src` memakai `'unsafe-inline'` (`headers-miniapp.conf:24-25`).

## Peredam

- Di Telegram Web, Mini App berjalan di iframe pihak ketiga (cookie Strict tidak terkirim, storage terpartisi). **ASUMSI perilaku peramban, belum diuji di staging.**
- Tidak ada sink `innerHTML`/`eval` di klien; `h()` menyaring URL.
- CSP web ditegakkan.
- Tinjauan R04-11 tidak menemukan temuan yang memblokir.

## Keputusan

Pengguna **menerima risiko** untuk tenggat 22 Nov 2026 dan **tidak memisahkan host sekarang**.

## Konsekuensi

- Risiko S1 tetap terbuka sampai tindak lanjut selesai; bergantung pada asumsi iframe Telegram Web dan ketiadaan XSS.
- Pemisahan host ditunda; pekerjaan infrastruktur tetap harus dilakukan setelah tenggat.

## Tindak lanjut pasca-tenggat

1. Pisahkan host: web di `app.<domain>` atau Mini App di `miniapp.<domain>`. Cookie `__Host-` terisolasi per host. Perlu perubahan `docker/nginx/**`, `APP_URL`/config (backend-dev), dan base Vite (frontend-dev).
2. Tegakkan `default-src` dan `connect-src`, dan buang `'unsafe-inline'` dari `style-src` CSP Mini App.
3. Konfirmasi asumsi iframe Telegram Web di staging (uji TG-13).
4. Sediakan endpoint laporan CSP (S3).
