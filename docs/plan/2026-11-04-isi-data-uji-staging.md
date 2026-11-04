# Rencana: perintah artisan `sigap:isi-data-uji` (data uji staging)

Tanggal: 2026-11-04. Cabang: `feat/data-uji-staging` (dari `develop`). Tier keseluruhan: T2.

## Tujuan
Mengisi staging/produksi-uji dengan satu SPPG fiktif "(Uji)" yang koheren agar semua widget dashboard dan logika bisnis dapat diuji,
tanpa Faker/factory (DemoSeeder tidak berjalan di image `--no-dev`, classmap-authoritative).

## Tugas
| # | Tugas | Agen | Tier | Ketergantungan |
|---|---|---|---|---|
| 1 | Buat cabang `feat/data-uji-staging` dari `develop` (tanpa membawa `README.md` / `.tmp-nginx/`) | github-ops | T0 | - |
| 2 | Perintah `sigap:isi-data-uji` + penanda data uji + tes + `docs/ops/uji-staging.md` | backend-dev | T2 | 1 |
| 3 | Commit bertahap, push, buka PR ke `develop` (tanpa merge) | github-ops | T0 | 2 lulus tes dan pint |

## Aturan inti (kontrak)
- Kode SPPG `SPPG-UJI-01`; bila sudah ada, lewati (idempoten).
- Wajib `--confirm`; tanpa itu dry-run (hanya ringkasan jumlah baris, tanpa tulis DB).
- `--hapus --confirm` menghapus hanya baris bertanda uji, urutan FK benar, dalam transaksi.
- Tanpa pengguna peran lain berID Telegram palsu; admin yang ada tidak disentuh.
- Tanpa NIK; nama berakhiran "(Uji)"; telepon pola `0812-0000-xxxx`.

## Risiko
- Penanda uji pada banyak tabel: pilih satu mekanisme konsisten (rantai FK dari sppg uji), jangan mengubah skema bila tak perlu.
- Penghapusan salah sasaran: tes wajib membuktikan data lain utuh.
- Tabel `uji-staging.md` sudah ada (daftar uji tahap 7): tambahkan bagian baru, jangan menimpa.
