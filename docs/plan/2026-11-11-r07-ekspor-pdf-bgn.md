# R-07 Ekspor PDF Format BGN (Lampiran 30a-30d) - Rencana dan Spesifikasi

Tanggal: 2026-11-11 · Status: **rencana dengan keputusan final pengguna (2026-11-11), belum ada kode** (R07-1 baru mulai 26 Nov) · Tier: T2/T1 (semua sonnet, tanpa opus) · Keputusan pengguna 2026-11-11: pilihan A, "semirip mungkin dengan juknis BGN". Keputusan Q-1..Q-12 sudah final, lihat bagian "Keputusan" di bawah.
Fitur terkait: perluasan fitur 3 (Data SPPG), 5 (Data Penerima Manfaat), 14 (Data Keuangan), 16 (Laporan Rekapitulasi). Daftar 16 fitur resmi tidak berubah.
Menggantikan draf internal `docs/handoff/revisi/r07-format-rekap-keuangan.md` (format buatan sendiri, penandatangan Kepala BGN/investor) untuk laporan BGN; draf lama tetap sebagai arsip.
Sumber: `docs/referensi/bgn-kepka-401-1-2025.pdf`, Lampiran 30 (286 hlm, pindaian gambar tanpa lapisan teks, ukuran halaman 612x936 pt = Folio/F4).
Berkas acuan `claude/format-laporan-bgn-r07.md`, `claude/perbandingan-r07-bgn-vs-sigap.md`, `claude/keputusan-r07-pdf-bgn.md` tidak ada di repo dan tidak dipakai.

## Peta halaman (indeks PDF -> nomor cetak di kaki halaman)
Nomor cetak = indeks PDF + 1 (untuk Lampiran 30; di bagian lain juknis selisih berbeda, mis. Bab 4.7 indeks 73-74 bernomor cetak sama).
| Lampiran | Judul | Indeks PDF | Cetak | Dalam lingkup |
|---|---|---|---|---|
| 30a | Rekapitulasi Perhitungan Porsi MBG di SPPG | 259 | 260 | ya |
| 30b | Laporan Harian Penggunaan Dana | 260-261 | 261-262 (blok TTD di 261) | ya |
| 30c | Laporan 2 Mingguan Penggunaan Dana | 262-263 | 263-264 (blok TTD di 263) | ya |
| 30d | Laporan Bulanan: A Summary 264-265, B Anggaran 265-266, C Penerima Manfaat 266 | 264-266 | 265-267 | ya |
| 30e-30i | Neraca Besar, Petty Cash, Bahan Pangan, Dana Operasional, Insentif Fasilitas | 266-269 | 267-270 | **tidak** |
| 30j-30l | Pernyataan Tanggung Jawab, Bukti Tanda Terima, Daftar Nominatif Insentif Relawan | 269-271 | 270-272 | **tidak** |
Acuan data: Bab 4.7 juknis (indeks PDF 73-74): daftar 15 kelompok pengajuan, acuan bahan baku Rp8.000/Rp10.000 per orang, pagu operasional Rp3.000/orang, komponen biaya operasional.
Catatan: ringkasan di bawah dibaca dari gambar; tulisan "Kelas 4-6" untuk SMA, "Pembelajaan" di 30d-B, dan tanggal contoh "Februari 2025" pada 30a adalah salah ketik bawaan juknis, lihat Q-9.

## Pembagian peran dan penanda tangan (semua laporan)
Blok akhir: kota/kab SPPG + tanggal. Kiri **Pihak Pertama** (nama Yayasan; nama lengkap; "Ketua/Mewakili"). Kanan **Pihak Kedua** (Staf Akuntansi SPPG ...; nama lengkap). Tengah bawah **Mengetahui** (Kepala SPPG ...; nama lengkap). Ruang kosong untuk tanda tangan basah; nama diisi dari `sppgs.nama_ketua_yayasan`, pengguna `akuntan` SPPG, pengguna `kepala_sppg` SPPG (kosong "(Nama Lengkap)" bila tidak ada atau lebih dari satu; lihat Q-7).

## Spesifikasi per laporan

