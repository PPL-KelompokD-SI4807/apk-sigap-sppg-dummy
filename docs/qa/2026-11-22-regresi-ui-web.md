# Rencana uji regresi penuh: redesain UI WEB SiGAP SPPG — 22 Nov 2026

Penyusun: agen `qa-engineer` (T2). Status dokumen: RENCANA. Belum ada kasus yang dijalankan terhadap UI baru (kodenya belum mendarat di `feat/redesain-web-ui` saat dokumen ini ditulis). Kolom "Hasil" sengaja kosong; diisi hanya dengan bukti (keluaran skrip atau langkah yang benar-benar dijalankan). Data uji selalu fiktif. `php artisan test` tidak dijalankan di staging.

Dasar: `docs/plan/2026-11-22-redesain-web.md` (PM), sheet "Uji Per Peran SiGAP SPPG" (kasus 1-62), `docs/qa/2026-11-21-temuan-uji-peran.md` (P1-P13, kasus 64), `docs/qa/2026-11-22-uji-ulang.md` (kasus 39a-b, 40a-g, 61, 65, 66a-d, A179-1), `config/rbac.php`, `docker/nginx/snippets/headers-webapp.conf`.

## 1. Ruang lingkup dan prinsip

- Yang berubah: semua layar web (gaya `body.web` di `resources/css/web.css`, variabel tema dipetakan ulang), `web.html` (bar atas + rel ikon), modul baru `core/theme-web.js` (sakelar sistem/terang/gelap), ikon SVG, nav dan rel dari `menuFor(user)` + `pintasanWeb(user)`.
- Yang TIDAK boleh berubah: Mini App Telegram (tampilan, ukuran bundel, CSP-nya), perilaku fungsional semua 16 fitur, RBAC (menu dan tombol tetap dari peta hak server).
- Prinsip "tanpa masalah baru": setiap pemeriksaan otomatis dijalankan dua kali, pada build `origin/develop` (garis dasar, 613a84e) dan pada build cabang redesain, lalu dibandingkan. Temuan yang sudah ada di garis dasar dicatat sebagai "lama" (tidak memblokir tetapi dilaporkan); temuan yang hanya ada di build baru = "BARU" dan memblokir.
- Penomoran: kasus lama memakai nomor sheet (1-62) dan sufiks yang sudah dipakai (39a, 40a-g, 65, 66a-d). Kasus baru DILANJUTKAN dari nomor 67 (67-78, sub-kasus berhuruf), tidak ada sistem nomor baru.

## 2. Syarat uji (wajib, agar hasil sah)

1. CSP persis produksi: server uji membaca header langsung dari `docker/nginx/snippets/headers-webapp.conf` (skrip `cek-web.js` menolak berjalan bila tidak menemukan `Content-Security-Policy`). Server statis tanpa header = hasil tidak sah. Uji akhir tetap di staging nyata (kasus 72e) karena nginx staging adalah sumber kebenaran.
2. Build bersih dari ref git (`bangun-versi.sh <ref> <nama>`, memakai `git archive`, tidak menyentuh working tree). Web: `vite build --mode web`; Mini App: `vite build`. Build deterministik (dibuktikan: dua build `origin/develop` identik byte demi byte untuk web dan Mini App).
3. Tiga lebar (360, 768, 1280) dan dua tema (emulasi `prefers-color-scheme`) untuk setiap layar; 320 px untuk bar atas/rel (kasus 75c).
4. Peramban: Chromium (otomatis) plus pemeriksaan manusia di Firefox, Safari (macOS/iOS), Chrome Android (kasus 78); `web.css` memakai `:has()` dan kemungkinan `color-mix`/`oklch`.
5. Data: mock (`--peran`, `--fixtures`) hanya untuk uji CSS/tata letak; kebenaran RBAC dan alur data diuji di staging dengan akun fiktif tiap peran (ada 9 varian peran, lihat bagian 5).

## 3. Kasus lama yang dipakai ulang (nomor sheet)

Legenda: W = diuji ulang di web; W* = sheet menulis "Mini App" tetapi web mengizinkan peran itu (`TULIS_WEB`, `BACA_WEB`), jadi diuji di web sebagai cakupan tambahan; M = hanya Mini App (dijaga lewat kelas 74); B = bot/agen AI, tidak relevan untuk UI web.

