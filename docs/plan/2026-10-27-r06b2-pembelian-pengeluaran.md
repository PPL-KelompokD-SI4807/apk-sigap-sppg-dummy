# R-06b-2 Pembelian Bahan + Persetujuan Pengeluaran oleh Investor (Tier T2, tinjauan keamanan T3)

Tanggal: 2026-10-27 · Branch: `feat/r06b2-pembelian-pengeluaran` (dari `develop`, R-06b-1 dan adapter LLM sudah ter-merge) · PR ke `develop`
Fitur terkait: No 6 (Bahan Baku), No 7 (Pemasok), No 9 (Jadwal Produksi, perbaikan S2), No 14 (Keuangan), No 15 (Keluhan, notifikasi), notifikasi bot, Agen AI.
Keputusan pengguna final (5 butir lingkup); tidak menambah fitur.

Sumber acuan: `docs/plan/2026-10-26-r06b1-persetujuan-menu.md`, `docs/decisions/2026-10-26-tinjauan-keamanan-r06b1.md`, dan kode yang ada
(`PersetujuanMenuService`, `HargaPemasok`, `BudgetService`, `SecureUploadService`/`PengikatLampiran`/`LampiranController`, `NotificationService`/`DomainEventNotifications`,
`AiAgentService` vision dua tahap, `CatalogDefinitions`). **Catatan:** dokumen `claude/revisi-r06-peran-investor.md` TIDAK ADA di repo (sudah dicari);
rencana ini tidak bergantung padanya.

## Tujuan
1. Staf gudang mencatat pembelian bahan (bahan, jumlah, harga satuan, pemasok, foto nota wajib) -> akuntan memverifikasi (setujui/tolak + alasan).
   Pembelian terverifikasi otomatis menjadi pengeluaran kategori `bahan_baku` yang dihitung ke serapan anggaran.
2. Pengeluaran non-menu wajib disetujui investor pemilik SPPG. Ambang rupiah dapat dikonfigurasi (bawaan 0 = semua perlu persetujuan).
3. Notifikasi bot ke investor HANYA untuk: menu diajukan (sudah ada), pengeluaran diajukan, anggaran 80% dan 100%, keluhan kritis.
4. Perbaikan S2: jadwal produksi yang menunya tidak lagi `disetujui` ditandai dan tidak dapat pindah ke status produksi (`dimasak`).
5. Tool Agen AI: aksi tulis hanya AiDraft yang dikonfirmasi pengguna; foto nota lewat vision dua tahap yang sudah ada.

## Keputusan desain teknis (PM)

### Model data (semua migrasi aditif)
**`pembelian_bahans` (baru)**: `sppg_id`, `bahan_baku_id`, `pemasok_id`, `tanggal_beli` (date), `jumlah` decimal(12,3) > 0 maks 3 desimal,
`satuan` (string 20, snapshot dari bahan saat simpan), `harga_satuan` decimal(14,2) > 0 (per satuan bahan), `total` decimal(15,2) (dihitung server,
rupiah bulat: `round(jumlah x harga_satuan)` dengan bcmath, bukan float), `catatan` (nullable, maks 500), `nota_path` (nullable, diisi dari lampiran pertama),
`status` string(20) default `diajukan`, `versi` unsigned int default 1, `dicatat_oleh`, `diverifikasi_oleh`, `diverifikasi_pada`, `alasan_penolakan` (nullable),
`pengeluaran_id` (nullable, unik, FK `pengeluarans`), timestamps, soft delete (tidak ada endpoint hapus; soft delete hanya untuk konsistensi `SppgScope`/audit).
Indeks `(sppg_id, status, tanggal_beli)`.

**`pengeluarans` (kolom baru)**: `status_persetujuan` string(20) **default `diajukan` (fail-closed)**, `versi` unsigned int default 1,
`pembelian_bahan_id` (nullable, unik, FK). Indeks `(sppg_id, status_persetujuan, tanggal)`.
Migrasi meng-*backfill* baris yang SUDAH ada menjadi `disetujui` (data lama berasal dari dokumen sumber dan sudah dihitung dalam anggaran;
mengubahnya ke `diajukan` akan menurunkan serapan secara diam-diam). `down()` hanya menghapus kolom. Backfill hanya menyentuh kolom baru -> tetap ditinjau opus.

