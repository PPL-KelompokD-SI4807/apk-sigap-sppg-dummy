# Sprint Retrospective — Sprint 1 dan Sprint 2 (berjalan)

Tanggal: 24 November 2026
Peserta: tim pengembang SiGAP SPPG (5 anggota) dan tim agen AI
Cakupan: 26 Oktober – 24 November 2026
Metode: evaluasi proses berbasis bukti dari repositori, CI, dan log audit. Bukan kesan subjektif.

## 1. Angka

| Ukuran | Nilai | Sumber |
|---|---|---|
| Pull request ter-merge | 142 | GitHub REST, status `merged` |
| Metode tes PHP | 1.907 di 134 berkas | `grep` atas `tests/` |
| Tes frontend | 584 | `node --test resources/miniapp/tests/*.test.js` |
| Fitur CRUD selesai | 16 dari 16 | Subbab 5.2 |
| Paket revisi pemangku kepentingan | 7 (R-01 sampai R-07) | ADR di `docs/decisions/` |
| Issue terbuka | 20 | 19 pasca-rilis, 1 Dependabot |
| Hari dengan merge terbanyak | 22 PR (14 Nov), 20 PR (13 Nov) | GitHub REST |

Kecepatan tidak merata: tiga hari menyumbang 57 PR, sementara beberapa hari tanpa merge sama sekali. Pola ini wajar untuk kerja paralel lima orang, tetapi menyulitkan perkiraan kapasitas sprint berikutnya.

## 2. Yang berjalan baik

**Pengirisan vertikal per anggota.** Setiap anggota menyelesaikan fiturnya dari migrasi sampai layar, bukan per lapisan. Akibatnya tidak ada fitur yang menunggu lapisan lain selesai, dan seluruh 16 fitur tuntas dalam Sprint 1.

**Tes sebagai jaring pengaman yang benar-benar menangkap.** Bukan sekadar ada. Contoh nyata: uji regresi otomatis 170 kombinasi layar, lebar, dan tema menemukan bug Tinggi sebelum rilis — skrip anti-kedip tema gagal dimuat di alamat `/` karena jalur relatif, padahal lolos di `/web/`. Tanpa uji itu, setiap pengunjung `sigapsppg.app` akan melihat kedipan putih.

**CI menangkap apa yang tidak bisa ditangkap lokal.** Dua kali CI merah menyelamatkan perubahan yang tampak benar di mesin pengembang.

**Keputusan dicatat sebagai ADR.** Saat keputusan R04 perlu direvisi pada 23 November, jejaknya ada: apa yang diputuskan, mengapa, dan apa konsekuensinya. Revisinya pun dicatat beserta apa yang hilang.

**Pencadangan ganda.** Dua tujuan di penyedia berbeda (Amazon S3 dan Google Drive), terenkripsi, terjadwal, dan sudah diuji manual.

## 3. Yang tidak berjalan baik

### 3.1 Agen memverifikasi pekerjaannya sendiri dan tetap meleset

Dua kejadian nyata dalam satu hari:

- Agen menyunting `docs/api/openapi.yaml` padahal berkas itu hasil bangun dari `docs/api/paths/` dan `docs/api/components/`. CI merah. Lebih buruk, bila tidak ketahuan, dokumentasi itu akan terhapus pada pembangunan berikutnya.
- Nama field baru `kirim_ulang_sisa` tidak konsisten dengan jalur Mini App yang sudah memakai `sisa_kirim_ulang` untuk hal yang sama.

Keduanya lolos dari pemeriksaan agen sendiri dan baru tertangkap oleh CI dan tinjauan manusia. Pelajaran: laporan agen bahwa "semua tes lulus" bukan bukti bahwa pekerjaannya benar.

### 3.2 Lingkungan pengembang menyimpang dari CI

