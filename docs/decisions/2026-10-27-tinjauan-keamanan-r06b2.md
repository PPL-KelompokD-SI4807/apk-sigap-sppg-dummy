# Tinjauan keamanan R-06b-2 (pembelian bahan + persetujuan pengeluaran oleh investor)

Tanggal: 2026-10-27. Cabang: `feat/r06b2-pembelian-pengeluaran`. Peninjau: opus (read-only). Tindak lanjut: backend-dev.

## Ringkasan

Hasil: **0 Kritis, 0 Tinggi, 2 Sedang, 4 Rendah, 5 Info**. Tidak ada temuan yang memblokir. Semua temuan Sedang dan Rendah
sudah diperbaiki beserta tesnya (`tests/Feature/Pembelian/TinjauanKeamananR06b2Test.php`); L3 hanya didokumentasikan
(ditambah pengelompokan backfill yang kecil dan aman). Info tidak diubah.

Area yang diperiksa dan dinyatakan bersih:

- RBAC dan IDOR: Policy `verifikasi` (akuntan, bukan pencatat) dan `putuskan` (investor pemilik SPPG, lapis kedua di service); lintas SPPG 403/404.
- Urutan kunci baris: `sppgs` -> `pembelian_bahans` -> `pengeluarans` -> `persetujuan_pengeluarans`, seragam di BudgetService, PembelianBahanService, PersetujuanPengeluaranService.
- Uang dan anggaran: nominal rupiah bulat tanpa float, total pembelian dihitung server (bcmath), hanya pengeluaran `disetujui` dihitung ke serapan, ambang 80/100% idempoten.
- Audit: teks bebas, jalur nota, dan alasan tidak masuk jejak audit; keputusan tercatat dengan pemutus dan waktu.
- Agen AI: tidak ada tool verifikasi/keputusan; tulis hanya menjadi AiDraft; skema tool tidak menawarkan kolom isian server; draf `ubah` membawa `versi` dari server.
- S2 (aturan konsisten dengan R-06b-1): pemisahan tugas staf gudang (mencatat) dan akuntan (memverifikasi), investor memutuskan pengeluaran non-menu.
- Frontend (ditinjau tanpa diubah): teks pengguna lewat `textContent`, tombol keputusan hanya untuk peran berhak.

## Tabel temuan