**`persetujuan_pengeluarans` (baru, pola `pengajuan_menus`)**: `sppg_id`, `pengeluaran_id`, `nomor` (urut per pengeluaran), `pengeluaran_versi`,
`status` (diajukan/disetujui/ditolak/dibatalkan), snapshot `kategori`, `nominal`, `tanggal`, `pemasok_id`, `ambang_persetujuan` (nilai config saat diajukan),
`diajukan_oleh`, `diajukan_pada`, `diputuskan_oleh`, `diputuskan_pada`, `catatan_keputusan`, timestamps. Tanpa soft delete.
Imutabel di model (`fillable` kosong, tolak hapus, tolak perubahan selain satu transisi `diajukan` -> disetujui/ditolak/dibatalkan). Tanpa route ubah/hapus.

**Jadwal produksi (S2)**: TIDAK ada migrasi. Penanda dihitung saat baca (lihat S2) agar perubahan menu tidak perlu mengunci baris jadwal
(menghindari urutan kunci terbalik menu -> jadwal vs sppg -> jadwal -> menu).

### Status dan transisi
**Pembelian bahan**: `diajukan` -> `terverifikasi` (akuntan setujui; final) | `diajukan` -> `ditolak` (akuntan, alasan wajib 5..500 karakter)
| `ditolak` -> `diajukan` (staf gudang mengubah data, `versi` naik, alasan lama dikosongkan). Ubah saat `diajukan` juga menaikkan `versi`.
`terverifikasi` tidak dapat diubah/dihapus (409 `pembelian_sudah_diverifikasi`).
Verifikasi setuju dalam satu transaksi: buat `pengeluaran` (`kategori=bahan_baku`, `nominal=total`, `tanggal=tanggal_beli`, `pemasok_id`,
`uraian` = "Pembelian <nama bahan> <jumlah> <satuan>", `status_persetujuan=disetujui`, `pembelian_bahan_id`, `dicatat_oleh` = akuntan), isi `pengeluaran_id`,
hitung `melebihi_anggaran`, lalu `evaluasiAmbang` (80/100%). Pengeluaran hasil pembelian TIDAK melewati investor (keputusan pengguna: hanya non-menu).

**Pengeluaran**: definisi operasional "non-menu" = **setiap pengeluaran yang dicatat langsung oleh akuntan** (endpoint/draf AI `pengeluaran`),
apa pun kategorinya, termasuk `bahan_baku`. Belanja bahan untuk menu masuk lewat Pembelian Bahan. Ini menutup celah melabeli pengeluaran non-menu
sebagai `bahan_baku` agar lolos dari investor.
- Simpan: `nominal >= ambang` -> `diajukan` + baris `persetujuan_pengeluarans` + event `PengeluaranDiajukan`; `nominal < ambang` (hanya mungkin bila ambang > 0)
  -> `disetujui` langsung, tanpa baris persetujuan, lalu `evaluasiAmbang`.
- Ubah (PATCH, wajib `versi`): perubahan apa pun pada pengeluaran `diajukan`/`disetujui`/`ditolak` -> `versi` naik, status kembali `diajukan`,
  persetujuan yang masih `diajukan` -> `dibatalkan` (catatan sistem "pengeluaran diubah setelah diajukan"), baris persetujuan baru + event baru.
  Menambah lampiran lewat `POST /lampiran` pada pengeluaran yang ada tidak mengubah status (bukan nilai keuangan).
- Putuskan (investor): `diajukan` -> `disetujui` (lalu hitung `melebihi_anggaran` + `evaluasiAmbang`) | `diajukan` -> `ditolak` (catatan wajib 5..500).
- Hapus (akuntan, hapus lunak seperti sekarang): persetujuan `diajukan` -> `dibatalkan`. Pengeluaran ber-`pembelian_bahan_id` tidak dapat
  diubah/dihapus lewat endpoint pengeluaran (409 `pengeluaran_dari_pembelian`).