### 30a Rekapitulasi Perhitungan Porsi (lanskap, kertas F4)
- Judul "REKAPITULASI PERHITUNGAN PORSI MBG di SPPG"; "Periode : ... 2026 - ... 2026"; "Nama SPPG :".
- Dua tabel bertumpuk, masing-masing 5 hari kerja (contoh Sen-Jum, dua pekan = 10 hari; hari operasional ke-6 bila ada). Kolom: `No` | `KEBUTUHAN` | `ANGGARAN` | per hari { `JUMLAH PORSI`, `BIAYA PER HARI` } x5 | `TOTAL`.
- **Keputusan Q-1 (final):** baris `KEBUTUHAN` = **15 kelompok penerima manfaat** menurut Bab 4.7 juknis: Anak Balita; PAUD/TK/RA; SD/MI kelas 1-3; SD/MI kelas 4-6; SMP/MTS; SMA/MA/SMK; SLB; Santri; Pendidikan khusus lainnya; Pendidikan informal; Ibu hamil; Ibu menyusui; Food Sample (porsi); Uji Organoleptik (porsi); Pendidik dan Tenaga Kependidikan. Baris `Total` di akhir.
- `ANGGARAN` = acuan bahan baku per porsi: **Rp8.000** untuk Balita, PAUD/TK/RA, SD/MI kelas 1-3; **Rp10.000** untuk kelompok lain (termasuk Food Sample dan Uji Organoleptik, bawaan). Nilai bisa diubah per SPPG (pengaturan, bukan konstanta kode). `BIAYA PER HARI` = `JUMLAH PORSI` x `ANGGARAN`. Jumlah porsi per kelompok per hari = **estimasi** (Keputusan Q-3), diberi tanda "estimasi".
- Insentif fasilitas SPPG tidak ditaruh di tabel 30a (tiap baris adalah kelompok penerima); nilainya muncul di 30b-C, 30c, 30d.
- Hari tanpa produksi: kolom tetap tampil, nilai 0 / "-".

### 30b Laporan Harian Penggunaan Dana (potret, 2-3 hlm)
Kepala: `Nomor`, `Nama SPPG`, `Kecamatan`, `Kab/Kota`, `Provinsi`, `Tanggal`.
**Sasaran Penerima Manfaat**
- A. Peserta Didik. Kolom: `Kelompok Penerima Manfaat` | `Nama Sekolah` | `Jenis Kelamin: Jml Laki-laki, Jml Perempuan` | `Jumlah`. Baris (urutan baku): PAUD/TK/RA/TKLB; SD/MI/SD SLB Kelas 1-3; SD/MI/SD SLB Kelas 4-6; SMP/MTs/SMP SLB/Pesantren Kelas 1-3; SMA/MA/SMK/SMA SLB/Pesantren Kelas 4-6 (sic, lihat Q-9); ATS Usia < 9 tahun; ATS Usia 9-18 Tahun; Pendidik; Tenaga Kependidikan; baris `Total`. Satu kelompok boleh beberapa baris, satu per sekolah (`titik_layanans.nama`).
- B. Non-Peserta Didik. Kolom sama, `Nama Posyandu`. Baris: Ibu Hamil; Ibu Menyusui; Balita; `Total`.
**Rincian Pembelanjaan**
- A. Bahan Baku Pangan. Kolom: `Nama Bahan Baku` | `Jumlah` | `Unit Satuan` | `Harga Satuan` | `Total`; baris penutup "Total Belanja Bahan Baku Pangan". Sumber: `pembelian_bahans` berstatus `terverifikasi`, `tanggal_beli` = tanggal laporan; satu baris per (bahan, satuan, harga satuan).
- B. Biaya Operasional. Kolom: `Kebutuhan` | `Biaya per Hari (Rp)` | `Keterangan`. **17 baris baku**: Insentif Relawan; Insentif Harian Penanggung Jawab Satuan Pendidikan; Insentif Kader; BPJS Ketenagakerjaan; Listrik; Air Kemasan Galon; Air PDAM; Air Minum; Gas; Sewa Kendaraan; BBM; Pulsa; Internet; ATK; Alat Kebersihan; APD; Lain-lain. Penutup "Total Biaya Operasional".
- C. Biaya Insentif Fasilitas SPPG. Kolom: `Uraian` | `Jumlah` | `Keterangan`; satu baris "Biaya Insentif Fasilitas SPPG". Nilai bawaan (Keputusan Q-1): **Rp6.000.000 per hari operasional**, maksimal 6 hari per minggu, dihitung dari hari operasional (hari ada produksi/pengeluaran), dapat diatur per SPPG. Pada laporan harian, nilai hari itu; pada 30c/30d dijumlahkan sepanjang periode.
- Blok tanda tangan: tiga blok sesuai juknis (lihat "Pembagian peran"): **Pihak Pertama** (nama Yayasan, nama lengkap, Ketua/Mewakili), **Pihak Kedua** (Staf Akuntansi SPPG, nama lengkap), **Mengetahui** (Kepala SPPG, nama lengkap), dengan baris "(Kab/Kota SPPG), (Tanggal Bulan) 2026" di atasnya.
- Ringkas isi 30b (daftar periksa R07-5): header; sasaran penerima (A peserta didik per jenjang x jenis kelamin; B non-peserta didik); belanja bahan baku pangan; biaya operasional 17 baris; insentif fasilitas; tiga blok tanda tangan.

