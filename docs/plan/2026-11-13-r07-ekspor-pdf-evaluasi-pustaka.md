# R07-3a Evaluasi Pustaka PDF (dompdf, mPDF, FPDF) - Perbandingan dan Rekomendasi

Tanggal: 2026-11-13 · Status: **dokumen perbandingan; belum ada dependensi dipasang, belum ada kode** · Tier: T2 · Menunggu persetujuan pengguna sebelum `composer require` apa pun.
Terkait: `docs/plan/2026-11-11-r07-ekspor-pdf-bgn.md` (R07-3a, R07-3, risiko K5, K9; Keputusan Q-6, Q-10, Q-11). `claude/keputusan-r07-pdf-bgn.md` tidak ada di repo, tidak dipakai.

## 1. Fakta proyek yang menentukan pilihan (diperiksa di repo, develop 1528b2c)
- `composer.json` tidak memuat pustaka PDF apa pun; `vendor/` tidak ada di worktree. Satu-satunya "Barryvdh" di `composer.lock` adalah nama penulis paket lain, bukan dompdf. Jadi dompdf **bukan** bawaan; semua opsi sama-sama baru.
- Citra produksi `php:8.4-fpm-alpine`; ekstensi sudah ada: `gd` (freetype, jpeg, webp), `intl`, `zip`, `bcmath`, `pcntl`, `opcache`; `dom` dan `mbstring` bawaan citra. Tidak perlu ekstensi baru untuk ketiga opsi (dompdf butuh `dom`+`mbstring`; mPDF butuh `gd`+`mbstring`; FPDF butuh `zlib`+`gd` bila ada gambar PNG).
- **Batas memori yang berlaku untuk Job PDF (hal penting, belum tercermin di rencana R07-3):** Job berjalan di kontainer `queue` dengan `mem_limit` **192m** (`docker-compose.prod.yml`), `php.ini` `memory_limit = 192M`, worker `--memory=128 --timeout=80`. Rencana R07-3 menulis target "< ~256 MB per ekspor"; angka itu **melampaui** plafon 192M dan akan berujung fatal error. Total `mem_limit` kontainer sekarang 48 + 448 + 192 + 160 + 640 = 1.488 MB dari VPS 1,9 GB, sisa sekitar 400 MB untuk OS dan Docker, sehingga menaikkan `MEM_QUEUE` juga bukan pilihan murah. Target realistis: **puncak `memory_get_peak_usage(true)` <= ~120 MB termasuk bootstrap Laravel** (Laravel sendiri sekitar 30-40 MB), batas waktu < 30 s (timeout job 80 s).
- Foto nota tersimpan maks 1600 px sisi panjang (`config/unggahan.php`, `maks_sisi_simpan_px`). Untuk lampiran PDF tetap perlu diperkecil lagi dengan GD (disarankan ~800-900 px, JPEG kualitas ~75) agar memori dan ukuran berkas terkendali, apa pun pustakanya.
- Kertas juknis = 612 x 936 pt (Folio AS, 215,9 x 330,2 mm; terukur dari PDF sumber). Bukan 210 x 330 mm persis; selisih 5,9 mm lebar, tidak terlihat. Q-11 memakai istilah "F4/Folio"; yang dipakai di kode sebaiknya 612 x 936 pt agar identik dengan juknis.
- Isi per jenis ekspor: 30a lanskap; 30b potret; 30c potret; **30d = A potret + B potret + C lanskap dalam satu laporan** (campuran orientasi); halaman lampiran foto potret.
- Mesin PHP di mesin pengembang lokal 8.2.12, **bukan** 8.4; spike harus dijalankan di citra Docker 8.4.
- Berkas `LICENSE` tidak ada di repo; `composer.json` mencantumkan `MIT` (bawaan skeleton Laravel). Status lisensi proyek perlu ditegaskan pengguna bila mPDF dipertimbangkan (lihat risiko L1).