**Anggaran (cek yang ada, tidak diduplikasi)**: `BudgetService::ringkasan`, `evaluasiAmbang`, `PeringatanAnggaran` (idempoten), event
`AnggaranMencapai80/100/Melampaui` sudah ada. Perubahan: `terpakai`, `per_kategori`, `jumlah_pengeluaran`, `jumlah_melebihi_anggaran` hanya menghitung
`status_persetujuan = disetujui`; ringkasan menambah `menunggu_persetujuan: {jumlah, nominal}`. `evaluasiAmbang` dan `cekSisa` dipindah dari saat
mencatat ke saat pengeluaran menjadi `disetujui` (keputusan investor, verifikasi pembelian, atau di bawah ambang). `DailySummaryService` (rekap 17.00)
memakai saringan `disetujui` yang sama. `tolak_melebihi_anggaran=true` -> 422 saat menyetujui/memverifikasi.

### Konfigurasi ambang
`config('operasional.keuangan.ambang_persetujuan_pengeluaran')` = `(int) env('SIGAP_AMBANG_PERSETUJUAN_PENGELUARAN', 0)`; nilai negatif dianggap 0.
Aturan: perlu persetujuan bila `nominal >= ambang` (0 -> semua, karena nominal minimal 1). Tidak dapat diubah lewat API. Nilai saat diajukan disimpan
di snapshot. Tambahkan kunci ke `.env.example` (tanpa membaca `.env`).

### RBAC per peran
- Modul baru `pembelian-bahan` di `config/rbac.php` (tambahan di luar 16 fitur, seperti `persetujuan-menu`):
  admin `R`, kepala_sppg `R`, ahli_gizi `''`, akuntan `R`, staf_gudang `CRU`, relawan `''`, investor `''`.
- `rbac.keputusan` ditambah `'pembelian-bahan' => ['akuntan']` dan `'keuangan' => ['investor']` (memutuskan pengajuan pengeluaran).
  `Rbac::readOnly(investor)` tetap true; `AuthUserResource.keputusan` investor menjadi `["persetujuan-menu","keuangan"]`, akuntan `["pembelian-bahan"]`.
  Rute keputusan investor mengikuti pola pengecualian R-06b-1 (bukan membuka huruf C/U/D).
- Matriks `keuangan` tidak berubah: staf gudang tetap tanpa akses keuangan; akuntan CRUD; investor R + keputusan; admin/kepala R.
- Pemisahan tugas: pencatat pembelian (staf gudang) != pemverifikasi (akuntan); pencatat pengeluaran (akuntan) != pemutus (investor). Admin/kepala tidak pernah memutuskan (403).
- Scope SPPG: investor hanya untuk SPPG di pivot + `X-SPPG-Id` = `pengeluaran.sppg_id` (Policy + cek pivot di service, seperti `PengajuanMenuPolicy`).
  Record SPPG lain -> 404 (SppgScope); peran tanpa hak -> 403. `bahan_baku_id` dan `pemasok_id` wajib SPPG yang sama dan pemasok aktif (422).
- Unggahan: `config/unggahan.php` modul `pembelian-bahan` = `['model' => PembelianBahan::class, 'ability' => 'update', 'tertunda' => true,
  'rbac' => 'pembelian-bahan', 'kategori' => ['nota'], 'maks' => 3, 'sembunyikan_dari_peran' => ['investor']]`. Nota via `POST /lampiran` (tertunda)
  lalu `lampiran_ids` (min 1 saat buat) diikat `PengikatLampiran` di dalam transaksi. Pengeluaran langsung tetap memakai jalur `nota` multipart yang ada.
  Nota pembelian tidak disalin ke pengeluaran (investor tidak melihat nota pembelian).

