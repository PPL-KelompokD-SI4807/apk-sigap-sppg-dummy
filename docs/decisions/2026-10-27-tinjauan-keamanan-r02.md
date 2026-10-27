# Tinjauan keamanan R-02 (menu tombol Bot per peran + keputusan investor lewat Bot)

Tanggal: 2026-10-27. Cabang: `feat/r02-menu-bot`. Peninjau: opus (read-only). Tindak lanjut: backend-dev.

## Ringkasan

Hasil: **0 Kritis, 0 Tinggi, 1 Sedang, 6 Rendah, 5 Info**. Tidak ada temuan yang memblokir. Temuan Sedang (1) dan Rendah
(2-7) sudah diperbaiki beserta tesnya (`tests/Feature/Telegram/Bot/BotTinjauanR02Test.php`, ditambah penguatan
`BotKeputusanTest`); temuan Info yang masuk lingkup diperbaiki murah (11) atau sengaja tidak diubah (9, 10, 12); 8 (opsional)
tidak dikerjakan.

Area yang diperiksa dan dinyatakan bersih:

- callback_data: `b1.<kode>.<mac>` tanpa ID bisnis/versi/aksi; MAC HMAC-SHA256 terikat `telegram_id` dengan `hash_equals`; semua kegagalan dijawab seragam (tanpa oracle).
- Ikatan token: `from.id`, `chat.id` (hanya chat pribadi), dan `user_id` dicocokkan dengan pengguna yang dimuat ulang dari basis data; kedaluwarsa 10 menit; sekali pakai lewat UPDATE bersyarat.
- Urutan verifikasi (rate limit, MAC+baris, muat ulang pengguna, `punyaSesiPenuh`, hak, konsumsi, service) dan tidak ada hak yang dipercaya dari isi callback_data.
- Tanpa sesi penuh: semua jalur (menu, callback, balasan alasan) hanya memberi petunjuk masuk; token tidak dihabiskan; jendela alasan ditutup.
- `SppgAktif::untuk`: hanya SPPG milik (pivot) atau `sppg_id` sendiri, admin ditolak, dibersihkan di `finally`, tidak bocor antar job.
- Alasan penolakan tidak masuk Agen AI, `bot_aksi`, log, atau ringkasan payload update; balasan non-reply tetap ke Agen AI.
- Layar tinjau hanya memuat yang sudah ada di notifikasi (tanpa `uraian`, nota, nama penerima, telepon, NIK); semua nilai di-escape HTML.
- Menu per peran disaring dari `config/rbac.php` (`Rbac::can` + bukan self-only), maks. 6 butir; tombol callback hanya untuk yang berhak memutuskan.
- Aksi tulis dari Bot hanya keputusan eksplisit lewat service yang sama dengan API (kunci baris + versi + 409); `perlu_revisi` tetap di Mini App.
- Migrasi aditif (tabel baru, tanpa mengubah/menghapus data).

## Tabel temuan

