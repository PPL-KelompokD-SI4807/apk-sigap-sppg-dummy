# Mini App SiGAP SPPG (cangkang)

Vanilla JS (ES2020+), Vite, Chart.js (lazy). Sumber: `resources/miniapp/`, CSS: `resources/css/miniapp.css`, keluaran: `public/miniapp` (base `/miniapp/`).

```
npm install
npm run dev     # http://localhost:5173/?dev_role=kepala_sppg  (proxy /api -> localhost:8000, ubah VITE_API_PROXY)
npm run build
```

Mode dev (`import.meta.env.DEV`) memasang shim `Telegram.WebApp` bila `initData` kosong: `?dev_role=<peran>`, `?dev_theme=dark`. Bila backend belum ada, login memakai pengguna tiruan. Tidak ada di build produksi.

## Dashboard (R-07)
`modules/dashboard/` (rute `#/dashboard`, deep link `?r=/dashboard`, tautan di beranda): `logika.js` murni (diuji di `tests/dashboard.test.js`), `view.js` tabel renderer kunci widget -> kartu (kunci tak dikenal memakai fallback skalar), `index.js` rute, pemilih rentang 7/30 dan pemilih SPPG investor. Kartu `null` disembunyikan; grafik lewat `core/charts.js` dan dihancurkan saat render ulang/pindah layar.

## Hak akses: klien hanya membaca peta hak server
Sumber kebenaran hak = server (`docs/api/peta-hak.md`). `core/permissions.js` TIDAK memuat matriks atau aturan unit/kepemilikan; ia hanya
membaca `user` dari `GET /auth/me`: `modules`, `permissions` (huruf C/R/U/D per modul), `unit_scope`, `aksi.<kunci>`, `aksi.dashboard`,
`keputusan`, `agregat`, `baca_saja`, `multi_sppg`, dan `izin` per record pada 7 resource (jadwal produksi/distribusi, log produksi, bukti
distribusi, pembelian bahan, pengeluaran, notifikasi perjalanan).
- **Gagal tertutup**: peta tidak ada/rusak/bentuk tak dikenal => tanpa menu dan tanpa tombol aksi (`petaValid`). Record tanpa `izin` => tanpa Ubah/Hapus.
- Menu: `modules/menu.js` (`menuUntuk`). Aksi di luar CRUD: `bolehAksi(user, 'catat_mutasi_stok')`. Tab Jadwal: `jadwalTabBoleh` (dari `unit_scope`).
- Tombol per record: `defineCrudModule({ izin: true })` memakai `izin.ubah`/`izin.hapus` dari server (`permsDariIzin`); jangan menghitung ulang
  kepemilikan relawan atau hari yang sama di klien. `rowPerms` hanya untuk KEADAAN yang terbaca di record (mis. status final).
- Penjaga rute yang gagal => dialihkan ke beranda dengan pesan singkat (`alihkanKeBeranda`); rute `/ubah` juga memeriksa `izin.ubah` setelah record dimuat.
- Dropdown jadwal produksi untuk form Distribusi dan batch Keluhan: `GET /jadwal/produksi-ringkas` (kolom datar `nama_menu`), hanya bila `aksi.lihat_ringkasan_jadwal_produksi`.
- Tes parity per peran: `tests/peta-hak.test.js` dengan fixture `tests/fixtures/peta.js` (cermin keluaran server; perbarui bila `config/rbac.php` berubah).

## Menambah modul CRUD baru
1. Pastikan slug modul ada di `config/rbac.php` (server) sehingga muncul di `user.modules`/`user.permissions`; klien tidak menyimpan daftar hak.
2. Buat `modules/<nama>/index.js` (salin `modules/_contoh/`). `modules/registry.js` memuatnya otomatis lewat `import.meta.glob`.
3. Hapus `modules/_contoh` bila modul asli bertabrakan slug (modul asli otomatis menang).

