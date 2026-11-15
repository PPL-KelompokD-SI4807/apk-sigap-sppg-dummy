# Uji berdampingan juknis BGN vs PDF Laporan BGN (R07-5, Lampiran 30a-30d)

Tanggal: 2026-11-15 · Cakupan: keluaran `FpdfRenderer` (30a, 30b, 30c, 30d-A/B/C) terhadap Kepka BGN 401.1/2025.
Tugas: R07-5 bagian non-Agen-AI (tool AI sub-kategori dikerjakan terpisah).

## Sumber acuan
- Juknis pindaian `docs/referensi/bgn-kepka-401-1-2025.pdf` **tersedia di mesin pengembang** tetapi **tidak ada di git** (`docs/referensi/` ada di `.git/info/exclude`; berkas 9,5 MB, 286 hlm, 612 x 936 pt, tanpa lapisan teks). Pembacaan dilakukan dari gambar halaman cetak 260-267 (indeks 0-based 258-265) dan Bab 4.7 (cetak 73-74; indeks 0-based 72-73). Catatan: nomor "indeks PDF" di dokumen rencana adalah 1-based; pustaka yang berindeks 0 memakai angka itu dikurangi 1.
- Karena berkas juknis tidak ada di repo/CI, tes otomatis memakai **salinan literal label** juknis (bukan membaca pindaian). Yang tidak bisa diotomatiskan (tampilan visual: garis tabel, warna kepala biru, lebar kolom, huruf tebal) dibandingkan manual dan dicatat di bawah.

## Hasil ringkas
| Status | Jumlah |
|---|---|
| sesuai | 22 |
| diperbaiki | 6 |
| diterima | 16 |
| butuh keputusan manusia | 2 |

## Tabel selisih
Nomor halaman = nomor cetak di kaki halaman juknis.