### 30c Laporan 2 Mingguan (potret, 2 hlm)
- Judul "Laporan 2 (Dua) Mingguan Penggunaan Dana Program MBG TA 2026"; `Nomor: xx/LPA/x/2026`; `Periode (dd-mm-2026) s/d (dd-mm-2026)`.
- "Yang bertandatangan di bawah": `Nama`, `Jabatan`, `Yayasan`, `SPPG`, `ID SPPG`, `Kecamatan`, `Kab/Kota`, `Provinsi` (penandatangan = Pihak Pertama; Q-7).
- "Dengan ini menyampaikan laporan penggunaan dana sebagai berikut:"
- I. Rincian Keuangan: `Dana Pemasukan - Total : Rp`; `Realisasi Anggaran`: Bahan Baku : Rp; Operasional : Rp; Insentif Fasilitas SPPG : Rp; Total : Rp; `Sisa Anggaran - Total : Rp`.
- II. Keterangan: kalimat baku "Dana yang telah digunakan sesuai dengan kebutuhan kegiatan yang telah direncanakan dengan rincian sebagai berikut:" lalu `Bahan Baku :`, `Operasional :`, `Insentif Fasilitas SPPG :` (uraian teks ringkas), `No. Rekening / Virtual Account :` (`sppgs.no_va`).
- Kalimat baku: "Sisa dana sebesar Rp ... akan dialihkan ke periode selanjutnya. Pengalihan sisa dana ini bertujuan untuk mendukung kegiatan yang telah direncanakan pada periode selanjutnya." Blok tanda tangan di halaman 2.

### 30d Laporan Bulanan (A potret, B potret, C lanskap; indeks PDF A 264-265, B 265-266, C 266)
- Judul "Laporan bulanan Penggunaan Dana Program MBG TA 2026"; `Nomor: xx/LPA/x/2026`; `Periode Bulan ... 2026`.
- **A. Laporan Bulanan (Summary)**: isi dan urutan identik 30c (penandatangan, I Rincian Keuangan, II Keterangan, kalimat sisa dana, blok TTD), periode = satu bulan kalender.
- **B. Laporan Bulanan (Anggaran)**: `Nomor`, `Periode Bulan ... 2026`. Tabel: `No` | `Tanggal` | `Perihal` | `Dana Masuk` | `Dana Keluar` | `Saldo` | `Keterangan`. Urutan baris: "Penerimaan Saldo Awal"; lalu "Pembelanjaan:" dengan subkelompok **Bahan Baku Pangan**, **Biaya Operasional**, **Insentif Fasilitas SPPG** (baris transaksi per tanggal di bawah tiap subkelompok); saldo berjalan. Catatan kaki baku: "*dibuat dari tanggal awal sampai dengan akhir bulan". Dana masuk = pencairan; dana keluar = pengeluaran disetujui.
- **C. Laporan Bulanan (Penerima Manfaat)**: `Bulan: ... 2026`. Kolom (17): `No` | `Hari` | `Tanggal` | `Ket` | Peserta Didik { PAUD/TK/RA/TKLB; SD/MI/SD SLB Kelas 1-3; SD/MI/SD SLB Kelas 4-6; SMP/MTs/SMP SLB/Pesantren Kelas 1-3; SMA/MA/SMK/SMA SLB/Pesantren Kelas 4-6; ATS Usia < 9 tahun; ATS Usia 9-18 Tahun; Pendidik; Tenaga Kependidikan } | Non Peserta Didik { Ibu Hamil; Ibu Menyusui; Balita (Non PAUD / 6-59 bulan) } | `Total`. Satu baris per hari kalender/produksi dalam bulan; hitungan porsi dibagikan per kelompok (Q-3).