## 2. Versi terkini dan kompatibilitas (diverifikasi via Packagist/GitHub, 2026-11-13)
| Pustaka | Versi stabil terbaru | Syarat PHP | Ekstensi/dependensi wajib | Lisensi |
|---|---|---|---|---|
| `dompdf/dompdf` | 3.1.6 (20 Jul 2026) | `^7.1 \|\| ^8.0` (8.4 tercakup; 3.1.1 menambah perbaikan PHP 8.5) | ext-dom, ext-mbstring, masterminds/html5, dompdf/php-font-lib, dompdf/php-svg-lib | LGPL-2.1 |
| `mpdf/mpdf` | 8.3.1 (11 Mar 2026) | daftar eksplisit termasuk `~8.4.0`, `~8.5.0` | ext-gd, ext-mbstring, setasign/fpdi, myclabs/deep-copy, psr/log, psr/http-message, shim | **GPL-2.0-only** |
| `setasign/fpdf` | 1.9.0 (1 Jun 2026) | tidak dibatasi (perlu uji `E_ALL` di 8.4, lihat spike) | ext-zlib, ext-gd | MIT (paket Packagist; FPDF asli: bebas dipakai dan diubah, tanpa pembatasan) |

Catatan:
- Laravel 13 tidak menjadi masalah: ketiganya bukan paket Laravel; dipanggil langsung dari Service/Job. Pembungkus `barryvdh/laravel-dompdf` v3.1.2 memang menyatakan dukungan Laravel 13, tetapi **tidak dibutuhkan** dan sebaiknya dihindari (satu lapis tambahan, konfigurasi default longgar). Usulan: antarmuka tipis `PdfRenderer` di `app/Services/` agar pustaka dapat ditukar tanpa mengubah agregator.
- Angka versi dan tanggal berasal dari `repo.packagist.org` (dibaca saat evaluasi); pin versi tepat di `composer.lock` saat dipasang.

## 3. Tabel perbandingan
Tanda: [V] diverifikasi dari dokumentasi/sumber resmi hari ini; [P] pengetahuan umum pustaka, **perlu dibuktikan di spike**.

