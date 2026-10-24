# Skema Basis Data SiGAP SPPG

Acuan untuk agen fitur. Sumber: ERD proposal (Gambar 7, subbab 4.3) dengan penyesuaian yang tercantum di bagian "Penyimpangan dari ERD".
Migrasi: `database/migrations/2026_10_24_1000NN_*` (urutan sesuai ketergantungan FK). Model: `app/Models`. Enum: `app/Enums`. Factory: `database/factories`. Data demo: `php artisan db:seed --class=DemoSeeder` (local/testing).

## Konvensi

- InnoDB, utf8mb4. Kolom enum disimpan sebagai `string` dan di-cast ke Enum PHP di model (portabel MySQL/SQLite). Nilai Enum = nilai string di DB.
- Semua tabel transaksi memiliki `sppg_id` (FK `sppgs.id`, restrict) dan memakai trait `BelongsToSppg` (scope otomatis; sppg_id dipaksa = SPPG pengguna non-admin). `sppg_id` tidak ada di `$fillable` (kecuali tabel non-tenant); admin mengisinya lewat Form Request.
- `softDeletes` ada pada tabel yang ditandai (SD). `Auditable` (audit ke `audit_logs`) ada pada tabel yang ditandai (AU).
- Tanggal di-cast `date:Y-m-d`, uang `decimal:2`, stok `decimal:3`. Tidak ada kolom NIK di mana pun (UU PDP).
- Foto/berkas: kolom `*_path` menyimpan path relatif pada disk privat (diakses lewat signed URL, NFR-06). Tabel `lampirans` (polimorfik) menyimpan berkas unggahan aman; keluhan, mutu, dan log produksi memakai alur unggah tertunda (`POST /lampiran` lalu `lampiran_ids` pada store/update, maks 5 per data) dan TIDAK menerima `foto_path` dari body.
- Pencarian: `Model::search($teks)` (trait `App\Models\Searchable`, LIKE ter-escape) pada kolom di `$searchable`.
- Kolom user pencatat memakai FK `users.id` (nullOnDelete): `dicatat_oleh`, `dibuat_oleh`, `pemeriksa_id`, `petugas_id`, `ditangani_oleh`.

## Tabel

Notasi: PK `id` pada semua tabel kecuali dinyatakan lain; SD = softDeletes; AU = Auditable; T = ber-tenant (`sppg_id`, BelongsToSppg).