## Pemetaan data SiGAP -> laporan
| Kebutuhan laporan | Sumber SiGAP | Catatan |
|---|---|---|
| Identitas SPPG (nama, ID SPPG, kecamatan, kab/kota, provinsi, rekening/VA, Yayasan, ketua yayasan) | `sppgs`: tambah `id_sppg_bgn`, `kecamatan`, `kab_kota`, `provinsi`, `no_va`, `nama_ketua_yayasan` (nullable); `mitra_yayasan` = Yayasan | R07-1; tanpa mengubah `kode` |
| Saldo awal (Keputusan Q-2) | kolom baru nullable di `sppgs` (`saldo_awal`, `saldo_awal_tanggal`), diisi **manual oleh Akuntan sekali per SPPG** sebelum SiGAP dipakai; hanya Akuntan/Admin boleh mengubah, tiap perubahan masuk `audit_logs` | R07-1 menyediakan kolom + endpoint + Policy; 30c/30d memakainya sebagai saldo awal periode pertama, periode berikutnya otomatis = saldo akhir sebelumnya (pencairan - pengeluaran disetujui) |
| Pengaturan anggaran per SPPG (Keputusan Q-1) | kolom/pengaturan baru nullable di `sppgs`: acuan bahan baku per porsi kelompok kecil (bawaan 8000) dan lainnya (bawaan 10000), insentif fasilitas per hari (bawaan 6000000), maks hari/minggu (bawaan 6) | R07-1; nilai bawaan dipakai bila kolom kosong |
| Dana masuk | `pencairan_danas.nominal` per `tanggal_cair` | |
| Belanja bahan baku rinci | `pembelian_bahans` (`terverifikasi`) | kolom jumlah/satuan/harga_satuan/total sudah ada |
| Belanja bahan baku total, operasional, insentif | `pengeluarans` berstatus `disetujui` | verifikasi pembelian otomatis membuat pengeluaran `bahan_baku` (`pembelian_bahan_id`); **jangan hitung ganda**: total bahan baku dari pengeluaran, rincian dari pembelian, selisih (bahan_baku tanpa pembelian) tampil "Bahan baku lainnya" |
| Porsi harian | `log_produksis.realisasi_porsi` (per `jadwal_produksi_id`), cadangan `jadwal_distribusis.jumlah_porsi` | |
| Penerima per kelompok/jenjang/sekolah/gender | `penerima_manfaats` aktif + `titik_layanans`: tambah `jenis_kelamin`, `jenjang` (nullable); perluas `kelompok_sasaran` dengan `pendidik`, `tenaga_kependidikan`, `ats` bila perlu | tanpa NIK; PDF hanya agregat. 15 kelompok 30a diturunkan dari (`kelompok_sasaran`, `jenjang`, `jenis_satuan`); usulan: `jenjang` setingkat 30b (paud_tk, sd_1_3, sd_4_6, smp, sma, ats_kurang_9, ats_9_18) + `jenis_satuan` nullable (reguler, slb, pesantren/santri, pendidikan_khusus, informal) agar SLB, Santri, Pendidikan khusus lainnya, Pendidikan informal terpisah di 30a tetapi menyatu ke baris jenjang 30b (R07-1 memfinalkan; lihat Q-13). Food Sample dan Uji Organoleptik adalah **porsi, bukan orang**: sumber bawaan kolom nullable di `log_produksis` (`porsi_food_sample`, `porsi_uji_organoleptik`), kosong = 0 |
| Sub-kategori 17 baris | `pengeluarans.sub_kategori` (nullable) + `kategori` baru `insentif_fasilitas` | data lama tidak diubah |
| Nomor LPA | tabel baru pencatat laporan (R07-2): `sppg_id`, `jenis`, periode, `nomor_urut`, `nomor` | cetak ulang periode yang sama = nomor sama; unik per (sppg, jenis, periode) |
| Foto nota | `pengeluarans.nota_path`, `pembelian_bahans.nota_path` | halaman lampiran bukti terpisah, urut per nomor baris |

