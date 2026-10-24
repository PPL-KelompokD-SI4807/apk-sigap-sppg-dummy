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

## Draf AI
`#/draf/<uuid>` (atau `?draft=<uuid>`, `startapp=draft_<uuid>`) membaca `GET /ai-drafts/{uuid}` dan membuka form modul dengan prefill; kolom confidence < 0,7 diberi label "Perlu dicek"; simpan mengirim `ai_draft_id`. Form manual juga bisa: `#/slug/baru?draft=<uuid>`.

## Lainnya
- `core/api.js`: `api.get/post/put/del`, `fetchList`, `submitWithQueue` (offline -> IndexedDB + Idempotency-Key, dikirim ulang saat online).
- `core/ui.js`: `toast`, `showConfirm`, `setMainButton`/`hideMainButton` (MainButton Telegram; bilah bawah di dev).
- `components/dashboard.js`: `registerWidget({id,title,roles,render(el)})`; `core/charts.js`: `renderChart(canvas, config)` (Chart.js lazy).