| ID | Tingkat | Lokasi | Skenario singkat | Status |
|---|---|---|---|---|
| M1 | Sedang | `PembelianBahanPolicy`, `LampiranController::store` (mode terikat), `config/unggahan.php` | Nota dapat ditambah ke pembelian SESUDAH terverifikasi lewat `POST /lampiran {modul: pembelian-bahan, lampiranable_id}` karena hak `update` tidak mengecek status; varian TOCTOU: nota ditambah setelah akuntan membuka layar tanpa mengubah `versi`. | Diperbaiki. Lihat "Keputusan M1/M2". |
| M2 | Sedang | `PengeluaranPolicy`, `LampiranController::store`, `config/unggahan.php` | Nota dapat ditambah ke pengeluaran yang sudah disetujui investor atau yang berasal dari pembelian terverifikasi tanpa kembali ke `diajukan`. | Diperbaiki. Lihat "Keputusan M1/M2". |
| L1 | Rendah | `WriteDraftTool::execute`, `DraftValidator`, `DraftValidationResult` | `total`, `status`, `satuan`, `status_persetujuan`, `melebihi_anggaran`, `versi` (isian server) dapat lolos ke `ai_drafts.payload` bila tool dipanggil langsung (di luar penyaring skema). | Diperbaiki. Payload disaring ke kunci aturan Form Request (`saring`), bukan menambah `ai.protected_arguments` karena `status`/`satuan`/`status_persetujuan`/`melebihi_anggaran` adalah argumen sah tool baca (filter) dan modul lain. Keyakinan kunci yang dibuang ikut dibuang; kunci bergalat tetap. |
| L2 | Rendah | `PersetujuanPengeluaranService::putuskan` | `SppgAktif::milik()` (pembacaan biasa) dijalankan sebelum `kunciSppg`; di MySQL REPEATABLE READ snapshot terbentuk pada pembacaan pertama sehingga ringkasan anggaran saat persetujuan bisa basi. | Diperbaiki. `kunciSppg` kini pernyataan pertama transaksi. Jalur lain (verifikasi pembelian, `ubah`/`hapus` pengeluaran, pencairan) sudah memulai dengan `kunciSppg`; dikunci oleh tes pernyataan pertama. |
| L3 | Rendah | Migrasi `2026_10_27_000102_add_persetujuan_to_pengeluarans_table` | Migrasi tidak idempoten bila gagal di tengah (DDL MySQL tidak transaksional); `UPDATE` backfill satu pernyataan pada seluruh tabel mengunci banyak baris. | Catatan + perbaikan kecil. Backfill kini per 5.000 baris (aman diulang, berhenti sendiri). Prosedur operator di bawah. |
| L4 | Rendah | `routes/api/v1/pembelian-bahan.php`, event `PengeluaranDiajukan` | PUT/PATCH pembelian tanpa `throttle:unggah` (pengeluaran.update sudah punya); tiap ubah pengeluaran mengirim notifikasi baru ke investor. | Diperbaiki sebagian. `throttle:unggah` ditambahkan pada ubah pembelian. Dedup notifikasi per pengeluaran per jam TIDAK ditambahkan: kunci dedup sudah per `versi` dan mengubah jendela akan menelan pengajuan ulang yang sah; batas laju route (20/menit/pengguna) dinilai cukup. Catatan. |
| I1 | Info | `PembelianBahanService::verifikasi` | Pembelian terverifikasi langsung menjadi pengeluaran `disetujui` tanpa investor. | Risiko diterima. Kontrol: nota wajib + pemisahan tugas staf gudang/akuntan (pencatat tidak boleh memverifikasi). |
| I2 | Info | `BudgetService::perluPersetujuan` (ambang > 0) | Ambang > 0 memungkinkan memecah nominal agar di bawah ambang; pengeluaran ditolak lalu diedit di bawah ambang disetujui otomatis. | Diterima (ambang bawaan 0 = semua diajukan). Catatan untuk keputusan bisnis bila ambang diaktifkan. |
| I3 | Info | `AturanPembelianBahan` (`str_starts_with($this->path(), 'ai-')`) | Deteksi dry-run AI lewat awalan path `ai-` rapuh bila rute `ai-*` didaftarkan di luar prefix dry-run. | Catatan. Saran: ganti dengan atribut/flag permintaan yang diset `DraftValidator` (mis. `$request->attributes->set('ai_dry_run', true)`). |
| I4 | Info | `PersetujuanPengeluaranResource`, `PengeluaranResource` | Investor melihat nama pencatat/pemutus dan `pembelian_bahan_id`. | Diterima, mengikuti pola persetujuan-menu (investor adalah pemilik dana). |
| I5 | Info | Tool AI `cari_pembelian_bahan` | Tool baca tersedia untuk admin, kepala SPPG, dan akuntan (hak R); hanya staf gudang yang mendapat tool draf buat/ubah. Tidak ada tool verifikasi atau keputusan. | Catatan (sesuai desain). |

## Keputusan M1/M2

Pilihan terkecil yang aman, tanpa mengubah kontrak PATCH yang sudah dipakai frontend:

1. **Ability baru `lampirkan`** (bukan memperketat `update`). `config/unggahan.php` kini memakai `ability => lampirkan` untuk
   `pembelian-bahan` dan `keuangan-pengeluaran`. `PembelianBahanPolicy::lampirkan` = `update` + status `bisaDiubah()`;
   `PengeluaranPolicy::lampirkan` = `update` + bukan hasil pembelian (`pembelian_bahan_id` null). `update` tidak diubah sehingga
   PATCH tetap menjawab 409 terstruktur (`pembelian_sudah_diverifikasi`, `pengeluaran_dari_pembelian`) seperti sebelumnya;
   hanya `POST /lampiran` terikat yang kini 403 untuk kasus tersebut.
