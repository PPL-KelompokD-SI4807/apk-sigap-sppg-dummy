# Menu Tombol Bot per Peran dan Keputusan Investor (R-02)

Kode: `app/Services/Telegram/Bot/*`, router di `TelegramUpdateHandler`, tabel `bot_aksi`. Tidak ada endpoint REST baru (semua lewat webhook `POST /api/v1/telegram/webhook`); kontrak ini adalah perilaku bot.

## Perintah

| Perintah | Perilaku |
|---|---|
| `/menu` | Beranda (layar `h`): ringkasan hari ini sesuai peran + tombol utama 2-3 kolom disaring dari `config/rbac.php` (matriks 3.4) + baris tetap. Dikirim sebagai pesan baru terlindungi; navigasi berikutnya mengedit pesan itu. |
| `/start` | Pengguna dengan sesi penuh: sambutan (peran, SPPG) + ringkasan + beranda dalam satu pesan. Tanpa sesi: salam netral + satu tombol "Masuk lewat Mini App". |
| `/bantuan` | Sesi penuh: layar Bantuan (`bt`) dengan tombol Kembali. Tanpa sesi: hanya petunjuk masuk + tombol masuk. |
| `/batal` | Membatalkan jendela balasan alasan penolakan yang terbuka. |

Semua jalur (perintah menu, callback, balasan alasan) mewajibkan `SesiService::punyaSesiPenuh`. Tanpa sesi penuh balasannya hanya `TelegramUpdateHandler::PERLU_MASUK` + tombol web_app ke akar Mini App: tanpa data, tanpa daftar tombol data, token tidak dihabiskan, jendela alasan penolakan ditutup. Pengguna tidak terdaftar/nonaktif: perilaku lama (tanpa menu).

## Beranda (R02-A2)

Layar `h` dibangun `LayarBeranda`; pesan diedit di tempat. Teks: judul + nama SPPG, bagian "Ringkasan hari ini" (`BotRingkasanService`), baris "Diperbarui HH:mm" (membuat Segarkan biasanya berubah). Ringkasan hanya ada bila SPPG efektif sudah diketahui:

- peran satu-SPPG: `users.sppg_id`; investor satu SPPG: otomatis; admin dan investor >1 SPPG: pilih dulu lewat tombol "Pilih SPPG" (layar `ps`, 8 SPPG pertama; admin = semua SPPG aktif, investor = pivot). Sebelum dipilih hanya sapaan + Pilih SPPG (+ "Menunggu keputusan" untuk investor); tombol data tidak muncul.
- Ringkasan memakai `DailySummaryService::hitung` dan `BudgetService::ringkasan` (ambang peringatan `operasional.keuangan.ambang_peringatan_persen`) dengan `sppg_id` eksplisit, disaring per modul RBAC: jadwal (relawan: hanya sub-jenis unitnya), stok, keluhan, anggaran, antrean persetujuan (investor, `canDecide`). Relawan hanya jadwal unit + jumlah log hari ini milik sendiri. Hanya hitungan/persentase/status; tanpa nama, kontak, uraian, atau data anak.
- Cache `telegram.ringkasan_cache_detik` (60 dtk; 0 = mati) per (SPPG, tanggal, profil peran), hanya baris agregat hasil saringan peran itu.

Tombol utama: `BotLayarRegistry::MENU` (profil -> [id layar, label, modul, hak]). Tombol tampil bila layar tujuannya TERDAFTAR di registri dan `BotLayarRegistry::hakRbac` (aturan yang sama dengan `BotNavigasi`) mengizinkan peran saat ini; layar yang belum dibangun tidak pernah muncul. Entri khusus: `ps` (admin atau investor >1 SPPG) dan `kp` "Menunggu keputusan" (callback `b1` sekali pakai, hanya `Rbac::canDecide`). Susunan: 3 kolom bila semua label baris pendek (<= 12 karakter), selain itu 2 kolom (`BotTombol::susun`).

