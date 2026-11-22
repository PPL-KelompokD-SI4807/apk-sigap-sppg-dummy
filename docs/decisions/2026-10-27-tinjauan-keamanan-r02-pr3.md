# Tinjauan keamanan R-02 PR-3 (aksi tulis berkonfirmasi lewat Bot)

Tanggal: 2026-10-27. Cabang: `feat/r02-pr3-aksi-tulis`. Peninjau: opus (read-only, tahap E2). Tindak lanjut: backend-dev.

## Ringkasan

Hasil: **0 Kritis, 0 Tinggi, 0 Sedang, 4 Rendah, 8 Info**. Tidak ada temuan yang memblokir. Rendah 1-4 dan Info 6, 7, 8 diperbaiki
beserta tesnya (`tests/Feature/Telegram/Bot/BotAksiTulisTest.php`, `BotAksiTulisKeamananTest.php`, `BotAksiTulisNotifikasiAsliTest.php`).
Info 5, 9, 10, 11, 12 sengaja tidak diubah (dicatat).

Lingkup PR: tiga aksi tulis berkonfirmasi dua langkah (kirim ulang rekap, mulai perjalanan, tindak lanjut keluhan baru -> diproses),
layar `mp` (Mulai Perjalanan), ekstraksi `RekapHarianController::kirimUlang` ke `DailySummaryService::kirimUlang`.

Area yang diperiksa dan dinyatakan bersih:

- Urutan verifikasi tiap klik: rate limit, MAC + baris + ikatan `from.id`/`chat.id`/`user_id`, pengguna dimuat ulang (`canAuthenticate`),
  `punyaSesiPenuh` sebelum data apa pun, lalu `BotAksiTulisService::periksa` (peran saat ini, huruf RBAC termasuk unit relawan,
  SPPG token == `users.sppg_id` == subjek, Policy di dalam `ActingUser` + `SppgAktif::untuk`, versi yang dilihat). Isi callback tidak pernah menjadi sumber hak.
- Admin dan investor tidak pernah mendapat tombol atau token aksi, dan token buatan untuk mereka ditolak; admin tidak menerbitkan token apa pun.
- Sekali pakai: token aksi dan grup Ya/Batal dikonsumsi atomik; tekan ganda, replay, dan dua layar konfirmasi tidak menggandakan efek.
- Tanpa sesi penuh: hanya `PERLU_MASUK` + tombol masuk untuk semua peran dan semua jenis token baru; token tidak dihabiskan; layar asal dinetralkan.
- Privasi: teks keluhan/tindak lanjut, nama, telepon, chat id PIC, dan pesan perjalanan tidak tampil di layar, log, atau audit.
- REST tidak berubah: kirim ulang rekap REST memanggil service yang sama, tanpa jeda bot; `TripNotificationService::mulai` hanya mendapat parameter opsional.
- Tidak ada perubahan skema; `bot_aksi.jenis` <= 24 karakter, `subjek_tipe` <= 20.

## Tabel temuan