### Pengelompokan BGN dan pemetaan kategori lama (bawaan, perlu konfirmasi Q-5)
Tiga kelompok BGN: **Bahan Baku** = kategori `bahan_baku`; **Insentif Fasilitas SPPG** = kategori baru `insentif_fasilitas`; **Operasional** = sisanya (termasuk insentif relawan, yang di juknis berada di 30b-B baris 1).
Pemetaan baris operasional untuk data lama tanpa `sub_kategori`: `insentif_relawan` -> Insentif Relawan; `energi`, `transportasi`, `peralatan`, `lainnya` -> **Lain-lain** dengan Keterangan memuat nama kategori lama (tanpa tebakan Listrik/Gas/BBM agar angka tidak salah klasifikasi). Data baru memakai `sub_kategori` (17 nilai tetap, enum) sehingga baris tepat.

## RBAC dan privasi
- Cetak/unduh: `akuntan`, `kepala_sppg`, `admin` (admin memilih SPPG). `investor`: hanya SPPG miliknya via pivot dan hanya angka agregat; `ahli_gizi`, `staf_gudang`, `relawan`: tidak ada akses. Semua dibatasi scope SPPG; akses tanpa hak = 403.
- PDF memuat nama sekolah/posyandu dan agregat; **tanpa** nama, NIK, alergi, atau kelas penerima individu. Nama pengguna hanya pada blok tanda tangan.
- **Keputusan Q-4 (final)**, selaras ADR k=5 (`docs/decisions/2026-10-26-ambang-agregat-k5.md`): 30b-A/B dan 30d-C menyilangkan titik layanan x kelompok x jenis kelamin, yang dilarang bagi investor. Investor hanya boleh mengunduh **agregat: 30a, 30c, 30d bagian A dan B**; permintaan 30b atau 30d-C oleh investor dijawab 403 (bukan data tersamar). 30a memuat jumlah per kelompok (bukan per titik); sel kecil < 5 mengikuti penyamaran ADR k=5 bagi investor.
- Tiap ekspor tercatat di `audit_logs` (siapa, jenis, periode, SPPG). Tanpa isi data di log.
- Data contoh dan PDF contoh: fiktif.

## Daftar tugas (TaskCreate)
Catatan: alat TaskCreate tidak tersedia pada sesi mana pun, sehingga **tabel ini adalah daftar tugas resmi** (lengkap dengan ketergantungan). Delegasi dimulai **26 Nov**, bukan sebelumnya. Semua agen berjalan di `sonnet`; tanpa `opus`. Perubahan lingkup tetap lewat PM.