### Kunci baris + versi -> 409
- Semua tulis di transaksi. Urutan kunci tetap: `sppgs` (mutex `BudgetService::kunciSppg`) -> `pembelian_bahans` -> `pengeluarans` -> `persetujuan_pengeluarans`.
  Ubah pembelian oleh staf gudang cukup mengunci baris pembelian (tidak pernah mengunci sppg setelah pembelian, jadi tidak ada siklus).
  Jalur menu (menu -> pengajuan) dan jadwal (sppg -> jadwal -> menu) tidak menyentuh tabel baru.
- Body wajib `versi` pada PATCH pembelian, PATCH pengeluaran, verifikasi, dan keputusan. Versi beda -> 409 `versi_tidak_cocok` + `versi_server`
  (`GalatAuth::respons`, pola R-06b-1). Status bukan `diajukan` saat verifikasi/keputusan -> 409 `status_tidak_valid` (keputusan ganda/investor kedua -> 409).
- Draf AI ubah: `versi` diambil server dari record target saat draf dibuat (bukan dari LLM), sehingga konfirmasi draf basi -> 409.
  Bila `DraftService` tidak dapat menyimpannya, Mini App mengirim versi record yang dimuat saat konfirmasi (asumsi cadangan, catat di laporan).

### Audit tanpa data pribadi
Model baru dan `Pengeluaran` memakai `Auditable` dengan `$auditExclude` untuk teks bebas (`uraian`, `catatan`, `catatan_keputusan`, `alasan_penolakan`,
`nota_path`). Audit mencatat id, status lama/baru, versi, nominal/total, kategori, user_id, sumber (web/bot/ai). Tidak ada nama, nomor HP, atau isi nota.
Pesan galat dan log aplikasi tidak memuat isi nota hasil vision.

### Kontrak API (ringkas; rinci di `docs/api/openapi.yaml` via `docs/api/paths/**`)
- `GET /api/v1/pembelian-bahan?status=&bahan_baku_id=&pemasok_id=&dari=&sampai=&q=` (paginasi standar)
- `POST /api/v1/pembelian-bahan` `{bahan_baku_id, pemasok_id, tanggal_beli, jumlah, harga_satuan, catatan?, lampiran_ids[1..3], ai_draft_id?}` -> 201
  `{id, status:"diajukan", versi:1, total, satuan, ...}`. Klien tidak pernah mengirim `total`, `status`, `satuan`.
- `GET /api/v1/pembelian-bahan/{id}` -> termasuk `lampirans`, `pengeluaran_id`, `harga_referensi` (harga pemasok terkini dari `HargaPemasok`, informatif untuk akuntan, null bila tidak ada).
- `PATCH /api/v1/pembelian-bahan/{id}` `{versi, ...field, lampiran_ids?, ai_draft_id?}` (staf gudang; status diajukan/ditolak).
- `POST /api/v1/pembelian-bahan/{id}/verifikasi` `{keputusan: "disetujui"|"ditolak", alasan?, versi}` (akuntan) -> 200 pembelian + `pengeluaran_id`.
- `GET /api/v1/pengeluaran?status_persetujuan=diajukan|disetujui|ditolak` (filter baru) · `GET /api/v1/pengeluaran/{id}` menambah
  `status_persetujuan`, `versi`, `pembelian_bahan_id`, `riwayat_persetujuan[]` (snapshot + keputusan).
- `POST /api/v1/pengeluaran` (respons menambah status) · `PATCH /api/v1/pengeluaran/{id}` sekarang wajib `versi`.
- `POST /api/v1/pengeluaran/{id}/keputusan` `{keputusan: "disetujui"|"ditolak", catatan?, versi}` (investor) -> 200.
- `GET /api/v1/keuangan/ringkasan` menambah `menunggu_persetujuan`.
- `PATCH /api/v1/jadwal-produksi/{id}` `status=dimasak` ditolak 422 (`status`) bila menu tidak `disetujui`; resource jadwal menambah
  `menu_belum_disetujui` (bool) dan filter `?menu_belum_disetujui=1`.
