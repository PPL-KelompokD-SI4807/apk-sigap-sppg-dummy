# Tinjauan keamanan R-07 tahap 1 (dashboard per peran) — 2026-10-29

Peninjau: opus, read-only. Cakupan: `app/Services/Dashboard/**`, `DashboardController`, `DashboardPolicy`, rute dashboard, pengecualian `ResolveSppgAktif`, migrasi indeks, tool AI `ringkas_dashboard_saya`, layar bot `db`. Tanpa ekspor PDF (format BGN belum ada).

## Kesimpulan
Tidak ada temuan Tinggi. Batas SPPG/investor benar di API, bot, dan tool AI. Tidak ada temuan Sedang di dalam cakupan R-07.

## Yang sudah aman
- Himpunan SPPG dihitung server (admin: semua; investor: pivot `investor_sppg` + SPPG belum dihapus; lainnya: `users.sppg_id`). `?sppg_id` hanya menyaring himpunan itu; SPPG asing dan tak ada sama-sama 403 `sppg_aktif_ditolak`.
- Cakupan divalidasi sebelum cache dibaca; kunci cache memuat user, peran, unit, hash id SPPG, rentang, tanggal.
- Semua query non-admin lewat `Cakupan::tabel()` (`whereIn sppg_id` + `deleted_at`); widget admin dijaga hak modul `pengguna`.
- Hak baca per widget (`Rbac::can R`); bot mengecek ulang sesi penuh dan hak tiap klik; tool AI tanpa argumen id bebas dan hanya baca.
- k=5 dengan penyamaran sekunder untuk kebutuhan khusus (hanya ahli gizi); investor tidak menerima agregat penerima manfaat. Tidak ada NIK/HP/PIC/teks keluhan/jalur nota.
- Throttle `throttle:api`, sekitar 15–25 query agregat per permintaan, tanpa N+1.

## Temuan dan tindak lanjut
| # | Tingkat | Temuan | Tindak lanjut |
|---|---|---|---|
| 1 | Sedang (di luar cakupan) | `tests/Feature/Unggahan/UnggahanAmanTest.php` berstatus terhapus di working tree sejak sebelum R-07 | Bukan bagian PR ini, tidak di-stage. Pengguna memutuskan: pulihkan atau hapus sengaja |
| 2 | Rendah | Nama SPPG masuk konteks LLM (injeksi); komentar klaim "tidak ada teks DB" keliru | Diperbaiki: catatan data-bukan-instruksi + koreksi komentar |
| 3 | Rendah | Tebakan `?sppg_id` tidak dicatat | Diperbaiki: `Log::notice` seperti jalur header |
| 4 | Rendah | Mode gabungan admin tanpa batas (`whereIn` besar, `per_sppg` tanpa batas) | Dicatat untuk skala nasional; batasi `per_sppg` bila SPPG > ratusan |
| 5 | Info | Kunci cache tanpa `mode` | Diperbaiki |
| 6 | Info | Cache ditulis dalam transaksi rollback (bot admin, tool AI) | Diterima; tidak bocor |
| 7 | Info | Join referensi tanpa `sppg_id`/`deleted_at` | Aman karena validasi tulis; pertahanan berlapis menyusul |
| 8 | Info | Widget investor membaca tabel di luar hak R, digerbang `rekap-harian` | Keputusan: diterima, hanya agregat setara rekap harian |
| 9 | Info | Query terbuang (`pembelian_bahans`, pencairan harian), COUNT `failed_jobs` tanpa indeks | Optimasi menyusul |
| 10 | Info | `sppg_pilihan` basi sampai 60 dtk setelah pivot dicabut | Diterima; klik divalidasi ulang |