| Kriteria | dompdf 3.1.6 | mPDF 8.3.1 | FPDF 1.9.0 |
|---|---|---|---|
| Pendekatan | HTML/CSS 2.1 -> PDF (PHP murni, backend CPDF) | HTML/CSS -> PDF (PHP murni) | API imperatif (Cell/MultiCell/Line), tanpa HTML |
| Ukuran kertas Folio 612x936 pt | ya, `folio` = 612x936 [V]; `setPaper('folio','landscape')` | ya, `FOLIO` = 612x936 [V]; nama `F4` **tidak ada** (pakai `FOLIO`) | ya, `AddPage('P', [215.9,330.2])` atau ukuran pt [P] |
| Orientasi campuran dalam satu berkas (30d: A/B potret + C lanskap) | **Tidak didukung** untuk satu dokumen: `setPaper` bersifat dokumen; named pages tidak didukung (laporan komunitas, tidak ada entri di matriks CSS resmi). Jalan keluar: dua berkas terpisah atau gabung memakai FPDI (dependensi tambahan) | **Ya**: `@page rotated {size: landscape}` + `page: rotated`, atau `<pagebreak orientation="L">` [V] | **Ya**: `AddPage('L')`/`AddPage('P')` per halaman [P] |
| Tabel besar, multi-halaman | baik untuk ratusan baris; `thead` berulang [P]; `page-break-inside` baris didukung sejak 1.1.0, **tidak** pada row group [V]; tata letak tabel otomatis memakai memori nyata [P] | baik; `thead` dan `tfoot` berulang [P]; ada opsi hemat memori `simpleTables`, `packTableData` [V] | manual: helper tabel (tinggi baris dihitung sendiri, ulang header saat ganti halaman); sepenuhnya deterministik [P] |
| Header/footer berulang + "Halaman x dari y" | `position: fixed` / `$canvas->page_text()` setelah render (hindari `isPhpEnabled`) [P] | `SetHTMLHeader/Footer`, `{PAGENO}`, `{nbpg}`, per `@page` [V] | override `Header()`/`Footer()`, `AliasNbPages` [P] |
| colspan/rowspan (kepala bertingkat 30a, 30d-C 17 kolom) | ya (HTML) | ya (HTML) | colspan manual; rowspan perlu geometri manual (lebar kolom diketahui, tetap) |
| Unicode/font | UTF-8; DejaVu Sans bawaan; font lain via `@font-face` TTF (`unicode-range` tidak didukung) [V] | UTF-8 asli; bundel font DejaVu/FreeSerif dll. (berat) [P] | font inti hanya cp1252 (cukup untuk teks Indonesia Latin, perlu konversi `iconv` dari UTF-8; karakter di luar cp1252 menjadi "?"); UTF-8 penuh butuh tFPDF/`addFont` [P] |
| Kemiripan dengan Lampiran 30 (pindaian tabel berborder, kepala berkelompok, blok TTD tiga kolom) | tinggi dan cepat dibuat (HTML/CSS); iterasi visual murah | tinggi, sama dengan dompdf, kendali CSS lebih lengkap | tinggi dan presisi tertinggi (posisi tiap sel dikendalikan), biaya kode paling besar |
| Memori (hipotesis, belum terukur; kasus: 30d-C 17x31 + 20 foto, Folio) | sedang: sekitar 40-100 MB [P]; dikenal boros untuk dokumen sangat besar; JPEG disisipkan tanpa decode, PNG didecode | sedang: sekitar 30-90 MB [P]; dokumentasi resmi menyebut tabel dapat menghabiskan memori cepat dan menyarankan `simpleTables`, `packTableData`, hindari PNG alpha/interlaced [V] | rendah: sekitar 5-20 MB [P] |
| Ukuran dependensi (perkiraan, ukur saat spike) | sedang: puluhan paket kecil + font; sekitar belasan MB [P] | **besar**: bundel font TTF; sekitar puluhan MB [P] | **sangat kecil**: satu berkas inti (sekitar ratusan KB dengan font) [P] |
| Pemeliharaan | aktif; rilis 3.1.x rutin; **6 advisory Moderat dipublikasikan 20 Jul 2026 (diperbaiki di 3.1.6)**: DoS BMP/bitmap besar, kebocoran keberadaan berkas lewat SVG dan `@font-face`, bypass chroot, baca berkas lokal lewat SVG data-URI; riwayat 3 advisory Kritis SVG/RCE (2023-2024) pada `php-svg-lib` [V] | aktif (8.3.1 Mar 2026); **tidak ada advisory terpublikasi** di GitHub [V] | stabil, jarang berubah (sengaja minimalis); rilis 1.9.0 Jun 2026 |
| Keamanan terhadap input tak tepercaya | permukaan serangan terbesar di antara tiga: parser HTML/CSS, pemuat gambar/SVG/font, akses berkas lokal/jarak jauh. Wajib: `isRemoteEnabled=false`, `isPhpEnabled=false`, `chroot` ke satu folder, tanpa SVG/BMP, hanya HTML dari Blade (nilai di-escape) | permukaan serupa (HTML/CSS, gambar, `<img src>` berkas lokal); wajib escape semua nilai, tanpa konten pengguna mentah; matikan `curl` jarak jauh | **terkecil**: tidak ada parser HTML/CSS; teks pengguna hanya melewati escaping string FPDF; gambar hanya JPEG/PNG hasil GD kita sendiri |
| Lisensi vs proyek | LGPL-2.1: boleh dipakai sebagai pustaka Composer oleh aplikasi berlisensi apa pun, selama tidak mengubah pustaka dan tetap mematuhi ketentuan LGPL saat distribusi | **GPL-2.0-only**: bila aplikasi didistribusikan (mis. repo publik berlisensi MIT, citra Docker disebar) gabungan wajib mengikuti GPL; internal di satu VPS tidak memicu kewajiban, tetapi menjadi beban hukum bila proyek dirilis | permisif; tanpa beban |
| Biaya penulisan | rendah (Blade + CSS; agen sonnet terbiasa) | rendah | tinggi (helper tabel, hitung tinggi baris, uji tata letak) |
| Pengujian PDF | uji data di Service; uji PDF hanya `%PDF`/jumlah halaman/ukuran | sama | sama; plus tata letak murni kode sehingga unit-test geometri mungkin |

