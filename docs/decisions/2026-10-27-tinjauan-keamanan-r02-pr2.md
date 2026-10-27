# Tinjauan keamanan R-02 PR-2 (menu bertingkat, navigasi `n1`, layar baca di chat)

Tanggal: 2026-10-27. Cabang: `feat/r02-pr2-layar-baca`. Peninjau: opus (read-only, tahap E1). Tindak lanjut: backend-dev.

## Ringkasan

Hasil: **0 Kritis, 0 Tinggi, 0 Sedang, 5 Rendah, 7 Info**. Tidak ada temuan yang memblokir. Rendah 1-5 dan Info 12 diperbaiki beserta tesnya
(`tests/Feature/Telegram/Bot/BotTinjauanE1Test.php`, penguatan `BotLayarBacaTest`, `BotLayarBacaMatriksTest`, `BotLayarBacaSesiTest`,
`BotNavSesiTidakPenuhTest`, `tests/Feature/Ai/SppgBoundBacaAdminTest.php`). Info 6, 7, 8, 10, 11 sengaja tidak diubah (dicatat). Info 9 adalah pertanyaan terbuka untuk pemilik produk.

Area yang diperiksa dan dinyatakan bersih:

- Token `n1`: HMAC-SHA256 dengan kunci turunan `bot-nav|v1` (berbeda dari `b1`), terikat `telegram_id`, `exp`, layar, dan SPPG; `hash_equals`; `chat.id == from.id`; semua kegagalan dijawab seragam dan dihitung ke `callback_gagal_rate`; panjang < 64 byte ditegakkan saat membentuk.
- Hak tidak pernah diambil dari isi token: tiap klik memuat ulang pengguna, `punyaSesiPenuh`, RBAC peran saat ini, keanggotaan SPPG (pivot/penempatan), Policy; tes peran diubah, nonaktif, pivot dicabut, sesi dicabut.
- Tanpa sesi penuh: semua layar, perintah pintasan, dan Segarkan hanya memberi petunjuk masuk; adapter tool tidak dipanggil (tes dengan mock).
- Adapter `BotLayarService`: whitelist kolom per seksi, argumen dibangun server dan divalidasi `ArgumentValidator`, kolom whitelist harus ada di spec; `uraian`, nota, nama lengkap pengemudi, telepon, `catatan_keputusan` tidak pernah tampil (tes pemindaian).
- Penyamaran: agregat penerima manfaat k=5 untuk semua peran; keluhan tersamar dan terpotong; Tim hanya hitungan; `@` dibuang dan semua nilai di-escape HTML.
- Penimpa baca-saja admin: hanya admin aktif, SPPG harus ada, dibersihkan di `finally`, bersarang benar, tidak memengaruhi peran lain.
- Aksi tulis tidak ada di PR ini; token `b1` keputusan investor tidak berubah semantiknya (sekali pakai, grup, versi).

## Tabel temuan