| Tabel (Model) | Flag | Kolom penting | Relasi / indeks |
|---|---|---|---|
| `sppgs` (Sppg) | SD | kode (unik), nama, alamat, mitra_yayasan, telepon, kapasitas_porsi_harian, status_operasional (StatusOperasional) | hasMany semua tabel T + users |
| `titik_layanans` (TitikLayanan) | T SD | nama, jenis (JenisTitikLayanan), alamat, jarak_km, jumlah_sasaran, pic_nama, pic_telepon, pic_telegram_chat_id (null bila PIC belum terhubung), is_active | hasMany penerima_manfaats, jadwal_distribusis, feedback_keluhans, notifikasi_perjalanans; idx (sppg_id,nama) |
| `penerima_manfaats` (PenerimaManfaat) | T SD AU | titik_layanan_id, nama (inisial/nama minimal), kelompok_sasaran (KelompokSasaran), kelas_keterangan, catatan_alergi, is_active | belongsTo titikLayanan |
| `pemasoks` (Pemasok) | T SD | nama, jenis (JenisPemasok), kontak, alamat, jenis_bahan, is_active | hasMany bahan_bakus, pengeluarans, mutasi_stoks |
| `bahan_bakus` (BahanBaku) | T SD | pemasok_id?, kode, nama, kategori (KategoriBahan), satuan, stok (saldo dari mutasi), stok_minimum, kedaluwarsa_terdekat | belongsTo pemasok; hasMany mutasi_stoks; belongsToMany menus; scope `stokMenipis()`, `akanKedaluwarsa($hari=3)` |
| `mutasi_stoks` (MutasiStok) | T SD | bahan_baku_id, jenis (JenisMutasi), jumlah (masuk/keluar positif; penyesuaian = selisih bertanda), harga_satuan?, tanggal_mutasi, tanggal_kedaluwarsa? (masuk), jadwal_produksi_id? (keluar), pemasok_id?, dicatat_oleh, catatan | belongsTo bahanBaku, jadwalProduksi, pemasok |
| `menus` (Menu) | T SD | nama_menu, kelompok_sasaran, tanggal_sajian, siklus, energi_kkal, protein_g, lemak_g, karbohidrat_g, serat_g, catatan | belongsToMany bahanBakus (pivot `menu_bahan.gram_per_porsi`); hasMany jadwal_produksis |
| `menu_bahan` (MenuBahan, Pivot) | - | PK komposit (menu_id, bahan_baku_id), gram_per_porsi | tanpa sppg_id (lewat menu), tanpa timestamps. Kebutuhan = Sum(gram_per_porsi x target_porsi) |
| `standar_gizis` (StandarGizi) | T | kelompok_sasaran, {energi,protein,lemak,karbohidrat,serat}_{min,max}; unik (sppg_id, kelompok_sasaran) | `peringatanUntuk(Menu)` mengembalikan kolom di luar rentang |
| `jadwal_produksis` (JadwalProduksi) | T SD | menu_id, dibuat_oleh, tanggal_produksi, jam_mulai_masak, target_porsi (<= kapasitas SPPG, divalidasi service), status (StatusJadwalProduksi), catatan | hasOne logProduksi; hasMany pemeriksaanMutus, jadwalDistribusis, feedbackKeluhans, mutasiStoks; `isLayakDistribusi()` = pemeriksaan terbaru Layak; scope `tanggal()` |
| `log_produksis` (LogProduksi) | T SD | jadwal_produksi_id (UNIK: 1 log per jadwal), realisasi_porsi, sisa_makanan_kg, jam_selesai, kendala, foto_path, dicatat_oleh | belongsTo jadwalProduksi |
| `pemeriksaan_mutus` (PemeriksaanMutu) | T SD AU | jadwal_produksi_id (1:N, boleh periksa ulang), pemeriksa_id, waktu_pemeriksaan, suhu_makanan_c, kebersihan_dapur, kebersihan_petugas, rasa_baik, aroma_baik, tampilan_baik (checklist organoleptik), organoleptik_baik (TURUNAN: true bila ketiga butir true), catatan_organoleptik, sampel_disimpan, hasil (HasilMutu), tindakan_korektif, foto_path (legacy, tidak diisi API; foto lewat lampirans) | belongsTo jadwalProduksi |
| `armadas` (Armada) | T SD | plat_nomor (unik per sppg), jenis, kapasitas_box, status (StatusArmada) | hasMany jadwal_distribusis |
| `pengemudis` (Pengemudi) | T SD | nama, no_hp, no_sim?, is_active | hasMany jadwal_distribusis |
| `jadwal_distribusis` (JadwalDistribusi) | T SD AU | jadwal_produksi_id, titik_layanan_id, armada_id?, pengemudi_id?, petugas_id? (users), tanggal_distribusi, jam_berangkat, jam_rencana_tiba, jumlah_porsi, status (StatusJadwalDistribusi), bukti_belum_masuk_pada (penanda atomik pemindai `sigap:cek-bukti-distribusi`: event BuktiDistribusiBelumMasuk sekali per jadwal), catatan | hasOne buktiDistribusi; hasMany notifikasiPerjalanans; scope `tanggal()` |
| `notifikasi_perjalanans` (NotifikasiPerjalanan) | T | jadwal_distribusi_id, titik_layanan_id, dipicu_oleh, penerima_chat_id?, dialihkan_ke_kepala, waktu_kirim?, estimasi_tiba, status_kirim (StatusKirim), pesan, error | tanpa SD (log) |
| `bukti_distribusis` (BuktiDistribusi) | T SD AU | jadwal_distribusi_id (UNIK: 0..1 per jadwal), dicatat_oleh, waktu_berangkat?, waktu_tiba, waktu_ambil_foto?, porsi_diterima, nama_pic, no_berita_acara, berita_acara_path, foto_path, latitude?, longitude?, catatan_selisih (wajib bila diterima < dikirim, validasi di Form Request), terlambat, menit_keterlambatan | belongsTo jadwalDistribusi |
| `pencairan_danas` (PencairanDana) | T SD AU | dicatat_oleh, periode (mis. `2026-10`), sumber_dana, nominal, tanggal_cair, no_referensi, bukti_path, catatan | hasMany pengeluarans; `totalPengeluaran()`; factory `terserap(80)`/`terserap80()`/`terserap100()` |
| `pengeluarans` (Pengeluaran) | T SD AU | pencairan_dana_id?, pemasok_id?, dicatat_oleh, tanggal, kategori (KategoriPengeluaran), uraian, nominal, nota_path | belongsTo pencairanDana, pemasok. Sisa anggaran = Sum(pencairan) - Sum(pengeluaran) (BudgetService) |
| `feedback_keluhans` (FeedbackKeluhan) | T SD | titik_layanan_id?, jadwal_produksi_id? (kaitan batch), dicatat_oleh, ditangani_oleh, sumber (SumberKeluhan), kategori (KategoriKeluhan), prioritas (PrioritasKeluhan), status (StatusKeluhan: baru, diproses, selesai), deskripsi, foto_path, tindak_lanjut, dieskalasi_pada, selesai_pada | scope `kritis()`, `terbuka()`; `PrioritasKeluhan::dariKategori()` memberi Kritis untuk makanan_basi/gejala_sakit |
| `rekap_harians` (RekapHarian) | T | tanggal, ringkasan (json), dikirim_pada, status_kirim (StatusKirim); unik (sppg_id, tanggal) | tanpa SD |
| `lampirans` (Lampiran) | T | polimorfik lampiranable_type/id (NULL = unggahan tertunda belum terikat), modul, kategori, uploaded_by, path, mime, ukuran, sha256 | morphTo lampiranable; trait `HasLampiran` pada BuktiDistribusi, FeedbackKeluhan, PemeriksaanMutu, LogProduksi, dst. |
| `audit_logs` (AuditLog) | - | sppg_id?, user_id?, event, auditable_type, auditable_id, old_values, new_values (json), ip, user_agent, sumber (web/bot/ai/sistem; `AuditLog::withSource()`) | idx (auditable_type, auditable_id), (sppg_id, created_at); tidak ber-scope tenant |
| `ai_interactions` (AiInteraction) | - | sppg_id?, user_id?, jenis_input (JenisInputAi), tool_name, argumen (json), model, latensi_ms, hasil (HasilAi), error | hasMany aiDrafts |
| `ai_drafts` (AiDraft) | T SD | uuid (unik, route key), user_id, ai_interaction_id?, module, action (AksiAiDraft), target_id? (untuk update/delete), payload (json), confidence (json), status (StatusAiDraft), expires_at (30 menit), confirmed_at | `bisaDikonfirmasi()`, scope `pending()`, `sudahKedaluwarsa()` |
| `invitations` (Invitation) | - | token_hash (sha256, unik; `Invitation::hashToken()`), tipe (TipeUndangan: pengguna/pic), role?, unit?, sppg_id?, user_id? (akun yang dikaitkan), titik_layanan_id? (undangan PIC), created_by, expires_at (72 jam), used_at, used_by | `isUsable()`, scope `aktif()`; tidak ber-scope tenant (dicari lewat token) |
| `telegram_updates` (TelegramUpdate) | - | update_id (unik, idempotensi webhook), payload (json), processed_at | |