## 4. Risiko
| No | Risiko | Pustaka | Mitigasi |
|---|---|---|---|
| L1 | Lisensi GPL-2.0-only tidak cocok bila proyek dirilis terbuka/permisif | mPDF | Pengguna menegaskan lisensi proyek; hindari mPDF bila ada rencana rilis permisif |
| M1 | Puncak memori melebihi 192M di kontainer `queue` (fatal error saat Job) | semua, paling mungkin dompdf/mPDF dengan foto | ukur di spike dengan `memory_get_peak_usage(true)`; perkecil foto dengan GD; satu Job per berkas; batasi jumlah lampiran per berkas (mis. 20); tulis ke berkas sementara lalu kirim |
| M2 | Rencana R07-3 menulis target < ~256 MB, melebihi plafon 192M | rencana | koreksi target menjadi <= ~120 MB puncak (persetujuan pengguna) |
| O1 | Orientasi campuran 30d tidak mungkin dalam satu berkas | dompdf | pisah 30d-A/B dan 30d-C menjadi dua berkas (keputusan pengguna) atau tambah FPDI; atau pilih FPDF/mPDF |
| S1 | Injeksi HTML/CSS/SVG lewat nama sekolah, nama bahan, keterangan, dll. | dompdf, mPDF | semua nilai lewat `e()`/`htmlspecialchars`; tanpa SVG; `isRemoteEnabled=false`, `chroot`; uji payload di spike |
| S2 | 6 advisory baru dompdf (Jul 2026) menunjukkan beban patch berkelanjutan | dompdf | pin >= 3.1.6; `composer audit` di CI (usulan ke github-ops); tidak menerima URL/gambar dari pengguna |
| U1 | Karakter di luar cp1252 menjadi "?" | FPDF | data pengguna berbahasa Indonesia (Latin); uji nama dengan aksen dan tanda kutip tipografis; konversi `iconv` dengan `//TRANSLIT`; tFPDF bila perlu |
| B1 | Biaya kode helper tabel membengkak, jadwal 3-7 Des | FPDF | batasi spike untuk mengukur usaha; lingkup layar tetap sesuai Spesifikasi |
| P1 | Deprecation PHP 8.4 (mis. parameter nullable implisit) muncul di log | FPDF, pustaka lama | uji `error_reporting(E_ALL)` di citra 8.4; `php.ini` produksi sudah mengecualikan `E_DEPRECATED` tetapi Laravel dapat mencatatnya |
| F1 | Hasil visual berbeda dari juknis (pindaian 612x936 pt) | semua | R07-5 uji berdampingan; spike membandingkan 30d-C dengan hlm 266 |

## 5. Rencana uji kecil (spike), disarankan ~0,5-1 hari, **setelah persetujuan pengguna**
Dilakukan di **direktori sementara terpisah dengan `composer.json` sendiri** (di luar `app/`, tidak menyentuh `composer.json`/`composer.lock` proyek), di dalam citra `php:8.4-cli-alpine` dengan ekstensi sama seperti `Dockerfile`, `memory_limit=192M` dan `max_execution_time` sama dengan produksi. Data fiktif. Hasil dilaporkan ke PM sebagai tabel; kode spike dibuang.
1. **S1 30d-C**: 17 kolom x 31 baris + 1 baris total, kepala 3 tingkat, Folio lanskap. Bandingkan visual dengan juknis hlm 266 (indeks PDF).
2. **S2 30d-B**: tabel 200 dan 1.000 baris potret, header tabel berulang, footer "Halaman x dari y" dan catatan kaki Q-12 pada tiap halaman.
3. **S3 30b**: 17 baris operasional + tiga blok tanda tangan di bagian akhir; pastikan blok TTD tidak terpotong lintas halaman.
4. **S4 campuran**: satu berkas 30d: A potret, B potret, C lanskap (lulus/gagal untuk tiap pustaka).
5. **S5 lampiran foto**: 20 foto nota JPEG 1600 px diperkecil GD ke 900 px, dua per halaman.
6. **S6 teks dan injeksi**: nama dengan aksen, tanda kutip tipografis, apostrof, nama sangat panjang; muatan uji `<script>`, `<img src="file:///...">`, `<svg>`, `@import`, `<link>`, `<iframe>` pada kolom teks; harus tampil sebagai teks biasa atau ditolak, tanpa membaca berkas atau jaringan.
7. **Ukur**: `memory_get_peak_usage(true)` per kasus, waktu dinding, ukuran berkas, ukuran `vendor/` (`du -sh`), jumlah paket; verifikasi MediaBox 612x936 dengan `pdfinfo` dan orientasi per halaman; jalankan dengan `error_reporting(E_ALL)` untuk deprecation PHP 8.4.
8. **Ambang lulus**: puncak <= ~120 MB (kasus terberat S1+S5), waktu < 30 s, S4 lulus atau jalan keluar disetujui, S6 aman, visual diterima pengguna terhadap juknis, usaha kode FPDF (jam) dilaporkan.
Siapa: `backend-dev` (T2, sonnet/high) setelah R07-2; PM meninjau tabel hasil.