| Kasus | Peran / topik | Web? | Yang diperiksa ulang pada UI baru |
|---|---|---|---|
| 1 | Admin: Pengguna | W | Formulir, modal undangan, hapus lunak; rel Admin |
| 2, 3, 4, 5 | Admin: SPPG, Titik, Penerima, Bahan/Pemasok/Armada, Keluhan | W | Daftar, formulir, keadaan `.field.invalid`, label tombol Tambah (P12) |
| 6 | Admin hanya baca (negatif) | W | Tombol tulis tidak muncul; rel tidak memuat tautan tulis |
| 7 | Admin: Laporan BGN | W | Input bulan, unduh PDF, tombol utama tidak tertutup |
| 8-18 | Kepala SPPG: titik/penerima, jadwal, 409, log, armada, bukti, notifikasi, rekap, keuangan (negatif), laporan | W | Seg tab Produksi/Distribusi, date/time, modal 409 "Muat ulang", panel Pesan ke PIC, mainbar |
| 19-24 | Ahli Gizi: menu, persetujuan, mutu, jadwal, keluhan, keuangan (negatif) | W | `.komposisi-row`, unggah foto mutu, tab Keluhan (`.seg`), keuangan tidak ada di rel/URL |
| 25-31 | Akuntan: pencairan, pengeluaran, sub-kategori, ajukan ulang, pembelian, laporan, jadwal (negatif) | W | `.rupiah-wrap`, foto nota, notice, KPI anggaran 80/100%, `input[readonly]` |
| 32-35 | Staf Gudang: bahan, pemasok, pembelian, keuangan (negatif) | W | Mutasi stok, badge stok minimum/kedaluwarsa, foto nota |
| 36-43 | Relawan dapur dan distribusi | W* | Rel relawan (modul sedikit), batas pemilik, unit dapur tanpa Jadwal Distribusi, Log Produksi tidak ada di unit distribusi |
| 44-50 | Investor | W | Pemilih SPPG multi, tombol keputusan, hanya baca, nota privat, agregat tanpa data pribadi |
| 51 | Login dan sesi | W | Layar Masuk, bar atas/rel tersembunyi sebelum login, logout, sesi habis, pilihan tema bertahan |
| 52 | URL gambar privat | W | Tidak terpengaruh CSS; pastikan `img-src` tidak diubah |
| 53, 54, 55 | Unggah desktop, ponsel, batas ukuran | W | Input berkas `.sr-only` (kelas 1), tombol Pilih/Ambil foto, progres, pesan galat |
| 56 | Kirim ganda | W | Tombol disabled saat kirim; keadaan disabled terbaca |
| 57 | Responsif 360/768/1024 | W | Inti regresi: kelas 9 (skrip semua layar) |
| 58 | Tema Telegram | M | Kelas 74 (Mini App identik) |
| 59, 60, 61, 62 | Bot dan Agen AI | B | Tidak diuji di rencana ini |
| 39a, 39b | Filter tanggal dan tab `.seg` 360 px | W (dan M) | Keluhan, Pembelian Bahan; regresi A179-1 |
| 40a, 40b, 40e, 40f | Status distribusi, petugas, mutu (API/UI) | W | Ubah status dari UI web; pesan galat 422 terbaca |
| 40c, 40d, 40g | Pesan ke PIC (konfirmasi, dialihkan, panel) | W | Panel "Pesan ke PIC" di detail jadwal, badge status |
| 64 | Batas petugas jadwal (dua relawan) | W | Pesan penolakan di web terbaca, tombol sesuai hak |
| 65 | Jejak audit data master | W | Layar Log Audit Admin |
| 66a, 66b, 66c, 66d | Log Audit: daftar/filter, penyaring data, peran lain 403, akun nonaktif | W | Filter tanggal, detail diff, Log Audit tidak ada di nav peran lain (kelas 73) |

Hitungan: dari 62 kasus sheet, 57 relevan web (1-35 W, 36-43 W*, 44-57 W), 1 hanya Mini App (58), 4 bot (59-62). Ditambah 15 kasus berhuruf/nomor khusus (39a, 39b, 40a-g, 64, 65, 66a-d). Total kasus lama dipakai ulang di web: 72.

### 3.1 Temuan lama yang harus tetap tertutup (regresi CSS)

| ID | Temuan lama | Pemeriksaan ulang |
|---|---|---|
| P2 | Input berkas `.sr-only` tertimpa `.field input` (kamera terblokir) | Kasus 67b (skrip galeri + manual kamera) |
| P3 | Grid detail 3 kolom menggeser label/nilai | Kasus 75b (detail dengan nilai panjang di 768 dan 1280) |
| P4 | Pesan galat foto tampil ganda | Kasus 68a |
| P5 | Input tanggal "SIM berlaku hingga" melebar | Kasus 68e |
| P6, P8 | Tombol "+ Distribusi", redirect Bukti Distribusi | Kasus 40a-g |
| P7 | Banner `peringatan` jadwal (frontend belum menampilkan) | Kasus 10 (periksa banner terbaca di dua tema) |
| P12 | Label "+ Susun Rekap" dan tombol tambah modul lain | Kasus 68a, matriks bagian 4 |
| P13/66 | Layar Log Audit Admin | Kasus 66a-66d |
| A179-1 | `.seg` flex-wrap global | Kasus 69, 75 (semua layar ber-`.seg`: Keluhan, Jadwal, Dashboard, Keuangan, dll.) |

## 4. Kasus baru (67-78): kelas kerusakan khas redesain CSS

Kolom Cara: S = skrip `cek-web.js` (otomatis, wajib bandingkan dengan garis dasar), M = mata/tangan manusia, S+M = keduanya. Sintaks skrip ada di bagian 7.

