# Temuan uji per peran (P1-P13) dan kasus 64 — 21 Nov 2026

Penguji: agen `qa-engineer`. Dasar kode: develop d0cf41e. Nomor PR diisi oleh pemanggil (placeholder `PR frontend #?`, `PR AI #?`, `PR backend #?`). Data uji fiktif.

Legenda status: Lulus = terbukti lewat langkah/tes yang dijalankan; Diperbaiki = perbaikan ada di PR, belum diuji ulang oleh QA kecuali dinyatakan; Terbuka = belum dikerjakan.

## 1. Tabel temuan

| ID | Peran / kasus | Temuan | Tingkat | Status | PR |
|---|---|---|---|---|---|
| P1 | Form Keluhan (web) | Semula dilaporkan bermasalah; DIBATALKAN. Uji ulang di d0cf41e: Lulus. Kode tidak diubah. | - | Dibatalkan (Lulus) | - |
| P2 | Akuntan, Pengeluaran > foto nota (Mini App, kasus 26) | Kamera terblokir di Mini App. Web lulus; Bukti Distribusi Mini App lulus. Penyebab BELUM terbukti. Hipotesis: tombol `<label for>` dengan CSS `.sr-only` tertimpa `.field input`; tombol kini memanggil `input.click()`. WAJIB uji ulang di Telegram Android. | Tinggi | Diperbaiki (belum terverifikasi di perangkat) | PR frontend #? |
| P3 | Web, detail | Grid 3 kolom menggeser label/nilai. | Rendah | Diperbaiki | PR frontend #? |
| P4 | Mini App, Bukti Distribusi | Pesan "Foto serah terima wajib diisi." tampil ganda. | Rendah | Diperbaiki | PR frontend #? |
| P5 | Mini App, pengemudi | Input tanggal "SIM berlaku hingga" melebar. | Rendah | Diperbaiki | PR frontend #? |
| P6 | Web, detail jadwal produksi | Tombol "+ Distribusi" dan keterangan undang PIC Telegram ditambahkan. | Rendah | Diperbaiki | PR frontend #? |
| P7 | Jadwal, jam berangkat < jam mulai masak | Kini mengembalikan field `peringatan` (bukan 422). Tindak lanjut: frontend belum menampilkan banner `data.peringatan`. | Sedang | Backend diperbaiki; tampilan frontend Terbuka | PR backend #? |
| P8 | Status jadwal "diterima" -> Bukti Distribusi | Ringan diperbaiki: redirect ke `#/bukti-distribusi/baru?jadwal=<id>` bila berhak. Selidik server: PATCH status jadwal (siap, berangkat) tidak mengirim pesan. Pesan ke PIC datang dari jalur notifikasi-perjalanan terpisah: mulai -> PerjalananDimulai ke PIC (status jadwal tidak berubah); Bukti Distribusi -> PerjalananTiba ke PIC hanya bila ada perjalanan aktif; keterlambatan -> Kepala SPPG. Penyatuan jalur menunggu persetujuan manusia; tidak ada pengiriman baru ditambahkan. | Sedang | Redirect diperbaiki; penyatuan jalur menunggu keputusan | PR frontend #? |
| P9 | Investor, menu Ekspor Laporan BGN (kasus 49) | Menu tak terlihat. Penyebab frontend: peta hak dimuat tanpa X-SPPG-Id; kini dimuat ulang setelah SPPG dipilih. Server benar: `GET /auth/me` tanpa header false, dengan header true; `LaporanBgnPolicy` tidak diubah. | Sedang | Diperbaiki | PR frontend #? |
| P10 | Akuntan minta ubah sub-kategori pengeluaran via agen (kasus 60) | Ditolak agen padahal berhak. Penyebab: aturan 3 prompt mengarahkan semua penolakan ke "Kepala SPPG/Admin"; tool hanya menerima `target_id` sementara aturan 9 melarang menanyakan ID. Perbaikan: `kata_kunci`, `HakModulAi`, pesan menyebut peran berhak. Kepala SPPG ditolak = perilaku benar. | Tinggi | Diperbaiki | PR AI #? |
| P10b | Mini App, agen | Kotak merah "null" tampil. | Sedang | Diperbaiki | PR frontend #? |
| P11 | Agen vs keluhan | Akuntan tanpa modul = benar; pesan kini jelas "Anda tidak punya akses ke data keluhan"; kata "terbaru" tak lagi dianggap niat tambah. | Sedang | Diperbaiki | PR AI #? |
| P12 | Rekap Harian | Status kirim web tak segar; filter tanggal Mini App melebar/tanpa placeholder; label "+ Susun Rekap". Efek samping: label tombol tambah modul web lain ikut berubah (periksa saat uji ulang). | Rendah | Diperbaiki | PR frontend #? |
| P13 | Admin, log audit | Audit tercatat di server tetapi tidak ada layar untuk Admin. Tidak dikerjakan sekarang; issue Sprint 2 "Layar log audit untuk Admin" (label qa, severity:sedang) dibuat github-ops. | Sedang | Terbuka (dijadwalkan Sprint 2) | - |

Catatan: P2 tidak boleh dinyatakan selesai sebelum uji ulang di Telegram Android; banner `peringatan` (P7) masih perlu dikerjakan frontend.

## 2. Kasus 64: batas petugas jadwal (dua relawan distribusi)

Skenario: jadwal J1 ditugaskan ke relawan R1; relawan R2 (unit distribusi, SPPG sama) mencoba memicu perjalanan untuk J1. Terkait temuan F-02 di `docs/qa/2026-11-19-audit-rbac.md`.

Perintah (lokal, SQLite memori, bukan staging; `composer install` dahulu): `php artisan test --filter=NotifikasiPerjalananTest`

Hasil: **LULUS** — 18 tes, 18 lulus, 164 assertion, di d0cf41e.

Tes yang mencakup:
- `test_relawan_hanya_memicu_jadwal_yang_ditugaskan_kepadanya_atau_belum_bertugas` (`tests/Feature/NotifikasiPerjalanan/NotifikasiPerjalananTest.php`): R2 ditolak 422 (`jadwal_distribusi_id`) pada `POST /notifikasi-perjalanan` dan `/pratinjau` untuk jadwal milik R1; 0 notifikasi dibuat dan event `PerjalananDimulai` tidak dikirim; jadwal tanpa petugas boleh dipicu relawan mana pun; R1 (pratinjau) dan Kepala SPPG (membuat) boleh memicu jadwal R1.
- `test_service_mulai_menolak_relawan_bukan_petugas_untuk_jalur_bot_dan_ai`: `TripNotificationService::mulai` menolak R2 dengan `ValidationException` pada jalur bot/AI; 0 notifikasi.
- Terkait validasi penugasan: `test_petugas_wajib_relawan_distribusi_aktif_sppg_sama` (`tests/Feature/GrupB/JadwalDistribusiTest.php`).

Kesimpulan: F-02 (Sedang) tertutup oleh kode dan tes di d0cf41e untuk jalur API dan service (bot/AI).

Celah/catatan jujur:
- Dibuktikan hanya lewat tes otomatis lokal; belum ada uji HTTP di staging atau perangkat Telegram nyata.
- Tes memakai dua relawan unit distribusi. Relawan unit dapur dan peran lain tidak dibahas tes ini.
- Jadwal tanpa petugas sengaja boleh dipicu relawan distribusi mana pun; ini keputusan desain yang diuji, bukan celah, tetapi perlu diketahui PM.
- Pemicu lewat PATCH status jadwal tidak mengirim pesan (lihat P8), sehingga tidak relevan untuk batas ini.
