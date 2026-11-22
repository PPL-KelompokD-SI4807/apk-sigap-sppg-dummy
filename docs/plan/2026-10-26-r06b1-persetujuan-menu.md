# R-06b-1 Harga Pemasok + Persetujuan Menu oleh Investor (Tier T2, tinjauan keamanan T3)

Tanggal: 2026-10-26 · Branch: `feat/r06b1-persetujuan-menu` (dari `develop`, R-06a sudah ter-merge) · PR ke `develop`
Fitur terkait: No 7 (Pemasok), No 8 (Menu dan Gizi), No 9 (Jadwal Produksi), notifikasi bot. Keputusan pengguna final; tidak menambah fitur.

## Tujuan
1. Harga bahan per pemasok (termasuk koperasi = pemasok berjenis `koperasi`) dengan tanggal berlaku dan riwayat.
2. Rincian biaya menu: bahan x takaran x harga pemasok terkini = biaya per porsi; total = biaya per porsi x jumlah porsi (asumsi ditampilkan).
3. Alur persetujuan per pengajuan: ahli gizi mengajukan (draf -> diajukan), investor SPPG tersebut memutuskan
   (disetujui / ditolak / perlu_revisi; catatan wajib untuk ditolak/perlu_revisi). Harga dibekukan (snapshot) saat diajukan.
4. Hanya menu `disetujui` yang dapat dipakai Jadwal Produksi. Jejak keputusan tidak dapat diubah/dihapus.
5. Notifikasi bot: investor saat ada pengajuan menunggu (biaya per porsi + total, tombol "Lihat di Mini App"); ahli gizi saat diputuskan.

## Keputusan desain teknis (PM)
**Harga pemasok**
- Tabel baru `harga_pemasoks` (aditif): `sppg_id`, `pemasok_id`, `bahan_baku_id`, `harga` (decimal 14,2, per satuan bahan),
  `gram_per_satuan` (nullable, wajib bila satuan bahan bukan kg/g/gram/liter/l/ml), `berlaku_mulai` (date), `catatan`, `dicatat_oleh`, timestamps.
  Riwayat append-only: tidak ada endpoint ubah/hapus; koreksi = entri baru. `berlaku_mulai` dalam rentang hari ini -1 tahun s.d. +1 tahun.
- Hak akses mengikuti modul `pemasok` (C: admin, staf_gudang; R: admin, kepala_sppg, ahli_gizi, akuntan, staf_gudang). Investor tetap tanpa akses modul pemasok.
- Pemasok dan bahan wajib satu SPPG (validasi server). `JenisPemasok` ditambah `koperasi` (kolom string, tanpa migrasi).
- Harga terkini satu bahan = entri `berlaku_mulai <= hari ini` terbaru (seri: id terbesar) dari pemasok aktif SPPG yang sama;
  pemasok utama bahan (`bahan_bakus.pemasok_id`) diutamakan bila punya harga berlaku. Pemasok yang dipakai dicatat di rincian.
- Konversi takaran (`menu_bahan.gram_per_porsi`): kg/liter/l = 1000 g, g/gram/ml = 1 g (asumsi 1 ml = 1 g), satuan lain memakai `gram_per_satuan`.
  Bahan tanpa harga berlaku atau tanpa konversi -> ditandai di rincian; pengajuan ditolak 422 dengan daftar bahannya.

**Jumlah porsi (asumsi, dihitung server)**
- Urutan: (1) `target_porsi` jadwal produksi terdekat (tanggal >= hari ini, status bukan batal) untuk menu tsb -> `jadwal_produksi`;
  (2) jumlah penerima manfaat aktif SPPG dengan `kelompok_sasaran` = menu -> `penerima_manfaat_kelompok`; (3) total penerima aktif -> `penerima_manfaat_total`.
  Respons memuat `jumlah_porsi`, `sumber_porsi`, `keterangan_asumsi`. Klien tidak pernah mengirim harga/biaya/porsi.

