# Rencana PR-F: kesalahan input di bot langsung ke Mini App

- Tanggal: 2026-11-08
- Branch: `feat/ai-salah-input-ke-miniapp` (dari `develop`, sudah memuat PR #57)
- Fitur terkait: lintas fitur (Agen AI untuk semua tool tulis); uji staging pada Fitur 8 Menu dan Gizi (Ahli Gizi)
- ADR: `docs/decisions/2026-11-08-salah-input-ke-miniapp.md`

## Tujuan
Bila input pengguna di bot salah, bot tidak bertanya balik lewat LLM. Server membuat AiDraft dari bagian yang valid,
menandainya "perlu diperbaiki" beserta kesalahan per kolom, lalu membalas satu pesan server dengan tombol web_app
"Buka di Mini App" ke formulir ter-prefill. Penyimpanan tetap lewat konfirmasi pengguna.

## Penyebab (temuan kode)
- `WriteDraftTool::execute` mengembalikan `ToolResult::invalid('Draf belum dibuat. Tanyakan ke pengguna: ...')` ke LLM,
  sehingga LLM bertanya balik, memakan token Groq, dan konteks hilang.
- `AiAgentService::runTool` mengembalikan galat skema argumen ("Argumen tidak valid") ke LLM (mis. `lemak: "5 ml"`).
- `MenuWriteTool`: satuan tak cocok / bahan tidak ditemukan -> draf tidak dibuat dan model diminta bertanya ulang.
- Kemungkinan bug "bahan ay" -> "Ayam (kg)": LLM menormalkan "ay" menjadi "ayam" sehingga tingkat "sama persis" lolos
  satu baris. Perlu dibuktikan lewat log `ai_interactions` di tes/kode, dan dijaga di server.

## Tugas
| No | Tugas | Agen | Tier | Model/effort | Bergantung |
|---|---|---|---|---|---|
| 1 | Buat branch dari develop terbaru | github-ops | T0 | haiku/low | - |
| 2 | Jalur kesalahan server: kategori galat, draf "perlu diperbaiki", hentikan loop LLM, satu pesan + tombol web_app sesuai hak peran; bug "ay"; hapus markdown mentah; OpenAPI + `docs/api/ai-tools.md`; feature test | backend-dev | T3 | opus/high | 1 |
| 3 | Formulir dari draf: kesalahan per kolom, `perlu_diperbaiki`, `pilihan_referensi` di komposisi menu; tanpa simpan otomatis; node test | frontend-dev | T2 | sonnet/high | 1 (paralel dengan 2, kontrak di bawah) |
| 4 | ADR | project-manager | - | - | 2 |
| 5 | Commit bertahap, PR ke develop dengan checklist DoD, pantau CI | github-ops | T0 | haiku/low | 2, 3, 4 |

## Kontrak API (disepakati sebelum paralel)
`GET /api/v1/ai-drafts/{uuid}` -> `data`:
- `status`: tetap `pending` (draf terbuka, dapat disimpan dari Mini App). Tidak ada nilai status baru di DB.
- `perlu_diperbaiki`: boolean baru (true bila `errors` tidak kosong).
- `errors`: `{ "<kolom>": ["pesan"] }`, kunci bertitik untuk baris (mis. `bahan.0.bahan_baku_id`, `bahan.1.gram_per_porsi`).
- `payload`: hanya kolom yang valid/aman; kolom tidak valid dikosongkan.
- `pilihan_referensi`: format dari PR #57 (tidak berubah).

## Aturan perilaku
- Kategori kesalahan: satuan tidak cocok/tak dikenal, referensi tidak ditemukan, tanggal/angka tidak valid/di luar batas,
  kolom wajib kosong, galat Form Request, galat skema argumen tool tulis.
- Lebih dari satu kandidat (bukan sama persis) -> tombol `ai_pilih` tetap (bukan kesalahan).
- Tidak ditemukan -> jalur kesalahan; tidak menawarkan buat bahan otomatis.
- Jalur kesalahan tidak memanggil LLM lagi pada pesan itu; balasan disusun server, teks polos atau HTML ter-escape.
- Tombol hanya bila peran punya hak tulis modul (ToolRegistry/Rbac); admin tanpa SPPG baca-saja; URL non-HTTPS -> tanpa tombol.
- Pesan galat tanpa data sensitif; draf hanya milik pengguna dan SPPG-nya.
- Jawaban LLM: penanda markdown mentah (`**`, `__`, backtick) dibersihkan sebelum dikirim (teks polos).

## Risiko
- Menghentikan loop saat galat dapat memotong permintaan multi-aksi dalam satu pesan; diterima (satu pesan, satu perbaikan).
- Galat skema yang benar-benar salah format dari LLM (bukan salah pengguna) juga diarahkan ke Mini App; diterima.
- Perilaku Groq nyata belum teruji otomatis; wajib uji manual di bot staging.
- Modul Mini App yang belum memakai crud-module (formulir kustom) mungkin belum menampilkan galat per kolom.

## Selesai bila
`php -d memory_limit=-1 artisan test` lulus (kecuali UnggahanAmanTest::test_berkas_php_bertopeng_jpg_ditolak),
`node --test`, `vendor/bin/pint --test`, `npm run build` lulus; PR terbuka ke develop; tidak di-merge.