| No | Tingkat | Lokasi | Skenario singkat | Status |
|---|---|---|---|---|
| 1 | Sedang | `BotKeputusanService::putuskan`, `Auditable::writeAudit` | Keputusan lewat Bot dijalankan tanpa pengguna login, sehingga baris audit `user_id` NULL (pemutus tidak terlacak di `audit_logs`). | Diperbaiki: panggilan service dibungkus `ActingUser::run` di dalam `SppgAktif::untuk`; tes memastikan semua audit keputusan bot (menu dan pengeluaran) ber-`user_id` investor. Seluruh tes keputusan lama lulus. |
| 2 | Rendah | `TelegramUpdateHandler::handle` | Foto/suara/stiker sebagai balasan ke pesan ForceReply tidak lewat `balasanCatatan` (foto/suara jatuh ke Agen AI). | Diperbaiki: semua balasan ke pesan lain (`reply_to_message`) lewat `balasanCatatan`; non-teks ditolak "Kirim alasan penolakan sebagai teks", jendela tetap terbuka, berkas tidak diunduh, tidak ke Agen AI. Tes foto, suara, stiker. |
| 3 | Rendah | `BotAksiService::konsumsi` | Setujui dan Tolak dari layar yang sama bisa sama-sama lolos pada pembalapan (UPDATE per baris, saudara dikedaluwarsakan sesudahnya). | Diperbaiki: konsumsi token ber-grup dalam transaksi dengan `lockForUpdate` atas seluruh baris grup (urut id); ditolak bila saudara sudah terpakai/menunggu catatan. Tes dengan instance basi dan keadaan perlombaan. Perilaku MySQL belum diuji. |
| 4 | Rendah | `SppgAktif::milik` (cache), `BotKeputusanService::periksa/putuskan` | Pivot `investor_sppg` yang dicabut antara pemeriksaan hak dan pemanggilan service masih terbaca dari cache. | Diperbaiki: `SppgAktif::segarkanMilik()` dipanggil di `periksa`, di awal `untuk`, dan di dalam jalur keputusan sebelum service. Tes: pivot dicabut antara `periksa` dan `putuskan` menghasilkan `ditolak_hak`, data tidak berubah. |
| 5 | Rendah | `PengajuanMenu` (audit) | `catatan_keputusan` menu (teks bebas) masuk `audit_logs`, tidak konsisten dengan `PersetujuanPengeluaran`. | Diperbaiki: `catatan_keputusan` ditambahkan ke `$auditExclude` `PengajuanMenu`. Tes privasi varian menu; tes R-06b-1 lama tidak bergantung pada perilaku lama (seluruh suite lulus). `docs/api/bot-menu.md` disesuaikan. |
| 6 | Rendah | `BotCallbackHandler` (callback, catatan), `BotKeputusanService::putuskan` | `report($e)` mentah: `QueryException` memuat SQL + binding sehingga alasan penolakan dapat masuk log. | Diperbaiki: `BotGalat::catat` hanya mencatat konteks, kelas, kode, berkas, dan baris (`bot.galat`). Tes `QueryException` tidak membocorkan catatan. |
| 7 | Rendah | `TelegramUpdateHandler`, `BotCallbackHandler::balasanCatatan` | `/menu`, `/start`, `/bantuan`, dan balasan alasan yang gagal tanpa batas laju (banjir token `bot_aksi`/balasan). | Diperbaiki: `RateLimiter` per `telegram_id` (`telegram.perintah_rate` 20/menit, `telegram.catatan_gagal_rate` 20/menit); lewat batas dijawab diam. Tes. |
| 8 | Info | `BotCallbackHandler::handle` (catch umum) | Kegagalan tak terduga hanya memberi pesan umum tanpa tombol "Muat ulang". | Tidak dikerjakan (opsional). Pesan umum mengarahkan ke `/menu`. |
| 9 | Info | `BotCallbackHandler.php:136-148` | Satu-satunya autentikasi jalur balasan catatan adalah secret webhook (tanpa MAC); `prompt_message_id` berurutan dan mudah ditebak. Bila secret webhook bocor, penolakan atas nama investor dapat dipalsukan, sama seperti jalur pesan Agen AI yang sudah ada. | Tidak diubah; secret webhook adalah batas kepercayaan. Dicatat di sini. |
| 10 | Info | `SesiService::punyaSesiPenuh` (wilayah R-03) | Belum memeriksa `perangkat_tepercaya.dicabut_at`. Aman sekarang karena pencabutan perangkat (logout/cabut sesi) selalu menghapus token sesinya. | Tidak diubah (wilayah R-03); dicatat. Bila kelak pencabutan perangkat dipisah dari penghapusan token, `punyaSesiPenuh` harus ikut memeriksa kolom ini. |
| 11 | Info | Migrasi `bot_aksi`, `BotAksiService::terbit` | Pembersihan memfilter `created_at` tanpa indeks; deteksi tabrakan kode lewat teks pesan galat. | Diperbaiki: indeks `created_at` ditambahkan pada migrasi; tabrakan kode dikenali dari SQLSTATE 23000. Tes keberadaan indeks. |
| 12 | Info | `BotKeputusanService.php:354-363` | Validasi `min:5` setelah `trim` menerima 5 karakter tak terlihat (zero-width), sama dengan Form Request. | Tidak diubah; dicatat. Opsional kelak: tolak karakter format Unicode (kategori Cf) di kedua tempat. |

## Belum teruji

- Konkurensi MySQL sungguhan: atomisitas konsumsi token (UPDATE bersyarat dan `lockForUpdate` grup) hanya diuji di SQLite, yang mengabaikan penguncian baris. Perlu uji dua koneksi paralel di MySQL 8.4 (staging).
- Klien Telegram sungguhan: perilaku hash `#/rute` pada tombol `web_app` (fallback `?r=`), `ForceReply` (placeholder, balasan terarah), dan `editMessageText` pada pesan lebih dari 48 jam (kode memakai fallback `sendMessage` bila Telegram menolak).
- Worker antrean sungguhan: tes memakai antrean sinkron; kebersihan `SppgAktif::untuk`/`ActingUser` antar job pada worker yang berjalan lama belum diamati langsung.
