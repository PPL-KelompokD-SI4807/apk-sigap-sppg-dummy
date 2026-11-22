# Redesain antarmuka WEB SiGAP SPPG (3 PR berurutan)

Tanggal: 2026-11-22. Cakupan: hanya web (`web.html`, `web-main.js`, `resources/css/web.css`). Mini App Telegram tidak berubah.
Ini perubahan tampilan di luar 16 fitur CRUD (bukan PBI baru); mengikuti R04 (akses web) dan keputusan R04-6 (tanpa sumber daya eksternal).

## Referensi visual
`/tmp/claude-0/-home-claude/42ca4451-5906-51b7-b073-e290c52d2dd0/scratchpad/sigap-web/index.html` (token, radius, bayangan, bar atas, rel, hero, seg, kartu, aktivitas). Ambil gaya, bukan data. Berkas itu memuat Google Fonts: JANGAN disalin.

## Temuan teknis (hasil pemeriksaan PM)
- CSP web (`docker/nginx/snippets/headers-webapp.conf`): `style-src 'self'` tanpa `unsafe-inline`, `font-src 'self'` tanpa `data:`, `script-src 'self'`.
  Akibat: tidak boleh atribut `style="..."` atau `setAttribute('style')` (CSSOM `el.style.x = ...` boleh); SVG sebaris dengan atribut presentasi boleh; font harus berkas terpisah (set `build.assetsInlineLimit` agar woff2 tidak di-inline sebagai data URI, atau pakai tumpukan font sistem).
- Tes frontend memakai `node --test resources/miniapp/tests/*.test.js` (bukan vitest). `tests/ukuran-bundel.test.js` menjaga gzip +10% dari garis dasar (`tests/fixtures/ukuran-bundel.json`, web 17284 / web_total 177163). Bila CSS/ikon baru melewati batas, perbarui garis dasar lewat `UPDATE_UKURAN=1` dan jelaskan di PR.
- `develop` jarak jauh = 613a84e; HEAD kerja saat ini c05764a sudah termasuk di dalamnya. `develop` lokal basi (8e874a9): cabang baru harus dari `origin/develop`.
- Pengujian visual 360 px dan dua tema tidak bisa dijalankan otomatis dari lingkungan PM; frontend-dev harus memakai peramban tanpa kepala bila ada, atau menandai jujur layar yang belum diperiksa.

## Tugas
| # | Isi | Tier | Agen | Cabang |
|---|---|---|---|---|
| 1 | Fondasi tema web + sakelar siang/malam + tes unit | T2 | frontend-dev sonnet/high | `feat/web-redesain-1-tema` |
| 2 | Kerangka: bar atas + rel ikon + peta ikon SVG, dibangun dari menuFor/pintasanWeb | T2 | frontend-dev sonnet/high | `feat/web-redesain-2-kerangka` |
| 3 | Layar Masuk, beranda hero, komponen bersama, charts, dasbor | T2 | frontend-dev sonnet/high | `feat/web-redesain-3-layar` |
| 4 | Cabang, commit, PR per tugas | T0 | github-ops haiku/low | - |

Ketergantungan: 1 -> 2 -> 3 (berurutan; PR 2 bertumpu pada token PR 1, PR 3 pada kerangka PR 2). github-ops baru bergerak setelah frontend-dev melapor tiap tahap.

## Kriteria selesai per PR
- `php artisan test`, `vendor/bin/pint --test`, `node --test resources/miniapp/tests/*.test.js`, build Vite (`npm run build`) lulus.
- Mini App tidak berubah: tidak ada nilai `:root` di `miniapp.css` yang diubah, semua aturan baru di bawah `body.web`.
- Tanpa `<link>`/CDN eksternal; CSP tidak dilonggarkan.
- Kontras WCAG AA di terang dan gelap (termasuk `badge-*`, `alert-*`).
- Diperiksa di 360 px tanpa geseran mendatar pada body, kedua tema.

## Risiko
- Aturan `body.web` bisa kalah spesifisitas dari `miniapp.css`: pakai petakan ulang variabel, bukan menimpa tiap aturan.
- Emoji di `menu-icon` dipakai tes yang ada; ubah dengan cadangan emoji agar tes tidak patah.
- Bayangan/gradien memakai nilai literal; tema gelap butuh nilai sendiri (token `--shadow`, `--shadow-hero`).
- Penumpukan PR: PR 2 dan 3 memuat diff PR sebelumnya sampai PR sebelumnya di-merge.