| Profil | Tombol utama (urutan tampil, bila layar terdaftar) |
|---|---|
| admin | Pilih SPPG; setelah SPPG dipilih: Rekap, Keluhan, Pengguna, Titik Layanan, Stok, Distribusi, Keuangan, Jadwal, Mutu (tanpa tombol tulis/`b1`) |
| kepala_sppg | Jadwal, Distribusi, Armada, Rekap Harian, Keluhan, Keuangan, Stok, Mutu, Log Produksi, Titik Layanan, Tim, Notifikasi Perjalanan, Mulai Perjalanan |
| ahli_gizi | Menu dan Gizi, Mutu, Jadwal, Keluhan, Rekap, Stok, Penerima Manfaat (agregat), Log Produksi |
| akuntan | Pencairan, Pengeluaran, Anggaran, Stok, Pemasok, Pembelian Bahan, Armada, Rekap, Catat pengeluaran, Catat pencairan |
| staf_gudang | Stok, Stok Menipis, Kedaluwarsa, Pemasok, Pembelian Bahan, Menu, Jadwal, Log Produksi, Catat mutasi stok |
| relawan dapur | Jadwal Produksi, Log Produksi, Menu Hari Ini, Mutu, Buat Keluhan, Profil Saya |
| relawan distribusi | Jadwal Distribusi, Bukti Distribusi, Mulai Perjalanan, Armada, Menu Hari Ini, Buat Keluhan, Profil Saya |
| relawan tanpa unit | Menu Hari Ini, Mutu, Armada, Buat Keluhan, Profil Saya |
| investor | Menunggu keputusan, Rekap, Anggaran, Pilih SPPG (bila >1) |

Baris tetap semua peran (paling bawah): [Tanya Agen AI (layar `ai`, hanya bila `ToolRegistry::forUser` tidak kosong), Bantuan (`bt`)] dan [Segarkan, Buka Mini App (`web_app` ke akar Mini App)]. Layar turunan memakai `BotTombol::kaki` (Kembali ke induk, Beranda bila induk bukan beranda, Segarkan). Tombol aksi tulis PR-3 dijelaskan di bagian "Aksi tulis berkonfirmasi"; "Mulai Perjalanan" (layar `mp`) tampil hanya bila peran saat ini berhak memicu perjalanan (`LayarAksi::tampilUntuk`). Tombol alur draf (Catat pengeluaran/pencairan/mutasi stok, Buat Keluhan; layar `dpk`/`dpc`/`dms`/`dkl`) dijelaskan di bagian "Alur draf Agen AI" (R02-C2). Pintasan `web_app` per modul v1 digantikan layar chat (B1); "Buka di Mini App" per layar disediakan layar masing-masing.

## callback_data

Format `b1.<kode>.<mac>` (sekitar 23 byte): `kode` = 8 byte acak base64url (11 karakter), `mac` = 6 byte pertama HMAC-SHA256 atas `kode|telegram_id` (kunci turunan dari `app.key`, base64url 8 karakter). Tidak memuat ID bisnis, versi, SPPG, atau aksi; semuanya ada di baris `bot_aksi` dan dibaca server. Rotasi `app.key` membatalkan semua token (aman).

### Tabel `bot_aksi`

`kode` (unik), `grup` (token satu layar), `user_id`, `telegram_id`, `chat_id`, `sppg_id`, `jenis`, `subjek_tipe`/`subjek_id`/`subjek_versi` (versi yang DILIHAT pengguna saat layar dirender), `status`, `expires_at`, `used_at`, `catatan_expires_at`, `prompt_message_id`, `hasil`. Tanpa NIK, telepon, nama, atau isi catatan. Pembersihan: `sigap:bersihkan-aksi-bot` harian 03:30 (baris lebih dari `telegram.aksi_retensi_hari` = 30 hari). Maks. `telegram.aksi_aktif_maks` (50) token aktif per pengguna; yang tertua dikedaluwarsakan.

| `jenis` | Arti | Dibuat oleh |
|---|---|---|
| `daftar_persetujuan` | buka daftar pengajuan (atau pemilih SPPG bila investor punya >1 SPPG dan `sppg_id` kosong); juga "Muat ulang" / "Kembali ke daftar" | `/menu`, hasil keputusan, 409 |
| `pilih_sppg` | pilih SPPG (sudah disertai `sppg_id`) | pemilih SPPG |
| `tinjau` | tampilkan satu pengajuan | daftar |
| `setuju` | setujui pengajuan | layar tinjau |
| `tolak` | tolak pengajuan; membuka jendela alasan | layar tinjau |
| `kirim_ulang_rekap` | buka konfirmasi kirim ulang rekap (subjek `rekap_harian`) | layar Rekap `rk` (kepala_sppg) |
| `picu_perjalanan` | buka konfirmasi mulai perjalanan (subjek `jadwal_distribusi`) | layar Mulai Perjalanan `mp` (kepala_sppg, relawan distribusi) |
| `tindak_keluhan` | buka konfirmasi keluhan baru -> diproses (subjek `keluhan`) | layar Keluhan `kl` (kepala_sppg) |
| `ya_kirim_ulang_rekap`, `ya_picu_perjalanan`, `ya_tindak_keluhan` | "Ya, lakukan": jalankan service | layar konfirmasi (grup dengan `batal_aksi`) |
| `batal_aksi` | batalkan tanpa perubahan data | layar konfirmasi |