```js
import { defineCrudModule } from '../../components/crud-module.js';
export default defineCrudModule({
  slug: 'bahan-baku',          // kunci user.permissions + rute #/bahan-baku
  title: 'Bahan Baku', icon: '🥬', description: '...',
  endpoint: '/bahan-baku',     // /api/v1/bahan-baku
  columns: [{ key: 'nama', primary: true }, { key: 'stok', label: 'Stok', type: 'number' }],
  fields:  [{ name: 'nama', label: 'Nama', type: 'text', required: true }],
  // opsional: permissionSlug, mapPayload(values,{mode}), listQuery, itemTitle(rec), izin, bolehTambah(user), akses(user), menuInfo(user), prefillDraf(payload)
});
```
Rute otomatis: `#/slug` (daftar), `#/slug/baru`, `#/slug/:id` (detail), `#/slug/:id/ubah`. Tombol Tambah/Ubah/Hapus muncul sesuai peta hak server (`user.permissions`, dan `izin` per record bila `izin: true`); modul masuk menu bila peta memuat `R`. Peran `R*` (pengguna) langsung diarahkan ke profil sendiri.

## Skema kolom (`fields`)
`{name,label,type,required,options,min,max,maxlength,step,pattern,placeholder,help,default,readonly}`.
`type`: `text|number|date|time|select|textarea|photo|relasi`. Relasi: `relation:{endpoint,label:'nama',value:'id',as:'nama_relasi_di_detail'}` (memuat `?per_page=100`). Foto dikompres (maks 1600 px, JPEG 0,8) dan dikirim multipart bila ada.
Kolom daftar: `{key,label,primary,badge,type,format(rec)}`.

## Unggah foto aman (`components/photo-upload.js`, `components/lampiran-field.js`)
Dua jalur, sesuai kontrak `docs/api/paths/lampiran.yaml`:
1. **Foto bagian dari payload modul** (bukti distribusi `foto`/`berita_acara`, pengeluaran `nota`, pencairan `bukti`): cukup tambah kolom
   `{ name: 'nota', label: 'Foto nota', type: 'photo', photoLabel: 'nota', required: false }`. Nama kolom = nama field multipart di kontrak.
   Foto dipilih dari kamera (`capture=environment`) atau galeri, dikompres di canvas (maks 1600 px, JPEG 0,8; turun kualitas bila > 5 MB),
   dipratinjau, bisa diganti/dihapus. Hanya foto baru yang dikirim (multipart otomatis oleh `core/api.js`; PUT lewat POST + `_method`).
   Offline: `submitWithQueue` menyimpan File di IndexedDB beserta Idempotency-Key, dikirim ulang oleh `flushQueue`.
   Saat ubah, nilai awal kolom boleh objek `BerkasRingkas` (`{lampiran_id,url}`) dan dipratinjau lewat token.
2. **Lampiran tambahan ke record yang sudah ada** (keluhan, mutu, log produksi, dst.), `POST /lampiran` {modul, lampiranable_id, kategori, berkas}:
   di `detailExtras` panggil
   `createLampiranPanel({ modul: 'keluhan', recordId: rec.id, kategori: 'foto', lampiran: rec.lampiran, canAdd: p.U })`.
   Setiap foto diunggah dengan `Idempotency-Key` yang dibuat sekali per pilihan foto dan dipakai ulang saat Coba lagi; offline -> status
   "Menunggu jaringan" (antrean `core/offline-queue.js`); galat 413/422/429/409 dipetakan oleh `pesanUnggah` (Indonesia).
3. **Foto pada form modul tertunda** (keluhan, mutu, log produksi; `config/unggahan.php` -> `tertunda`): di `fields` modul tambahkan
   `lampiranField({ modul: 'mutu', label: 'Foto pemeriksaan', existing: record?.lampiran })` (`components/lampiran-field.js`). Tiap foto dikompres lalu
   langsung diunggah ke `POST /lampiran` tanpa `lampiranable_id` (progres XHR 0-100%, galat per foto berbahasa Indonesia, Coba lagi memakai
   `Idempotency-Key` yang sama). Simpan diblokir bila ada foto yang belum selesai/gagal; id terunggah dikirim sebagai `lampiran_ids` oleh
   `mapPayload` (`lampiranIdsPayload`). Jenis (JPEG/PNG/WebP), maks 5 MB, dan maks 5 foto dicek di klien (`core/lampiran-uploader.js`) dan
   tetap ditegakkan server. Bukti distribusi memakai jalur 1 (foto bagian dari payload) + panel lampiran jalur 2 untuk lampiran tambahan.