| No | Tingkat | Lokasi | Skenario singkat | Status |
|---|---|---|---|---|
| 1 | Rendah | `BotAksiTulisService::versi` (perjalanan) | Sidik hanya memuat jumlah perjalanan AKTIF: perjalanan dimulai lalu dibatalkan orang lain di antara konfirmasi dan "Ya" mengembalikan sidik yang sama, "Ya" basi lolos dan PIC menerima notifikasi kedua. | Diperbaiki: sidik memuat jumlah aktif + jumlah, id terbesar, dan `updated_at` terbaru SEMUA perjalanan jadwal (`sidikPerjalanan`). Tes `test_mulai_lalu_batal_di_antara_konfirmasi_dan_ya_data_sudah_berubah` (diverifikasi gagal tanpa perbaikan). |
| 2 | Rendah | `DailySummaryService::kirimUlang`, sidik rekap | Rekap sudah `menunggu` + `dikirim_pada` null: `save()` tidak mengubah baris, `updated_at` dan sidik tetap, sehingga konfirmasi kedua lolos dan rekap terkirim dua kali. | Diperbaiki (jalur bot saja): rekap `menunggu` tidak diberi tombol; `periksa` dan `jalankan` (di dalam kunci baris) menolak ramah (`SEDANG_DIKIRIM`, "sedang dikirim"). REST tetap menerima. Tes `test_rekap_menunggu_ditolak_ramah_dan_dua_konfirmasi_tidak_dua_kali_kirim`, `test_kirim_ulang_rekap_dibatasi_satu_kali_per_lima_menit`. |
| 3 | Rendah | `BotAksiTulisKeamananTest` (galat service) | Asersi rollback audit vakum: service tiruan melempar sebelum audit, dan `Event::fake` menutup listener/dedup/afterCommit. | Diperbaiki: `BotAksiTulisNotifikasiAsliTest` dengan listener asli + `Queue::fake([SendTelegramNotification])`, audit dipaksa gagal (`AuditLog::creating`) pada mulai perjalanan dan kirim ulang rekap: tanpa job notifikasi, tanpa baris perjalanan, status rekap tetap, jeda dilepas; tes kontrol membuktikan jalur berhasil menjadwalkan job. Pertahanan berlapis: audit perjalanan kini ditulis setelah simpan tetapi sebelum event (`mulai(..., setelahSimpan:)`); audit rekap sudah sebelum `kirimUlang`. Catatan: `PerjalananDimulai`/`RekapHarianDibuat` ber-`ShouldDispatchAfterCommit`, jadi rollback sudah mencegah notifikasi apa pun urutan auditnya; tes tidak dapat membedakan urutan. Asersi lama diberi keterangan jujur. |
| 4 | Rendah | `BotAksiTulisService::jalankan` (layar hasil di luar try), `BotCallbackHandler::aksiTulis` (catch) | Galat setelah commit (menyusun layar hasil, mengedit pesan) dicatat `gagal` dan pengguna melihat "Terjadi kesalahan" padahal notifikasi sudah terkirim (mendorong coba lagi buta). | Diperbaiki: hasil ditetapkan sebelum render; galat render di `jalankan` dan galat tampil di handler memakai "Aksi sudah diproses. Periksa hasilnya di Mini App sebelum mencoba lagi." dan mencatat hasil sesuai efek (`HASIL_BEREFEK`). Tes `test_galat_setelah_commit_dilaporkan_sudah_diproses` (render gagal dan edit gagal). |
| 5 | Info | `eksekusiRekap` (`Cache::add`) | Jeda 5 menit bergantung pada store cache (atomik di redis/database/array); `cache:clear` menghapus jeda. | Tidak diubah; dicatat. Lapisan lain tetap: token sekali pakai, sidik versi, penolakan rekap `menunggu` (No 2). |
| 6 | Info | `BotCallbackHandler::aksiTulis` (ditolak hak) | Token yang ditolak hak tetap aktif bersama saudaranya; bila hak dipulihkan dalam 10 menit, tombol lama hidup lagi. | Diperbaiki: ditolak hak/data hilang -> token dan grup Ya/Batal `kedaluwarsa` (`BotAksiService::kedaluwarsakan`), bukan dipakai, tanpa efek. Tanpa sesi tetap tidak dihabiskan. Tes `test_ditolak_hak_mengedaluwarsakan_grup_ya_dan_batal`, penyesuaian `test_hak_dicek_ulang_tiap_klik`, `test_idor_subjek_sppg_lain_ditolak_tanpa_efek`. |
| 7 | Info | `LayarMulaiPerjalanan` | Layar `mp` (query + aturan keberangkatan per jadwal) tanpa jeda, tidak seperti layar berat lain. | Diperbaiki: `jedaDetik()` = `LayarKeuangan::jedaBerat()`. Tes `test_layar_mulai_perjalanan_dijeda_seperti_layar_berat`. |
| 8 | Info | `BotAksiTulisKeamananTest`, `BotAksiTulisTest` | Tes lemah: `assertTrue(true)`, belum ada relawan distribusi lintas SPPG / jadwal bukan hari ini, ketukan chat grup tanpa asersi, alasan "berubah ATAU di luar jendela", matriks tombol bersyarat `if ($teks !== null)`. | Diperbaiki: penyedia data tanpa kepala; tes relawan lintas SPPG dan jadwal besok (versi asli, alasan tepat); ketukan grup dijawab tanpa teks, tanpa pesan/edit ke grup; alasan tepat (jendela/sedang dikirim dengan versi asli); matriks keterbukaan layar ditulis tangan per peran tanpa syarat. |
| 9 | Info | `tests/Feature/Ai/ToolKatalogTest` | 13 tes risky = `test_peran_tanpa_hak_tidak_melihat_tool_modul` (`expectNotToPerformAssertions` di dalam loop), sudah ada di `develop`. | Tidak diubah (di luar PR ini); dicatat. |
| 10 | Info | `phpunit.xml` | `LLM_*`/`STT_*`/`AI_*` tidak ditimpa, sehingga tes lokal bergantung pada `.env`. | Tidak diubah; tes dijalankan dengan env shell yang menetralkan ke nilai bawaan `config/ai.php`. Dicatat untuk github-ops/PM. |
| 11 | Info | `BotAksiTulisService.php:418` | Konfirmasi perjalanan menampilkan plat nomor kendaraan (bukan data pribadi pengemudi), tetapi perubahan plat tidak masuk sidik versi (hanya `armada_id`). | Diterima; opsional masukkan `armada.updated_at` ke sidik. |
| 12 | Info | `BotAksiTulisService::AKSI` | Ahli gizi (keluhan RU) sengaja tidak mendapat tombol proses keluhan, sesuai rencana 3.7-B (aksi kepala_sppg). Validasi `StoreNotifikasiPerjalananRequest` dan Policy di jalur bot setara REST, ditambah Policy `view` jadwal (lebih ketat). | Tidak perlu tindakan; membuka untuk ahli gizi cukup menambah peran di `AKSI` bila disetujui. |

## Belum teruji

- MySQL 8.4: kunci grup `b1` (`lockForUpdate`), kunci baris subjek di `jalankan`, transaksi bersarang/savepoint (`jalankan` -> `mulai`/`perbarui`/`kirimUlang`) dan rollback-nya; semua tes memakai SQLite in-memory.
- Jalur notifikasi asli listener -> dedup (`Cache::add notif:*`) -> job `afterCommit` saat rollback di luar transaksi tes `RefreshDatabase`, dan worker antrean sungguhan.
- Store cache non-array (redis/database) untuk jeda kirim ulang rekap dan dedup notifikasi.
- Worker dengan batas waktu 30 detik yang terhenti setelah token berstatus `dipakai` tetapi sebelum `selesai` (token tidak bisa dipakai ulang; pengguna perlu membuka layar lagi).
- Klien Telegram sungguhan: `protect_content`, edit netral, fallback `sendMessage`, perilaku tombol konfirmasi pada pesan lama.
