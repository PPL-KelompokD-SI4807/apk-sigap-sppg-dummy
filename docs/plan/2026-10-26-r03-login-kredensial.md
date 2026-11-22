# R-03 Login Kredensial (Tier T3)

Tanggal: 2026-10-26 · Branch: `feat/r03-login-kredensial` (dari `main` 7e1ae3c) · PR ke `develop`
Fitur terkait: No 1 Data Pengguna (Login Akun). Keputusan pengguna final, tidak menambah fitur di luar daftar.

## Tujuan
Dua lapis autentikasi: initData Telegram (maks 1 jam) divalidasi setiap Mini App dibuka, lalu login
kredensial (username ATAU no telepon + password) dengan token sekali pakai 6 digit dari bot untuk
aktivasi, reset password, dan perangkat/sesi baru. Sesi login penuh tanpa kedaluwarsa waktu.

## Keputusan desain (PM)
- Kolom `users.phone` yang sudah ada dipakai sebagai no telepon (tidak membuat kolom ganda); migrasi aditif, tidak mengubah/menghapus data lama.
- Token pra-login: ability `auth:pra-login`, kedaluwarsa pendek, hanya rute login/aktivasi/reset/token.
- Token login penuh: ability `auth:penuh` + ability peran (Rbac), `expires_at` null; `config('sanctum.expiration')` = null.
- Middleware ability login penuh di grup rute terautentikasi; rute pra-login dikecualikan.
- Perangkat dikenali dari `device_id` acak klien (localStorage, bukan CloudStorage yang tersinkron lintas perangkat), disimpan ter-hash. Perangkat tepercaya hanya setelah verifikasi token; kepercayaan dicabut bersama sesi.
- Token 6 digit dikirim hanya ke `users.telegram_id` dari basis data, tidak pernah ke chat dari input.
- Pemeriksaan sesi untuk bot (R-02): method service `punyaSesiPenuh(User)`.
- Dev bypass tidak boleh menerbitkan token login penuh.

## Tugas
| ID | Tier | Agen | Tugas | Bergantung |
|---|---|---|---|---|
| R03-1 | T3 | backend-dev (opus/high) | Migrasi aditif, model, Form Request, aturan password + daftar sandi umum lokal, AuthService/OTP/Sesi, middleware ability, endpoint, audit, throttle/penguncian, tes, openapi.yaml | - |
| R03-2 | T1 | frontend-dev (sonnet/medium) | Layar Masuk, aktivasi/atur password, input token 6 digit, lupa password, daftar perangkat + keluar semua, penanganan 401/403 | R03-1 (kontrak API) |
| R03-3 | T0 | github-ops (haiku/low) | Commit bertahap, PR ke develop dengan checklist DoD, pantau CI 8.3/8.4, tanpa merge | R03-1, R03-2 |
| R03-4 | T3 | PM (opus) | Tinjauan keamanan: bypass login, kebocoran token/sesi, IDOR, brute force, token ke akun salah | R03-1..3 |

## Risiko
- Tes lama memakai `Sanctum::actingAs` tanpa ability akan 403 setelah middleware dipasang; perbaiki lewat helper tes, bukan melonggarkan middleware.
- Sesi tanpa kedaluwarsa: token bocor berlaku sampai dicabut; mitigasi daftar sesi + keluar semua + pencabutan otomatis.
- Duplikat nilai `phone` lama dapat menggagalkan indeks unik; migrasi harus aman.
- Penguncian akun dapat disalahgunakan untuk DoS; penguncian sementara, bukan permanen.