| No | Kelas | Langkah singkat | Hasil diharapkan | Cara |
|---|---|---|---|---|
| 67a | `[hidden]` | Galeri: 12 elemen ber-`hidden` (panel, tombol, badge, modal-overlay, appbar, mainbar, back, kpi, alert, menu-card, field, seg) | Semua `display:none`, lebar dan tinggi 0 | S |
| 67b | `.sr-only` / input berkas | Galeri + layar Bukti Distribusi, Pengeluaran, Pembelian, Mutu, Log Produksi, Menu | Input berkas ~1 px (bukan 100% x 44 px); tombol Pilih foto membuka dialog berkas; Ambil foto membuka kamera ponsel | S+M |
| 67c | `[hidden]` bar | Layar Masuk (sebelum login); beranda; formulir dan layar tanpa tombol utama | Bar atas, rel, tombol kembali, `#mainbar` tidak terlihat bila `hidden` (aturan `display:flex` baru tidak boleh mengalahkan `[hidden]`); bar muncul hanya sesudah login | S+M |
| 67d | `.dash-slot:empty`, kotak kosong | Dasbor tiap profil (8 profil) dan relawan tanpa unit | Tidak ada celah/kotak kosong; slot kosong `display:none` | S+M |
| 68a | Keadaan galat form | Kirim kosong di Keluhan, Pengeluaran, Jadwal, Menu; galeri `.field.invalid`, `.needs-check` | Bingkai galat terlihat di dua tema; `.field-error` kosong tidak menyisakan ruang; pesan tidak ganda (P4); fokus pindah ke bidang salah pertama dan tidak tertutup bar atas | S+M |
| 68b | `input[readonly]` | Pembelian terverifikasi, Pengeluaran terkunci, Rekap | Terbaca berbeda dari aktif tetapi kontras tetap AA; tidak tampak seperti disabled | S+M |
| 68c | `.rupiah-wrap` | Pencairan/Pengeluaran: nilai 15 digit di 360 px | Awalan "Rp" dan input satu baris, tanpa meluber, fokus ring mengikuti pembungkus | S+M |
| 68d | `.komposisi-row` | Menu: 10 baris, nama bahan 100 karakter di 360 px | Grid select/jumlah/hapus tidak meluber; tombol hapus >= 44 px | S+M |
| 68e | Tanggal/waktu/bulan | Jadwal (date, time), Pengemudi (SIM), filter Keluhan/Pembelian/Rekap, Laporan BGN (month), datetime-local | Tidak melewati induk di 360 px; pemilih bawaan mengikuti tema gelap | S+M |
| 68f | Teks panjang | Nama 120 karakter, URL/email tanpa spasi, catatan 2000 karakter di kartu, detail, tabel, rel, toast | Terbungkus, tidak ada geseran mendatar | S+M |
| 68g | Disabled dan kirim ganda | Kasus 56 di 3 formulir | Tombol disabled terbaca, satu data tersimpan | M |
| 69a | Modal vs bar/rel | Hapus data, dialog undangan, modal 409 Muat ulang | `.modal-overlay` (z 50) menutupi bar atas dan rel; tombol dialog dapat diklik | S+M |
| 69b | Toast | Picu toast sukses/galat di 360 dan 1280 | `.toasts` (z 40) di atas mainbar dan rel, tidak menutupi tombol utama | S+M |
| 69c | Tombol utama | Formulir panjang, gulir sampai bawah | `#mainbtn` dapat diklik di seluruh lebarnya, rel kiri tidak menutupi tepinya, isi terakhir tidak tertutup (`has-mainbar`) | S+M |
| 69d | Menu "Lainnya" | Buka menu overflow rel/nav, lalu picu modal dan toast | Menu overflow di bawah modal dan toast, tertutup Esc, tidak terpotong tepi layar | M |
| 69e | z-index bar/rel | Skrip `tumpuk` | z-index bar atas/rel < mainbar (20); tidak ada elemen fixed/sticky di atas dialog | S |
| 70a | Urutan Tab | Tiap layar: Tab dari awal | Urutan logis (bar atas, rel, isi); ada "lewati ke konten" bila nav panjang | S+M |
| 70b | Cincin fokus | Tab 30x per layar, dua tema | `outline` >= 2 px, kontras >= 3:1 terhadap latar, tidak tertutup elemen lain | S |
| 70c | Area sentuh | Semua tautan, tombol, input, rel, sakelar, pil nav di 360 px | Kotak >= 44 x 44 px (aturan web.css `min-height:44px` tidak boleh hilang); tautan dalam kalimat dikecualikan | S |
| 70d | Nama aksesibel | Rel ikon SVG, sakelar tema, tombol kembali | Setiap ikon punya `aria-label`/teks; `aria-current` pada item aktif | S+M |
| 70e | Papan ketik saja | Masuk, Keluhan > Tambah > Simpan; Esc menutup modal dan fokus kembali ke pemicu | Seluruh alur selesai tanpa tetikus | M |
| 71a | Lencana | Galeri: `badge`, `-ok`, `-info`, `-warn`, `-danger` dua tema | AA 4.5:1 | S |
| 71b | Peringatan | `alert-danger`, `alert-ok`, `notice` | AA | S |
| 71c | KPI | `kpi-ok/-warn/-danger`, nilai, label, sub, progress | AA; status tidak hanya dibedakan warna (ada teks/ikon) | S+M |
| 71d | Tabel | `tbl-row.bad`, `.num.pos`, `.num.neg` | AA (garis dasar lama gagal di `.num.pos` gelap 4.40:1 dan `.num.neg` gelap 4.34:1; build baru tidak boleh lebih buruk) | S |
| 71e | Muted/hint/placeholder | `.muted`, `kpi-label`, `section-title`, placeholder, readonly | AA (garis dasar lama gagal 4.10:1 pada `#707579` di `#f0f0f5`) | S |
| 71f | Tombol dan tautan | `btn`, `btn-primary`, `btn-danger`, `link`, tombol Masuk | AA (garis dasar lama: `btn-primary` 4.13:1 terang dan 3.72:1 gelap) | S |
| 71g | Teks di atas gradien/hero/gambar | Hero beranda, layar Masuk, kartu ber-bayangan | Dinilai manusia (skrip hanya menandai "tak dapat dihitung") | M |
| 71h | Grafik dan toast | Chart.js (seri, grid, legenda) dan `.toast` berwarna tetap | Terbaca di kedua tema; warna grafik mengikuti tema atau cukup kontras di keduanya | M |
| 72a | CSP dengan header produksi | Skrip, semua layar | 0 pelanggaran `style-src`, `font-src`, `script-src`, `img-src` | S |
| 72b | Gaya sebaris | Pindai statis kode baru: `style=`, `setAttribute('style'`, `cssText`, `<style>`, atribut `style` pada SVG, `url(data:` pada CSS, `@import` | Nol (hanya `el.style.x = ...` CSSOM yang boleh) | M (grep) |
| 72c | Font | Jaringan: berkas `.woff2` diminta dari `/web/assets/`; tidak ada `data:` font; `build.assetsInlineLimit` diatur | `font-src 'self'` terpenuhi; font benar-benar terpakai (bukan jatuh ke font sistem diam-diam) | S+M |
| 72d | Sumber luar | Jaringan semua layar | Nol permintaan ke luar origin (tanpa Google Fonts/CDN) | S |
| 72e | CSP di staging | Buka build staging, konsol DevTools, semua peran | 0 pelanggaran di staging (nginx asli) | M |
| 72f | Trusted Types report-only | Hitung laporan per layar | Tidak lebih banyak dari garis dasar | S |
| 72g | Chart.js dan ikon | Dasbor di dua tema | Tidak ada pelanggaran CSP dari Chart.js/SVG | S+M |
| 73a-73i | Menu per peran | Satu kasus per varian: 73a admin, 73b kepala_sppg, 73c ahli_gizi, 73d akuntan, 73e staf_gudang, 73f relawan tanpa unit, 73g relawan dapur, 73h relawan distribusi, 73i investor. Bandingkan tautan nav, rel, dan beranda dengan tabel bagian 5 dan `/auth/me` staging | Tepat sama; tidak ada tautan modul di luar hak; nav = rel = beranda | S (mock) + M (staging) |
| 73j | URL langsung tanpa hak | Tempel `#/keuangan` sebagai ahli gizi/staf gudang, `#/log-produksi` sebagai relawan distribusi, `#/audit-log` sebagai non-admin, `#/penerima-manfaat` sebagai investor | Dialihkan dengan "Halaman itu bukan hak akses Anda." (kasus 24, 31, 35, 43, 50, 66c) | M |
| 73k | Peta rusak/hilang (gagal tertutup) | Mock `/auth/me` tanpa `aksi`; relawan tanpa unit; akun nonaktif (peta kosong) | Rel/nav hanya Beranda dan Sesi, pesan peta rusak tampil, tidak ada rel kosong atau patah | S (fixtures) + M |
| 73l | Banyak vs sedikit modul | Admin (22 tautan) dan relawan tanpa unit (8) di 360/768/1280 dan tinggi 600 px | Rel bisa digulir, "Lainnya" berfungsi; rel sedikit tidak melebar atau bolong | S+M |
| 73m | Investor multi-SPPG | Ganti SPPG aktif (kasus 44, P9) | Nav dan rel dimuat ulang sesuai hak, nama SPPG aktif terlihat, tidak ada data SPPG lain | M |
| 73n | Item aktif dan sub-modul tersembunyi | Buka `jadwal/distribusi`, `keuangan/pencairan`, `menu-gizi/standar-gizi`, `penerima-manfaat-agregat` | Induk ditandai aktif; tidak ada item aktif ganda | S+M |
| 73o | Ikon per modul | Semua modul di registry (23) | Setiap tautan punya ikon SVG atau cadangan; tes emoji lama tidak patah | S+M |
| 74a | Bundel Mini App identik | `bandingkan-dist.js dist/dasar/miniapp dist/baru/miniapp` | IDENTIK byte demi byte (nama berkas memuat hash isi) | S |
| 74b | Sumber Mini App | `git diff origin/develop -- resources/css/miniapp.css resources/miniapp/index.html resources/miniapp/main.js` | Tidak ada perubahan pada `:root` dan aturan non-`body.web`; perubahan `miniapp.css` yang ada diperiksa satu per satu | M (diff) |
| 74c | Ukuran bundel | `node --test resources/miniapp/tests/ukuran-bundel.test.js` tanpa `UPDATE_UKURAN` untuk `miniapp` | Lulus; garis dasar `miniapp` tidak dinaikkan (hanya `web`/`web_total` boleh, dengan alasan di PR) | S |
| 74d | Tampilan Mini App | `cek-web.js --app miniapp --peran kepala_sppg,investor` (stub Telegram.WebApp bawaan skrip) pada build dasar dan baru: 360 dan 768, dua tema; bandingkan PNG (`sha256sum`); manual di Telegram Android, iOS, Desktop (kasus 58) | Piksel identik; 0 pelanggaran baru; terbaca di tema Telegram | S+M |
| 74e | Kebocoran gaya | Halaman Mini App: `body` tidak ber-kelas `web`; `web.css`, font baru, `theme-web.js` tidak diminta | Tidak ada permintaan jaringan ke aset web | S |
| 75a | Geseran mendatar | Setiap layar, 360/768/1280, dua tema: `scrollWidth === clientWidth` | Sama | S |
| 75b | Elemen meluber dari induk | Sama; plus data panjang (fixtures) | Nol elemen melewati induk, nol isi terpotong | S |
| 75c | Lebar minimum | 320 px (body `min-width:320`), lanskap ponsel 740x360 | Bar atas dan rel tetap terpakai tanpa geseran | S+M |
| 75d | Zoom | 200% dan 400% (reflow) | Konten tidak hilang, tidak ada dua arah gulir kecuali tabel | M |
| 75e | Wadah gulir `.tbl` | Tabel lebar di 360 px | Gulir di dalam tabel, bukan di body | S+M |
| 76a | Sakelar tiga keadaan | `--sakelar` (selektor ikon tema) 360 dan 1280 | Ada keadaan sistem, terang tetap, gelap tetap; pilihan bertahan setelah muat ulang | S |
| 76b | Mengikuti sistem langsung | Keadaan "sistem": ubah `prefers-color-scheme` saat halaman terbuka | Berganti tanpa muat ulang | S |
| 76c | Tanpa kedipan | Simpan pilihan gelap, sistem terang, `--tunda-js 600` | Tidak ada transisi terang lalu gelap pada bingkai awal; `color-scheme` meta mencegah putih pada gelap | S |
| 76d | Storage diblokir atau rusak | Mode privat; nilai tersimpan "foo"; kunci dihapus | Jatuh ke sistem tanpa galat konsol | S+M |
| 76e | Lintas rute dan sesi | Ganti tema, pindah rute, logout, login ulang (kasus 51) | Tema bertahan; tidak ada data pribadi di storage; tes `token-storage` dan `api-web` tidak patah | S+M |
| 76f | Dua tab | Ubah tema di tab 1 | Tab 2 mengikuti atau minimal tetap utuh | M |
| 76g | Kontrol bawaan | Pemilih tanggal, scrollbar, `select`, `autofill` pada gelap | Mengikuti tema (tidak putih mencolok) | M |
| 76h | Label sakelar | Tiap keadaan | Label menyebut keadaan dan aksi berikutnya (bahasa Indonesia, tanpa istilah campur) | M |
| 77a | Tes frontend | `node --test resources/miniapp/tests/*.test.js` | Semua lulus (garis dasar 510 lulus pada 19 Nov); tes emoji `menu-icon` tidak patah | S |
| 77b | Build | `npm run build` (dua mode) | Tanpa galat; ukuran web dalam batas +10% atau garis dasar diperbarui dengan penjelasan | S |
| 77c | Unduhan dan lampiran | Unduh PDF Laporan BGN (kasus 7, 18, 30, 49), buka foto lampiran (kasus 52) | Tidak terpengaruh | M |
| 77d | Sesi habis dan offline | Kasus 51; matikan jaringan saat simpan | Pesan Indonesia jelas, isian tidak hilang | M |
| 78a | Peramban lain | Firefox, Safari macOS dan iOS, Chrome Android, 360 dan 1280 | Tidak ada fitur CSS yang gagal diam-diam (`:has()`, `color-mix`, `oklch`, `dvh`, `backdrop-filter`) | M |
| 78b | Preferensi sistem | `prefers-reduced-motion`, `forced-colors` (Windows High Contrast) | Transisi dimatikan; kontrol tetap terlihat | M |
| 78c | Kinerja | Muat dingin web 4G lambat | Tidak ada FOIT font lama; aset awal sesuai `ukuran-bundel.test.js` | S+M |

