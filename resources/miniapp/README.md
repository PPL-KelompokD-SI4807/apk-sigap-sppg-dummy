# Mini App SiGAP SPPG (cangkang)

Vanilla JS (ES2020+), Vite, Chart.js (lazy). Sumber: `resources/miniapp/`, CSS: `resources/css/miniapp.css`, keluaran: `public/miniapp` (base `/miniapp/`).

```
npm install
npm run dev     # http://localhost:5173/?dev_role=kepala_sppg  (proxy /api -> localhost:8000, ubah VITE_API_PROXY)
npm run build
```

Mode dev (`import.meta.env.DEV`) memasang shim `Telegram.WebApp` bila `initData` kosong: `?dev_role=<peran>`, `?dev_theme=dark`. Bila backend belum ada, login memakai pengguna tiruan. Tidak ada di build produksi.

## Menambah modul CRUD baru
1. Pastikan slug ada di `core/permissions.js` (16 slug resmi sudah ada).
2. Buat `modules/<nama>/index.js` (salin `modules/_contoh/`). `modules/registry.js` memuatnya otomatis lewat `import.meta.glob`.
3. Hapus `modules/_contoh` bila modul asli bertabrakan slug (modul asli otomatis menang).

```js
import { defineCrudModule } from '../../components/crud-module.js';
export default defineCrudModule({
  slug: 'bahan-baku',          // kunci permissions + rute #/bahan-baku
  title: 'Bahan Baku', icon: '🥬', description: '...',
  endpoint: '/bahan-baku',     // /api/v1/bahan-baku
  columns: [{ key: 'nama', primary: true }, { key: 'stok', label: 'Stok', type: 'number' }],
  fields:  [{ name: 'nama', label: 'Nama', type: 'text', required: true }],
  // opsional: permissionSlug, mapPayload(values,{mode}), listQuery, itemTitle(rec)
});
```
Rute otomatis: `#/slug` (daftar), `#/slug/baru`, `#/slug/:id` (detail), `#/slug/:id/ubah`. Tombol Tambah/Ubah/Hapus muncul sesuai `permissions.js`; modul masuk menu bila peran punya `R`. Peran `R*` (pengguna) langsung diarahkan ke profil sendiri.

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
- `components/dashboard.js`: `registerWidget({id,title,roles,render(el)})`; `core/charts.js`: `renderChart(canvas, config)` (Chart.js lazy).