- Kode galat: 403 tanpa hak, 404 SPPG lain, 409 `versi_tidak_cocok`/`status_tidak_valid`/`pembelian_sudah_diverifikasi`/`pengeluaran_dari_pembelian`, 422 validasi.
- Throttle: verifikasi/keputusan memakai limiter yang sama dengan `persetujuan-menu.keputusan`; POST pembelian memakai `throttle:unggah` + `IdempotencyKey`.

### Tool Agen AI / AiDraft
- `CatalogDefinitions`: resource baru `pembelian-bahan` (module `pembelian-bahan`): tool baca (filter status/bahan/pemasok/tanggal) untuk peran ber-R,
  tool tulis create/update (draf) hanya untuk staf_gudang memakai `StorePembelianBahanRequest`/`UpdatePembelianBahanRequest`; field `total`,
  `status`, `satuan`, `versi`, `lampiran_ids` tidak pernah diterima dari LLM. Konfirmasi lewat `AiDraftConfirmer` (`resource: 'pembelian-bahan'`) di endpoint store/update.
- Resource `pengeluaran` yang ada: tool baca menambah filter `status_persetujuan`; draf akuntan saat dikonfirmasi menjadi pengeluaran `diajukan` (alur normal).
- TIDAK ada tool untuk verifikasi pembelian maupun keputusan pengeluaran (keputusan hanya di Mini App, konsisten R-06b-1; investor tidak pernah membuat AiDraft).
  ToolRegistry tetap disaring per peran, jadi staf gudang tidak pernah melihat tool keuangan.
- Foto nota di bot: jalur vision dua tahap yang ada (tahap 1 vision tanpa tool menyalin teks = data tak tepercaya; tahap 2 memanggil tool draf).
  Berkas foto Telegram TIDAK disimpan sebagai lampiran; saat konfirmasi draf di Mini App, form prefill mewajibkan unggah nota lewat jalur aman.
  Instruksi di teks nota ("setujui otomatis", dsb.) tidak berpengaruh karena tool keputusan tidak ada dan `total` dihitung ulang server.
- Perbarui `docs/api/ai-tools.md`.

### Notifikasi (hanya yang diminta)
- `RECIPIENTS`: `AnggaranMencapai80` dan `AnggaranMencapai100` ditambah `Role::Investor`; `KeluhanKritis` ditambah `Role::Investor`;
  event baru `PengeluaranDiajukan` (`ShouldDispatchAfterCommit`) -> `[Role::Investor]`. `AnggaranMelampaui` TIDAK ke investor (selalu terpancar bersamaan
  dengan 100% karena `>` mengimplikasikan `>=`, jadi akan menjadi notifikasi ganda). Tidak ada notifikasi baru untuk akuntan/staf gudang.
- Investor dicari lewat pivot `investor_sppg` (sudah ada di `usersWithRoles`, termasuk penyaringan SPPG terhapus R4).
- Isi `PengeluaranDiajukan`: kategori, nominal, tanggal, serapan/sisa anggaran periode; TANPA `uraian` (teks bebas). Dedup `pengeluaran_diajukan:{sppg}:{id}:{versi}`.
  Tombol "Lihat di Mini App" -> `/keuangan/pengeluaran/{id}?sppg={sppg}`.
- Keluhan kritis untuk investor tanpa `deskripsi` (teks bebas pelapor; hanya kategori + titik layanan); peran lain tetap seperti sekarang.
  Mekanisme varian pesan per peran bebas dipilih backend asal dedup terpisah per varian.
- Tombol anggaran/keluhan memakai petunjuk `?sppg={sppg}` (divalidasi server) agar investor multi-SPPG membuka SPPG yang benar.
- Perbarui `docs/api/notifikasi.md`.