Jumlah kasus baru: 67a-d (4), 68a-g (7), 69a-e (5), 70a-e (5), 71a-h (8), 72a-g (7), 73a-o (15), 74a-e (5), 75a-e (5), 76a-h (8), 77a-d (4), 78a-c (3) = 76. Total rencana: 72 kasus lama + 76 kasus baru = 148.

## 5. Menu per peran (kasus 73): tautan yang diharapkan

Diturunkan dari `config/rbac.php` (modul ber-huruf R, investor tanpa C/U/D, aturan unit relawan) dan ringkasan aksi di `docs/api/peta-hak.md`. Ini TURUNAN STATIS untuk membandingkan mock; kebenaran akhir = `GET /auth/me` di staging. "Profil Saya" = modul `pengguna` dengan hak `R*` (tanpa daftar). Selalu ada: Beranda dan "Sesi dan Browser".

| Varian peran | Modul (R) | Pintasan/aksi tambahan | Total tautan (modul + tambahan + Sesi) |
|---|---|---|---|
| admin | 18: pengguna, notifikasi-perjalanan, sppg, titik-layanan, penerima-manfaat, bahan-baku, pemasok, menu-gizi, jadwal, log-produksi, mutu, armada, bukti-distribusi, keuangan, keluhan, rekap-harian, persetujuan-menu, pembelian-bahan | dashboard, laporan-bgn, audit-log | 22 |
| kepala_sppg | 18 (sama dengan admin) | dashboard, laporan-bgn | 21 |
| ahli_gizi | 14: pengguna*, sppg, titik-layanan, penerima-manfaat, bahan-baku, pemasok, menu-gizi, jadwal, log-produksi, mutu, bukti-distribusi, keluhan, rekap-harian, persetujuan-menu | dashboard | 16 |
| akuntan | 11: pengguna*, sppg, titik-layanan, bahan-baku, pemasok, log-produksi, armada, bukti-distribusi, keuangan, rekap-harian, pembelian-bahan | dashboard, laporan-bgn | 14 |
| staf_gudang | 9: pengguna*, sppg, titik-layanan, bahan-baku, pemasok, menu-gizi, jadwal, log-produksi, pembelian-bahan | dashboard | 11 |
| relawan tanpa unit | 7: pengguna*, sppg, titik-layanan, menu-gizi, mutu, armada, keluhan | (dashboard false) | 8 |
| relawan dapur | 9: pengguna*, sppg, titik-layanan, menu-gizi, jadwal (produksi), log-produksi, mutu, armada, keluhan | dashboard | 11 |
| relawan distribusi | 10: pengguna*, notifikasi-perjalanan, sppg, titik-layanan, menu-gizi, jadwal (distribusi), mutu, armada, bukti-distribusi, keluhan | dashboard | 12 |
| investor | 8: pengguna*, sppg, bahan-baku, mutu, keuangan, keluhan, rekap-harian, persetujuan-menu | dashboard, laporan-bgn (agregat), penerima-manfaat-agregat; pemilih SPPG bila multi | 12 |

