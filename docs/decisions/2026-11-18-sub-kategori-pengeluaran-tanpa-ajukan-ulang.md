# ADR: Ubah sub-kategori pengeluaran disetujui tidak mengajukan ulang ke investor

- Tanggal: 2026-11-18
- Status: **Diterima** oleh pengguna (opsi A)
- Tier: T3 (mengubah aturan alur persetujuan R-06b-2 dan kontrol keuangan)
- Fitur terkait: No 14 Data Keuangan (pengeluaran), laporan BGN (Lampiran 30a-30d)
- Acuan: `app/Services/Keuangan/BudgetService.php` (`ubahPengeluaran`), `app/Services/Persetujuan/PersetujuanPengeluaranService.php`, ADR `2026-10-27-tinjauan-keamanan-r06b2.md`.
- Mengubah sebagian: aturan "perubahan apa pun -> diajukan ulang" di `docs/decisions/2026-10-27-tinjauan-keamanan-r06b2.md` (butir 2 tentang `PenjagaLampiranInduk`, baris 56). Setelah ADR ini, pengecualiannya hanya ubah `sub_kategori` saja pada pengeluaran `disetujui`; pesan 409 `pengeluaran_sudah_disetujui` kini menyebut "ubah nominal/tanggal/kategori/nota".

## Konteks

Sejak R-06b-2, setiap ubah pengeluaran (nominal, tanggal, kategori, nota, atau sub-kategori) menaikkan `versi`, mengembalikan status ke `diajukan`, membatalkan pengajuan lama, dan memberi tahu investor. Pengeluaran `disetujui` yang hanya dikoreksi sub-kategorinya (mis. "Listrik" menjadi "Gas") otomatis keluar dari serapan anggaran dan dari laporan BGN sampai investor menyetujui ulang, padahal realisasi belanjanya tidak berubah.

## Keputusan

Bila pengeluaran berstatus `disetujui` dan satu-satunya atribut yang benar-benar berubah adalah `sub_kategori`:

1. Status tetap `disetujui`; tidak ada pengajuan baru dan tidak ada notifikasi investor. Pengeluaran tetap dihitung ke anggaran dan laporan BGN.
2. Pembandingan memakai nilai lama vs baru setelah normalisasi (enum, tanggal `Y-m-d`, nominal rupiah bulat). Field lain yang dikirim dengan nilai sama dianggap tidak berubah. Unggah nota baru dianggap perubahan (tetap mengajukan ulang).
3. `versi` tetap naik (kunci optimistik; klien dengan versi basi tetap mendapat 409).
4. Jejak audit memakai mekanisme `Auditable` yang ada: event `updated` pada `audit_logs` berisi pengguna, waktu, `sppg_id`, `auditable_id`, dan `sub_kategori` lama/baru (plus `versi`), tanpa teks bebas atau data pribadi.

Tidak berubah: ubah nominal/tanggal/kategori/nota tetap mengajukan ulang; status `diajukan` dan `ditolak` berperilaku seperti sebelumnya; pengeluaran hasil pembelian bahan ditolak (409); sub-kategori tidak berlaku untuk `bahan_baku` dan `insentif_fasilitas` (422); Policy `update` (hanya akuntan), scope SPPG, dan kunci `versi` tidak dilonggarkan.

## Alasan

- Sub-kategori adalah klasifikasi pelaporan, bukan besaran uang: tidak mengubah serapan anggaran, ambang 80/100%, atau `melebihi_anggaran`.
- Laporan BGN harus mencerminkan realisasi belanja; mengeluarkan pengeluaran yang sudah disetujui dari laporan hanya karena koreksi label menyesatkan.
- Investor tetap punya jejak audit atas setiap koreksi.

## Konsekuensi dan risiko

- Akuntan dapat memindahkan pengeluaran `disetujui` antar-sub-kategori tanpa persetujuan baru. Mitigasi: audit append-only dan peran terbatas akuntan. Tidak ada notifikasi proaktif ke investor atas koreksi ini; bila diperlukan, tambahkan tampilan riwayat audit di layar investor (di luar lingkup ADR ini).
- Tidak ada batas jumlah koreksi per pengeluaran.

## Efek samping yang diketahui

1. `riwayat_persetujuan` pada respons pengeluaran tetap menampilkan `pengeluaran_versi` lama (versi saat pengajuan diputuskan), sedangkan `versi` pengeluaran sudah naik karena koreksi sub-kategori. Selisih ini disengaja (pengajuan tidak dibuat ulang); klien tidak boleh memakai `pengeluaran_versi` sebagai kunci optimistik, gunakan `versi` pengeluaran.
2. Mengosongkan `sub_kategori` (null) pada pengeluaran operasional memindahkan baris pengeluaran itu ke "Lain-lain" (dengan keterangan kategori lama) di laporan BGN Lampiran 30b, sesuai aturan data lama tanpa sub-kategori.

## Keputusan tambahan (pengguna, 2026-11-18)

- Mengosongkan `sub_kategori` ke null pada pengeluaran `disetujui` tetap TANPA ajukan ulang (sama seperti mengganti sub-kategori).
- Unggah nota baru bersama perubahan `sub_kategori` tetap mengajukan ulang.
- Notifikasi proaktif dan layar riwayat audit untuk investor DITUNDA ke issue pasca-deadline; tidak termasuk PR ini. Sementara itu jejak koreksi hanya tersedia di `audit_logs`.
