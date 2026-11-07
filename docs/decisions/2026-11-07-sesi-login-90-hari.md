# ADR: Sesi login "tetap masuk" 90 hari (sliding) untuk Mini App

- Tanggal: 2026-11-07
- Status: Diusulkan (menunggu tinjauan manusia dan uji staging MySQL 8.4)
- Tier: T3 (autentikasi Sanctum dan initData)
- Branch: `feat/sesi-login-90-hari`

## Konteks

Pengguna Mini App harus login ulang terlalu sering. Target: tetap masuk tanpa tindakan selama 90 hari terakhir aktif, tanpa mengorbankan keamanan. Lapis password R-03 dan token bot 6 digit pada perangkat baru sudah ada dan tidak boleh melemah.

## Keputusan

1. **Masa sesi per peran (config, bukan hardcode).** Peran biasa 90 hari, Admin 30 hari. Nilai di `config/kredensial.php` bagian `sesi`; env (`SIGAP_SESI_MASA_HARI`, `SIGAP_SESI_MASA_HARI_ADMIN`, `SIGAP_SESI_BATAS_ABSOLUT_HARI`, `SIGAP_SESI_MAKS_PER_PENGGUNA`) hanya boleh **mengetatkan**: kode menjepit ke batas keras (absolut ≤ 180 hari, peran biasa ≤ 90, Admin ≤ 30 dan tak melebihi peran biasa, maks 5 sesi; nilai ≤ 0 menjadi 1).
2. **Sliding expiry dengan batas absolut.** Tiap pemakaian token menggeser `expires_at` menjadi min(sekarang + masa peran, `created_at` + 180 hari). Penggeseran paling sering sekali per jam per token (UPDATE bersyarat lewat listener `TokenAuthenticated`); `last_used_at` bawaan Sanctum dimatikan agar tidak menulis DB tiap request. Waktu login awal = `personal_access_tokens.created_at` (token penuh tidak dirotasi), jadi tanpa kolom baru dan tanpa migrasi. Kedaluwarsa diperiksa di setiap request lewat `Sanctum::authenticateAccessTokensUsing`.
3. **initData tetap wajib dan tidak dilonggarkan.** HMAC dan `auth_date` pada pertukaran `/auth/telegram` tidak berubah. Yang diperpanjang hanya token Sanctum.
4. **Tidak ada token login penuh baru dari initData saja** (opsi A, keputusan pemilik). Token masih berlaku + initData segar → `langkah: sesi_aktif` dan masa digeser. Token sudah kedaluwarsa → `langkah: masuk`: pengguna mengetik password lagi (perangkat tepercaya tidak perlu token bot).
5. **Frontend:** pada 401 (dengan atau tanpa `alasan: sesi_kedaluwarsa`) Mini App membuang token dan memanggil `POST /auth/telegram` **sekali** dengan initData milik Mini App yang sedang terbuka (tanpa Bearer, sehingga tidak pernah menghasilkan `sesi_aktif`): `masuk`/`aktivasi` → form password; gagal (initData basi, umur maks `TELEGRAM_INIT_DATA_MAX_AGE`) → layar "Sesi berakhir" dengan petunjuk membuka ulang dari Telegram. Request asal tidak diulang otomatis. Tanpa loop, 401 bersamaan berbagi satu login senyap, 401 terlambat dari request lama tidak menghapus token baru, 401 pada rute logout tidak memicu login senyap.
6. **Pencabutan seketika tetap.** Logout, akun dihapus/dinonaktifkan, perubahan peran/unit/SPPG/status/password/telegram_id, `restoreUser` mencabut semua token pengguna (hook model `User`), tak peduli sisa masa. Perubahan peran memaksa token baru. Celah yang ditemukan dan ditutup: `PenghapusDataUji --lepas-pengguna` mengosongkan `sppg_id` lewat query builder tanpa event; kini sesinya dicabut dan diaudit.
7. **Banyak perangkat, dibatasi 5.** Login baru yang melebihi batas mencabut sesi yang **paling lama tidak dipakai** (bukan yang sedang dipakai login). Sesi kedaluwarsa dibersihkan dulu. Baris user dikunci (`lockForUpdate`) di awal penerbitan token.
8. **Kepercayaan perangkat diberi batas waktu** (opsi A, keputusan pemilik). Perangkat tepercaya bila belum dicabut dan `diverifikasi_pada` + 180 hari (≤ batas absolut sesi) masih di masa depan; tidak bergeser karena dipakai. `expires_at` token tak melewati akhir kepercayaan perangkatnya. Token bot wajib minimal sekali per 180 hari per perangkat. Maks 5 perangkat tepercaya (kelebihan dicabut, perangkat yang sedang login tidak). Perintah harian `sigap:bersihkan-perangkat` (03.50) hanya mengisi `dicabut_at`. Dipilih `diverifikasi_pada`, bukan "terakhir dipakai", karena yang kedua membuat pengguna aktif dipercaya selamanya.
9. **Audit.** Login memakai event yang ada; baru: `auth.sesi_diperpanjang` (sekali per token per hari kalender), `auth.sesi_dicabut_batas`, `auth.sesi_dicabut_otomatis` (pemicu `hak_akses_berubah`, `akun_dihapus`, `akun_dipulihkan`, `sppg_uji_dihapus`), `auth.perangkat_dicabut_batas`. Isi hanya id sesi/perangkat dan tanggal; tidak ada token, initData, atau device_id mentah.
10. **Token lama yang sudah beredar:** tidak dimigrasi. Batas dihitung saat dipakai dari `last_used_at`/`created_at` plus batas absolut; `expires_at` diisi pada pemakaian pertama. Token tanpa `created_at` ditolak (fail closed). Token lama yang tak pernah dipakai tetap tertolak. Sistem baru berjalan ±2 minggu sehingga tidak ada sesi aktif yang terputus mendadak. `sanctum:prune-expired --hours=24` dijadwalkan 03.45.

