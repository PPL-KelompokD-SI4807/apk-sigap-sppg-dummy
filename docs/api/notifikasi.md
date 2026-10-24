# Notifikasi Bot Berbasis Peran (FR-10, FR-12, FR-13)

Pusat: `app/Services/Notification/NotificationService.php` + pemetaan `DomainEventNotifications.php`.
Listener tunggal `App\Listeners\KirimNotifikasiDomain` didaftarkan oleh `SigapIntegrationServiceProvider` **hanya** untuk kelas `App\Events\<Nama>` yang ada saat boot (`class_exists`). Pengiriman lewat job `SendTelegramNotification` (antrean default).

**Modul lain jangan membuat listener Telegram sendiri** untuk event di bawah: cukup pancarkan event (sebaiknya `ShouldDispatchAfterCommit`). Bila perlu event baru, minta pemetaannya ditambahkan di `DomainEventNotifications`.

## Tabel penerima

| Event (`App\Events\`) | Penerima | Status |
|---|---|---|
| `StokMenipis` | staf_gudang, kepala_sppg | terhubung |
| `BahanAkanKedaluwarsa` | staf_gudang, kepala_sppg | terhubung |
| `KekuranganBahan` | staf_gudang, kepala_sppg | terhubung |
| `MutuTidakLayak` | kepala_sppg, ahli_gizi (admin bila tidak ada penerima) | terhubung |
| `KeluhanKritis` | kepala_sppg, ahli_gizi (admin bila tidak ada penerima) | terhubung |
| `PerjalananDimulai` | PIC titik layanan (`pic_telegram_chat_id`); bila belum terhubung / `dialihkan_ke_kepala` -> kepala_sppg | terhubung; `status_kirim` diperbarui (terkirim/gagal); dialihkan tetap `tidak_terkirim` (FR-12) |
| `PerjalananTiba` | PIC; bila tidak ada -> kepala_sppg | terhubung |
| `PerjalananTerlambat` | PIC (bila ada) **dan** kepala_sppg | terhubung |
| `RekapHarianDibuat` | kepala_sppg, ahli_gizi, akuntan | terhubung; `rekap_harians.status_kirim/dikirim_pada` diperbarui (tanpa penerima -> `tidak_terkirim`) |
| `BuktiDistribusiDicatat` | kepala_sppg | terhubung |
| `DistribusiTerlambat` (bukti dicatat terlambat) | kepala_sppg | terhubung |
| `AnggaranMencapai80` / `AnggaranMencapai100` / `AnggaranMelampaui` | akuntan, kepala_sppg | terhubung |
| `BuktiDistribusiBelumMasuk` (bukti belum masuk 60 menit setelah jam rencana tiba) | kepala_sppg | **disiapkan** (kelas belum ada; aktif otomatis saat kelas dibuat) |

Pengawas penerima rekap (FR-13 "pengawas terdaftar") belum punya model data; saat ini rekap dikirim ke peran di atas. Admin hanya penerima cadangan untuk event kritis.

## Aturan pengiriman

- Penerima: pengguna `is_active`, tidak terhapus lunak, `telegram_id` terisi, `sppg_id` = SPPG event, peran sesuai. Dicek ulang saat job berjalan (nonaktif/ganti Telegram -> dilewati).
- Dedup per (kunci event, chat) 12 jam via cache (`StokMenipis` per bahan per hari, `Perjalanan*` per perjalanan, `Anggaran*` per periode, rekap per menit agar kirim ulang manual tetap terkirim).
- Retry: maks 3 galat sementara, jeda 10/30/90 detik, batas 30 menit; 429 mematuhi `retry_after`; 400/403 (chat tidak ada/bot diblokir) tidak diulang. Limiter global `telegram-out` 25 pesan/detik (NFR-03).
- Isi pesan HTML dengan semua nilai dinamis di-escape; tanpa nomor telepon, chat id, atau NIK. Deskripsi keluhan dipotong 150 karakter. Pesan ke pengguna memuat tombol web_app ke layar modul (`#/<slug>`); pesan ke PIC tanpa tombol.
- Log aman `notifikasi.kirim`: jenis, status, kode galat, hash 12 karakter chat id, user_id.
- Kegagalan notifikasi tidak pernah menggagalkan alur domain (listener menelan galat setelah `report()`).

## Payload yang dibaca

Properti publik event dibaca berdasarkan nama (camelCase, dengan cadangan snake_case):

| Event | Properti |
|---|---|
| StokMenipis | sppgId, bahanBakuId, nama, satuan, stok, stokMinimum |
| BahanAkanKedaluwarsa | sppgId, bahanBakuId, nama, satuan, stok, tanggalKedaluwarsa, sisaHari |
| KekuranganBahan | sppgId, jadwalProduksiId, tanggalProduksi, namaMenu, targetPorsi, kekurangan[{nama, satuan, kekurangan}] |
| MutuTidakLayak | sppgId, pemeriksaanMutuId, jadwalProduksiId, tanggalProduksi, namaMenu, tindakanKorektif, jumlahDistribusiDitahan |
| KeluhanKritis | id, sppgId, kategori, titikLayananNama, deskripsi |
| Perjalanan* | id, sppgId, titikLayananNama, picTelegramChatId, dialihkanKeKepala, estimasiTiba (+ `notifikasi_perjalanans.pesan`) |
| RekapHarianDibuat | id, sppgId, tanggal, ringkasan (porsi, mutu, distribusi, keluhan, stok, keuangan, sisa_makanan_kg) |
| BuktiDistribusiDicatat / DistribusiTerlambat | id, sppgId, jadwalDistribusiId, titikLayananNama, porsiDikirim, porsiDiterima, waktuTiba, acuanTiba, terlambat, menitKeterlambatan |
| Anggaran* | sppgId, periode, anggaran, terpakai, sisa, persen |
| BuktiDistribusiBelumMasuk (diharapkan) | sppgId, jadwalDistribusiId, titikLayananId, titikLayananNama, jamRencanaTiba ("HH:MM[:SS]" atau ISO), menitSejakRencana |