| ID | Tugas | Tier / model / effort | Agen | Jadwal | Bergantung pada | Kriteria selesai |
|---|---|---|---|---|---|---|
| R07-0 | Pembacaan juknis hlm 259-273 dan Bab 4.7, rencana, spesifikasi, risiko | T2 (PM) | project-manager | 11 Nov (selesai) | - | dokumen ini |
| R07-D1 | Keputusan pengguna Q-1..Q-12 | keputusan manusia | pengguna | 11 Nov (selesai, final) | R07-0 | bagian "Keputusan" di bawah |
| R07-1 | Migrasi hanya ADD COLUMN nullable: `sppgs` (`id_sppg_bgn`, `kecamatan`, `kab_kota`, `provinsi`, `no_va`, `nama_ketua_yayasan`, saldo awal + tanggal, pengaturan anggaran per porsi/insentif fasilitas); `penerima_manfaats` (`jenis_kelamin`, `jenjang`, `jenis_satuan`; perluasan `kelompok_sasaran` pendidik, tenaga_kependidikan, ats); `pengeluarans` (`sub_kategori` 17 nilai); `log_produksis` (porsi Food Sample, Uji Organoleptik); enum/`KategoriPengeluaran` baru `insentif_fasilitas`; model, Form Request, Policy (saldo awal: hanya Akuntan/Admin + audit log), tes; audit dampak `::cases()` pada `AgregatPenerimaService`, dasbor, `BudgetService`, tool AI; `openapi.yaml`; kirim kontrak ke frontend-dev | T2 / sonnet / high | backend-dev | 26-28 Nov | R07-D1 (selesai) | `php artisan test` + `pint --test` hijau; tidak ada data lama berubah; tes migrasi naik/turun; 403 untuk peran tanpa hak; kontrak terkirim |
| R07-G1 | Branch `feat/r07-1-kolom-bgn`, commit bertahap, PR ke develop dengan checklist DoD, pantau CI | T0 / haiku / low | github-ops | 28 Nov | R07-1 | PR terbuka, CI hijau (tanpa merge) |
| R07-T1 | **Tinjauan pengguna 28 Nov**: skema, pemetaan 17 baris, keputusan data lama | manusia | pengguna | 28 Nov | R07-1 | persetujuan atau catatan |
| R07-2 | `LaporanBgnService` + agregator 30a-30d (harian/2 mingguan/bulanan), saldo awal dari R07-1 lalu otomatis, estimasi porsi per kelompok (proporsi aktif x realisasi, bertanda "estimasi"), tabel dan penomor LPA `xx/LPA/x/2026`, **Job antrean** pembuat laporan, RBAC + scope SPPG + audit log (investor hanya 30a, 30c, 30d A/B, lainnya 403), tes rekonsiliasi (total 30c = jumlah 30b harian; 30d-A = 30d-B; tanpa hitung ganda bahan baku) | T2 / sonnet / high | backend-dev | 30 Nov-2 Des | R07-1 (diterima) | angka cocok dengan fixture fiktif; investor tidak melihat data pribadi; tes lulus; kontrak endpoint ke frontend-dev |
| R07-3a | Evaluasi pustaka PDF (Docker, RAM 1,9 GB): ringkasan opsi + uji memori mini, ajukan ke PM **tanpa memasang dependensi** | T2 / sonnet / high | backend-dev | 30 Nov-2 Des (setelah/di sela R07-2, agen sama) | - | ringkasan < 10 baris + rekomendasi; PM minta persetujuan pengguna sebelum `composer require` |
| R07-3 | Render PDF **F4/Folio 210 x 330 mm** (lanskap untuk 30a dan 30d-C), kolom/urutan/judul/kalimat baku/tiga blok TTD sesuai Spesifikasi; halaman lampiran foto nota terpisah (diperkecil GD); Job antrean mengirim hasil ke pengguna lewat bot `sendDocument` | T2 / sonnet / high | backend-dev | 3-7 Des | R07-2, R07-3a + persetujuan dependensi | PDF contoh fiktif 4 jenis; memori puncak terukur < ~256 MB per ekspor; tes 200/403; tes job + sendDocument (Http fake) |
| R07-G2 | PR R07-2 dan R07-3 (dua PR), pantau CI | T0 / haiku / low | github-ops | 2 Des dan 7 Des | R07-2, R07-3 | PR terbuka, CI hijau |
| R07-T2 | **Tinjauan pengguna 7 Des**: tampilan PDF vs juknis | manusia | pengguna | 7 Des | R07-3 | persetujuan atau catatan |
| R07-4 | Isian baru fitur 3, 5, 14 (sesuai kontrak R07-1: saldo awal, pengaturan anggaran, jenis kelamin/jenjang, sub-kategori) dan layar ekspor (jenis laporan, periode, tombol "Kirim PDF ke Telegram"; unduh langsung web menyusul); selaras adapter platform R-04 | T1 / sonnet / medium | frontend-dev | 7-10 Des | R07-1 (isian), R07-2/R07-3 (endpoint ekspor) | layar sesuai kontrak; tanpa mengubah wilayah file lain |
| R07-5 | Uji berdampingan juknis hlm demi hlm (30a-30d), perbaikan, tool Agen AI sub-kategori (aksi tulis = draf AiDraft + konfirmasi), catatan `docs/ops` singkat | T2 / sonnet / high | backend-dev (+frontend-dev untuk perbaikan layar, T1) | 10-12 Des | R07-3, R07-4 | daftar selisih PDF vs juknis kosong atau diterima; tes tool AI; CI hijau |
| R07-G3 | PR R07-4/R07-5, CHANGELOG | T0 / haiku / low | github-ops | 10-12 Des | R07-4, R07-5 | PR terbuka, CI hijau |
| R07-T3 | **Tinjauan pengguna 12 Des**: penerimaan akhir; merge hanya atas persetujuan eksplisit | manusia | pengguna | 12 Des | semua | keputusan merge |