Status: `aktif` -> `dipakai` / `menunggu_catatan` -> `selesai`; `kedaluwarsa` untuk token saudara, jendela alasan yang dibatalkan, dan yang melewati batas aktif.

### Masa berlaku

| Item | Nilai | Config |
|---|---|---|
| Umur tombol | 10 menit sejak layar dirender | `telegram.callback_ttl_menit` |
| Jendela balasan alasan penolakan | 10 menit sejak menekan Tolak | `telegram.catatan_ttl_menit` |
| Rate limit callback | 30 per menit per `telegram_id` (balasan "Terlalu cepat") | `telegram.callback_rate` |
| Batas tombol tidak sah | 10 per menit per `telegram_id` (selanjutnya dijawab diam) | `telegram.callback_gagal_rate` |
| Batas `/start`, `/bantuan`, `/menu` | 20 per menit per `telegram_id` (lewat batas: diabaikan tanpa balasan) | `telegram.perintah_rate` |
| Batas balasan alasan penolakan yang gagal | 20 per menit per `telegram_id` (lewat batas: diam, tetap tidak ke Agen AI) | `telegram.catatan_gagal_rate` |

## Urutan verifikasi tiap callback (wajib)

1. Rate limit per `telegram_id`.
2. MAC valid, baris ada, `status=aktif`, belum kedaluwarsa, `from.id == telegram_id`, `message.chat.id == chat_id` (hanya chat pribadi), `user_id` == pengguna yang ditemukan dari `from.id` sekarang. Semua kegagalan dijawab seragam: "Tombol sudah tidak berlaku. Ketik /menu." (tidak ada oracle).
3. Pengguna dimuat ulang dari basis data (`canAuthenticate`).
4. `punyaSesiPenuh` (tanpa sesi: petunjuk masuk, token tidak dihabiskan).
5. Hak dihitung ulang (`BotKeputusanService::periksa`): `Rbac::canDecide(modul)` (`persetujuan-menu` / `keuangan`), SPPG token termasuk pivot `investor_sppg`, `sppg_id` record == `sppg_id` token, Policy `putuskan` di dalam `SppgAktif::untuk`. Gagal: "Anda tidak berhak melakukan aksi ini." (token tidak dihabiskan).
6. Konsumsi token atomik, lalu panggil service. Token tanpa grup: `UPDATE ... WHERE status='aktif' AND expires_at>now` (harus 1 baris). Token satu layar (grup): transaksi dengan `lockForUpdate` atas semua baris grup, sehingga hanya SATU token per layar yang pernah lolos (Setujui dan Tolak tidak bisa sama-sama lolos); saudara lain langsung `kedaluwarsa`. Tekan ganda/replay/paralel: hanya satu yang lolos.
7. `answerCallbackQuery` + `editMessageText` (fallback `sendMessage`); log aman `bot.aksi` (jenis, hasil, `user_id`, subjek, `sppg_id`, hash chat id) tanpa catatan atau PII.

`SppgAktif::untuk(User, sppgId, Closure)` menetapkan SPPG aktif hanya untuk closure (investor: harus milik pivot; peran lain: `sppg_id` sendiri; admin ditolak), dibersihkan di `finally`, tanpa bocor antar job.

## Navigasi baca `n1` (R-02 v2, PR-2 tahap A1)

Format `n1.<layar>.<sppg36>.<exp36>.<mac>` (maks. sekitar 40 byte, ditegakkan < 64): `layar` = id pendek (`^[a-z][a-z0-9]{0,7}$`), `sppg36` = id SPPG basis 36 (`0` = tanpa SPPG), `exp36` = detik Unix kedaluwarsa basis 36 (TTL `telegram.nav_ttl_menit` = 30), `mac` = 6 byte HMAC-SHA256 atas `n1|layar|sppg36|exp36|telegram_id`, kunci turunan `bot-nav|v1` dari `app.key` (berbeda dari `b1`), dibandingkan `hash_equals`. Tanpa baris basis data; dapat dipakai ulang sampai kedaluwarsa (Segarkan/Kembali/Beranda). Aksi yang mengubah keadaan tetap `b1` sekali pakai.

