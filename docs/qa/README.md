# docs/qa — Pengujian dan Temuan QA

## Tujuan
Wilayah kerja `qa-engineer`: rencana uji, hasil uji per peran (RBAC), dan laporan temuan agar aplikasi dipakai pengguna nyata tanpa kendala. Folder ini tidak berisi kode aplikasi.

## Format temuan
Setiap temuan memuat:

| Bidang | Isi |
|---|---|
| Peran | Kepala SPPG / Ahli Gizi / Akuntan / Staf Gudang / Relawan / Investor / Admin |
| Platform | Mini App atau web |
| Langkah reproduksi | Langkah bernomor, dengan data uji fiktif |
| Hasil diharapkan | Perilaku yang seharusnya |
| Hasil nyata | Perilaku yang terjadi (sertakan pesan galat/bukti) |
| Keparahan | Kritis / Tinggi / Sedang / Rendah (label `severity:*`) |

Opsional: dugaan penyebab dan file terkait.

## Spreadsheet uji
Spreadsheet "Uji Per Peran SiGAP SPPG": <!-- TODO: tempel tautan spreadsheet di sini -->
