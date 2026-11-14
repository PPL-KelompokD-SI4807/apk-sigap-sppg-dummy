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
Menampilkan gambar privat: `createAuthImage(berkas, {alt})` (fetch + `Authorization` -> blob -> objectURL, dilepas saat pindah layar).
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