2. **`PenjagaLampiranInduk` di bawah kunci baris** (dipanggil `LampiranController::store` menggantikan kunci polos). Memeriksa ulang
   status pada baris terkunci (jawaban 409 bila berubah di antara Policy dan kunci) dan **menaikkan `versi`** pada setiap lampiran
   terikat yang berhasil. Hanya baris induk yang dikunci (tidak pernah `sppgs` sesudahnya), jadi tidak bertabrakan dengan urutan
   `sppgs` -> induk.
   - Pembelian: verifikasi dari layar lama (versi basi) -> 409 `versi_tidak_cocok`. Ini menutup TOCTOU tanpa menolak mode terikat,
     yang masih dibutuhkan alur lampiran susulan.
   - Pengeluaran `diajukan`/`ditolak`: versi naik dan baris `persetujuan_pengeluarans` yang menunggu ikut versi baru; keputusan
     investor dari layar lama -> 409, lalu memuat ulang dan memutuskan.
   - Pengeluaran `disetujui`: **ditolak 409 `pengeluaran_sudah_disetujui`** (bukan otomatis kembali ke `diajukan`) agar tidak ada
     pembatalan persetujuan diam-diam lewat unggah foto. Jalur resmi: PATCH pengeluaran (BudgetService::ubahPengeluaran menaikkan
     versi dan mengajukan ulang), lalu lampiran boleh ditambah. Konsisten dengan aturan "perubahan apa pun -> diajukan ulang".
3. Dampak frontend (dikirim terpisah ke agen frontend): panel "Lampiran lain" pada pengeluaran `disetujui` kini mendapat 409; sebaiknya
   disembunyikan/dinonaktifkan dengan petunjuk "Ubah pengeluaran agar diajukan ulang".

## Prosedur operator migrasi (L3)

- Jalankan `php artisan migrate` di jam sepi setelah cadangan basis data (DDL MySQL menimbulkan metadata lock singkat pada `pengeluarans`).
- Bila gagal di tengah: periksa `SHOW COLUMNS/INDEX/CREATE TABLE` untuk `pengeluarans` dan `pembelian_bahans`, hapus kolom/indeks/FK yang sempat
  terbentuk (kebalikan `down()`), lalu ulangi. Migrasi tidak memakai penjaga `hasColumn`.
- Bila hanya backfill yang terputus (kolom sudah ada, sebagian baris masih `diajukan`): jalankan manual
  `UPDATE pengeluarans SET status_persetujuan='disetujui' WHERE status_persetujuan='diajukan' AND created_at < '<waktu mulai migrasi>';`
  (boleh diulang; baris baru setelah migrasi memang `diajukan`, jadi batasi dengan waktu mulai).

## Tes yang ditambahkan

`tests/Feature/Pembelian/TinjauanKeamananR06b2Test.php`: M1 (staf/akuntan 403 pada pembelian terverifikasi, penjaga 409, TOCTOU versi basi 409,
peran tanpa hak/investor 403), M2 (hasil pembelian 403, disetujui 409 lalu PATCH lalu boleh, diajukan menaikkan versi + keputusan basi 409),
L1 (payload draf buat pembelian dan pengeluaran tanpa kunci isian server), L2 (pernyataan pertama transaksi = kunci `sppgs` pada putuskan,
verifikasi, ubah, hapus), L4 (middleware `throttle:unggah` pada ubah pembelian). `UnggahanAmanTest` menyesuaikan fixture ke pengeluaran `diajukan`
(pabrik bawaan `disetujui` kini ditolak oleh M2).

## Belum teruji

- Migrasi 000102 (kolom, FK, backfill per kelompok) pada MySQL 8.4 sungguhan dan tabel besar; tes memakai SQLite in-memory (tanpa FK tambahan).
- Balapan sungguhan di MySQL: `lockForUpdate` diabaikan SQLite, sehingga urutan kunci, snapshot REPEATABLE READ (L2), dan dua unggahan/verifikasi
  bersamaan baru terbukti secara logika, tinjauan kode, dan tes urutan pernyataan, belum dengan dua koneksi paralel.
- Vision foto nota end-to-end (Telegram -> LLM vision -> draf pembelian) dengan penyedia sungguhan.
- Alur Telegram (bot/notifikasi investor `PengeluaranDiajukan`, deep link `?sppg=`) pada klien Telegram sungguhan.