Menampilkan gambar privat: `createAuthImage(berkas, {alt})` (fetch dengan kredensial dari transport, `kredensialBerkas`: Bearer di Mini App, cookie sesi same-origin di web -> blob -> objectURL, dilepas saat pindah layar atau render ulang).
Hanya jalur `/api/v1/lampiran/{id}` yang diterima (`lampiranPath`), sehingga token tidak terkirim ke domain lain. Jangan memakai `<img src>` langsung.

## Draf AI
`#/draf/<uuid>` (atau `?draft=<uuid>`, `startapp=draft_<uuid>`) membaca `GET /ai-drafts/{uuid}` dan membuka form modul dengan prefill; kolom confidence < 0,7 diberi label "Perlu dicek"; simpan mengirim `ai_draft_id`. Form manual juga bisa: `#/slug/baru?draft=<uuid>`.

## Lainnya
- `core/api.js`: `api.get/post/put/del`, `fetchList`, `submitWithQueue` (offline -> IndexedDB + Idempotency-Key, dikirim ulang saat online).
- `core/ui.js`: `toast`, `showConfirm`, `setMainButton`/`hideMainButton` (MainButton Telegram; bilah bawah di dev).
- `components/dashboard.js`: `registerWidget({id,title,bolehFor(user),render(el)})` (`bolehFor` membaca peta hak server; tanpa itu widget tidak tampil); `core/charts.js`: `renderChart(canvas, config)` (Chart.js lazy).

## Lapisan transport (R04-8)
Modul dan komponen mengimpor klien API HANYA dari `core/transport.js`. Build Mini App (bawaan) meneruskannya ke `api.js` (Bearer); `vite build --mode web` menggantinya dengan `core/transport-web.js` (cookie HttpOnly + CSRF, tanpa storage, tanpa antrean offline). Pengecualian terdaftar di `tests/transport-statis.test.js` (auth.js, main.js, web-main.js, auth-web, sesi/layar). Ukuran gzip dijaga `tests/ukuran-bundel.test.js` (garis dasar `tests/fixtures/ukuran-bundel.json`, batas +10%; perbarui dengan `UPDATE_UKURAN=1 node --test resources/miniapp/tests/ukuran-bundel.test.js`). Tes: `node --test resources/miniapp/tests/*.test.js`.

Alias web kedua (PR2): `core/auth.js` diganti `core/auth-web.js` pada build web (pengguna dari `GET /auth/me`, tanpa initData, tanpa token/device id di storage). Alur masuk Telegram di adaptor ini menolak dengan pesan "tidak tersedia di versi web". Pemindaian bundel web: `tests/ukuran-bundel.test.js`.

Beranda web (PR3): `beranda-web.js` (kartu menu dari `menuFor(user)` = peta hak server, tanpa matriks di klien) dan `rute-web.js` (layar sementara "Segera tersedia di web" per modul menurut peta). Dimuat lazy dari `web-main.js` bersama `modules/registry.js`; build web memakai `modulePreload: false`. Ukuran: `web` di fixture = aset awal, `web_total` = semua berkas.

Layar baca web (PR4a): `defineCrudModule` menyediakan `registerWeb()` (daftar + detail saja, tanpa Tambah/Ubah/Hapus, draf AI, foto privat); bagian tambahan lewat `listTopWeb`/`detailExtrasWeb`. Modul didaftarkan di `BACA_WEB` (`rute-web.js`): sppg, titik-layanan, penerima-manfaat, pemasok, armada, pengemudi. Modul lain tetap "Segera tersedia di web". Tes: `tests/baca-web.test.js`.

Layar baca web 4b (operasional): jadwal (+distribusi), log-produksi, mutu, notifikasi-perjalanan, bukti-distribusi, keluhan, keuangan (ringkasan, pencairan, pengeluaran), rekap-harian, dan dasbor (Chart.js lazy) memakai `registerWeb()`; bagian tambahan lewat `listTopWeb`/`detailExtrasWeb` (boleh mengembalikan `{ nodes, destroy }`). Tanpa foto, lampiran, dan aksi tulis; layar custom (kebutuhan bahan, status terakhir, ringkasan live rekap) menyusul.