## 6. REKOMENDASI (untuk persetujuan pengguna)
**Pilih `setasign/fpdf` (^1.9, MIT) sebagai pustaka utama R07-3, dibungkus antarmuka `PdfRenderer` dan helper tabel internal, dengan `dompdf/dompdf` ^3.1.6 sebagai cadangan yang diuji pada spike yang sama.** Alasan: (1) kontainer `queue` hanya 192 MB dan VPS 1,9 GB sudah hampir penuh, FPDF paling hemat memori dan ukuran dependensi; (2) laporan 30d memerlukan orientasi campuran (A/B potret, C lanskap) dalam satu berkas, yang tidak didukung dompdf tanpa dua berkas atau FPDI, tetapi wajar di FPDF dan mPDF; (3) Lampiran 30 adalah formulir tabel dengan lebar kolom tetap, sehingga tata letak imperatif memberi kemiripan paling deterministik pada Folio 612 x 936 pt dan permukaan serangan terkecil (tanpa parser HTML/CSS/SVG, relevan karena dompdf baru menerbitkan 6 advisory pada Juli 2026); (4) lisensi permisif tanpa beban hukum. Harga yang dibayar: kode tata letak lebih panjang dan font inti cp1252 (cukup untuk teks Indonesia Latin). mPDF tidak direkomendasikan karena GPL-2.0-only dan bobot dependensi, kecuali pengguna memastikan proyek tidak akan dirilis terbuka dan lebih mengutamakan kecepatan pembuatan lewat HTML/CSS. Bila spike menunjukkan usaha FPDF terlalu besar atau pengguna lebih memilih HTML/CSS, pilih dompdf 3.1.6 dengan 30d dipecah menjadi dua berkas (A+B potret, C lanskap) dan konfigurasi aman (`isRemoteEnabled=false`, `isPhpEnabled=false`, `chroot`, tanpa SVG). Catatan: ini menyimpang dari bawaan PM sebelumnya ("dompdf bila uji memori lolos") karena dua temuan baru (plafon 192M dan 30d campuran orientasi).

## 7. Keputusan yang dibutuhkan dari pengguna
1. Setujui pilihan utama: FPDF (rekomendasi), dompdf, atau mPDF.
2. Setujui spike di direktori sementara (tanpa mengubah `composer.json` proyek) dan ambang lulus pada bagian 5; `composer require` ke proyek baru setelah hasil spike disetujui.
3. Koreksi target memori R07-3 dari "< ~256 MB" menjadi "<= ~120 MB puncak" (plafon queue 192M), atau setujui menaikkan `MEM_QUEUE` (tidak disarankan, sisa RAM VPS sekitar 400 MB).
4. Bila dompdf dipilih: 30d dikirim sebagai dua berkas (A+B dan C) atau tambah FPDI?
5. Tegaskan lisensi proyek (tidak ada berkas `LICENSE`; `composer.json` = MIT) bila mPDF dipertimbangkan.
6. Izin menambahkan `composer audit` ke CI (usulan untuk `github-ops`).

Sumber verifikasi (dibaca 2026-11-13): repo.packagist.org (dompdf/dompdf, mpdf/mpdf, setasign/fpdf), github.com/dompdf/dompdf (advisory, wiki CSSCompatibility, `src/Adapter/CPDF.php`), github.com/mpdf/mpdf (advisory, `src/PageFormat.php`), mpdf.github.io (Using @page, Memory problems), github.com/barryvdh/laravel-dompdf (rilis). Tidak ada data proyek yang dikirim ke layanan luar.