(* = Profil Saya.) Pemeriksaan negatif tegas: ahli gizi/staf gudang/relawan tanpa Keuangan; akuntan/staf gudang/relawan tanpa Jadwal tulis; relawan dapur tanpa Jadwal Distribusi; relawan distribusi tanpa Log Produksi; investor tanpa Penerima Manfaat (daftar), Pembelian Bahan, Pemasok, Armada; non-admin tanpa Log Audit; investor tanpa Laporan BGN jenis non-agregat.

## 6. Matriks 16 fitur x keadaan layar (cakupan fungsional + CSS)

Setiap baris dijalankan per peran berhak (lihat sheet) pada tiga lebar dan dua tema. Keadaan layar yang wajib: daftar kosong, daftar berisi, daftar galat/offline, detail, Tambah, Ubah, modal hapus, galat validasi, sukses (toast). Kolom "Penekan CSS" menyebut elemen yang paling mungkin rusak.

| No | Fitur | Hash web | Kasus lama | Penekan CSS khas |
|---|---|---|---|---|
| 1 | Data Pengguna | `#/pengguna`, `/baru`, `/:id`, `#/sesi` | 1, 51 | Modal undangan, `invite-link`, `.seg`, Profil Saya |
| 2 | Notifikasi Perjalanan | `#/notifikasi-perjalanan` | 15, 42, 64 | Pratinjau, badge status, countdown |
| 3 | Data SPPG | `#/sppg` | 2 | `input[readonly]` (no_va), `.detail` |
| 4 | Data Titik Layanan | `#/titik-layanan` | 2, 8 | Undang PIC, kartu panjang |
| 5 | Penerima Manfaat | `#/penerima-manfaat`, `#/penerima-manfaat-agregat` | 3, 8, 50 | Agregat sel kecil (nilai disamarkan), tabel |
| 6 | Bahan Baku | `#/bahan-baku`, stok menipis, mutasi | 4, 32 | Badge stok/kedaluwarsa, tabel batch, input angka |
| 7 | Pemasok | `#/pemasok` | 4, 33 | Harga pemasok, form |
| 8 | Menu dan Gizi | `#/menu-gizi`, `/standar-gizi`, rincian biaya | 19, 20 | `.komposisi-row`, total gizi, ajukan ke investor |
| 9 | Jadwal Produksi dan Distribusi | `#/jadwal`, `#/jadwal/distribusi` | 9, 10, 11, 22, 40a-g | `.seg`, date/time, banner peringatan, modal 409, panel PIC |
| 10 | Log Produksi Harian | `#/log-produksi` | 12, 36, 37 | Unggah foto, batas pemilik |
| 11 | Pemeriksaan Mutu | `#/mutu` | 21 | Foto, badge Layak/Tidak |
| 12 | Armada dan Pengemudi | `#/armada`, `#/pengemudi` | 4, 13 | Input tanggal SIM (P5), data pribadi tersembunyi |
| 13 | Bukti Distribusi | `#/bukti-distribusi`, `/baru?jadwal=` | 14, 41, 53-55 | Input berkas `.sr-only`, galeri foto, progres |
| 14 | Data Keuangan | `#/keuangan`, `/pencairan`, `/pengeluaran`, pembelian-bahan, persetujuan-menu | 25-30, 34, 45, 46, 48 | `.rupiah-wrap`, KPI anggaran 80/100%, `progress`, notice ajukan ulang |
| 15 | Feedback dan Keluhan | `#/keluhan` | 5, 23, 39 | `.seg` tab, prioritas kritis (badge-danger) |
| 16 | Rekap Harian | `#/rekap-harian` | 16 | Filter tanggal, status kirim, label "+ Susun Rekap" |
| - | Dasbor (8 profil) | `#/dashboard` | - | Chart.js, `kpi-*`, `.dash-slot`, legenda |
| - | Laporan BGN | `#/laporan-bgn` | 7, 18, 30, 49 | Input bulan, penandatangan, PDF |
| - | Log Audit | `#/audit-log`, `/:id` | 65, 66a-d | Filter tanggal, diff, paginasi |
| - | Beranda, Masuk, Pilih SPPG | `#/`, `#/ganti-sppg` | 44, 51 | Hero, menu-grid, OTP (`.otp`), `.check-row`, nav/rel |

