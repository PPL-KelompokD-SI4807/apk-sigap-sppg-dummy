# R-06a Peran Investor + Akses Multi-SPPG (Tier T3)

Tanggal: 2026-10-26 · Branch: `feat/r06a-peran-investor` (dari `develop`, R-03 sudah ter-merge) · PR ke `develop`
Fitur terkait: No 1 (Data Pengguna), No 3 (SPPG), dan akses baca No 5/6/11/14/15/16. Keputusan pengguna final; tidak menambah fitur.

## Tujuan
Peran `investor` (pemilik SPPG) yang dapat memiliki banyak SPPG, memilih SPPG aktif di Mini App, dan hanya
MEMBACA dasbor ringkas, keuangan, stok, mutu, keluhan, rekap harian, serta agregat penerima manfaat (tanpa nama/data pribadi).
Aksi tulis investor (setujui/tolak/minta revisi) = R-06b, BELUM dikerjakan.

## Keputusan desain (PM)
- `users.role` sudah string(30), jadi peran baru = config `rbac.php` + validasi/whitelist peran (aditif, tanpa ubah data).
- Investor: `users.sppg_id` wajib null; kepemilikan HANYA dari pivot `investor_sppg` (user_id, sppg_id, unik, FK). Peran lain tetap satu SPPG lewat `users.sppg_id` (tidak berubah).
- SPPG aktif: header `X-SPPG-Id` divalidasi tiap request terhadap pivot. Tanpa header: pakai SPPG satu-satunya bila investor hanya punya satu; bila >1 atau 0 -> fail closed (galat terstruktur). SPPG di luar kepemilikan -> 403. Peran non-investor: header berbeda dari `sppg_id` sendiri -> 403; admin tidak berubah.
- SppgScope/BaseSppgPolicy untuk investor membatasi ke SATU SPPG aktif (bukan gabungan semua), sehingga tidak ada data lintas SPPG tercampur; peran lain tidak dilonggarkan.
- Matriks investor: `sppg` R, `bahan-baku` R (stok), `mutu` R, `keuangan` R, `keluhan` R, `rekap-harian` R (dasbor ringkas), `pengguna` R*, sisanya kosong. `penerima-manfaat` kosong pada matriks; investor membaca lewat endpoint agregat terpisah (jumlah per kategori, tanpa nama/NIK/alamat/tanggal lahir).
- Daftar SPPG yang boleh diakses: endpoint untuk pemilih SPPG. Kepemilikan dikelola Admin lewat endpoint khusus admin; akun dibuat lewat undangan 72 jam yang ada (peran `investor` ditambahkan ke whitelist undangan). Login = alur R-03 tanpa perkecualian.
- Agen AI: investor hanya tool baca/ringkas (mewarisi matriks), tidak pernah membuat AiDraft; tool memakai SPPG aktif; nama anak tidak dikirim ke LLM. Chat bot tanpa header: investor >1 SPPG -> fail closed dengan arahan memilih SPPG di Mini App.

## Keputusan tambahan pengguna (2026-10-26, lanjutan)
1. Layar Admin kepemilikan SPPG investor (tautkan/lepas investor ke SPPG) MASUK lingkup R-06a:
   - Backend: endpoint khusus Admin (daftar kepemilikan, tautkan, lepas), hanya peran admin (lainnya 403),
     validasi target ber-peran `investor` dan SPPG ada, idempoten/unik, tercatat di audit log; didokumentasikan di `docs/api/openapi.yaml`.
   - Frontend: layar Admin untuk melihat/menautkan/melepas SPPG milik seorang investor.
2. Bot Telegram: investor dengan >1 SPPG ditolak (fail closed) dan diarahkan memilih SPPG di Mini App. Disetujui.
Tidak ada penambahan fitur lain.

## Tugas
| ID | Tier | Agen | Tugas | Bergantung |
|---|---|---|---|---|
| R06a-1 | T3 | backend-dev (opus/high) | config rbac investor, whitelist peran, migrasi pivot, resolver SPPG aktif + middleware, SppgScope/BaseSppgPolicy, endpoint daftar SPPG + kepemilikan (admin) + agregat penerima, AI read-only, seeder fiktif, tes IDOR, openapi.yaml, test + pint hijau | - |
| R06a-2 | T1 | frontend-dev (sonnet/medium) | Pemilih SPPG aktif (kirim `X-SPPG-Id`), menu investor baca-saja (tombol tulis disembunyikan), layar agregat penerima, penanganan 403, peran investor di form undangan, layar Admin kepemilikan SPPG investor; build + tes unit | R06a-1 |
| R06a-3 | T3 | PM + agen read-only (opus/high) | Tinjauan keamanan: IDOR lintas investor, pemalsuan header, kebocoran PII, eskalasi tulis, AI tool/draf, regresi peran lain. Hasil ke `docs/decisions/2026-10-26-tinjauan-keamanan-r06a.md` | R06a-1, R06a-2 |
| R06a-4 | T3 | backend-dev (opus/high) | Perbaikan temuan Tinggi/Kritis (bila ada) + tinjau ulang | R06a-3 |
| R06a-5 | T0 | github-ops (haiku/low) | Commit bertahap, PR ke develop dengan checklist DoD, pantau CI 8.3/8.4, tanpa merge | R06a-3/4 |

## Risiko
- Model `User` memakai SppgScope: investor (sppg_id null) bisa gagal memuat profilnya sendiri bila scope diterapkan ke tabel users; perlu penanganan eksplisit tanpa membuka data pengguna SPPG lain.
- Header SPPG aktif dapat dipalsukan: validasi wajib di server setiap request (bukan dari state klien/cache).
- Celah agregat: kelompok kecil (mis. 1 anak) dapat mengidentifikasi individu; agregat hanya hitungan per kategori tanpa field pribadi.
- Kebocoran lewat relasi/eager-load (keluhan, mutu, rekap) yang menyertakan nama penerima; Resource harus diperiksa untuk investor.
- Token abilities peran: investor tidak boleh mendapat ability tulis.
- Pekerjaan queue/scheduler tanpa pengguna login tidak terpengaruh scope (tetap memfilter sendiri).