Formulir web (PR4c): `registerWeb({ tulis: true })` pada modul di `TULIS_WEB` (`rute-web.js`) menambah Tambah/Ubah/Hapus yang mengikuti peta hak/izin server. Idempotency-Key dibuat SEKALI per formulir (dipakai ulang pada pengulangan manual; server hanya menyimpan respons 2xx), tanpa antrean offline, draf AI, dan bidang foto (PR5). 403/404/409 memakai pesan tetap Indonesia; 422 ditampilkan per bidang lewat teks. Tes: `tests/form-web.test.js`.

SPPG aktif web (PR7): `core/sppg-aktif-web.js` (alias Vite untuk `core/sppg-aktif.js` pada build web) menyimpan pilihan HANYA di memori; `core/api-web.js` mengirim `X-SPPG-Id` dari memori pada jalur cookie (bukan `/web/auth/*`). 409 `sppg_aktif_belum_dipilih` dan 403 `sppg_aktif_ditolak` memakai pesan tetap dan membuka pemilih (`/ganti-sppg`, investor multi-SPPG). Bagian murni dipakai bersama lewat `core/sppg-aktif-logika.js`; Mini App tetap memakai sessionStorage.

Formulir web produksi (PR4d): `jadwal` (produksi), `log-produksi`, `mutu` masuk `TULIS_WEB`. `defineCrudModule` menerima `formTopWeb` (info baca-saja di atas formulir) dan `detailExtrasWeb(rec, ctx, perms)` (perms dari peta/izin server); bidang `lampiranField` (`lampiran: true`) dan foto dilewati di web sampai PR5. Ubah status jadwal produksi memakai `PATCH /jadwal/{id}` hanya bila `izin.ubah`. Beranda web menampilkan nama SPPG aktif investor dari `GET /sppg-saya`.

Formulir web distribusi (PR4e): `jadwal/distribusi`, `notifikasi-perjalanan`, `bukti-distribusi` masuk `TULIS_WEB`. Jadwal distribusi: sisa porsi dari GET, ubah status via PATCH hanya bila `izin.ubah`. Notifikasi perjalanan: pratinjau estimasi (POST `/pratinjau`, tanpa efek samping), Berangkat memakai Idempotency-Key sekali per formulir, ubah status via `POST /{id}/status` hanya bila `izin.ubah_status`. Bukti distribusi: foto serah terima WAJIB di server saat tambah (`foto required`), sehingga opsi modul `tambahWeb: false` menyembunyikan Tambah dan rute `/baru` di web sampai PR5; Ubah/Hapus tanpa foto tetap mengikuti izin server. Alur unggah tidak disentuh.

Formulir web keuangan, keluhan, rekap (PR4f): `keuangan/pencairan`, `keuangan/pengeluaran`, `keluhan`, `rekap-harian` masuk `TULIS_WEB`. Hak dari peta server (rbac.php): keuangan CRUD hanya akuntan (admin/kepala/investor baca); keluhan Admin CRUD, kepala/ahli gizi RU (tindak lanjut), relawan CR, investor baca; rekap Kepala SPPG CRU, peran lain baca. Foto bukti transfer/nota/keluhan bersifat opsional di server, jadi Tambah tetap hidup tanpa foto (foto/lampiran: PR5). Pengeluaran: info sisa anggaran (GET ringkasan), `versi` dikirim saat ubah, Ubah/Hapus mengikuti `izin` record; panel keputusan investor TIDAK ada di web (nota belum tampil, menunggu PR5). Keluhan: tautan tindak lanjut hanya bila `aksi.tindak_lanjut_keluhan`. Rekap: catatan (PATCH), kirim ulang, dan susun ulang hanya bila `aksi.kirim_ulang_rekap`/`aksi.susun_rekap`. Idempotency-Key sekali per formulir (server memakainya pada POST pencairan/pengeluaran).