FK `users.sppg_id -> sppgs.id` (nullOnDelete) ditambahkan migrasi `..._100028_add_sppg_foreign_key_to_users_table`.

## Enum (`App\Enums`, semua string-backed dengan `label()` dan `values()`)

| Enum | Nilai |
|---|---|
| StatusOperasional | aktif, libur, nonaktif |
| JenisTitikLayanan | sekolah, paud, posyandu, lainnya |
| KelompokSasaran | siswa, balita, ibu_hamil, ibu_menyusui |
| KategoriBahan | karbohidrat, protein_hewani, protein_nabati, sayur, buah, susu, bumbu, lainnya |
| JenisMutasi | masuk, keluar, penyesuaian |
| JenisPemasok | umkm, petani, peternak, nelayan |
| StatusJadwalProduksi | direncanakan, dimasak, selesai, dibatalkan |
| StatusJadwalDistribusi | menunggu, siap, berangkat, diterima, dibatalkan |
| HasilMutu | layak, tidak_layak |
| StatusArmada | tersedia, bertugas, servis |
| StatusKirim | menunggu, terkirim, gagal, tidak_terkirim (notifikasi perjalanan dan rekap) |
| KategoriPengeluaran | bahan_baku, insentif_relawan, energi, transportasi, peralatan, lainnya |
| SumberKeluhan | guru, orang_tua, penerima, relawan, lainnya |
| KategoriKeluhan | makanan_basi, gejala_sakit, porsi_kurang, terlambat, rasa, kemasan, lainnya |
| PrioritasKeluhan | rendah, sedang, tinggi, kritis |
| StatusKeluhan | baru, diproses, selesai |
| StatusAiDraft | pending, confirmed, discarded, expired |
| AksiAiDraft | create, update, delete |
| JenisInputAi | teks, foto, suara |
| HasilAi | sukses, gagal, ditolak, fallback |
| TipeUndangan | pengguna, pic |