| Hlm | Label/struktur juknis | Keluaran PDF | Status | Catatan |
|---|---|---|---|---|
| 260 (30a) | Judul "REKAPITULASI PERHITUNGAN PORSI MBG di SPPG"; "Periode :"; "Nama SPPG :" | sama | sesuai | |
| 260 (30a) | Kolom `No`, `KEBUTUHAN`, `ANGGARAN`, tiap hari `JUMLAH PORSI` + `BIAYA PER HARI`, `TOTAL` | sama | sesuai | Juknis memecah "BIAYA PERHARI" karena sempit; bukan label berbeda |
| 260 (30a) | Kepala hari "17 Februari 2025" (tanggal panjang, tanpa nama hari) | sebelumnya "Senin 02-11-2026" | diperbaiki | Kini "2 November 2026" |
| 260 (30a) | Kolom `TOTAL` satu kolom | `TOTAL` dua sub-kolom `PORSI` dan `BIAYA` | diterima | Porsi dan biaya sama-sama dibutuhkan pembaca; tidak mengubah label juknis |
| 260 (30a) | Dua tabel bertumpuk, masing-masing 5 hari | satu tabel per pekan (2 tabel untuk 2 pekan), tiap tabel diawali "Pekan mulai ..." | diterima | Baris "Pekan mulai" adalah tambahan |
| 260 (30a) | Halaman potret (tabel diperkecil) | lanskap Folio | diterima | Keputusan R07-3 (lanskap untuk 30a dan 30d-C ada di rencana); 11+ kolom tidak terbaca di potret. Bila ingin potret, ubah di `FpdfRenderer::render()` |
| 73 (Bab 4.7) | 15 kelompok berurutan: Anak Balita; PAUD/TK/RA; SD/MI kelas 1-3; SD/MI kelas 4-6; SMP/MTS; SMA/MA/SMK; SLB; Santri; Pendidikan khusus lainnya; Pendidikan informal; Ibu hamil; Ibu menyusui; Food Sample; Uji Organoleptik; Pendidik dan Tenaga Kependidikan | 15 baris sama dan berurutan | sesuai | Halaman 30a kosong; daftar diambil dari Bab 4.7 (Keputusan Q-1) |
| 73 | "Food Sample", "Uji Organoleptik" (satuan "porsi" ditulis di kolom jumlah) | "Food Sample (porsi)", "Uji Organoleptik (porsi)" | diterima | Penanda satuan karena baris ini porsi, bukan orang (Q-1/Q-13) |
| 73 | "SD/MI kelas 1 - 3" (spasi di sekitar tanda hubung) | "SD/MI kelas 1-3" | diterima | Beda spasi saja |
| 260 (30a) | (tidak ada) | baris ke-16 "Belum terklasifikasi" bila ada penerima tanpa jenjang/kelompok; tanda " *" pada baris estimasi; catatan estimasi dan total periode | diterima | Keputusan Q-3 dan K2; tidak ada tebak-tebakan klasifikasi |
| 261 (30b) | Judul "Laporan Harian Penggunaan Dana Program MBG TA 2026" | sebelumnya "LAPORAN HARIAN PENGGUNAAN DANA" | diperbaiki | Kini "... PROGRAM MBG TA 2026" (tahun dari tanggal laporan) |
| 261 | `Nomor`, `Nama SPPG`, `Kecamatan`, `Kab/Kota`, `Provinsi`, `Tanggal` | sama, urutan sama | sesuai | |
| 261 | "Sasaran Penerima Manfaat"; "A. Peserta Didik" kolom `Kelompok Penerima Manfaat`, `Nama Sekolah`, `Jenis Kelamin` (`Jml Laki-laki`, `Jml Perempuan`), `Jumlah` | sama | sesuai | |
| 261 | 9 baris A: PAUD/TK/RA/TKLB; SD/MI/ SD SLB Kelas 1-3; SD/MI/ SD SLB Kelas 4-6; SMP/MTs/ SMP SLB/Pesantren Kelas 1-3; SMA/MA/SMK/SMA SLB/Pesantren Kelas 4-6; ATS Usia < 9 tahun; ATS Usia 9 -18 Tahun; Pendidik; Tenaga Kependidikan; lalu `Total` | label dan urutan sama (spasi setelah "/" dan sebelum "-18" dinormalkan) | sesuai | Pemisahan baris di juknis hanya akibat pembungkusan kata |
| 261 | "SMA/MA/SMK/SMA SLB/Pesantren **Kelas 4-6**" | disalin tepat | butuh keputusan manusia | Kemungkinan salah ketik juknis ("Kelas 10-12"); sama di 30d-C. Q-9 hanya mengoreksi salah *ejaan* jelas, jadi disalin tepat. Ubah di `JenjangPenerima::Sma` bila disetujui |
| 261 | "B. Non-Peserta Didik", kolom `Nama Posyandu`; baris Ibu Hamil, Ibu Menyusui, **Balita**, Total | sebelumnya baris ketiga "Balita (Non PAUD / 6-59 bulan)" | diperbaiki | Keterangan usia di juknis hanya pada kolom 30d-C; 30b-B kini "Balita" (`BarisSasaranBgn::labelHarian()`) |
| 261 | "Rincian Pembelanjaan", "A. Bahan Baku Pangan": `Nama Bahan Baku`, `Jumlah`, `Unit Satuan`, `Harga Satuan`, `Total`; "Total Belanja Bahan Baku Pangan" (contoh Beras/Ayam/Wortel/Telur hanya contoh) | sama | sesuai | |
| 261 | (tidak ada) | baris "Bahan baku lainnya (tanpa rincian pembelian)" | diterima | Aturan K4/Pemetaan (bahan baku tanpa pembelian terverifikasi) |
| 262 | "B. Biaya Operasional": `Kebutuhan`, `Biaya per Hari (Rp)`, `Keterangan`; 17 baris (Insentif Relawan ... Lain-lain); "Total Biaya Operasional" | sama; jumlah baris 17, urutan sama | sesuai | Tabel dapat terbelah ke halaman berikut; kepala tabel diulang |
| 262 | "C. Biaya Insentif Fasilitas SPPG": `Uraian`, `Jumlah`, `Keterangan`; satu baris "Biaya Insentif Fasilitas SPPG" | sebelumnya kepala kolom "Jumlah (Rp)" | diperbaiki | Kini "Jumlah" persis juknis |
| 262 | (tidak ada) | baris "Total belanja hari ini: Rp..." | diterima | Ringkasan tambahan |
| 262 | Blok tanda tangan: "(Kab/Kota SPPG), (Tanggal Bulan) 2026"; kiri Pihak Pertama/(Nama Yayasan)/(Nama Lengkap)/Ketua/Mewakili; kanan Pihak Kedua/Staf Akuntansi SPPG .../(Nama Lengkap); tengah bawah Mengetahui,/Kepala SPPG .../(Nama Lengkap) | susunan dan label sama | sesuai | |
| 262, 264, 266 | "Staf Akuntansi SPPG ...", "Kepala SPPG ..." (titik-titik = nama SPPG) | sebelumnya "Staf Akuntansi SPPG SPPG Anggrek Demo" (kata SPPG ganda bila nama SPPG berawalan "SPPG") | diperbaiki | Awalan "SPPG" pada nama tidak diulang |
| semua | Blok tanda tangan tidak terputus antar halaman | pada 30a (3 hlm dalam fixture) nama Kepala SPPG jatuh ke halaman 3, terpisah dari "Mengetahui" (ruang yang dipesan 66 mm, blok sebenarnya ~90 mm) | diperbaiki | `butuhRuang(92)`; tes mengunci untuk semua jenis |
| 263 (30c) | Judul "Laporan 2 (Dua) Mingguan Penggunaan Dana Program MBG TA 2026" (huruf kapital-kecil) | semua huruf kapital | diterima | Kata sama; tes lama mengunci versi kapital. Ubah bersama tes `test_isi_30c_angka_dan_tanda_tangan` bila perlu |
| 263 | `Nomor: xx/LPA/x/2026`; `Periode (dd-mm-2026) s/d (dd-mm-2026)` | "Nomor : (belum diterbitkan)" atau nomor terbit; "Periode : 02-11-2026 s/d 15-11-2026" | sesuai | |
| 263 | "Yang bertandatangan di bawah" (kalimat terpotong di juknis) | "Yang bertandatangan di bawah ini:" | diterima | Dilengkapi agar kalimat utuh; tidak mengubah arti |
| 263 | Nama, Jabatan, Yayasan, SPPG, ID SPPG, Kecamatan, Kab/Kota, Provinsi | sama, urutan sama | sesuai | |
| 263 | "Dengan ini menyampaikan laporan penggunaan dana sebagai berikut:" | sama | sesuai | |
| 263 | I. Rincian Keuangan: Dana Pemasukan - Total; Realisasi Anggaran (Bahan Baku, Operasional, Insentif Fasilitas SPPG, Total); Sisa Anggaran - Total | sama, urutan sama | sesuai | |
| 263 | (tidak ada) | baris "Saldo Awal Periode" sebelum Dana Pemasukan | diterima | Menjelaskan sisa anggaran (Q-2) |
| 263 | "Realisasi Anggaran" dan baris "Total" ditebalkan | huruf biasa | diterima | Gaya saja |
| 263-264 | II. Keterangan: kalimat baku + Bahan Baku, Operasional, Insentif Fasilitas SPPG, No. Rekening / Virtual Account; kalimat "Sisa dana sebesar Rp ... akan dialihkan ... periode selanjutnya. Pengalihan sisa dana ini ..." | sama | sesuai | |
| 264 | Blok tanda tangan (tempat "Tempat, (Tanggal Bulan) 2026") | "<Kab/Kota>, <tanggal>" | sesuai | Placeholder juknis diisi data SPPG |
| 265 (30d-A) | "Laporan bulanan Penggunaan Dana Program MBG TA 2026"; `Nomor`; `Periode Bulan ... 2026`; subjudul "A. Laporan Bulanan (Summary)" | judul kapital; "Periode : Bulan November 2026" | diterima | Kapitalisasi judul; isi identik 30c |
| 265-266 (30d-A) | isi dan urutan identik 30c | sama | sesuai | |
| 266 (30d-B) | "B. Laporan Bulanan (Anggaran)", `Nomor`, `Periode Bulan ... 2026` | sama | sesuai | |
| 266 | Kolom `No`, `Tanggal`, `Perihal`, `Dana Masuk`, `Dana Keluar`, `Saldo`, `Keterangan` | sama, urutan sama | sesuai | |
| 266 | Baris: Penerimaan Saldo Awal; "**Pembelajaan:**" (salah ketik); Bahan Baku Pangan; Biaya Operasional; Insentif Fasilitas SPPG | "Pembelanjaan:" dan urutan sama | diterima | Koreksi ejaan sesuai Q-9; tes memastikan "Pembelajaan" tidak tercetak |
| 266 | (tidak ada) | baris "Penerimaan Dana" per pencairan dan "Subtotal ..." pada subkelompok; baris Total | diterima | Dibutuhkan agar saldo berjalan dapat dihitung (rencana: dana masuk = pencairan) |
| 266 | Catatan kaki "*dibuat dari tanggal awal sampai dengan akhir bulan" | sama | sesuai | |
| 266 | Tidak ada blok tanda tangan setelah B | blok tiga pihak dicetak juga di 30d-B dan 30d-C | butuh keputusan manusia | Tambahan di luar juknis (juknis hanya menandatangani bagian A). Tidak mengubah angka; hapus bila ingin persis juknis |
| 267 (30d-C) | "C. Laporan Bulanan (Penerima Manfaat)", `Bulan: ... 2026` | sama (+ baris "Nama SPPG") | sesuai | Baris Nama SPPG: diterima sebagai tambahan |
| 267 | 17 kolom: No, Hari, Tanggal, Ket; Peserta Didik (9 kolom, urutan 30b-A); Non Peserta Didik (Ibu Hamil, Ibu Menyusui, Balita (Non PAUD / 6-59 bulan)); Total | sama | sesuai | Kolom "Belum terklasifikasi" muncul hanya bila ada data tak terklasifikasi (diterima, K2) |
| 267 | Halaman potret | potret (bawaan `laporan-bgn.penerima_lanskap=false`) | sesuai | |
| 267 | Kata di sel patah ("PAUD/TK/R A/TKLB") | patah serupa pada kolom sempit | diterima | Sifat kolom sempit di juknis |