11. **Token login penuh disimpan di `localStorage`** (keputusan pemilik; sebelumnya `sessionStorage`, yang hilang saat Mini App ditutup sehingga 90 hari tidak terasa di HP). Syarat yang diterapkan: hanya token penuh dipersistenkan (kunci `sigap_token`); token pra-login, initData, dan password tidak; token dihapus saat logout, keluar-semua (tanpa syarat, agar tab lain ikut keluar via event `storage`), hapus sesi, 401/sesi berakhir, 403 `perlu_login_penuh`, dan 403 dari `/auth/telegram`; sisa kunci `sessionStorage` lama dibuang tanpa dipakai; semua akses storage dibungkus try/catch dengan fallback ke memori + alur password/login senyap tanpa layar kosong; event `storage` dari tab lain hanya mengeluarkan tab yang memegang token yang dihapus. Konsekuensi: request yang kena 401 **tidak diulang otomatis** (login senyap tanpa Bearer tidak pernah menghasilkan `sesi_aktif`); pengguna melihat form password atau layar "Sesi berakhir" lalu mengulang aksinya.
12. **Pertahanan XSS karena token di `localStorage`.**
    - Audit klien: tidak ada `innerHTML`/`eval`/`new Function`; semua render lewat `h()` (text node). `h()` diperketat: atribut `on*` string, `srcdoc`, dan `javascript:`/`vbscript:` ditolak, `data:` hanya untuk gambar raster, `style` string lewat CSSOM. Parameter rute memakai whitelist `[A-Za-z0-9_-]{1,128}` dan `encodeURIComponent` (menutup traversal encode ganda ke path API ber-Bearer). Gambar lampiran hanya dari `/api/v1/lampiran/{id}`. Pemindai statis di tes mencegah regresi.
    - Header di nginx `/miniapp/` (`docker/nginx/snippets/headers-miniapp.conf`): CSP **ditegakkan** `script-src 'self' https://telegram.org/js/telegram-web-app.js; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors` host web Telegram (tanpa `X-Frame-Options`); CSP lengkap (`default-src`, style, img, font, connect, media) tetap **Report-Only** sampai diuji di empat klien Telegram; `Referrer-Policy: no-referrer`; `Permissions-Policy` dengan `camera=(self)`. `style-src 'unsafe-inline'` tetap diperlukan karena `telegram-web-app.js` menyuntik elemen `<style>`; jangan diketatkan.

