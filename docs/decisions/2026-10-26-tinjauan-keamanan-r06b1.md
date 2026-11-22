# Tinjauan keamanan R-06b-1 (persetujuan menu oleh investor)

Tanggal: 2026-10-26. Cabang: `feat/r06b1-persetujuan-menu`. Peninjau: opus (read-only). Tindak lanjut: backend-dev.

## Ringkasan

Hasil: **0 Kritis, 0 Tinggi, 2 Sedang, 4 Rendah, 4 Info**. Tidak ada temuan yang memblokir. Semua temuan Sedang dan Rendah
yang dapat diperbaiki sudah diperbaiki beserta tesnya (`tests/Feature/Persetujuan/TinjauanKeamananR06b1Test.php`),
kecuali S2 yang menunggu keputusan pengguna.

Area yang diperiksa dan dinyatakan bersih:

- Balapan kunci menu -> pengajuan dan penomoran versi (urutan kunci seragam menu -> pengajuan; versi dicocokkan di bawah kunci).
- Snapshot dibuat server; model `PengajuanMenu` memakai `fillable` kosong, klien tidak pernah mengirim angka biaya.
- IDOR dan RBAC: `canDecide` hanya untuk investor pemilik SPPG pengajuan (Policy + pemeriksaan pivot di service).
- Imutabilitas pengajuan: tidak ada route ubah/hapus, model menolak perubahan snapshot dan transisi selain diajukan -> status akhir.
- Jadwal produksi menolak menu yang belum disetujui, baik saat buat maupun saat ganti menu.
- Deep link `?sppg=` divalidasi terhadap SPPG milik investor.
- UI memakai `textContent` (tidak ada injeksi HTML dari catatan atau nama menu).

## Tabel temuan

| ID | Tingkat | Lokasi | Skenario singkat | Status |
|---|---|---|---|---|
| S1 | Sedang | `MenuController::rincianBiaya`, `MenuResource` (`pengajuan_terakhir`) | Relawan/staf gudang dapat membaca biaya per porsi, total, dan catatan keputusan investor lewat `rincian-biaya` dan `pengajuan_terakhir` walau tidak punya hak modul persetujuan/harga pemasok. | Diperbaiki. `rincian-biaya` memakai `MenuPolicy::lihatRincianBiaya` (hak R `persetujuan-menu` ATAU hak R `pemasok` + `menu-gizi`); `pengajuan_terakhir` hanya dikirim bila hak R `persetujuan-menu`. Catatan: staf gudang tetap boleh `rincian-biaya` karena memenuhi alternatif kedua (memang berhak melihat harga pemasok), tetapi tidak melihat `pengajuan_terakhir`. |
| S2 | Sedang | `ProductionPlanningService` (jadwal yang sudah ada) | Menu disetujui lalu diubah (kembali draf) tidak memengaruhi jadwal produksi lama; jadwal tetap berjalan dengan menu yang tidak lagi berstatus disetujui. | **Terbuka**, menunggu keputusan pengguna: blokir atau tandai jadwal yang menunya sudah tidak disetujui. Asumsi saat ini: jadwal lama tetap jalan. |
| R1 | Rendah | `BiayaMenuService` (hitung biaya), `PersetujuanMenuService::ajukan` | Harga dan takaran besar membuat biaya melebihi decimal(14,2)/(16,2) lalu galat basis data (500). | Diperbaiki. Hasil >= 1e12 per porsi atau >= 1e14 total, atau tidak finite, dijawab 422 `biaya_melebihi_batas`. |
| R2 | Rendah | `StoreHargaPemasokRequest` (`gram_per_satuan`) | Nilai 0,0001 atau lebih dari 3 desimal tidak konsisten dengan kolom decimal(12,3) sehingga terbulatkan menjadi 0 dan merusak konversi satuan. | Diperbaiki. Minimal 0,001 dan maksimal 3 desimal (`decimal:0,3`, `min:0.001`). |
| R3 | Rendah | `ProductionPlanningService::pastikanMenuMilikSppg` | Status menu diperiksa tanpa kunci; menu dapat kembali draf di antara pemeriksaan dan penyimpanan jadwal. | Diperbaiki. Baris menu dikunci (`lockForUpdate`) di dalam transaksi; urutan sppg -> menu, tidak bertabrakan dengan menu -> pengajuan. |
| R4 | Rendah | `NotificationService::usersWithRoles` (pivot `investor_sppg`) | Investor SPPG yang sudah dihapus lunak masih menerima notifikasi. | Diperbaiki. Join `sppgs` dengan `deleted_at IS NULL`, sejalan `SppgAktif::milik()`. |
| I1 | Info | Model `PengajuanMenu` (guard imutabilitas) | Guard imutabilitas ada di model; query builder atau `saveQuietly` dapat melewatinya. Jaminan di tingkat basis data butuh trigger. | Risiko diterima (backlog: trigger MySQL bila diperlukan). |
| I2 | Info | `PersetujuanMenuService::ajukan` | Pengajuan dengan `jumlah_porsi` 0 menghasilkan total 0 yang menyesatkan investor. | Diperbaiki. 422 `jumlah_porsi_nol`. |
| I3 | Info | logika.js:57-62, PersetujuanMenuService.php:138-148 | `versi_menu` dari klien selalu sama dengan `menu_versi` snapshot; server memeriksa keduanya, jadi aman namun redundan. | Catatan. |
| I4 | Info | Rbac.php:254-256 | Ability token `persetujuan-menu:putuskan` ditambahkan tetapi belum dipakai middleware mana pun; tidak berbahaya. | Catatan. |

## Belum teruji

- Deep link Telegram (`web_app` URL dengan `?sppg=`) pada klien Telegram sungguhan; tes hanya memeriksa isi URL.
- Balapan kunci di MySQL sungguhan: tes memakai SQLite in-memory yang mengabaikan `lockForUpdate`. Perilaku kunci (R3, urutan
  menu -> pengajuan, penomoran) baru terbukti secara logika dan tinjauan kode, belum dengan dua koneksi MySQL paralel.