## 7. Skrip pemeriksa otomatis

Lokasi (di luar repo, tidak di-commit): `/tmp/claude-0/-home-claude/42ca4451-5906-51b7-b073-e290c52d2dd0/scratchpad/qa-web/` (`cek-web.js`, `lib/`, `bangun-versi.sh`, `bandingkan-dist.js`). Bantuan: `node cek-web.js --bantuan`.

Alur eksekusi setelah kode mendarat:
1. `./bangun-versi.sh feat/redesain-web-ui baru` (garis dasar sudah dibangun: `dist/dasar` dari `origin/develop` 613a84e).
2. Garis dasar (sudah dijalankan untuk develop, hasil di `hasil/baseline-develop-*`): `node cek-web.js --dist dist/dasar/web --out hasil/dasar-X --tab-maks 20 "#/" galeri tumpuk` dan `... --peran semua "#/"`.
3. Build baru dengan perintah yang sama, tambahkan `--bandingkan hasil/dasar-X/hasil.json`; keluar kode 1 bila ada GAGAL BARU.
4. Sakelar: `--sakelar "<selektor ikon tema>"` (di build WIP terlihat `.tema-btn`, harus dikonfirmasi frontend-dev). Kedipan: `--tema-init "localStorage.setItem('<kunci>','gelap')" --tunda-js 600 --tema terang` (kunci penyimpanan diminta dari frontend-dev).
5. Layar pasca-login: tambahkan hash (`"keluhan=#/keluhan"`) dan `--peran`; isi data lewat `--fixtures`; komponen baru yang belum ada di galeri lewat `--galeri-tambahan`.
6. Mini App: `--app miniapp --dist dist/<x>/miniapp` dan `bandingkan-dist.js`.

Yang dicakup skrip (per layar x lebar x tema): header CSP persis nginx (dibaca dari snippet), galat konsol/`pageerror`, pelanggaran CSP (enforced = GAGAL, report-only = info), permintaan luar origin dan respons >= 400, `scrollWidth === clientWidth`, elemen melewati induk/terpotong/isi meluber, `[hidden]` terlihat, `.sr-only` dan input berkas membesar, kotak kosong, target sentuh >= 44 px (kotak), kontras teks WCAG AA terhadap latar efektif (termasuk komposit alfa, `oklch/color-mix` lewat kanvas, ambang teks besar), kontras placeholder, batas kontrol 3:1, fokus keyboard (cincin, kontras, tertutup), uji susun-tumpuk modal/toast/mainbar/rel, tema efektif cocok dengan emulasi, kedipan tema (sampling latar per bingkai), sakelar tema, font gagal dimuat, tangkapan layar penuh per kombinasi.

