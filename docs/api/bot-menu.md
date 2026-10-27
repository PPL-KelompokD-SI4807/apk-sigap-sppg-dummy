# Menu Tombol Bot per Peran dan Keputusan Investor (R-02)

Kode: `app/Services/Telegram/Bot/*`, router di `TelegramUpdateHandler`, tabel `bot_aksi`. Tidak ada endpoint REST baru (semua lewat webhook `POST /api/v1/telegram/webhook`); kontrak ini adalah perilaku bot.

## Perintah

| Perintah | Perilaku |
|---|---|
| `/menu` | Keyboard inline per peran (pintasan Mini App, maks. 6) + "Menunggu keputusan" (hanya yang berhak memutuskan) + "Buka SiGAP". |
| `/start`, `/bantuan` | Pengguna dengan sesi login penuh mendapat keyboard menu yang sama; tanpa sesi: salam netral + tombol "Buka SiGAP". Ringkasan peran/SPPG di sapaan hanya untuk yang sudah punya sesi penuh. |
| `/batal` | Membatalkan jendela balasan alasan penolakan yang terbuka. |

Semua jalur (perintah menu, callback, balasan alasan) mewajibkan `SesiService::punyaSesiPenuh`. Tanpa sesi penuh balasannya hanya `TelegramUpdateHandler::PERLU_MASUK` + tombol web_app ke akar Mini App: tanpa data, tanpa daftar tombol data, token tidak dihabiskan, jendela alasan penolakan ditutup. Pengguna tidak terdaftar/nonaktif: perilaku lama (tanpa menu).

## Pintasan layar (tombol `web_app`, bukan callback)

URL: `<TELEGRAM_MINIAPP_URL>?r=<rute>[&sppg=<id>]#<rute>`. `?r=` adalah fallback untuk klien yang tidak mempertahankan hash; Mini App memvalidasi rute. `sppg` hanya untuk investor dengan tepat satu SPPG (petunjuk; server tetap memvalidasi pivot `investor_sppg`). Investor lebih dari satu SPPG: tanpa `sppg`, Mini App menampilkan pemilih SPPG.

Butir disaring `Rbac::can(modul, huruf)` dan bukan self-only (`BotMenuCatalog`); `config/rbac.php` tetap satu-satunya sumber hak.

| Profil | Pintasan (urutan tampil) |
|---|---|
| admin | Pengguna, Data SPPG, Titik Layanan, Keluhan, Rekap Harian |
| kepala_sppg | Jadwal, Notifikasi Perjalanan (`/notifikasi-perjalanan/baru`), Bukti Distribusi, Keluhan, Rekap Harian, Keuangan |
| ahli_gizi | Menu dan Gizi, Persetujuan Menu, Jadwal, Mutu Pangan, Keluhan, Rekap Harian |
| akuntan | Keuangan, Pengeluaran, Pembelian Bahan, Rekap Harian |
| staf_gudang | Bahan Baku, Stok Menipis, Kedaluwarsa Dekat, Pembelian Bahan, Pemasok |
| relawan dapur | Jadwal, Log Produksi, Mutu Pangan, Keluhan |
| relawan distribusi | Jadwal, Notifikasi Perjalanan, Bukti Distribusi, Keluhan |
| relawan tanpa unit | Mutu Pangan, Keluhan, Armada |
| investor | Persetujuan Menu, Pengeluaran, Keuangan, Rekap Harian, Keluhan, Mutu Pangan |

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

## Alur keputusan investor

`/menu` -> "Menunggu keputusan" -> (pilih SPPG bila >1) -> daftar (maks. 5 menu + 5 pengeluaran, ringkasan saja) -> Tinjau -> Setujui / Tolak / Buka di Mini App.

- Layar tinjau memakai data segar server dan hanya memuat yang sudah ada di notifikasi: menu (nama, biaya per porsi, porsi, total), pengeluaran (kategori, nominal, tanggal, serapan anggaran periode). TANPA `uraian`, nota, nama penerima, telepon, NIK. Nilai di-escape HTML.
- Setujui memanggil `PersetujuanMenuService::putuskan` / `PersetujuanPengeluaranService::putuskan` (kunci baris + versi) dengan versi dari baris token, di dalam `ActingUser::run` + `SppgAktif::untuk`. Pivot `investor_sppg` dibaca segar (cache `SppgAktif::milik` dibuang lewat `segarkanMilik`) pada pemeriksaan hak dan tepat sebelum service. Bot hanya `disetujui`/`ditolak`; `perlu_revisi` tetap di Mini App. Audit bersumber `bot` dan mencatat `user_id` pemutus. `catatan_keputusan` TIDAK masuk jejak audit (menu maupun pengeluaran); hanya di kolom keputusan resmi.
- 409 (versi berubah, menu berubah, sudah diputuskan/dibatalkan): pesan jelas + tombol "Muat ulang" (token `daftar_persetujuan` baru). Data tidak berubah. Layar tinjau juga menolak bila versi sekarang berbeda dari versi saat daftar dirender.
- Tolak tanpa alasan tidak mungkin: menekan Tolak mengubah token menjadi `menunggu_catatan`, menghapus tombol layar tinjau, dan mengirim pesan `ForceReply`. Alasan harus berupa BALASAN ke pesan itu (`reply_to_message.message_id == prompt_message_id`), dari pengguna yang sama, dalam jendela waktu, dengan sesi penuh dan hak yang dihitung ulang. Panjang: menu 5-1000, pengeluaran 5-500 karakter (konstanta `CATATAN_MIN/MAX` pada `KeputusanPengajuanRequest` / `KeputusanPengeluaranRequest`, dipakai bersama). Tidak valid: diminta ulang tanpa menghabiskan jendela. Balasan ke pesan prompt TIDAK PERNAH diteruskan ke Agen AI, apa pun hasilnya dan apa pun jenis pesannya: foto/suara/stiker dijawab "Kirim alasan penolakan sebagai teks" tanpa mengunduh berkas dan jendela tetap terbuka; teks bebas lain tetap ke Agen AI. Satu jendela alasan per pengguna; token Tolak baru atau `/batal` membatalkan yang lama. Isi alasan tidak disimpan di `bot_aksi`, log, jejak audit, atau ringkasan payload update; hanya di kolom keputusan resmi. Galat pada jalur ini dicatat aman (`bot.galat`: kelas, kode, lokasi; tanpa pesan exception karena `QueryException` memuat SQL + binding).

## Hal yang sengaja tidak ada

- Tidak ada tombol keputusan di notifikasi `MenuDiajukan`/`PengeluaranDiajukan` (investor masuk lewat `/menu`).
- Tidak ada verifikasi pembelian bahan (akuntan) atau pembuatan/ubah data lain lewat bot; aksi tulis Agen AI tetap draf yang dikonfirmasi di Mini App.