| No | Tingkat | Lokasi | Skenario singkat | Status |
|---|---|---|---|---|
| 1 | Rendah | `Layar/LayarBeranda.php` | Tiap render/Segarkan beranda investor menerbitkan baris `bot_aksi` baru ("Menunggu keputusan"): bom token. | Diperbaiki: `BotAksiService::terbitAtauPakai` memakai ulang token `daftar_persetujuan` aktif (user, chat, SPPG, sisa umur >= separuh TTL) bila ada. Tetap sekali pakai; setelah dipakai render berikutnya menerbitkan yang baru. Tes: 30 kali Segarkan tidak menambah baris; sekali pakai tetap; tes keputusan v1 lulus. |
| 2 | Rendah | `BotCallbackHandler::navigasiBaca` | Klik ditolak (hak hilang) atau tanpa sesi meninggalkan layar data lama di chat. | Diperbaiki: pesan asal diedit menjadi teks netral tanpa data dan tanpa keyboard (`LAYAR_DITUTUP_HAK`, `LAYAR_DITUTUP_SESI`); bila edit gagal tidak ada pesan data baru (hanya log kode). Tes. |
| 3 | Rendah | `BotCallbackHandler::kirim`, `BotKeputusanService` | Layar data `b1` (pemilih SPPG, daftar, tinjau, hasil, muat ulang) dibuat `lindungi=false`: fallback `sendMessage` tanpa `protect_content`. | Diperbaiki: kelima layar data `b1` bertanda terlindungi. Tes fallback edit gagal untuk alur pemilih, daftar, tinjau, hasil. |
| 4 | Rendah | `SppgAktif::untukBacaAdmin` (docblock), `BotRingkasanService::untuk` | Klaim "tulisan apa pun di-rollback" terlalu luas (hanya koneksi DB bawaan); cache ringkasan ditulis dari dalam transaksi baca-saja admin. | Diperbaiki: docblock dipersempit (efek samping non-DB tidak dibatalkan); ringkasan admin baca-saja dihitung langsung tanpa cache. Tes `Queue/Bus/Notification/Mail/Event::fake` pada seluruh layar admin: tidak ada efek samping, cache tidak ditulis; non-admin tetap ber-cache. Jeda beranda admin tidak ditambahkan (antarmuka jeda tidak mengenal pengguna; beranda tidak berat). |
| 5 | Rendah | `BotLayarBacaTest` | Asersi `protect_content` kosong (`?? 'true'`). | Diperbaiki: asersi vakum dibuang; tes baru mewajibkan `protect_content=true` pada pesan akar (`/menu`, `/start`, `/bantuan`, semua perintah pintasan) dan fallback edit semua layar `n1`. |
| 6 | Info | `SppgBound::apply` | Pembatasan SPPG admin baca-saja satu lapis (SppgScope tidak memfilter admin). | Tidak diubah; dijaga tes per layar (admin tiap layar tidak melihat SPPG lain) dan tes tool di luar katalog. |
| 7 | Info | `LogProduksi::scopeMilikSaya` | Bergantung pada `ActingUser` (tanpa pengguna login hasil kosong). | Tidak diubah (fail closed); dicatat. |
| 8 | Info | `BotLayarService` | Adapter membangun `CatalogReadTool` langsung, melewati `config('ai.tools.disabled')`. | Tidak diubah; disengaja agar layar tidak bergantung LLM/konfigurasi tool. Didokumentasikan di `docs/api/bot-menu.md`. |
| 9 | Info | RBAC (pertanyaan terbuka) | Investor dapat membuka `/stok /keluhan /mutu /keuangan` karena RBAC memberi R; daftar keluhan menampilkan nama titik layanan. Sama dengan katalog AI. | Dibiarkan; keputusan pemilik produk: perlukah investor dibatasi lebih ketat di chat daripada Mini App? |
| 10 | Info | Domain tanpa skema | Beberapa domain tidak punya skema sendiri sehingga hanya berupa tautan. | Tidak diubah; dicatat. |
| 11 | Info | `BotNavCodec` | Token terikat `telegram_id`, bukan `user_id`; akun yang dipindah ke Telegram lain memakai token baru. | Tidak diubah; hak tetap dihitung ulang tiap klik. |
| 12 | Info | `BotLayarRegistry::daftarkan` | Id layar 8 karakter lolos validasi tetapi id halamannya (9 karakter) memicu `LogicException` laten saat membentuk token. | Diperbaiki: id layar maks. 7 karakter, tidak berakhiran angka. Tes batas dan token halaman terpanjang < 64 byte. |

Tes tambahan kualitas (permintaan peninjau): `SppgBoundBacaAdminTest` kini memastikan daftar tool admin tidak kosong dan identik di dalam/luar penimpa, serta mencakup `CariTitikLayananTool` dan `DaftarPengajuanMenuTool` (di-skip `bacaProvider`) untuk admin dan regresi investor banyak SPPG (fail closed tanpa SPPG aktif; hanya SPPG aktif di dalam `SppgAktif::untuk`). Kedua tool itu kini memakai `SppgBound::apply`.

## Belum teruji

- MySQL: `untukBacaAdmin` (transaksi bersarang/savepoint dan rollback), kunci baris grup `b1`, perilaku `UNIQUE` pada `bot_aksi`; semua tes memakai SQLite in-memory.
- Efek samping non-DB di luar yang di-fake (HTTP ke Telegram dari dalam closure admin, cache store non-array).
- Klien Telegram sungguhan: `protect_content`, penghapusan keyboard saat edit netral, perilaku `message is not modified`.
- TOCTOU antara pemeriksaan hak dan render di dalam satu klik (jendela sangat sempit; hasil hanya data yang baru saja berhak dilihat).
- Worker panjang (queue worker yang hidup lama): kebersihan penimpa `SppgAktif` dan cache `milik` antar job diuji hanya dalam satu proses uji.