Yang TETAP butuh mata/tangan manusia: teks di atas gradien/bayangan hero (ditandai "perlu mata"), estetika dan hirarki visual, kenyamanan keyboard sebenarnya dan pembaca layar, kontrol bawaan peramban (pemilih tanggal), kamera ponsel dan dialog berkas, Telegram Android/iOS/Desktop untuk Mini App, peramban selain Chromium, CSP di nginx staging nyata, RBAC sebenarnya dengan akun fiktif tiap peran (mock hanya meniru peta hak), data panjang dan nyata, kinerja, `forced-colors`, zoom, dan kebenaran teks/label bahasa Indonesia.

## 8. Kriteria lulus dan go/no-go

- NO-GO bila: ada GAGAL BARU (bukan di garis dasar) pada kategori csp, geser-mendatar, meluber, hidden, sr-only, target-sentuh, fokus, tumpuk, tema, kedipan, konsol; atau bundel Mini App tidak identik; atau tautan menu keluar dari hak peran; atau kontras teks lebih buruk dari garis dasar.
- Kontras: target tidak lebih buruk dari garis dasar; kegagalan AA lama (lihat 71d-71f) dicatat sebagai temuan Sedang yang diperbaiki redesain bila build baru lulus, dan memblokir bila masih gagal pada komponen yang disentuh redesain.
- GO bersyarat hanya bila semua kasus Cara = M yang berstatus "wajib sebelum rilis" (72e, 73a-73i di staging, 74d manual, 76a-c manual, 70e) sudah dijalankan manusia.
- Temuan dilaporkan dengan format `docs/qa/README.md` dan dikirim ke `frontend-dev` (layar/CSS) atau `backend-dev` (peta hak, header nginx); `project-manager` untuk prioritas.

## 9. Garis dasar yang sudah dijalankan (develop 613a84e, UI lama)

Skrip dijalankan terhadap build bersih `origin/develop` (dibangun ke `dist/dasar`, deterministik). Hasil di `qa-web/hasil/baseline-develop-*` (scratchpad, bukan repo). Ini bukti bahwa ALAT bekerja dan garis dasar lama; BUKAN penilaian UI baru.

| Cakupan garis dasar (UI lama) | Kombinasi | Hasil |
|---|---|---|
| Layar Masuk + galeri komponen + uji tumpuk, CSP persis produksi, 3 lebar x 2 tema | 18 | 0 pelanggaran CSP enforced, 0 geser mendatar, 0 elemen meluber, 0 `[hidden]` terlihat, 0 `.sr-only`/input berkas membesar, 0 target < 44 px, 0 kegagalan fokus/tumpuk (z modal 50 > toasts 40 > mainbar 20). Satu-satunya GAGAL: kontras AA (93 temuan, lihat 71d-71f). 54 peringatan kontras komponen/placeholder (batas input < 3:1). |
| Beranda 9 varian peran (mock dari `config/rbac.php`), 3 lebar x 2 tema | 54 | GAGAL: 27 x `.muted.small` dan 27 x `.section-title` `#707579` pada `#f0f0f5` = 4.10:1; 54 x peringatan konsol lama "Slug modul ganda dilewati: titik-layanan (./_contoh/index.js)" (Rendah, ada di develop). |
| Mini App (kepala_sppg, investor; stub Telegram.WebApp), 360/768 x 2 tema | 8 | Peringatan konsol lama yang sama; kontras `#999999` pada putih 2.85:1 untuk "Selamat datang" dan badge 3.86:1 (tema Telegram). Tangkapan layar tersimpan sebagai pembanding piksel. |

Catatan: dua build develop berurutan identik byte demi byte untuk web dan Mini App, sehingga `bandingkan-dist.js` sah sebagai bukti "Mini App tidak berubah".

## 10. Risiko

1. CSP (tertinggi): atribut `style` sebaris dan font `data:` jalan lancar di server lokal tanpa header, rusak di staging. Mitigasi: kasus 72a-72e dan 72b (grep statis).
2. Aturan `body.web` kalah spesifisitas dari `miniapp.css` atau sebaliknya (`.field input` vs `.sr-only`, `.seg`), dan `miniapp.css` dimuat web sehingga setiap perubahannya dapat bocor ke Mini App. Mitigasi: kasus 67, 74a-74c.
3. `[hidden]` dikalahkan `display:flex` pada bar/rel baru. Mitigasi: 67a, 67c.
4. Kontras: garis dasar lama sudah gagal AA di beberapa pasangan (Telegram biru `#2481cc` pada putih 4.13:1, `--hint` 4.10:1); redesain yang memetakan ulang variabel dapat memperbaiki atau memperburuk. Mitigasi: 71 dan `--bandingkan`.
5. Sakelar tema: kedipan (tema hanya diterapkan setelah JS jalan; CSP melarang skrip sebaris di `<head>`, jadi pencegah kedipan harus berupa berkas skrip eksternal sinkron atau CSS murni), dan storage diblokir. Mitigasi: 76a-76e.
6. Menu dari `menuFor` + `pintasanWeb`: relawan tanpa unit (8 tautan) dan peta rusak; tes `peta-hak.test.js`, `baca-web.test.js` bisa patah bila ikon emoji diganti. Mitigasi: 73, 77a.
7. Ikon SVG: tombol hanya-ikon tanpa nama aksesibel dan target < 44 px di rel. Mitigasi: 70c, 70d.
8. Fitur CSS baru (`:has()` sudah dipakai di `web.css`, `color-mix`, `oklch`) tidak didukung peramban lama di ponsel pekerja dapur. Mitigasi: 78a.
9. Mock menutupi kebenaran: uji CSS dengan data kosong tidak menangkap data panjang. Mitigasi: `--fixtures`, uji staging dengan data fiktif.
10. Pesan galat/labels sakelar dan nav yang berubah teks dapat mematahkan tes yang mencocokkan string. Mitigasi: 77a.