**Menu dan pengajuan**
- Kolom aditif `menus.status_persetujuan` (string 20, default `draf`; nilai draf/diajukan/disetujui/ditolak/perlu_revisi) dan `menus.versi` (unsigned int, default 1).
  Baris lama menjadi `draf` (tidak ada persetujuan diam-diam). Factory Menu default `disetujui` agar tes lama tetap relevan; tes baru memakai state eksplisit.
- Tabel baru `pengajuan_menus`: `sppg_id`, `menu_id`, `nomor` (urut per menu), `menu_versi`, `status` (diajukan/disetujui/ditolak/perlu_revisi/dibatalkan),
  `rincian` (JSON snapshot: bahan, takaran, satuan, konversi, pemasok, harga, berlaku_mulai, biaya), `biaya_per_porsi`, `jumlah_porsi`, `sumber_porsi`,
  `keterangan_asumsi`, `total_biaya`, `catatan_pengaju`, `diajukan_oleh`, `diajukan_pada`, `diputuskan_oleh`, `diputuskan_pada`, `catatan_keputusan`, timestamps. Tanpa soft delete.
- Imutabel: model menolak hapus dan menolak perubahan apa pun kecuali satu transisi `diajukan` -> status akhir (kolom keputusan saja). Tidak ada endpoint ubah/hapus.
- Ubah menu (atribut atau komposisi bahan, termasuk lewat konfirmasi AiDraft) mengunci baris menu, menaikkan `versi`, mengembalikan status ke `draf`;
  pengajuan yang masih `diajukan` menjadi `dibatalkan` (catatan sistem "menu diubah setelah diajukan"). Menu disetujui yang diubah = perlu pengajuan baru.
  Hapus menu dengan pengajuan `diajukan` -> pengajuan `dibatalkan`.
- Ajukan: hanya `ahli_gizi` SPPG tsb, body `{versi, catatan_pengaju?}`; status menu harus draf/ditolak/perlu_revisi; `versi` harus sama dengan versi server (409 bila beda).
- Putuskan: hanya `investor` pemilik SPPG aktif (pivot + `X-SPPG-Id` = `pengajuan.sppg_id`). Transaksi: `lockForUpdate` menu lalu pengajuan (urutan tetap),
  cek `pengajuan.status = diajukan`, `menu.status_persetujuan = diajukan`, `menu.versi = pengajuan.menu_versi = versi_menu` dari klien; selain itu 409.
  Investor kedua yang memutuskan pengajuan yang sama -> 409. Audit log dicatat.

**RBAC**
- Modul baru `persetujuan-menu` di `config/rbac.php`: admin R, kepala_sppg R, ahli_gizi CR (C = mengajukan), investor R, lainnya kosong.
- Hak memutuskan TIDAK memakai huruf C/U/D: config baru `rbac.keputusan` = `['persetujuan-menu' => ['investor']]` + `Rbac::canDecide()`.
  Akibatnya `Rbac::readOnly(investor)` tetap true (unggah lampiran dan modul lain tetap tertutup). Ability token `persetujuan-menu:putuskan`;
  `AuthUserResource` menambah `keputusan: ["persetujuan-menu"]` untuk Mini App. Peran lain (termasuk admin) -> 403 saat memutuskan.
- Investor tetap tanpa akses modul `menu-gizi`, `pemasok`, `jadwal`; data menu dilihat lewat snapshot pengajuan.

**Jadwal produksi**: buat/ubah (termasuk lewat AiDraft) menolak menu yang tidak `disetujui` (422 pada `menu_id`). Jadwal lama tidak diubah.

**Notifikasi**: event `MenuDiajukan` -> investor pemilik SPPG (via pivot `investor_sppg`, bukan `users.sppg_id`); `MenuDiputuskan` -> ahli_gizi SPPG.
Isi: nama menu, biaya per porsi, total, jumlah porsi, keputusan + catatan (diputuskan). Tanpa data pribadi. Tombol "Lihat di Mini App" ke `/persetujuan-menu/<id>`
dengan petunjuk SPPG (`sppg=<id>`) yang tetap divalidasi server. Dedup per pengajuan.

**Agen AI**: tidak ada tool tulis untuk persetujuan (keputusan hanya di Mini App, keputusan pengguna 7; investor tidak pernah membuat AiDraft).
Tool baca `daftar_pengajuan_menu` mewarisi matriks. Jalur AiDraft menu/jadwal yang ada wajib lewat service yang sama (versi + cek disetujui).

