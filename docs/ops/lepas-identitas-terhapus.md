# Melepas identitas login akun terhapus lunak (R04-13)

Mulai perbaikan ini, menghapus pengguna (hapus lunak) mengosongkan `username` dan `phone` barisnya; `telegram_id` tetap agar daftar ulang memulihkan baris lama. Baris yang SUDAH terhapus sebelum perbaikan masih memegang nilai itu (indeks unik MySQL tetap menghitungnya), sehingga perlu dibersihkan sekali dengan perintah di bawah.

Perintah ini mengubah data dan **tidak dapat dibalik** (nilai lama tidak disimpan di mana pun). Hanya baris terhapus lunak yang disentuh; akun aktif dan nonaktif-belum-dihapus tidak berubah.

## Langkah di staging

1. Cadangkan database lebih dulu (mis. `mysqldump --single-transaction` ke berkas di luar repo) dan simpan di tempat aman.
2. Dry run (default, tidak mengubah apa pun, hanya mencetak jumlah baris):
   `docker compose exec app php artisan pengguna:lepas-identitas-terhapus`
3. Periksa jumlahnya wajar, lalu jalankan sungguhan:
   `docker compose exec app php artisan pengguna:lepas-identitas-terhapus --confirm`
4. Jalankan dry run lagi; jumlah harus 0 (perintah idempoten, aman diulang).

Ringkasan (jumlah baris saja, tanpa data pribadi) dicatat di audit log dengan event `sistem.identitas_terhapus_dilepas`.

## Pemulihan bila keliru

Pulihkan dari cadangan DB langkah 1. Tidak ada cara lain mengembalikan nilai yang sudah dikosongkan.