### Perbaikan S2 (jadwal produksi lama)
- Penanda: `menu_belum_disetujui = menu.status_persetujuan !== disetujui` dihitung di resource/daftar (eager load menu, tanpa N+1).
- Blokir: `ProductionPlanningService::perbarui` menolak transisi `direncanakan -> dimasak` bila menu (dikunci, urutan sppg -> jadwal -> menu) tidak `disetujui`
  (422 pada `status`, pesan "Menu jadwal ini belum disetujui investor; ajukan ulang menu sebelum memasak"). `dimasak -> selesai` dan `-> dibatalkan` tetap
  diizinkan (makanan sudah diproses / jadwal boleh dibatalkan). Jalur AiDraft jadwal melewati service yang sama.
- Bila menu disetujui kembali, penanda hilang dan jadwal dapat dimasak dengan versi menu terbaru yang disetujui (asumsi).
- Tidak ada notifikasi untuk S2. Perbarui baris S2 di tinjauan R-06b-1 menjadi "Diperbaiki di R-06b-2" pada berkas tinjauan R-06b-2.

### Data contoh
Seeder/factory fiktif: pemasok "UD Sumber Pangan Fiktif", pembelian beras/telur fiktif dengan status diajukan/terverifikasi/ditolak, pengeluaran
energi/transportasi diajukan/disetujui/ditolak. `PengeluaranFactory` default `disetujui` agar tes lama tetap relevan; tes baru memakai state eksplisit. Tanpa NIK/HP nyata.

## Tugas (berurutan)
| ID | Tier | Agen (model/effort) | Tugas | Bergantung |
|---|---|---|---|---|
| R06b2-1 | T2 | backend-dev `backend` (sonnet/high) | Pembelian bahan: migrasi `pembelian_bahans`, model + enum status, RBAC `pembelian-bahan` + `keputusan`, `config/unggahan.php`, Form Request, Policy, `PembelianBahanService` (hitung total bcmath, kunci + versi, verifikasi -> pengeluaran), controller + rute, tes (RBAC 403, IDOR 404, versi 409, keputusan ganda 409, nota wajib/ikat, total server) | - |
| R06b2-2 | T2 | backend-dev `backend` (sonnet/high) | Persetujuan pengeluaran: migrasi kolom + backfill + `persetujuan_pengeluarans`, imutabilitas, ambang config + `.env.example`, `PersetujuanPengeluaranService`, ubah/hapus/keputusan, `BudgetService` + `DailySummaryService` saring `disetujui` + `menunggu_persetujuan`, pindah `evaluasiAmbang`, event `PengeluaranDiajukan`, RECIPIENTS investor + varian keluhan + `?sppg=`, tes (investor lain 403, admin 403, 80/100% saat disetujui, rekap, notifikasi) | R06b2-1 |
| R06b2-3 | T2 | backend-dev `backend` (sonnet/high) | S2 jadwal (penanda + blokir dimasak), tool AI (`pembelian-bahan` baca/draf, filter status pengeluaran, versi draf ubah), seeder fiktif, `docs/api/openapi.yaml` + `paths/**`, `notifikasi.md`, `ai-tools.md`, `skema.md`; `php artisan test` + `pint --test`; kirim kontrak API + contoh JSON ke `frontend` | R06b2-2 |
| R06b2-4 | T1 | frontend-dev `frontend` (sonnet/medium) | Modul Mini App `pembelian-bahan` (daftar, form + unggah nota tertunda wajib, ubah saat ditolak, verifikasi akuntan + alasan wajib, `harga_referensi`, prefill draf AI), Keuangan (badge/filter status, `versi` pada PATCH, riwayat persetujuan, tombol Setujui/Tolak investor, `menunggu_persetujuan`), Jadwal (badge menu belum disetujui, tombol mulai masak nonaktif + pesan 422), deep link `/keuangan/pengeluaran/{id}`, `/keuangan`, `/keluhan` dengan `?sppg=`, tangani 409 (muat ulang); `textContent`; build + tes unit | R06b2-3 |
| R06b2-5 | T3 | agen read-only `reviewer` (opus/high) | Tinjauan keamanan: RBAC + scope SPPG + pivot investor, unggahan nota, tool AI/vision + prompt injection, migrasi backfill, urutan kunci, audit tanpa PII, isi notifikasi. Laporan ke orkestrator; PM menulis `docs/decisions/2026-10-27-tinjauan-keamanan-r06b2.md` | R06b2-3, R06b2-4 |
| R06b2-6 | T2 | backend-dev / frontend-dev (sonnet/high; opus bila gagal 2x) | Perbaikan temuan Kritis/Tinggi (+ Sedang yang murah) beserta tes, tinjau ulang ringan | R06b2-5 |
| R06b2-7 | T0 | github-ops `github` (haiku/low) | Commit bertahap (Conventional Commits), PR ke `develop` dengan checklist DoD, pantau CI 8.3/8.4, update CHANGELOG, TANPA merge | R06b2-5/6 |