- Kontainer kerja kehilangan ekstensi PHP `bcmath`, sehingga 47 tes yang menghitung uang gagal dengan `Call to undefined function bcmul()`. Sempat terbaca sebagai regresi.
- Lima berkas tes menghitung "hari ini" dengan tanggal UTC sementara aplikasi memakai tanggal lokal. Tes selalu merah antara 00.00 dan 07.00 WIB. CI berjalan di UTC sehingga tidak pernah terkena.

Keduanya memakan waktu diagnosis yang seharusnya tidak perlu, dan keduanya berisiko membuat tim mengabaikan tes merah.

### 3.3 Pekerjaan dibangun lalu dibuang

Mesin tantangan tiruan dibangun lengkap dengan tes pada 23 November, lalu dihapus seluruhnya pada 24 November ketika pemilik produk memutuskan kredensial salah harus ditolak di layar kata sandi.

Akar masalahnya bukan teknis. Keputusan perilaku yang terlihat pengguna diserahkan ke PM, padahal yang berhak memutuskan adalah pemilik produk. Pertanyaannya baru diajukan setelah implementasi berjalan.

### 3.4 Dokumentasi menyimpang dari perilaku

Panduan "Kode tidak datang?" menyebut sebab yang sudah tidak mungkin terjadi. Dokumen rencana R04 masih menggambarkan respons seragam versi lama. Keduanya diperbaiki, tetapi hanya karena ada yang sengaja memeriksa.

### 3.5 Kebersihan repositori

Terdapat lebih dari 170 cabang jauh, sebagian besar sudah ter-merge dan basi. Daftar cabang menjadi sulit dibaca dan berisiko salah pilih.

### 3.6 Upacara Scrum tidak lengkap

Sprint Review dijalankan. Retrospective tidak pernah dijalankan sampai dokumen ini, yaitu di pertengahan Sprint 2. Akibatnya perbaikan proses pada bagian 4 baru dirumuskan sekarang, bukan setelah Sprint 1.

## 4. Tindakan untuk Sprint 2

| No | Tindakan | Pemilik | Ukuran keberhasilan |
|---|---|---|---|
| 1 | Tambah pemeriksaan CI yang gagal bila berkas hasil bangun disunting tangan | backend-dev | `openapi.yaml` yang tidak sinkron membuat CI merah sebelum tinjauan manusia |
| 2 | Daftar periksa kontrak API: nama field baru wajib dicocokkan dengan konvensi yang sudah ada di Mini App dan web | PM | Tidak ada lagi dua nama untuk satu hal |
| 3 | Dokumentasikan ekstensi PHP wajib dan tambahkan pesan gagal yang jelas saat `bcmath` tidak ada | backend-dev | Pengembang baru tahu penyebabnya dalam satu menit |
| 4 | Perilaku yang terlihat pengguna diputuskan pemilik produk sebelum implementasi, bukan sesudah | PM | Tidak ada lagi pekerjaan yang dibangun lalu dibuang |
| 5 | Hapus cabang yang sudah ter-merge | github-ops | Daftar cabang di bawah 30 |
| 6 | Retrospective dijadwalkan di akhir setiap sprint | PM | Dokumen retrospektif ada untuk Sprint 2 |
| 7 | Jalankan uji beban k6 dan evaluasi Agen AI yang skripnya sudah siap tetapi belum pernah dijalankan | QA | NFR-01 dan NFR-11 punya angka nyata, bukan hanya target |

## 5. Yang belum terukur

Jujur dicatat agar tidak dianggap selesai:

- **NFR-01 kinerja API**: skrip k6 ada, belum pernah dijalankan. Tidak ada angka p95.
- **NFR-11 akurasi Agen AI**: 100 kasus uji tersedia, evaluasi belum dijalankan terhadap LLM sungguhan.
- **NFR-08 skor SUS**: lembar UAT dan SUS sudah disiapkan, responden lapangan belum ada.
- **Pemeriksaan manual lintas peramban dan Telegram ponsel**: belum dijalankan.

Keempatnya memerlukan eksekusi di server atau responden manusia, dan tidak dapat dipenuhi dengan data buatan.