## Alternatif yang ditolak

- **Token tanpa kedaluwarsa (status quo):** token bocor berlaku selamanya.
- **Cookie sesi:** cookie di WebView Telegram tidak andal, ada risiko CSRF, guard Sanctum memang tanpa cookie, dan sub-request bot butuh Bearer.
- **Rotasi token tiap pemakaian:** bentrok pada request paralel dan banyak tab.
- **Refresh token terpisah:** kompleksitas tanpa manfaat; login ulang lewat initData + password sudah ada.
- **Token login penuh baru dari initData saja (opsi B):** pemegang akun Telegram cukup untuk masuk tanpa password; melemahkan lapis password R-03.
- **Kepercayaan perangkat tanpa batas waktu (opsi B):** HP lama yang tak dipakai >180 hari + password bocor cukup untuk login tanpa token bot.

## Konsekuensi dan risiko

- Pengguna yang hanya memakai **bot** (atau Agen AI lewat bot) tidak menggeser sesi; setelah masa habis bot meminta login ulang. Desain, bukan cacat.
- Token bot wajib minimal sekali per 180 hari per perangkat walau perangkat dipakai terus.
- `last_used_at` Sanctum hanya presisi per jam.
- Request yang akhirnya ditolak middleware lain (mis. 403 SPPG nonaktif) tetap menggeser masa sesi (dibatasi batas absolut).
- Penguncian baris login (`FOR UPDATE`) tidak dapat dibuktikan di SQLite; **wajib diverifikasi di staging MySQL 8.4** dengan dua login serentak saat pengguna sudah punya 5 sesi.
- Respons `sesi_kedaluwarsa` hanya diberikan bila id dan secret token cocok (`hash_equals`), sehingga tidak membuka enumerasi token; setelah token dipangkas, 401 umum tanpa `alasan`.
- Bila form password muncul di tengah pemakaian, request asal tidak diulang dan isian yang belum disimpan hilang.
- **Risiko `localStorage`:** token terbaca oleh XSS apa pun di origin yang sama. Mitigasi berlapis (audit/pengerasan `h()`, CSP `script-src` ketat, masa sesi dan batas absolut, batas 5 sesi, pencabutan seketika, kepercayaan perangkat 180 hari) mengurangi tetapi tidak menghilangkan risiko. `telegram-web-app.js` dimuat dari telegram.org tanpa SRI (skripnya berubah-ubah); bila domain itu disusupi, token dapat dicuri.
- Bila storage diblokir, pengguna harus login dua kali setelah 401 (reload menghilangkan token di memori); bukan loop dan bukan layar kosong.
- `nginx -t` dan perilaku CSP di klien Telegram belum diverifikasi (Docker tidak aktif saat pengembangan): wajib `curl -sI https://<DOMAIN>/miniapp/` (SEC-05) dan uji TG-13 di Telegram Web (`/k`, `/a`), Desktop, Android, dan iOS sebelum rilis. Rollback CSP cukup menghapus satu baris `add_header Content-Security-Policy`.
- Perubahan `bootstrap/app.php` (satu baris, respons 401 lewat `GalatAuth`) berada di luar wilayah agen mana pun di CLAUDE.md; ditinjau manusia.

## Verifikasi

`php artisan test` penuh, `node --test resources/miniapp/tests/*.test.js`, `pint --test`, `php docs/api/build-openapi.php --check`; tinjauan `opus` dua putaran. Kontrak: `docs/api/openapi.yaml` (auth).