## 11. Hasil eksekusi terhadap `feat/redesain-web-ui` (HEAD 029bc85 + ukuran-bundel.json) — 22 Nov 2026

Metode: build bersih dari `git archive` (tanpa menyentuh working tree), server uji dengan header CSP dibaca dari `headers-webapp.conf`, Chromium, garis dasar `origin/develop` 613a84e. Yang dijalankan: 170 kombinasi layar x lebar x tema pada build baru (galeri, tumpuk, Masuk, beranda 9 peran, 18 layar pasca-login via mock sesi dengan data kosong, dasbor berfixture), 12 skenario Masuk langkah kode/galat/terkunci, toast, rel, menu per peran, guard URL, peta hak rusak, tema (sakelar, storage rusak/diblokir, kedipan, dua tab), grafik saat ganti tema, Mini App (bundel, 10 tangkapan layar), `node --test resources/miniapp/tests/*.test.js` (546 lulus, 0 gagal). `php artisan test`/pint tidak dijalankan (bukan wilayah QA ini).

Terkonfirmasi (dengan bukti skrip): 0 pelanggaran CSP enforced di semua kombinasi; laporan Report-Only Trusted Types sama dengan garis dasar (6 = 6); font dimuat dari `/web/assets/*.woff2` (tanpa `data:`); 0 geseran mendatar, 0 elemen meluber, 0 `[hidden]`/`.sr-only` yang terlihat, 0 teks di bawah AA (garis dasar: 93 + 27 + 27 kegagalan kontras; baru: 0, termasuk teks di atas gradien yang dinilai dari piksel), 0 cincin fokus tidak terlihat/di bawah 3:1, urutan z modal 50 > toast 40 > mainbar 20 > bar atas/rel tanpa tumpang tindih, toast tidak menutupi tombol utama dan terbaca (kontras 6.57:1 terendah), nav/rel/beranda untuk 9 varian peran sama persis dengan tabel bagian 5 (rel dan beranda 22/21/16/11/14/8/11/12/12 tautan; nav atas tidak memuat "Sesi" dengan sengaja), tidak ada tautan di luar hak, 8 URL tanpa hak dialihkan dengan pesan, peta hak rusak menghasilkan rel/nav minimal tanpa patah, relawan tanpa unit (8 tautan) rel utuh, sakelar tiga keadaan bertahan setelah muat ulang dan mengikuti sistem, nilai tersimpan rusak/localStorage diblokir tidak menimbulkan galat, Mini App: CSS identik, hanya chunk JS berubah (+50 B, sesuai `charts.js`), 10 tangkapan layar identik piksel.

Temuan BARU:

| ID | Keparahan | Ringkas |
|---|---|---|
| W-01 | Tinggi | `web.html` memuat `./tema-awal.js` (relatif). Di alamat `/` (nginx merewrite internal ke `/web/index.html`) berkas diminta ke `/tema-awal.js`: 404 + diblokir nosniff, galat konsol di tiap muat, anti-kedip mati (kedipan terang-ke-gelap terukur). Di `/web/` normal. Saran: `src="/web/tema-awal.js"`. |
| W-02 | Sedang | Grafik tidak ikut tema penuh saat tema diganti: warna seri berubah, tetapi garis grid/sumbu hilang dan label sumbu tetap warna tema sebelumnya (`core/charts-tema-web.js`). Muat ulang menormalkan. |
| W-03 | Sedang | Fokus keyboard pada pil nav di <= 768 px: pil berfokus bisa tersisa sekitar 4 px di tepi kanan `.nav-grup` (browser hanya menggulir bila tak terlihat sama sekali). Saran: `scroll-padding-inline` + `scrollIntoView({inline:'nearest'})` saat fokus. |
| W-04 | Rendah | `.nav-pil` 38 px untuk pointer halus (>= 1024 px dengan mouse); 44 px hanya `@media (pointer: coarse)`. Menyimpang dari aturan 44 px; keputusan PM. |
| W-05 | Rendah | Tab kedua tidak mengikuti perubahan tema dari tab pertama (tetap utuh; menyesuaikan saat muat ulang). |

Tercatat LAMA (ada juga di garis dasar, tidak baru): batas input/select/textarea 1.96:1 (terang) dan 2.70:1 (gelap) < 3:1 (WCAG 1.4.11); peringatan konsol "Slug modul ganda dilewati: titik-layanan (./_contoh/index.js)" (Rendah); relawan dapur ke `#/jadwal/distribusi` dialihkan ke `#/jadwal` tanpa pesan.

Belum diuji: keadaan data berisi/data panjang nyata (mock hanya daftar kosong), modal (hapus, undangan, 409) dan toast lewat alur UI (toast diuji dengan DOM identik `core/ui.js`, bukan lewat pemicu JS), unggah foto/kamera, grafik doughnut (kode `charts-tema-web.js` hanya memperbarui warna bertipe string), dasbor 8 profil dengan bentuk widget asli, Laporan BGN/Pengguna/Log Audit dengan data, CSP di nginx staging, RBAC dengan akun nyata, Firefox/Safari/Chrome Android, zoom 200-400%, `forced-colors`, pembaca layar, Telegram Android/iOS/Desktop. Ukuran awal web naik (gzip aset awal 17.3 KB ke 52.5 KB, total web +24%; garis dasar diperbarui di PR).