Satu baris tabel dapat memuat beberapa label sekaligus; ringkasan menghitung baris tabel.

## Butir yang butuh keputusan manusia
1. "Kelas 4-6" pada baris SMA/MA/SMK (30b-A, 30d-C): disalin tepat dari juknis; kemungkinan salah ketik. Ubah label hanya atas keputusan eksplisit.
2. Blok tanda tangan juga pada 30d-B dan 30d-C (juknis hanya pada bagian A). Pilih: pertahankan atau hapus.
Opsional (status tabel "diterima", dicatat bila pemilik ingin lebih mirip juknis): orientasi 30a: lanskap (keputusan R07-3) vs potret seperti pindaian juknis; dan judul 30c/30d kapital semua vs kapital-kecil seperti juknis (mengubahnya memerlukan perubahan tes lama).

## Cara mengulang uji
1. Otomatis (label, urutan baris/kolom, 17 baris operasional, 15 kelompok 30a, 17 kolom 30d-C, blok tanda tangan utuh dalam satu halaman, tanpa salah ketik juknis): `php artisan test --filter=LaporanBgnJuknisTest` (berkas `tests/Feature/LaporanBgn/LaporanBgnJuknisTest.php`). Bila juknis berubah, ubah konstanta literal di berkas itu lebih dulu, lalu templat.
2. Visual (manual): letakkan juknis di `docs/referensi/bgn-kepka-401-1-2025.pdf` (tidak di-commit). Render halaman cetak 260-267 (indeks 0-based 258-265) dan keluaran SiGAP dengan PyMuPDF di direktori sementara:
   `pip install --target <direktori-sementara> pymupdf`, lalu `pymupdf.open(<pdf>)[<indeks>].get_pixmap(dpi=110).save(<png>)`. Keluaran SiGAP dibuat lewat `LaporanBgnPdfService::buat()` (mis. dari tes dengan fixture fiktif), jangan memakai data nyata.
3. Periksa per halaman: judul, urutan kolom/baris, jumlah baris operasional (17), tiga blok tanda tangan utuh pada satu halaman, nama SPPG tidak berulang "SPPG SPPG".