## Kontrak API (ringkas; rinci di `docs/api/openapi.yaml`)
- `GET /api/v1/pemasok/{pemasok}/harga?bahan_baku_id=` · `POST /api/v1/pemasok/{pemasok}/harga`
- `GET /api/v1/menu-gizi/{menu}/rincian-biaya` (harga terkini, pratinjau)
- `POST /api/v1/menu-gizi/{menu}/ajukan`
- `GET /api/v1/persetujuan-menu?status=&menu_id=` · `GET /api/v1/persetujuan-menu/{pengajuan}`
- `POST /api/v1/persetujuan-menu/{pengajuan}/keputusan` `{keputusan, catatan, versi_menu}`

## Tugas
| ID | Tier | Agen | Tugas | Bergantung |
|---|---|---|---|---|
| R06b1-1 | T2 | backend-dev (sonnet/high) | Migrasi aditif, model + imutabilitas, enum koperasi, RBAC `persetujuan-menu` + `keputusan`, harga pemasok, kalkulasi biaya, ajukan/putuskan dengan kunci baris + versi, gate jadwal, notifikasi via pivot, tool AI baca, seeder fiktif, tes (IDOR, balapan, RBAC, imutabel), openapi + notifikasi.md | - |
| R06b1-2 | T1 | frontend-dev (sonnet/medium) | Layar riwayat/tambah harga di Pemasok, rincian biaya + tombol Ajukan di Menu, daftar + detail Persetujuan Menu (investor: tombol keputusan + catatan wajib), status persetujuan di pilihan menu Jadwal, deep link; build + tes unit | R06b1-1 |
| R06b1-3 | T3 | PM + agen read-only (opus/high) | Tinjauan keamanan -> `docs/decisions/2026-10-26-tinjauan-keamanan-r06b1.md` | R06b1-1, R06b1-2 |
| R06b1-4 | T2 | backend-dev (sonnet/high; opus bila gagal 2x) | Perbaikan temuan Kritis/Tinggi + tinjau ulang ringan | R06b1-3 |
| R06b1-5 | T0 | github-ops (haiku/low) | Commit bertahap, PR ke develop dengan checklist DoD, pantau CI 8.3/8.4, tanpa merge | R06b1-3/4 |

## Keputusan tambahan PM (lanjutan, 2026-10-26)
- Menu berstatus `disetujui` yang diubah (atribut/komposisi, termasuk via AiDraft) kembali ke `draf`, `versi` naik; wajib pengajuan baru.
- Jadwal Produksi yang sudah dibuat dengan versi menu lama TIDAK diubah otomatis dan TIDAK diblokir (asumsi; perlu konfirmasi pengguna
  bila ingin diblokir/ditandai). Gate `disetujui` hanya berlaku saat membuat/mengubah jadwal.
- Rantai lanjutan: backend (R06b1-1) -> frontend (R06b1-2) -> tinjauan opus read-only (R06b1-3) -> perbaikan Kritis/Tinggi (R06b1-4) -> github-ops (R06b1-5).

## Risiko
- Balapan ubah-menu vs keputusan: dicegah dengan kunci baris menu -> pengajuan (urutan tetap, cegah deadlock) dan cek versi tiga arah.
- Snapshot dimanipulasi klien: semua angka dihitung server; Form Request tidak menerima field harga/biaya/porsi.
- IDOR: pengajuan SPPG lain via id langsung -> 404/403 lewat SppgScope + Policy; investor lain tanpa pivot -> 403 dari middleware.
- Notifikasi investor: `NotificationService` saat ini mencari penerima via `users.sppg_id` (investor null) -> perlu cabang pivot.
- Menu disetujui yang diubah kembali ke draf; jadwal produksi lama yang sudah memakai menu itu tidak diubah otomatis (residu, butuh keputusan manusia bila ingin diblokir).
- Presisi desimal: hitung dengan string/bcmath atau pembulatan konsisten 2 desimal pada tiap baris dan total.
