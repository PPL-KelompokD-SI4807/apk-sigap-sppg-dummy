# Keputusan: Ambang Sel Kecil Agregat Penerima Manfaat (k = 5)

Tanggal: 2026-10-26 · Status: Diterima · Terkait: R06a-S-02, R06a-S-04 (`docs/decisions/2026-10-26-tinjauan-keamanan-r06a.md`)

## Konteks
Investor (pemilik SPPG, pihak eksternal) hanya boleh membaca agregat penerima manfaat
(`GET /penerima-manfaat/agregat`). Kelompok `ibu_hamil`/`ibu_menyusui` adalah data kesehatan (kategori
spesifik UU PDP), dan nama titik layanan (sekolah/posyandu) ditampilkan. Tanpa ambang, sel kecil
(mis. "Posyandu X: 1" + "ibu hamil: 1") dapat mengidentifikasi individu.

## Keputusan
1. **Berlaku untuk** peran yang hanya punya hak agregat pada modul (`Rbac::aggregateOnlyModules`, saat ini
   investor). Admin, Kepala SPPG, dan Ahli Gizi (hak R penuh) tetap melihat angka asli.
2. **Ambang k = 5**, dapat diatur di `config/rbac.php` (`agregat_ambang.k`, minimal 2).
3. **Sel primer:** hitungan 1..k-1 menjadi `jumlah: null` + `penanda: "<5"`. Nol tetap ditampilkan.
4. **Marginal:** `aktif` disamarkan bila 1..k-1. `total` disamarkan bila `total` atau jumlah nonaktif
   (`total - aktif`) bernilai 1..k-1.
5. **Suppression sekunder (sel pelengkap):** per tabel (kelompok sasaran, titik layanan), selama ada sel
   tersamar dan banyaknya < 2 atau jumlah nilainya < k, sel positif terkecil berikutnya ikut disamarkan.
   Dengan begitu sel kecil tidak dapat dihitung dari `aktif` dikurangi sel yang terlihat.
6. **Penanda seragam:** semua sel tersamar (primer maupun pelengkap) memakai penanda yang sama (`"<5"`),
   agar pembaca tidak dapat membedakan keduanya. Akibatnya `"<5"` berarti "disamarkan", bukan selalu
   "kurang dari 5". Arti ini dijelaskan di OpenAPI dan perlu dijelaskan di legenda Mini App.
7. **SPPG sangat kecil** (`aktif` < k): seluruh rincian disamarkan, termasuk nol, agar tidak terungkap
   pola seperti "semua penerima = ibu hamil".
8. **Tidak ada tabel silang** titik layanan x kelompok sasaran, sekarang maupun nanti, untuk peran agregat.
9. **Nama titik layanan (S-04):** boleh dilihat investor, dengan ambang di atas. Titik layanan yang sudah
   dihapus lunak tidak ditampilkan.

## Implementasi
- `app/Services/PenerimaManfaat/AgregatPenerimaService.php` (`hitung`, `samarkan`, `topengTabel`)
- `app/Http/Controllers/Api/V1/PenerimaManfaatController.php` (`agregat`)
- Tes: `tests/Feature/Investor/AgregatAmbangTest.php`
- Kontrak: skema `PenerimaManfaatAgregat` di `docs/api/openapi.yaml` (field baru `total_penanda`,
  `aktif_penanda`, `penyamaran`, `penanda` per sel; `jumlah`/`total`/`aktif` dapat bernilai `null`).

## Risiko sisa (diterima)
- **Differencing antarwaktu:** investor yang mengamati agregat berulang kali dapat menyimpulkan perubahan
  kecil (mis. satu penerima masuk/keluar) bila sel melewati ambang. Ini dimitigasi sebagian oleh ambang
  dan penyamaran `total` saat jumlah nonaktif kecil. Mitigasi penuh (pembulatan acak atau publikasi berkala)
  belum diperlukan untuk skala MBG per SPPG.
- **Kerugian informasi:** pada SPPG kecil, sel besar dapat ikut tersamar sebagai pelengkap. Hal ini dianggap
  wajar untuk peran eksternal.