Urutan tiap klik `n1` (`BotCallbackHandler::navigasiBaca` + `BotNavigasi::buka`):
1. Rate limit (sama dengan `b1`).
2. Format + MAC + ikatan `telegram_id`; `chat.id == from.id` (chat pribadi). Gagal (palsu, milik akun lain, format rusak, layar tak dikenal): "Tombol sudah tidak berlaku. Ketik /menu." dan dihitung ke `callback_gagal_rate`.
3. Pengguna dimuat ulang (`canAuthenticate`; nonaktif/terhapus dijawab seragam seperti langkah 2).
4. `punyaSesiPenuh`; tanpa sesi: hanya `PERLU_MASUK` + tombol "Masuk lewat Mini App", jendela alasan ditutup, layar tidak dirender.
5. Kedaluwarsa (MAC sah): beranda segar diedit di tempat dengan catatan "Menu diperbarui." (bukan galat).
6. Hak dari keadaan SAAT INI: RBAC modul layar (`LayarBaca::hak()`: `R` penuh / `R*` / agregat / sesi saja) dari `config/rbac.php`; SPPG efektif: peran satu-SPPG = `users.sppg_id` (SPPG lain di token ditolak), investor = pivot `investor_sppg` lewat `SppgAktif::untuk`, admin = SPPG ada dan belum dihapus lewat `SppgAktif::untukBacaAdmin`; layar data tanpa SPPG efektif tidak dirender ("Pilih SPPG terlebih dahulu lewat /menu."). Lalu `LayarBaca::izinkan` (Policy) dan render di dalam `ActingUser` + konteks SPPG. Ditolak: alert "Anda tidak berhak membuka layar ini." tanpa data.
7. `editMessageText` (fallback `sendMessage` dengan `protect_content`), lalu `answerCallbackQuery` TEPAT sekali; edit tanpa perubahan dijawab "Sudah yang terbaru." tanpa pesan baru. Log `bot.nav` (layar, hasil, `user_id`, `sppg_id`) tanpa isi layar.

Penimpa baca-saja admin: `SppgAktif::untukBacaAdmin(admin, sppgId, Closure)` membuat `SppgBound::apply` mempersempit query tool admin ke satu SPPG selama closure; tidak mengubah `SppgAktif::id()` (SppgScope, BelongsToSppg, Policy, daftar tool admin tetap sama), menolak non-admin, closure dijalankan di transaksi yang selalu di-rollback (tidak ada tulisan atau token `b1` yang tersimpan), dibersihkan di `finally`. Di luar penimpa perilaku AI/REST tidak berubah.

## Layar baca modul (R02-B1)

Layar dibangun di `app/Services/Telegram/Bot/Layar/**` dan didaftarkan lewat `DefinisiLayar::semua()` (dimuat `BotLayarRegistry::layarSemua()`; `layarBawaan()` hanya beranda, pilih SPPG, Tanya Agen AI, bantuan). Hak, sesi penuh, SPPG, dan Policy tetap ditegakkan `BotNavigasi` pada setiap klik; layar hanya menyajikan.

Sumber data = tool baca Agen AI lewat adapter `BotLayarService` (`CatalogReadTool` dibangun langsung dari `ResourceSpec`, TIDAK bergantung pada `ToolRegistry`, `config('ai.tools.disabled')`, atau konfigurasi LLM). Argumen dibangun server dan divalidasi `ArgumentValidator`. **Whitelist kolom** per seksi: hanya kolom yang disebut yang diteruskan ke penyaji (mis. `uraian`, nota, `catatan_keputusan`, telepon, alamat tidak pernah tampil). Paginasi: 5 baris/halaman, maks. 4 halaman (batas tool 20 baris); id halaman ke-2..9 = id layar + angka 2..9 (`kl2`), id layar terdaftar tidak boleh berakhiran angka. Pilih SPPG: 8 per halaman, maks. 9 halaman.

| Id | Layar | Modul / hak | Sumber |
|---|---|---|---|
| `jd` | Jadwal hari ini (produksi + distribusi sesuai unit) | jadwal R | `cari_jadwal_produksi`, `cari_jadwal_distribusi` |
| `ds` | Distribusi hari ini | bukti-distribusi R | `cari_jadwal_distribusi`, `cari_bukti_distribusi` |
| `ar` | Armada + pengemudi (inisial + ID) | armada R | `cari_armada`, `cari_pengemudi` |
| `kl` | Keluhan terbuka (teks tersamar + terpotong) | keluhan R | `cari_keluhan` (terbuka) |
| `st` `sm` `kd` | Stok, stok menipis, kedaluwarsa (termasuk yang sudah lewat) | bahan-baku R | `cari_bahan_baku` |
| `mt` | Pemeriksaan mutu | mutu R | `cari_pemeriksaan_mutu` |
| `lg` | Log produksi hari ini (relawan: hanya milik sendiri, filter `milik_saya`) | log-produksi R | `cari_log_produksi` |
| `tl` | Titik layanan (PIC inisial) | titik-layanan R | spec `titik_layanan` |
| `np` | Perjalanan aktif | notifikasi-perjalanan R | `cari_notifikasi_perjalanan` (aktif) |
| `mg` `mh` | Menu dan gizi, menu hari ini | menu-gizi R | `cari_menu` |
| `pc` `pk` | Pencairan, pengeluaran (tanpa uraian/nota) | keuangan R | `cari_pencairan_dana`, `cari_pengeluaran` |
| `pe` `pb` | Pemasok (tanpa kontak), pembelian bahan | pemasok R, pembelian-bahan R | `cari_pemasok`, `cari_pembelian_bahan` |
| `pj` | Persetujuan menu (tanpa catatan keputusan) | persetujuan-menu R | `daftar_pengajuan_menu` (kini lewat `SppgBound`) |
| `rk` | Rekap harian (bagian disaring per modul RBAC) | rekap-harian R | `DailySummaryService::hitung` + `cari_rekap_harian` |
| `ku` `ag` | Keuangan, anggaran (ambang 80/100%) | keuangan R | `BudgetService::ringkasan` |
| `pn` | Penerima manfaat AGREGAT, tersamar k=5 untuk semua peran | penerima-manfaat agregat | `AgregatPenerimaService::hitung(samarkan: true)` |
| `tm` | Tim: hitungan pengguna aktif per peran | pengguna R | query hitungan (`sppg_id` eksplisit) |
| `pf` | Profil saya (tanpa kontak) | pengguna R* | pengguna login |