Urutan ketergantungan: R07-1 -> R07-2 -> R07-3 (+ R07-3a) -> R07-5; R07-1 -> R07-4 (isian) dan R07-2/3 -> R07-4 (layar ekspor); R07-5 setelah R07-3 dan R07-4. Satu agen backend memegang R07-1/2/3a/3/5, sehingga R07-2 dan R07-3a berjalan berurutan dalam jendela 30 Nov-2 Des (R07-3a boleh didelegasikan ke sesi terpisah bila R07-2 molor).
Aturan eskalasi: naik satu tier hanya setelah dua kali gagal tinjauan/tes; tidak memakai opus. Bila R07-2 menemui keputusan arsitektur, kembalikan ke PM.
Paralel dengan R-04: R07-4 menyentuh `resources/miniapp/**` dan sumber web bersama; koordinasikan lewat PM agar tidak bertabrakan dengan tugas R04 frontend.

## Risiko
| No | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| K1 | Juknis berupa pindaian; label dibaca dari gambar, isi baris 30a kosong | salah baca kata/kolom | R07-5 uji berdampingan; Q-1, Q-9 |
| K2 | Tidak ada data porsi per hari per kelompok (30d-C) dan jenis kelamin per penerima belum ada | kolom 30d-C bisa tidak akurat | estimasi dari porsi titik x komposisi aktif (Q-3), tandai "estimasi" di keterangan; data lama tanpa `jenjang`/`jenis_kelamin` masuk catatan "belum terklasifikasi" dan tidak diam-diam diisi |
| K3 | Saldo awal sebelum SiGAP dipakai tidak tercatat | saldo 30c/30d salah | Keputusan Q-2: Akuntan mengisi saldo awal sekali (kolom nullable); bila kosong, laporan menampilkan peringatan "saldo awal belum diisi" dan memakai 0 |
| K4 | Hitung ganda bahan baku (pembelian terverifikasi membuat pengeluaran) | total salah | aturan rekonsiliasi di Pemetaan; tes khusus |
| K5 | RAM server 1,9 GB; PDF dengan banyak foto nota | OOM, layanan lain ikut terkena | pustaka PDF ringan tanpa headless browser, perkecil gambar dengan GD, batasi jumlah lampiran per berkas, bulanan bisa lewat Job antrean (Keputusan Q-6: antrean) |
| K6 | Perluasan enum `KelompokSasaran`/`KategoriPengeluaran` | dasbor, agregat investor k=5, ambang anggaran, tool AI bisa berubah perilaku | R07-1 wajib audit pemakaian `::cases()`; tes regresi |
| K7 | Investor vs aturan k=5 | kebocoran sel kecil | Keputusan Q-4: investor hanya 30a, 30c, 30d A/B; 30b dan 30d-C 403; sel kecil 30a tersamar |
| K8 | Unduh PDF di Mini App Telegram (blob/anchor sering diblokir WebView) | fitur tampak rusak | Keputusan Q-10: kirim lewat bot (`sendDocument`); web menyusul dengan unduhan langsung |
| K9 | Kertas juknis F4 (210 x 330 mm); permintaan awal A4 | tidak "semirip mungkin" | Keputusan Q-11: F4; pastikan pustaka PDF mendukung ukuran kustom dan uji cetak |
| K10 | Menyerupai dokumen resmi namun bukan keluaran aplikasi resmi (AKSIS/Pelaporan Keuangan SPPG) | disalahpahami sebagai dokumen sah | catatan kaki kecil "Dihasilkan SiGAP SPPG" di luar area format (Q-12) |
| K11 | Jadwal: R07-3 dan R07-4 bersinggungan 7 Des; R07-1 berbarengan beban R-04 backend | geser | PM memantau; fondasi kolom bisa dimulai 26 Nov persis sesuai jadwal |
| K12 | Migrasi `sub_kategori`/kolom penerima pada tabel produksi | kunci tabel | hanya ADD COLUMN nullable, tanpa backfill; tes migrasi naik/turun |