Foto/lampiran web (PR5): formulir web kini memuat kolom `photo` (foto serah terima, berita acara, bukti transfer, nota) dan `lampiranField` (keluhan, mutu, log produksi); Tambah bukti distribusi aktif (hak = peta server). Unggahan lewat `uploadMultipart`/`request` versi web (`core/api-web.js`): cookie sesi same-origin, `X-Sigap-Klien: web`, `X-CSRF-Token` (403 `csrf_ditolak` -> muat ulang CSRF, ulangi sekali dengan kunci sama), `Idempotency-Key`, `X-SPPG-Id` dari memori; progres lewat XHR. Tanpa antrean offline di web (`antreOffline` web selalu false): jaringan putus = baris Gagal + Coba lagi dengan kunci yang sama; tidak ada blob di storage. Gambar privat memakai `kredensialBerkas()` dari transport (Mini App: Bearer + `omit`; web: cookie + `X-Sigap-Klien`), jadi bundel web bebas `Authorization`; object URL dicabut saat pindah layar. Detail web menampilkan foto + panel lampiran (unggah hanya bila izin server), foto nota, dan panel keputusan investor pengeluaran (`POST /keuangan/pengeluaran/{id}/keputusan`, hanya bila `izin.putuskan`). Validasi jenis/ukuran di klien hanya kenyamanan; server tetap sumber kebenaran. Tes: `tests/lampiran-web.test.js`, `tests/form-*-web.test.js`.

Data Pengguna web (PR-W3): `pengguna` masuk `BACA_WEB` dan `TULIS_WEB`; bagian web di `modules/pengguna/web.js` (`listTopWeb`, `detailExtrasWeb`, rute tambahan didaftarkan sebelum `/pengguna/:id`). Admin: daftar, detail, Tambah/Ubah/Hapus (Idempotency-Key sekali per formulir; Hapus akun sendiri disembunyikan), reset password (teks tetap; server mengosongkan password, tidak ada password sementara), sesi pengguna, permintaan reset, kepemilikan SPPG investor, dan undangan. Semua tombol dari `aksi.*`/`permissions` server. Peran R* = Profil Saya: daftar `GET /pengguna` tidak pernah dipanggil (dialihkan ke `/pengguna/{id sendiri}`), isi akun login, peran, dan perangkat/sesi sendiri (`GET /auth/sesi`, kelola di `#/sesi`); record lain = 403 server -> panel Akses ditolak. Undangan web: dialog "Salin tautan" (Clipboard API), tanpa berbagi Telegram dan tanpa draf AI; POST undangan sengaja TANPA Idempotency-Key (respons memuat token mentah, tidak boleh masuk cache idempotensi). Tautan hanya di dialog, dikosongkan saat ditutup (Tutup/Escape/pindah halaman), tidak pernah ke URL, storage, console, toast, atau pesan galat. Tes: `tests/pengguna-web.test.js`.
Persetujuan Menu web (PR-W4a): `persetujuan-menu` masuk `BACA_WEB` dan `TULIS_WEB` (modul kustom dengan `registerWeb({ tulis })`, bukan `defineCrudModule`). Daftar + detail (rincian biaya, gizi, riwayat); Setujui/Tolak/Perlu Revisi hanya bila peta hak server memuat `keputusan: persetujuan-menu` (investor), status `diajukan`, dan menu belum kedaluwarsa; catatan wajib (min. 5 karakter) untuk Tolak/Perlu Revisi. Muatan keputusan hanya `keputusan`, `versi_menu`, `catatan` (tanpa harga/biaya/porsi). Ahli gizi mengajukan lewat layar `#/persetujuan-menu/baru` (`modules/persetujuan-menu/ajukan-web.js`, dimuat lazy, guard `aksi.ajukan_menu`): daftar menu dari `GET /menu-gizi`, tombol Ajukan hanya pada status draf/ditolak/perlu_revisi, `POST /menu-gizi/{id}/ajukan` berisi `versi` + `catatan_pengaju`; 422 menampilkan bahan tanpa harga. Idempotency-Key dibuat sekali per isian (`kunciPerIsian`): dipakai ulang pada pengulangan manual, diganti bila isian berubah. Tanpa antrean offline. Tes: `tests/persetujuan-menu-web.test.js`.