Satu agen backend dikerjakan berurutan karena R06b2-1..3 berbagi `config/rbac.php`, `CatalogDefinitions.php`, dan `BudgetService.php`.
Frontend boleh mulai menyiapkan kerangka modul setelah kontrak R06b2-3 terkirim; maksimal 3 agen aktif.

## Asumsi (diputuskan PM, tanpa pertanyaan ke pengguna)
1. "Non-menu" = semua pengeluaran yang dicatat langsung akuntan; belanja bahan menu lewat Pembelian Bahan (diverifikasi akuntan, tanpa investor).
2. Pembelian bahan TIDAK otomatis membuat mutasi stok masuk; stok tetap dicatat staf gudang lewat fitur 6 (hindari dobel hitung dan perubahan perilaku fitur 6).
   Pembelian juga tidak menambah entri `harga_pemasoks`.
3. Pembelian terverifikasi bersifat final; koreksi (pembatalan/pembalikan) di luar lingkup (backlog).
4. Pengeluaran lama di-backfill `disetujui`; pengeluaran baru default `diajukan` (fail-closed).
5. Keputusan investor hanya disetujui/ditolak (tanpa `perlu_revisi`); akuntan memperbaiki lewat ubah -> otomatis diajukan ulang.
6. Investor tidak mendapat `AnggaranMelampaui` (redundan dengan 100%); akuntan/kepala tetap.
7. Foto nota dari bot tidak disimpan; nota diunggah di Mini App saat konfirmasi draf.
8. S2: penanda dihitung dari status menu saat ini, bukan versi; blokir hanya `direncanakan -> dimasak`.

## Risiko
- Perubahan perilaku keuangan: pengeluaran baru tidak lagi langsung masuk `terpakai`, peringatan 80/100% pindah ke saat disetujui; tes lama BudgetService/rekap perlu disesuaikan, bukan dihapus.
- Migrasi backfill menyentuh data (kolom baru) -> wajib ditinjau opus; uji `migrate` + `rollback` di MySQL staging.
- Pemecahan nominal untuk menghindari persetujuan bila ambang > 0 (bawaan 0 menutup risiko ini); dicatat untuk pengguna bila ambang dinaikkan.
- Pengeluaran menggantung bila investor tidak merespons; tanpa eskalasi (di luar lingkup); terlihat di `menunggu_persetujuan`.
- Balapan verifikasi/keputusan dan PATCH bersamaan: tes SQLite mengabaikan `lockForUpdate` (catatan R-06b-1); jaminan dari urutan kunci + cek versi di bawah kunci, belum diuji dua koneksi MySQL.
- Prompt injection lewat teks nota: mitigasi tanpa tool keputusan, total dihitung server, draf wajib dikonfirmasi manusia.
- PII di nota/teks bebas: nota privat via `LampiranController`, disembunyikan dari investor pada modul pembelian, teks bebas dikecualikan dari audit dan notifikasi investor.
- Guard imutabilitas di model dapat dilewati query builder (I1 R-06b-1; risiko diterima, backlog trigger MySQL).

## Pertanyaan terbuka
Tidak ada. Semua detail teknis diputuskan sebagai asumsi di atas; pengguna dapat membatalkan asumsi 1-8 bila tidak sesuai.
