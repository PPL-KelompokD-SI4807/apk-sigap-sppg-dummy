# Dokumentasi API SiGAP SPPG (OpenAPI 3.1)

`openapi.yaml` **dihasilkan** dari fragmen; jangan diedit langsung.

```
docs/api/
  base.yaml               info, servers, security global, tags
  paths/<modul>.yaml      path modul (relatif server /api/v1)
  components/common.yaml  Error, ValidationError, Paginated, parameter page/per_page/q/from/to, respons 401/403/404/422/429/500
  components/<modul>.yaml skema modul
  build-openapi.php       penggabung (tanpa dependensi)
  openapi.yaml            HASIL — di-commit
```

## Menambah modul (mis. bahan-baku)

1. Buat `docs/api/paths/bahan-baku.yaml`. Key tingkat atas = path, indentasi 2 spasi, tanpa tab:

   ```yaml
   /bahan-baku:
     get:
       tags: [Bahan Baku]
       summary: Daftar bahan baku
       parameters:
         - $ref: '#/components/parameters/Page'
         - $ref: '#/components/parameters/PerPage'
         - $ref: '#/components/parameters/Search'
       responses:
         '200':
           description: OK
           content:
             application/json:
               schema:
                 allOf:
                   - $ref: '#/components/schemas/Paginated'
                   - properties:
                       data:
                         type: array
                         items: { $ref: '#/components/schemas/BahanBaku' }
         '401': { $ref: '#/components/responses/Unauthorized' }
         '403': { $ref: '#/components/responses/Forbidden' }
   /bahan-baku/{id}:
     ...
   ```

2. Buat `docs/api/components/bahan-baku.yaml`. Key tingkat atas = seksi (`schemas`, `parameters`, `responses`, `requestBodies`, ...):

   ```yaml
   schemas:
     BahanBaku:
       type: object
       properties:
         id: { type: integer }
   ```

3. Jalankan `php docs/api/build-openapi.php`, lalu commit fragmen + `openapi.yaml`.
   Path/komponen ganda antar-fragmen ditolak (ganti nama, mis. `BahanBakuInput`). Tag baru boleh ditambahkan di `base.yaml`.

`tests/Feature/OpenApiTest.php` gagal bila `openapi.yaml` tidak sinkron (`--check`) atau ada rute `api/v1/*` yang belum punya path di OpenAPI.

## Konvensi respons
- Sukses tunggal: `{"data": {...}}` (API Resource). Daftar: `{"data": [...], "links": {...}, "meta": {...}}`, `per_page` maks 100.
- Galat: `{"message": "...", "errors"?: {"field": ["..."]}}` — 401, 403 (tanpa hak Tabel 5 / akun nonaktif), 404 (termasuk data SPPG lain), 422, 429, 500 (tanpa detail internal).