Pembelian Bahan web (PR-W4b): `pembelian-bahan` masuk `BACA_WEB` dan `TULIS_WEB` lewat `listTopWeb`/`formTopWeb`/`detailExtrasWeb` pada `defineCrudModule`. Hak dari peta server (rbac.php): staf gudang CRU (Tambah/Ubah), admin/kepala/akuntan baca, investor dan peran lain tanpa akses (guard menolak, tanpa panggilan API). Foto nota (1-3) diunggah dulu ke `POST /lampiran` (modul `pembelian-bahan`, tertunda, tanpa antrean offline), lalu `lampiran_ids` dikirim saat Simpan; Idempotency-Key sekali per formulir. Verifikasi akuntan (`POST /pembelian-bahan/{id}/verifikasi`) hanya bila `izin.verifikasi` record; kunci idempotensi dipakai ulang selama isian sama (`core/kunci-idempoten.js`). Nota bersifat privat: dimuat lewat cookie sesi (`createAuthImage`, `cache: no-store`, object URL dicabut saat pindah layar) hanya bila `lampiranDisembunyikan` false; pengguna baca-saja atau record bertanda disembunyikan tidak memanggil `GET /lampiran` dan tidak mendapat tautan nota. Formulir Ubah memilih bahan/pemasok dari objek bersarang `bahan_baku.id`/`pemasok.id` (resource tidak memuat `*_id`). Tes: `tests/pembelian-bahan-web.test.js`.
Menu dan Gizi web (PR-W2): `menu-gizi` dan `menu-gizi/standar-gizi` (sub-modul tersembunyi, tab pada daftar menu) masuk `BACA_WEB` dan `TULIS_WEB` lewat `listTopWeb`/`formTopWeb`/`detailExtrasWeb`/`onSavedWeb` pada `defineCrudModule`. Hak dari peta server (rbac.php): ahli gizi CRUD; admin, kepala SPPG, staf gudang, relawan baca; akuntan dan investor tanpa akses (guard menolak, tanpa panggilan API). Formulir menu memuat komposisi bahan (baris bahan + gram per porsi, maks. 50, tanpa ganda; daftar bahan dari `GET /bahan-baku`), pratinjau peringatan gizi (GET standar, tidak memblokir simpan) dan toast `peringatan_gizi` dari respons simpan; standar gizi dimuat ulang tiap formulir dibuka. Tombol "Ajukan ke Investor" (`POST /menu-gizi/{id}/ajukan`, muatan hanya `versi` + `catatan_pengaju`) tampil bila `aksi.ajukan_menu` dari server DAN status draf/ditolak/perlu_revisi; Idempotency-Key sekali per isian (`core/kunci-idempoten.js`: dipakai ulang pada pengulangan manual, diganti bila isian berubah); sesudah berhasil detail menu dimuat ulang. Rincian biaya (`/menu-gizi/{id}/rincian-biaya`, GET) hanya bagi pemegang `aksi.lihat_rincian_biaya`. Tanpa antrean offline dan draf AI. Tes: `tests/menu-gizi-web.test.js`.
Bahan Baku web (PR-W1): `bahan-baku` masuk `BACA_WEB` dan `TULIS_WEB` (`defineCrudModule` + `listTopWeb`/`detailExtrasWeb`; `registerWeb` menambah rute `stok-menipis`, `kedaluwarsa-dekat`, dan `:id/mutasi`). Hak dari peta server (rbac.php): staf gudang dan admin CRUD (Tambah/Ubah/Hapus), kepala/ahli gizi/akuntan/investor baca; relawan tanpa akses. Hapus yang ditolak server (bahan masih dipakai menu, 422) tampil sebagai toast. Catat mutasi stok (`modules/bahan-baku/mutasi-web.js`, dimuat lazy) hanya bila `aksi.catat_mutasi_stok`; panel Riwayat mutasi hanya bila `aksi.lihat_mutasi_stok === true` (selain itu `GET /bahan-baku/{id}/mutasi` TIDAK dipanggil). Tanpa draf Agen AI dan tanpa antrean offline: Idempotency-Key per isian lewat `core/kunci-idempoten.js` (kunci sama saat pengulangan manual, baru bila isian berubah); galat jaringan/5xx pada mutasi menyarankan memeriksa stok dahulu karena hasilnya tak pasti. Filter kategori di daftar kini memuat kedelapan kategori (sebelumnya protein nabati dan lainnya tidak ada). Tes: `tests/bahan-baku-web.test.js`.
