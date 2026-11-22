# R-07 — Dashboard laporan per peran (tombol "Dashboard" di chat bot) + ekspor PDF

Status: rancangan usulan, belum dikerjakan. Tingkat penting (agregasi lintas fitur; ekspor PDF keuangan), dengan tinjauan keamanan untuk cakupan data (scope SPPG, multi-SPPG investor) dan akses berkas bukti. Prasyarat R-02, R-03, R-06 sudah selesai.

## Permintaan pengguna
Dashboard laporan yang bisa dibuka lewat tombol "Dashboard" di chat bot Telegram. Isi disesuaikan dengan laporan yang paling dibutuhkan tiap pengguna. Laporan harus bisa diekspor ke PDF.

## Keputusan pengguna (2026-10-25)
1. Kecukupan widget per peran: ditanyakan nanti (pengguna meninjau dengan pengguna lapangan). Daftar di bawah = draf awal.
2. Periode mengikuti laporan masing-masing (tidak ada satu periode bawaan untuk semua).
3. Ekspor PDF dibutuhkan untuk merekap semua laporan, terutama keuangan. Alasan: bila rekapan tidak rapi dan tidak sesuai format yang diminta kantor pusat BGN, dana tidak direimburse.
4. Pengesahan: sediakan **kolom kosong tanda tangan** di PDF (dicetak dan ditandatangani manual). Tidak perlu persetujuan digital.
5. **Foto nota wajib disertakan sebagai bukti** di dalam PDF rekap keuangan.

## Bentuk
- Tombol Dashboard di menu bot membuka dua tingkat: (1) ringkasan cepat di chat (5–8 angka kunci), (2) tombol "Lihat detail" membuka layar Dashboard di Mini App (Chart.js) sesuai peran.
- Satu endpoint `GET /api/v1/dashboard` yang isinya ditentukan peran, dengan filter periode sesuai tiap laporan.
- Semua angka dihitung dari data yang boleh dilihat peran itu (RBAC dan SppgScope). Data penerima manfaat hanya agregat (k=5).
- Agen AI dapat menjawab "ringkas dashboard saya" memakai tool baca yang sama.

## Isi per peran (draf awal)
| Peran | Widget utama |
|---|---|
| investor (multi-SPPG) | Pemilih SPPG + gabungan; porsi diproduksi vs didistribusikan hari ini; serapan anggaran vs rencana (penanda 80/100%); pengajuan menunggu persetujuan; stok kritis; insiden mutu dan keluhan kritis; tren 7/30 hari |
| kepala_sppg | Status jadwal produksi hari ini; porsi target vs realisasi; distribusi per titik layanan; mutu; keluhan terbuka; stok kritis; serapan anggaran; pengajuan menunggu investor; status rekap 17.00 |
| ahli_gizi | Menu hari ini dan status persetujuan; gizi vs standar; biaya per porsi; kebutuhan khusus (agregat); hasil pemeriksaan mutu |
| akuntan | Pencairan vs pengeluaran; serapan anggaran per kategori; pengeluaran menunggu; selisih dan tren |
| staf_gudang | Stok per bahan; di bawah minimum; mendekati kedaluwarsa; mutasi hari ini; pengiriman pemasok/koperasi |
| relawan dapur | Menu dan target porsi hari ini; status log produksi; checklist mutu belum diisi |
| relawan distribusi | Rute dan titik layanan hari ini; status kirim; bukti distribusi belum diunggah; armada dan pengemudi |
| admin | Operasional lintas SPPG (ringkas); pengguna aktif per peran; login gagal; antrean webhook/job gagal; draf AI tertunda |

## Ekspor PDF
- Tiap laporan punya tombol "Ekspor PDF"; dari bot, PDF dikirim sebagai dokumen.
- Prioritas utama: **rekap keuangan** (pencairan, pengeluaran per kategori, rincian transaksi, periode, total, **foto nota sebagai bukti**, **kolom kosong tanda tangan**). Format resmi BGN belum diketahui; jangan ditebak (lihat `r07-format-rekap-keuangan.md`).
- Rekap lain (produksi, distribusi, mutu, stok, keluhan): tata letak standar rapi dengan kop SPPG, periode, tanggal cetak, kolom tanda tangan kosong.
- Foto nota dari lampiran aman yang terhubung ke pengeluaran (`lampiran_ids`), diperkecil, diberi nomor rujukan sama dengan baris transaksi; pengeluaran tanpa foto ditandai "bukti belum ada" (di PDF dan dashboard).
- Teknis: pembuatan PDF di server (DomPDF atau HTML-ke-PDF), template Blade per laporan, dijalankan lewat job, nama berkas `jenis-laporan_sppg_periode.pdf`, hak akses sama dengan dashboard, tercatat di log audit siapa yang mengekspor.
- Keamanan: tanpa nama anak/data pribadi; PDF keuangan hanya untuk akuntan, kepala SPPG, investor, admin; tautan unduhan tidak publik; foto nota hanya lewat jalur lampiran yang diotorisasi.

## Pekerjaan teknis
1. Backend: `DashboardService` per peran, query agregat berindeks, cache singkat (60 detik), endpoint + OpenAPI + feature test per peran (lintas peran/SPPG = 403 atau kosong).
2. Ekspor PDF: `ReportExportService` + template Blade; template keuangan menyesuaikan format BGN setelah contoh diterima; tes isi, ukuran, hak akses.
3. Bot: ringkasan teks + tombol "Lihat detail" + pengiriman PDF.
4. Mini App: layar Dashboard (kartu angka + Chart.js, tema Telegram, tombol Ekspor PDF, indikator pengeluaran tanpa foto nota).
5. Tinjauan keamanan: kebocoran lintas SPPG/investor, agregasi yang membuka data pribadi, beban query, akses berkas PDF dan foto nota.

## Dibutuhkan dari pengguna
- Contoh format rekap keuangan resmi dari kantor pusat BGN (data fiktif).
- Daftar laporan lain yang diminta pusat beserta periodenya.
- Penandatangan di kolom tanda tangan dan jumlah kolomnya.

## Pertanyaan terbuka
1. Peninjauan widget per peran (ditunda).
2. Apakah foto nota wajib untuk setiap pengeluaran (tolak simpan tanpa foto) atau hanya peringatan?