Perilaku tambahan hasil tinjauan E1 PR-2:
- Klik `n1` yang ditolak (hak hilang) atau tanpa sesi mengedit pesan asal menjadi teks netral tanpa data dan tanpa keyboard (`BotCallbackHandler::LAYAR_DITUTUP_HAK` / `LAYAR_DITUTUP_SESI`); tanpa sesi, `PERLU_MASUK` + tombol masuk tetap dikirim sebagai pesan baru. Bila edit gagal tidak ada pesan data baru.
- Semua layar data (`n1` dan layar keputusan `b1`) bertanda `lindungi`, sehingga fallback `sendMessage` memakai `protect_content`.
- Tombol "Menunggu keputusan" di beranda memakai ulang token `daftar_persetujuan` aktif milik (pengguna, chat, SPPG) selama sisa umurnya >= separuh TTL; render/Segarkan berulang tidak menumpuk baris `bot_aksi`. Token tetap sekali pakai.
- Ringkasan beranda admin baca-saja dihitung langsung tanpa cache (cache tidak ditulis dari transaksi yang di-rollback).
- Adapter `BotLayarService` membangun tool dari spec katalog dan sengaja tidak tunduk pada `config('ai.tools.disabled')`.
- Id layar terdaftar maks. 7 karakter dan tidak berakhiran angka.

Layar berat (`rk`, `ku`, `ag`) dijeda `telegram.layar_berat_jeda_detik` (3 dtk) per pengguna. Filter katalog baru: `milik_saya` pada `cari_log_produksi` (scope `LogProduksi::milikSaya`, memakai pengguna login). `DaftarPengajuanMenuTool` dan `CariTitikLayananTool` kini memakai `SppgBound::apply` agar admin baca-saja dipersempit ke SPPG terpilih.

## Perintah pintasan dan tombol Menu Telegram

`/jadwal /stok /keluhan /rekap /keuangan /anggaran /distribusi /mutu` (`BotLayarRegistry::PERINTAH`) membuka layar yang sama dengan tombolnya sebagai pesan baru ber-`protect_content`, dengan pemeriksaan yang sama (langkah 4 dan 6). Peran tanpa hak atau layar belum tersedia: "Perintah ini tidak tersedia untuk peran Anda."; admin/investor banyak SPPG: "Pilih SPPG terlebih dahulu lewat /menu."; tanpa sesi penuh: `PERLU_MASUK`. Termasuk dalam `telegram.perintah_rate`. `/menu`, `/start`, `/bantuan` (dengan sesi) juga dikirim ber-`protect_content`.