Enum milik fondasi auth: `Role`, `RelawanUnit`.

## Penyimpangan dari ERD (dan alasannya)

1. Nama tabel plural gaya Laravel (`sppgs`, `titik_layanans`, ...) agar sesuai konvensi dan factory; pivot `menu_bahan` tetap tunggal sesuai ERD. Semua model menetapkan `$table` eksplisit.
2. ERD hanya memuat sebagian kolom; ditambah kolom yang diwajibkan teks proposal/kebutuhan: `kedaluwarsa_terdekat`, kolom mutu (kebersihan, organoleptik, sampel), `dieskalasi_pada`, `waktu_ambil_foto`. Idempotency key (NFR-07) ditangani di lapisan HTTP, bukan kolom.
3. `sppg_id` ditambahkan langsung pada tabel anak (log_produksis, pemeriksaan_mutus, jadwal_distribusis, bukti_distribusis, dst.) agar scope isolasi sederhana dan terindeks (ERD: "langsung atau melalui induk").
4. `ai_interactions` memakai `jenis_input/hasil` ber-Enum; `ai_drafts.id` ERD (uuid PK) dipisah menjadi PK bigint + kolom `uuid` unik (konsisten dengan kontrak foundation dan relasi cepat); status `rejected` pada ERD diganti `discarded` sesuai kontrak PM.
5. `armadas.plat_nomor` unik per SPPG (bukan global) karena multi-tenant. Karena ada soft delete, plat/jadwal yang sudah dihapus lunak tetap menempati indeks unik: pulihkan (`restore`) alih-alih membuat ulang.
6. `invitations` ditambah `tipe`, `user_id`, `titik_layanan_id` (undangan PIC titik layanan, FR-12) dan `role` nullable.
7. `lampirans` (polimorfik, disebut di teks ERD) dibuat; bukti distribusi, keluhan, mutu, dan log produksi menyajikan foto lewat daftar `lampiran` (kolom `*_path` lama dipertahankan hanya untuk kompatibilitas dan tidak diisi dari body). Unggahan tertunda: `lampiranable_type/id` NULL dan `modul` mencatat modul tujuan sampai diikat `PengikatLampiran`.
8. `pengemudis.no_sim` dipertahankan dari ERD namun nullable (minimisasi data pribadi); pertimbangkan dihapus.
9. `mutasi_stoks`: penyesuaian memakai `jumlah` bertanda (selisih); saldo `bahan_bakus.stok` harus diperbarui oleh StockService dalam transaksi yang sama.