## Keputusan (final, pengguna 2026-11-11)
Seluruh pertanyaan Q-1..Q-12 sudah dijawab; tidak ada item "menunggu keputusan" (Q-13 disetujui pengguna); satu-satunya persetujuan tersisa adalah dependensi PDF pada R07-3a.
| No | Keputusan | Dampak ke tugas |
|---|---|---|
| Q-1 | 30a: baris `Kebutuhan` = 15 kelompok penerima manfaat Bab 4.7 juknis (hlm 73); `Anggaran` = acuan bahan baku per porsi: Rp8.000 (Balita, PAUD/TK/RA, SD/MI kelas 1-3), Rp10.000 (lainnya), dapat diubah per SPPG; biaya per hari = porsi x anggaran. Tafsiran DISETUJUI. Insentif fasilitas SPPG Rp6.000.000 per hari, maksimal 6 hari/minggu, dihitung dari hari operasional, dapat diatur per SPPG | R07-1 (pengaturan per SPPG), R07-2, R07-3 |
| Q-2 | Saldo awal diisi manual oleh Akuntan sekali per SPPG (sebelum SiGAP dipakai); periode berikutnya otomatis. Hanya Akuntan/Admin yang dapat mengubah, dengan audit log. 30c/30d memakainya sebagai saldo awal periode pertama | R07-1 (kolom + Policy + audit), R07-2 |
| Q-3 | Porsi per kelompok per hari = estimasi (proporsi penerima aktif per kelompok x realisasi porsi), diberi tanda "estimasi" | R07-2, R07-3 |
| Q-4 | Investor hanya boleh mengunduh agregat: 30a, 30c, 30d bagian A dan B. 30b dan 30d bagian C tidak (403) | R07-2 (Policy), R07-4 (sembunyikan pilihan) |
| Q-5 | `insentif_relawan` -> Insentif Relawan; `energi`, `transportasi`, `peralatan`, `lainnya` -> Lain-lain (data lama). Data baru memakai sub-kategori | R07-1, R07-2 |
| Q-6 | PDF dibuat lewat antrean (job); hasil dikirim ke pengguna | R07-2/R07-3 (Job), R07-4 (status "sedang dibuat") |
| Q-7 | Bawaan: penandatangan 30c/30d-A = ketua yayasan; nama akuntan/kepala SPPG dari pengguna SPPG, pilihan di layar ekspor bila lebih dari satu | R07-2, R07-4 |
| Q-8 | Bawaan: ATS, Pendidik, Tenaga Kependidikan menjadi nilai baru `kelompok_sasaran` | R07-1 |
| Q-9 | Bawaan: salin label juknis tepat, kecuali salah ejaan jelas ("Pembelanjaan"); selisih dicatat di R07-5 | R07-3, R07-5 |
| Q-10 | Unduh lewat bot dengan `sendDocument`; versi web menyusul dengan unduhan langsung | R07-3 (kirim), R07-4 (tombol "Kirim ke Telegram"), R-04 web |
| Q-11 | Kertas **F4/Folio (210 x 330 mm)**, sama dengan juknis (menggantikan "A4" dari permintaan awal) | R07-3 |
| Q-12 | Bawaan: catatan kaki kecil "Dihasilkan oleh SiGAP SPPG, bukan keluaran aplikasi resmi BGN" di margin bawah | R07-3 |
| Q-13 (disetujui pengguna, final) | Penerima SLB/Santri/Pendidikan khusus lainnya/informal: `jenjang` setingkat 30b + `jenis_satuan`; Food Sample dan Uji Organoleptik = kolom porsi nullable di `log_produksis` (kosong = 0). R07-1 boleh memfinalkan bentuk kolom dan melapor ke PM | R07-1 |

Koreksi halaman: indeks PDF 30a=259; 30b=260-261; 30c=262-263; 30d=264-266 (A 264-265, B 265-266, C 266); nomor cetak = indeks + 1 untuk Lampiran 30.

## Opsi pustaka PDF (untuk R07-3a; belum ada dependensi dipasang)
Dockerfile sudah memuat ekstensi `gd`, `intl`, `zip`; `dom`/`mbstring` bawaan citra PHP. Opsi: (1) `dompdf/dompdf` (PHP murni, HTML/CSS 2.1, tabel baik, lisensi LGPL, memori sedang, lanskap per bagian perlu pemisahan dokumen); (2) `mpdf/mpdf` (HTML/CSS lebih lengkap, lanskap per halaman, lebih berat dan GPL-2); (3) `tecnickcom/tcpdf` atau `setasign/fpdf` (kontrol penuh dan paling ringan, penulisan tata letak manual lebih panjang). Dihindari: Chromium/headless dan wkhtmltopdf (biner eksternal, RAM besar). Bawaan PM: dompdf bila uji memori (30d-C 17 kolom x 31 baris + 20 foto, kertas F4) lolos; jika tidak, mPDF atau FPDF. Pemasangan dependensi menunggu persetujuan pengguna.