`sigap:telegram-set-webhook` kini memanggil `setMyCommands` (daftar global `BotLayarRegistry::DESKRIPSI_PERINTAH`, tanpa scope per pengguna) dan `setChatMenuButton` bertipe `commands` (keputusan pengguna #3); Mini App dibuka lewat `/start` dan tombol Mini App. `--no-menu` melewati keduanya.

## Alur keputusan investor

`/menu` -> "Menunggu keputusan" -> (pilih SPPG bila >1) -> daftar (maks. 5 menu + 5 pengeluaran, ringkasan saja) -> Tinjau -> Setujui / Tolak / Buka di Mini App.

- Layar tinjau memakai data segar server dan hanya memuat yang sudah ada di notifikasi: menu (nama, biaya per porsi, porsi, total), pengeluaran (kategori, nominal, tanggal, serapan anggaran periode). TANPA `uraian`, nota, nama penerima, telepon, NIK. Nilai di-escape HTML.
- Setujui memanggil `PersetujuanMenuService::putuskan` / `PersetujuanPengeluaranService::putuskan` (kunci baris + versi) dengan versi dari baris token, di dalam `ActingUser::run` + `SppgAktif::untuk`. Pivot `investor_sppg` dibaca segar (cache `SppgAktif::milik` dibuang lewat `segarkanMilik`) pada pemeriksaan hak dan tepat sebelum service. Bot hanya `disetujui`/`ditolak`; `perlu_revisi` tetap di Mini App. Audit bersumber `bot` dan mencatat `user_id` pemutus. `catatan_keputusan` TIDAK masuk jejak audit (menu maupun pengeluaran); hanya di kolom keputusan resmi.
- 409 (versi berubah, menu berubah, sudah diputuskan/dibatalkan): pesan jelas + tombol "Muat ulang" (token `daftar_persetujuan` baru). Data tidak berubah. Layar tinjau juga menolak bila versi sekarang berbeda dari versi saat daftar dirender.
- Tolak tanpa alasan tidak mungkin: menekan Tolak mengubah token menjadi `menunggu_catatan`, menghapus tombol layar tinjau, dan mengirim pesan `ForceReply`. Alasan harus berupa BALASAN ke pesan itu (`reply_to_message.message_id == prompt_message_id`), dari pengguna yang sama, dalam jendela waktu, dengan sesi penuh dan hak yang dihitung ulang. Panjang: menu 5-1000, pengeluaran 5-500 karakter (konstanta `CATATAN_MIN/MAX` pada `KeputusanPengajuanRequest` / `KeputusanPengeluaranRequest`, dipakai bersama). Tidak valid: diminta ulang tanpa menghabiskan jendela. Balasan ke pesan prompt TIDAK PERNAH diteruskan ke Agen AI, apa pun hasilnya dan apa pun jenis pesannya: foto/suara/stiker dijawab "Kirim alasan penolakan sebagai teks" tanpa mengunduh berkas dan jendela tetap terbuka; teks bebas lain tetap ke Agen AI. Satu jendela alasan per pengguna; token Tolak baru atau `/batal` membatalkan yang lama. Isi alasan tidak disimpan di `bot_aksi`, log, jejak audit, atau ringkasan payload update; hanya di kolom keputusan resmi. Galat pada jalur ini dicatat aman (`bot.galat`: kelas, kode, lokasi; tanpa pesan exception karena `QueryException` memuat SQL + binding).

## Aksi tulis berkonfirmasi (R-02 PR-3, R02-C1)

Pola 3.7-B: layar baca -> tombol aksi `b1` (sekali pakai) -> layar KONFIRMASI (rincian dari data segar server + "Ya, lakukan" / "Batal", satu grup token sekali pakai) -> SATU pemanggilan service yang sama dengan REST -> layar hasil. Kode: `BotAksiTulisService`, `BotCallbackHandler::aksiTulis`, layar `LayarMulaiPerjalanan` (`mp`). Tidak ada endpoint REST baru dan tidak ada perubahan skema (`bot_aksi.jenis` string 24, `subjek_tipe` 20).

| Aksi | Tombol (layar) | Peran | Hak (`config/rbac.php` + Policy) | Service | Batas tambahan |
|---|---|---|---|---|---|
| Kirim ulang rekap | "Kirim ulang rekap dd/mm" (`rk`) | kepala_sppg | rekap-harian U, `RekapHarianPolicy::update` | `DailySummaryService::kirimUlang` (diekstrak dari `RekapHarianController::kirimUlang`, REST memanggil yang sama) | hanya rekap hari ini dan kemarin (zona operasional); maks. 1x per rekap per `telegram.kirim_ulang_rekap_jeda_menit` (5) lintas pengguna (`Cache::add` atomik); rekap berstatus `menunggu` (sedang/akan dikirim) tidak diberi tombol dan ditolak ramah ("sedang dikirim"). Hanya jalur bot; REST tidak berubah |
| Mulai perjalanan | "Berangkat: <tujuan> (HH:mm)" (`mp`) | kepala_sppg, relawan unit distribusi | notifikasi-perjalanan C (unit), `create` NotifikasiPerjalanan + `view` jadwal | `TripNotificationService::pratinjau` (konfirmasi: tujuan, kendaraan, estimasi tiba, penerima PIC/Kepala SPPG) lalu `mulai` | hanya jadwal distribusi hari ini; aturan `StoreNotifikasiPerjalananRequest::rules()` (jadwal milik SPPG pengguna, belum dihapus) + `pastikanBisaBerangkat` (siap/berangkat, mutu Layak, tanpa perjalanan aktif). Penolakan service = pesan ramah, bukan galat. Layar `mp` menampilkan alasan untuk jadwal yang belum bisa berangkat dan dijeda seperti layar berat (`telegram.layar_berat_jeda_detik`). Jejak audit ditulis setelah baris perjalanan disimpan tetapi sebelum `PerjalananDimulai` dipancarkan (`mulai(..., setelahSimpan:)`, parameter opsional; REST tidak memakainya) |
| Tindak lanjut keluhan | "Proses keluhan #id" (`kl`, hanya keluhan berstatus baru pada halaman tampil) | kepala_sppg, ahli_gizi | keluhan U (dari `config/rbac.php`; dicabut = tombol hilang), `FeedbackKeluhanPolicy::update` | `KeluhanService::perbarui(['status' => 'diproses'])` | hanya baru -> diproses; selesai/ditolak + teks tindak lanjut tetap di Mini App (tombol "Buka di Mini App" `/keluhan/{id}`). Teks keluhan/tindak lanjut TIDAK tampil di konfirmasi/hasil |

Admin dan investor tidak pernah mendapat tombol atau token aksi ini (juga ditolak bila token dibuat langsung). Ahli gizi (keluhan RU) mendapat tombol "Proses keluhan" yang sama dengan kepala SPPG (hanya baru -> diproses; hak U dihitung ulang dari `config/rbac.php` + Policy pada tiap render dan klik); relawan hanya mulai perjalanan dan hanya unit distribusi.

Urutan tiap klik aksi/"Ya"/Batal (setelah langkah 1-4 umum: rate limit, MAC + baris + ikatan `from.id`/`chat.id`/`user_id`, pengguna dimuat ulang + `canAuthenticate`, `punyaSesiPenuh`):
1. Tanpa sesi penuh: `PERLU_MASUK` + satu tombol "Masuk lewat Mini App" untuk SEMUA peran; layar asal dinetralkan (`LAYAR_DITUTUP_SESI`); token TIDAK dihabiskan.
2. `BotAksiTulisService::periksa`: peran saat ini termasuk daftar aksi, bukan admin/investor, huruf RBAC (termasuk unit relawan), `sppg_id` token == `users.sppg_id` saat ini == `sppg_id` subjek, Policy di dalam `ActingUser` + `SppgAktif::untuk`. Gagal: alert "Anda tidak berhak melakukan aksi ini." + layar asal dinetralkan (`LAYAR_DITUTUP_HAK`); token dan saudara satu layar (Ya/Batal) menjadi `kedaluwarsa` (bukan dipakai, tanpa efek), sehingga tidak hidup lagi walau hak dipulihkan.
3. Keadaan yang DILIHAT pengguna disimpan di `subjek_versi` (sidik: rekap = status kirim + waktu kirim/susun + `updated_at`; jadwal = status, tanggal, titik, armada, `updated_at`, PIC terhubung, mutu Layak, jumlah perjalanan aktif + jumlah/id terbesar/`updated_at` terbaru SEMUA perjalanan jadwal itu (mulai-lalu-batal di antara konfirmasi dan "Ya" terdeteksi); keluhan = status, prioritas, `updated_at`). Berbeda -> "Data sudah berubah" (setara 409): token basi ditutup, layar diganti pesan + tombol "Muat ulang" (`n1` ke layar baca asal). Di luar jendela (rekap bukan hari ini/kemarin, jadwal bukan hari ini) atau rekap sedang dikirim (`menunggu`) -> alert "Aksi tidak lagi tersedia." + pesan ramah + "Muat ulang".
4. Konsumsi atomik (grup Ya/Batal: hanya satu yang pernah lolos). Tekan ganda/replay/dua layar konfirmasi tidak menggandakan efek: token sekali pakai, versi dicek ulang di dalam transaksi dengan kunci baris subjek tepat sebelum service, perjalanan ganda ditolak service, kirim ulang rekap dibatasi jeda.
5. Service dijalankan di `AuditLog::withSource('bot')` + `ActingUser` + `SppgAktif::untuk` + transaksi. Jejak audit eksplisit (model ini tanpa trait Auditable): `bot_kirim_ulang_rekap`, `bot_picu_perjalanan`, `bot_tindak_keluhan`, `user_id` pengguna, `sumber=bot`, nilai hanya status/id (tanpa teks keluhan, pesan perjalanan, chat id PIC, nama, kontak). Galat tak terduga sebelum/di dalam transaksi: `BotGalat::catat` (kelas/kode/lokasi saja), transaksi di-rollback (event domain ber-`ShouldDispatchAfterCommit` tidak terkirim), layar "Terjadi kesalahan ... Periksa hasilnya di Mini App sebelum mencoba lagi", token `hasil=gagal`. Galat SETELAH commit (menyusun layar hasil, mengedit pesan): token dicatat dengan hasil sesuai efek (`dikirim_ulang`/`dimulai`/`diproses`) dan pengguna melihat "Aksi sudah diproses. Periksa hasilnya di Mini App sebelum mencoba lagi."

Tombol aksi pada layar baca memakai `BotAksiService::terbitAtauPakai` dengan subjek + versi yang sama, sehingga Segarkan berulang tidak menumpuk baris `bot_aksi`. Semua layar konfirmasi/hasil bertanda `lindungi` (fallback `sendMessage` dengan `protect_content`). Log `bot.aksi` hanya jenis, hasil, `user_id`, subjek, `sppg_id`, hash chat.

## Alur draf Agen AI (R-02 PR-3, R02-C2)

Bot TIDAK menulis data pada alur ini (keputusan pengguna #2): tombol hanya membuka layar petunjuk baca-saja (`LayarAlurDraf`, callback `n1`, tanpa baris `bot_aksi`, tanpa token `b1`). Pengguna lalu mengetik permintaan ke Agen AI seperti biasa; Agen AI membuat `AiDraft` (`DraftService`), bot mengirim tombol web_app "Periksa & Simpan" (`?draft=<uuid>`, `AiChatHandler`, TIDAK diubah), dan konfirmasi/penyimpanan tetap di Mini App (Form Request, unggah nota, Policy, `AiDraftConfirmer`).

| Tombol (layar) | Peran | Dasar RBAC | Tool tulis (syarat `ToolRegistry`) | Tombol "Buka formulir" (`?r=`) |
|---|---|---|---|---|
| Catat pengeluaran (`dpk`) | akuntan | keuangan C | `buat_pengeluaran` | `/keuangan/pengeluaran/baru` |
| Catat pencairan (`dpc`) | akuntan | keuangan C | `buat_pencairan_dana` | `/keuangan/pencairan/baru` |
| Catat mutasi stok (`dms`) | staf_gudang | bahan-baku U | `catat_mutasi_stok` | tidak ada (hanya petunjuk): satu-satunya rute mutasi Mini App adalah `/bahan-baku/:id/mutasi` (butuh id) |
| Buat Keluhan (`dkl`) | relawan dapur, distribusi, tanpa unit | keluhan C | `buat_keluhan` | `/keluhan/baru` |

- Tombol beranda tampil dan layar dapat dibuka hanya bila: pengguna aktif, bukan admin/investor, peran termasuk kolom Peran, `users.sppg_id` ada, huruf RBAC `config/rbac.php` saat ini mengizinkan (bukan self-only), DAN `ToolRegistry::resolveFor` (setara `forUser`) memuat tool tulis terkait. Pemeriksaan ini ada di `LayarAlurDraf::tampilUntuk` (sebagai `LayarAksi`) dan diulang pada setiap klik lewat `LayarAlurDraf::izinkan` di dalam `BotNavigasi` (sesi penuh, RBAC baca, kecocokan SPPG). Hak dicabut di antara render dan klik: pesan diganti teks netral `LAYAR_DITUTUP_HAK`, tanpa data.
- Tanpa sesi login penuh (semua peran): hanya `PERLU_MASUK` + tombol "Masuk lewat Mini App"; layar asal dinetralkan.
- Isi layar: judul (tanpa nama SPPG), kalimat petunjuk, 2 contoh kalimat per alur (konstanta `LayarAlurDraf::ALUR`, fiktif, tanpa data bisnis), catatan singkat (mis. foto nota ditambahkan di Mini App), dan tombol "Buka formulir" (`web_app`, `miniAppUrl(['r' => rute], rute)`, hanya bila URL HTTPS). Semua teks dinamis di-escape HTML; pesan bertanda `lindungi` (`protect_content`). Rute formulir diuji terhadap sumber Mini App (`resources/miniapp`, slug `defineCrudModule` + `${base}/baru`) dan pola aman `deeplink.js`.
- Tidak ada penulisan: klik tidak mengubah tabel mana pun (domain, `ai_drafts`, `audit_logs`, `bot_aksi`), tidak mengantre job/event/notifikasi.

## Hal yang sengaja tidak ada

- Tidak ada tombol keputusan di notifikasi `MenuDiajukan`/`PengeluaranDiajukan` (investor masuk lewat `/menu`).
- Tidak ada verifikasi pembelian bahan (akuntan) atau pembuatan/ubah data lain lewat bot selain tiga aksi berkonfirmasi di atas dan keputusan investor; aksi tulis Agen AI tetap draf yang dikonfirmasi di Mini App (bot hanya memberi petunjuk dan tombol formulir).
- Keluhan selesai/ditolak, teks tindak lanjut, dan pembatalan/ubah status perjalanan tidak tersedia di chat (Mini App).
