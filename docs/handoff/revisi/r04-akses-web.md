# R-04 — Akses aplikasi lewat website (tanpa Mini App), dengan login

Status: dicatat (2026-10-25); rancangan usulan menunggu persetujuan pengguna; belum dikerjakan. Tier kritis (autentikasi dan pemaparan ke internet publik). Memperluas R-03 (jalur web tidak punya initData).

## Permintaan
Pengguna dapat memakai aplikasi lewat browser di website, tanpa membuka bot Telegram, namun wajib login terlebih dahulu.

## Rancangan usulan
1. **Dua jalur masuk, satu aplikasi, satu set hak akses.**
   - Mini App di Telegram: initData + login kredensial (R-03).
   - Website: login kredensial + token sekali pakai yang dikirim bot ke chat Telegram pengguna. Token dari bot membuktikan pengguna menguasai akun Telegram yang terdaftar, sehingga tetap dua faktor walau tanpa initData.
2. **Alamat:** website di akar domain (`https://domain/`), Mini App tetap di `/miniapp/`. Mini App yang sekarang menolak dibuka di luar Telegram ("Buka lewat Telegram") diubah menjadi mode web yang sah setelah login.
3. **Tampilan:** memakai kode Mini App yang ada, dibuat responsif (ponsel dan desktop), tanpa ketergantungan pada `telegram-web-app.js` (tema, MainButton/BackButton diberi pengganti di web). Unggah foto bekerja lewat browser.
4. **Sesi web:** cookie HttpOnly + Secure + SameSite (Sanctum mode SPA/stateful), bukan token di localStorage; perlindungan CSRF; tetap login sampai logout (sesuai R-03). Jalur Mini App tetap memakai token bearer.
5. **Hak akses sama:** peran, scope SPPG, dan Policy tidak berubah. Menu dan tombol mengikuti `config/rbac.php`.
6. **Notifikasi dan bot** tetap lewat Telegram; Agen AI di web opsional untuk tahap berikutnya.

## Pengaman tambahan karena terbuka ke internet
- HTTPS wajib (HSTS), cookie Secure, CSP ketat, header keamanan.
- Batas percobaan login per akun dan per IP, penguncian sementara, catat di log audit; pertimbangkan CAPTCHA setelah beberapa kali gagal.
- Token sekali pakai: kedaluwarsa 5 menit, sekali pakai, ter-hash, batas percobaan.
- Pesan galat login seragam (tidak membocorkan apakah username/no telepon terdaftar).
- Daftar sesi aktif per pengguna dan "Keluar dari semua perangkat" (juga mencabut sesi web).
- Konfigurasi CORS dan domain tepercaya; tidak ada login lewat parameter URL.

## Prasyarat
- R-01 selesai (server tetap dengan domain dan HTTPS).
- R-03 sudah ada (kredensial, token sekali pakai, sesi). Catatan dari tinjauan R-03: token penuh sengaja TIDAK diikat ke initData per request supaya jalur web tetap mungkin.

## Hal yang perlu diputuskan pengguna
- Setuju memakai token sekali pakai dari bot sebagai faktor kedua di web? (Usulan: ya.)
- Apakah semua fitur tersedia di web? (Usulan: ya, termasuk unggah foto; Agen AI di web menyusul.)
- Domain yang dipakai.

## Dampak teknis
Konfigurasi Sanctum SPA (stateful) dan dua mode penjaga (bearer untuk Mini App, cookie untuk web), middleware CSRF/CORS, endpoint login web + pengiriman token lewat bot, layar Masuk berbasis web, penyesuaian router/tema Mini App agar berjalan di luar Telegram, penyajian aset, header keamanan di nginx, tes lintas jalur, dan tinjauan keamanan.
