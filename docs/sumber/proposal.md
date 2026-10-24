**Disusun oleh**

**102022400019 - Daven Bagus Putra Santoso**

**102022430006 - Hilmy Herdiansyah Shiddiq**

**102022400327 - Mutiara Qolbu Thohirah**

**102022400147 - Muhammad Dhimas Pranaya**

**102022400345 - Prabowo Kuntjoro Analta Ruswanto**

**Selasa, 6 Oktober 2026**

**Daftar Isi**

[**1. Pendahuluan 1**](#pendahuluan)

> [1.1 Latar Belakang 1](#latar-belakang)
>
> [1.2 Tujuan 3](#tujuan)
>
> [1.3 Output 3](#output)

[**2. Deskripsi Produk 6**](#deskripsi-produk)

> [2.1 Usulan Solusi 6](#usulan-solusi)
>
> [2.2 Deskripsi Produk 6](#deskripsi-produk-1)
>
> [2.3 Proses Bisnis 8](#proses-bisnis)
>
> [2.3.1 Proses Bisnis Saat Ini (Manual) 8](#proses-bisnis-saat-ini-manual)
>
> [2.3.2 Proses Bisnis Usulan (dengan SiGAP SPPG) 9](#proses-bisnis-usulan-dengan-sigap-sppg)
>
> [2.3.3 Analisis Aktivitas yang Didukung Sistem 12](#analisis-aktivitas-yang-didukung-sistem)

[**3. Kebutuhan Sistem 14**](#_Toc_sigap_12)

> [3.1 Kebutuhan Fungsional 14](#_Toc_sigap_13)
>
> [3.1.1 Daftar Kebutuhan 14](#_Toc_sigap_14)
>
> [3.1.2 Karakteristik Pengguna 15](#karakteristik-pengguna)
>
> [3.2 Kebutuhan Non Fungsional 17](#kebutuhan-non-fungsional)
>
> [3.3 Kebutuhan Teknis 18](#kebutuhan-teknis)

[**4. Rancangan Sistem 20**](#rancangan-sistem)

> [4.1 Use Case Diagram 20](#use-case-diagram)
>
> [4.2 Arsitektur Sistem 22](#arsitektur-sistem)
>
> [4.3 Rancangan ERD 28](#_Toc_sigap_21)
>
> [4.4 Class Diagram 31](#_Toc_sigap_22)
>
> [4.5 Mockup (Rancangan Antarmuka) 32](#_Toc_sigap_23)
>
> [4.6 Risiko dan Mitigasi 38](#_Toc_sigap_24)

[**5. Metode Pengembangan 40**](#metode-pengembangan)

> [5.1 Jadwal Pengembangan 40](#jadwal-pengembangan)
>
> [5.2 Tim Pengembang 43](#_Toc_sigap_27)

# Pendahuluan

## Latar Belakang

Ketahanan pangan dan pemenuhan gizi merupakan fondasi pembangunan sumber daya manusia. Agenda *Sustainable Development Goals* (SDGs) menempatkan isu ini pada Tujuan 2 (*Zero Hunger*/Tanpa Kelaparan), khususnya target 2.1 tentang akses seluruh masyarakat terhadap pangan yang aman, bergizi, dan cukup, serta target 2.2 tentang penghapusan segala bentuk malnutrisi, termasuk stunting pada anak balita \[sitasi\]. Isu tersebut juga berkaitan erat dengan Tujuan 3 (*Good Health and Well-Being*/Kehidupan Sehat dan Sejahtera), karena kualitas asupan gizi dan keamanan pangan berpengaruh langsung terhadap kesehatan anak, ibu hamil, dan ibu menyusui \[sitasi\].

Di Indonesia, upaya tersebut diwujudkan melalui program Makan Bergizi Gratis (MBG) yang mulai dijalankan pada Januari 2025 dan dikoordinasikan oleh Badan Gizi Nasional (BGN) \[sitasi\]. Ujung tombak operasional program ini adalah Satuan Pelayanan Pemenuhan Gizi (SPPG), yaitu dapur yang setiap hari merencanakan menu, mengadakan dan menyimpan bahan baku, memasak dalam skala besar, memeriksa mutu, mendistribusikan makanan ke titik layanan (sekolah, PAUD, posyandu, dan sejenisnya), serta mempertanggungjawabkan penggunaan dana. Seluruh rangkaian ini berlangsung dalam jendela waktu yang sempit, umumnya sejak dini hari hingga makanan diterima penerima manfaat pada pagi atau siang hari.

Pada banyak dapur, pencatatan operasional masih dilakukan secara manual atau semi-manual, misalnya buku catatan gudang, lembar kertas pemeriksaan mutu, berkas *spreadsheet* yang disimpan di perangkat masing-masing, serta foto bukti pengiriman yang tersebar di galeri ponsel dan grup percakapan. Pola ini menimbulkan beberapa masalah yang saling berkaitan:

1.  **Data terfragmentasi dan tidak sinkron.** Stok gudang, jadwal produksi, dan realisasi porsi dicatat di tempat berbeda sehingga Kepala SPPG sulit memperoleh gambaran kondisi dapur secara utuh dan tepat waktu.

2.  **Risiko keamanan pangan sulit ditelusuri.** Bila muncul laporan makanan basi atau gejala sakit setelah makan, penelusuran ke menu, batch produksi, hasil pemeriksaan mutu, dan armada pengantar memerlukan waktu karena catatannya tidak saling terhubung. Pemberitaan mengenai dugaan kasus keracunan pangan pada pelaksanaan MBG menunjukkan pentingnya ketertelusuran (*traceability*) ini \[sitasi\].

3.  **Perencanaan bahan dan porsi tidak presisi.** Tanpa perhitungan kebutuhan bahan dari menu dan target porsi, dapur berisiko kekurangan bahan, kelebihan stok, atau memakai bahan yang mendekati kedaluwarsa.

4.  **Akuntabilitas keuangan lemah.** Pencairan dana dan pengeluaran operasional yang direkap secara manual menyulitkan pemantauan sisa anggaran per periode dan menambah beban penyusunan laporan pertanggungjawaban.

5.  **Umpan balik lapangan lambat ditindaklanjuti.** Keluhan dari guru, orang tua, atau penerima manfaat sering disampaikan secara lisan atau lewat pesan pribadi sehingga tidak terdokumentasi dan tidak memiliki status tindak lanjut yang jelas.

Pengembangan sistem informasi berbasis web konvensional dapat menjawab sebagian masalah tersebut, tetapi menghadapi **hambatan adopsi**. Personel SPPG sangat beragam, mulai dari relawan dapur dan pengantar dengan literasi digital yang bervariasi hingga Kepala SPPG, Ahli Gizi, dan Akuntan. Mewajibkan mereka memasang aplikasi baru, mengingat alamat situs, serta mengelola kata sandi tambahan berpotensi menurunkan tingkat penggunaan, terutama ketika pekerjaan dapur menuntut kecepatan.

Karena itu, SiGAP SPPG dirancang agar **seluruh interaksi pengguna berlangsung di dalam Telegram**. Telegram merupakan aplikasi pesan yang tersedia gratis di Android, iOS, desktop, dan web, serta dikenal luas di Indonesia \[sitasi\]. Platform ini menyediakan **Telegram Mini Apps** (*Web Apps*), yaitu antarmuka web penuh (formulir, tabel, dasbor, unggah foto) yang dibuka langsung dari bot tanpa instalasi aplikasi tambahan. Identitas pengguna dijamin oleh Telegram melalui data peluncuran (initData) bertanda tangan kriptografis, sehingga pengguna tidak perlu membuat kata sandi baru. Bot Telegram juga dapat mengirimkan notifikasi proaktif, misalnya stok menipis, hasil pemeriksaan mutu tidak layak, atau keluhan kritis, ke orang yang tepat secara langsung. Untuk menekan beban input di sela pekerjaan dapur, SiGAP SPPG menambahkan **lapisan Agen AI**: pengguna cukup mengetik kalimat biasa, memotret nota, atau mengirim pesan suara di percakapan bot. Agen AI berbasis model bahasa besar (*Large Language Model*/LLM) dengan kemampuan pemanggilan fungsi (*function calling*) mengekstrak data terstruktur, lalu membuka formulir Mini App yang sudah terisi untuk diperiksa dan dikonfirmasi pengguna (*human-in-the-loop*). Data tetap divalidasi oleh backend dan tunduk pada hak akses peran. Dengan pendekatan ini, digitalisasi pencatatan dapur MBG dapat diterapkan dengan hambatan adopsi minimal, dari relawan hingga manajemen, sekaligus mendukung pencapaian SDG 2 dan SDG 3 melalui operasional dapur yang lebih tertib, aman, dan akuntabel.

## Tujuan

Tujuan pengembangan SiGAP SPPG adalah sebagai berikut.

- Menyediakan sistem informasi terpadu untuk mencatat dan memantau seluruh siklus operasional SPPG, mulai dari bahan baku, menu dan gizi, produksi, pemeriksaan mutu, distribusi, keuangan, hingga umpan balik, dalam satu basis data terpusat.

- Menurunkan hambatan adopsi teknologi dengan menghadirkan antarmuka berupa Telegram Mini App yang dapat diakses tanpa instalasi aplikasi baru dan tanpa kata sandi tambahan.

- Meningkatkan keamanan pangan dan ketertelusuran melalui pencatatan pemeriksaan mutu per jadwal produksi, bukti foto distribusi, serta keterhubungan keluhan dengan batch produksi terkait.

- Membantu perencanaan produksi yang presisi melalui validasi target porsi terhadap kapasitas SPPG dan estimasi kebutuhan bahan berdasarkan komposisi menu.

- Memperkuat akuntabilitas keuangan melalui pencatatan pencairan dana dan pengeluaran operasional per kategori, beserta pemantauan sisa anggaran per periode.

- Mempercepat respons terhadap kondisi kritis melalui notifikasi otomatis bot Telegram berbasis peran.

- Mempercepat dan mempermudah input data melalui Agen AI yang mengubah teks, foto nota, dan pesan suara menjadi draf formulir terstruktur pada 16 fitur CRUD SiGAP SPPG, dengan konfirmasi pengguna sebelum data disimpan.

- Menerapkan kontrol akses berbasis peran (*Role-Based Access Control*/RBAC) agar setiap data hanya dapat dimodifikasi oleh personel yang berwenang.

## Output

Hasil akhir yang ditargetkan dari pengembangan ini adalah:

- **Bot Telegram SiGAP SPPG** yang menangani *onboarding* pengguna melalui tautan undangan (*deep link*), membuka Mini App melalui tombol menu, dan mengirimkan notifikasi operasional berbasis peran.

- **Telegram Mini App** (HTML/CSS/JavaScript dengan telegram-web-app.js) yang menyediakan antarmuka untuk **16 fitur CRUD** sesuai PBI pada Subbab 5.2: (1) Data Pengguna (Login Akun) sebagai tanggung jawab seluruh kelompok; (2) Notifikasi Perjalanan Mobil ke Tujuan; (3) Data SPPG; (4) Data Titik Layanan; (5) Data Penerima Manfaat; (6) Data Bahan Baku; (7) Data Pemasok; (8) Data Menu dan Gizi; (9) Data Jadwal Produksi dan Distribusi; (10) Data Log Produksi Harian; (11) Data Pemeriksaan Mutu dan Keamanan Pangan; (12) Data Armada dan Pengemudi; (13) Data Bukti Distribusi; (14) Data Keuangan (pencairan dana dan pengeluaran operasional); (15) Data Feedback dan Keluhan; serta (16) Laporan Rekapitulasi Harian Operasional, dilengkapi dasbor pemantauan untuk Kepala SPPG.

- **Backend REST API dan *webhook* Telegram** berbasis Laravel (pola MVC) dengan autentikasi validasi HMAC initData, otorisasi RBAC, layanan unggah foto bukti, antrean (*queue*) notifikasi, dan penjadwal (*scheduler*) pemeriksaan stok serta kedaluwarsa.

- **Lapisan Agen AI** yang menerima teks, foto, dan pesan suara di *chat* bot, memanggil LLM eksternal dengan *tool* yang disaring sesuai peran, menghasilkan draf untuk seluruh fitur CRUD, dan membuka formulir Mini App terisi untuk konfirmasi, lengkap dengan log audit interaksi AI dan *fallback* ke formulir manual.

- **Basis data MySQL** yang dinormalisasi sesuai rancangan ERD, beserta skrip migrasi dan *seeder*.

- **Paket deployment Docker** (Docker Compose: nginx, php-fpm, mysql, queue worker, scheduler) yang siap dijalankan di VPS ber-HTTPS.

- **Dokumentasi**: spesifikasi API (OpenAPI), panduan pengguna per peran, dan laporan pengujian (unit, fitur, dan UAT).

**Batasan pengembangan:**

- Pengguna hanya berinteraksi melalui aplikasi Telegram. Tidak disediakan aplikasi *mobile* native maupun portal web publik di luar Telegram.

- Antarmuka utama berupa Mini App, bukan perintah teks (*chat command*). *Chat* bot hanya dipakai untuk /start (*onboarding*), /bantuan, notifikasi, dan masukan bahasa alami ke Agen AI. Bot hanya melayani *chat* pribadi, tidak melayani grup.

- Agen AI hanya **menyiapkan draf** dan menjawab pertanyaan baca. Tidak ada data yang tersimpan, berubah, atau terhapus tanpa konfirmasi pengguna di Mini App. Akurasi ekstraksi bergantung pada kualitas foto atau suara, dan fitur ini bergantung pada ketersediaan layanan LLM eksternal (formulir manual selalu tersedia).

- Sistem tidak terintegrasi langsung dengan sistem resmi BGN, perbankan, atau sistem pembayaran. Data pencairan dana dan pengeluaran dicatat manual berdasarkan dokumen sumber.

- Kandungan gizi per porsi diinput oleh Ahli Gizi berdasarkan hasil perhitungan dengan acuan Tabel Komposisi Pangan Indonesia (TKPI) \[sitasi\]. Sistem memvalidasi hasil tersebut terhadap standar per kelompok sasaran, tetapi tidak menghitung gizi otomatis dari basis data komposisi pangan.

- Pengelolaan stok menggunakan pencatatan mutasi (masuk/keluar/penyesuaian) dengan satu tanggal kedaluwarsa terdekat per bahan. Pelacakan per *batch* (FIFO/FEFO penuh) di luar ruang lingkup.

- Mini App memerlukan koneksi internet. Formulir yang belum terkirim disimpan sementara di perangkat (*draft*) dan dapat dikirim ulang saat koneksi tersedia, tetapi tidak ada mode luring penuh.

- Data pribadi penerima manfaat dibatasi pada data minimum yang diperlukan operasional (tanpa NIK), sejalan dengan prinsip UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi \[sitasi\].

# Deskripsi Produk

## Usulan Solusi

Solusi yang diusulkan adalah **SiGAP SPPG**, sistem informasi operasional dapur MBG yang dapat diakses sepenuhnya melalui Telegram. Sistem terdiri atas empat komponen: (1) **Bot Telegram** sebagai gerbang masuk, kanal notifikasi, dan kanal masukan bahasa alami; (2) **Telegram Mini App** sebagai antarmuka kerja harian; (3) **Agen AI** yang mengubah teks, foto, dan suara menjadi draf data terstruktur; serta (4) **backend Laravel** yang menyediakan REST API, *webhook* Telegram, serta basis data MySQL terpusat.

Keterkaitan antara permasalahan dan solusi dijabarkan pada Tabel 1.

| **No** | **Permasalahan (Latar Belakang)**                 | **Solusi pada SiGAP SPPG**                                                                                                                                                                                    |
|--------|---------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1      | Data operasional terfragmentasi dan tidak sinkron | Basis data terpusat untuk 16 fitur CRUD yang saling berelasi; Kepala SPPG memantau melalui dasbor Mini App secara langsung (*real-time*).                                                                     |
| 2      | Ketertelusuran keamanan pangan lemah              | Pemeriksaan mutu dicatat per jadwal produksi; distribusi hanya dapat dikonfirmasi berangkat bila hasil mutu "Layak"; keluhan dihubungkan ke jadwal produksi dan titik layanan; bukti foto tersimpan terpusat. |
| 3      | Perencanaan bahan dan porsi tidak presisi         | Validasi target porsi terhadap kapasitas SPPG; estimasi kebutuhan bahan = komposisi menu (gram/porsi) × target porsi, dibandingkan dengan stok; peringatan stok minimum dan kedaluwarsa.                      |
| 4      | Akuntabilitas keuangan lemah                      | Fitur Data Keuangan (pencairan dana dan pengeluaran operasional) dengan kategori, unggahan nota, rekap per periode, dan peringatan saat pengeluaran mencapai 80% dan 100% dana yang dicairkan.                |
| 5      | Umpan balik lambat ditindaklanjuti                | Formulir keluhan terstruktur dengan prioritas; kategori "makanan basi" atau "gejala sakit" otomatis berprioritas kritis dan memicu notifikasi segera; status tindak lanjut tercatat.                          |
| 6      | Hambatan adopsi aplikasi baru                     | Antarmuka dibuka dari Telegram yang sudah terpasang; autentikasi otomatis melalui initData tanpa kata sandi; tampilan mengikuti tema Telegram pengguna.                                                       |
| 7      | Beban input manual tinggi di sela pekerjaan dapur | Agen AI menerima teks, foto nota, atau pesan suara, lalu menyiapkan formulir terisi; pengguna cukup memeriksa dan menekan "Simpan".                                                                           |

## Deskripsi Produk

SiGAP SPPG adalah sistem informasi berbasis Telegram Mini App untuk mendukung pencatatan, pemantauan, dan pengambilan keputusan pada operasional harian SPPG. Pengguna memulai dari bot SiGAP, lalu menekan tombol menu "Buka SiGAP" untuk membuka Mini App yang menampilkan menu sesuai peran masing-masing.

**Fungsi utama:**

1.  **Autentikasi dan *onboarding* tanpa kata sandi.** Admin mendaftarkan pengguna dan peran. Sistem membuat tautan undangan sekali pakai (t.me/\<bot\>?start=\<token\>). Ketika tautan dibuka, bot mengaitkan akun Telegram dengan akun SiGAP. Setiap kali Mini App dibuka, backend memvalidasi tanda tangan HMAC initData sebelum menerbitkan token sesi.

2.  **Pengelolaan data master**: pengguna dan peran, profil serta kapasitas SPPG, titik layanan, penerima manfaat (kelompok sasaran dan catatan alergi), bahan baku beserta mutasi stok, pemasok lokal, dan armada.

3.  **Perencanaan dan pencatatan produksi**: menu dan kandungan gizi per porsi dengan validasi standar per kelompok sasaran, jadwal produksi dengan validasi kapasitas dan estimasi kebutuhan bahan, serta log produksi harian (realisasi porsi, sisa makanan, kendala).

4.  **Pemeriksaan mutu dan keamanan pangan**: suhu makanan, kebersihan dapur dan petugas, uji organoleptik, pencatatan sampel makanan yang disimpan, foto, dan keputusan Layak/Tidak Layak yang menjadi syarat keberangkatan distribusi.

5.  **Distribusi**: jadwal distribusi per titik layanan dan armada, serta bukti distribusi berupa foto, waktu berangkat dan tiba, jumlah porsi diterima, nama penerima (PIC), dan koordinat opsional. Saat armada berangkat, relawan distribusi (kurir) memicu Notifikasi Perjalanan Mobil ke PIC titik layanan tujuan beserta estimasi kedatangan.

6.  **Keuangan**: pencairan dana per periode, pengeluaran operasional per kategori dengan nota, dan rekap sisa anggaran.

7.  **Feedback/keluhan**: pencatatan terstruktur, prioritas otomatis, alur status (Baru → Diproses → Selesai), dan catatan tindak lanjut.

8.  **Notifikasi bot dan dasbor**: peringatan stok, kedaluwarsa, mutu, distribusi, anggaran, keluhan kritis, dan Laporan Rekapitulasi Harian Operasional pukul 17.00 WIB untuk Kepala SPPG dan pengawas.

9.  **Agen AI (input bahasa alami)**: pengguna mengirim teks ("terima beras 200 kg dari Tani Makmur, kedaluwarsa Maret"), foto nota atau dokumen, maupun pesan suara. Agen AI memanggil LLM dengan *tool* yang sesuai peran pengguna, mengekstrak data, lalu membalas ringkasan dengan tombol "Periksa & Simpan" yang membuka formulir Mini App terisi. Pertanyaan baca ("stok minyak goreng tinggal berapa?") dijawab langsung sesuai hak akses. Seluruh interaksi AI tercatat di log audit.

**Keunggulan Telegram Mini App dibandingkan aplikasi web atau *mobile* konvensional maupun bot berbasis perintah teks:**

- **Tanpa instalasi dan tanpa kata sandi.** Mini App berjalan di dalam Telegram dan identitas pengguna diverifikasi melalui initData bertanda tangan, sehingga *onboarding* relawan cukup dengan satu ketukan tautan undangan.

- **Antarmuka kaya, bukan perintah teks.** Formulir dengan validasi, daftar dengan filter, *date picker*, unggah foto dari kamera, dan grafik dasbor tidak dapat diwujudkan secara layak melalui perintah teks bot.

- **Pengalaman terasa *native*.** Komponen MainButton, BackButton, HapticFeedback, showPopup/showConfirm, dan themeParams (mode terang/gelap mengikuti Telegram) membuat tampilan konsisten dan mudah dipelajari.

- **Notifikasi terintegrasi.** Pesan notifikasi dapat memuat tombol *inline* bertipe web_app yang langsung membuka layar terkait, misalnya detail keluhan, sehingga alur "terima peringatan, lalu tindak lanjuti" berlangsung tanpa berpindah aplikasi.

- **Kombinasi percakapan dan formulir.** Agen AI di *chat* mempercepat input, sedangkan Mini App menyediakan tampilan konfirmasi yang jelas dan dapat dikoreksi. Kecepatan bahasa alami diperoleh tanpa mengorbankan ketelitian data.

- **Lintas platform dengan satu basis kode.** Mini App yang sama berjalan di Telegram Android, iOS, desktop, dan web.

- **Pembaruan instan.** Perubahan antarmuka cukup di-*deploy* di server tanpa proses rilis ke toko aplikasi.

## Proses Bisnis

### Proses Bisnis Saat Ini (Manual)

Alur operasional SPPG sebelum menggunakan sistem dapat diuraikan sebagai berikut:

1.  **Perencanaan menu.** Ahli Gizi menyusun siklus menu dan menghitung gizi di dokumen atau *spreadsheet* pribadi, lalu membagikannya dalam bentuk cetak atau foto ke grup percakapan.

2.  **Pengadaan dan penerimaan bahan.** Staf Gudang memperkirakan kebutuhan bahan berdasarkan pengalaman, memesan ke pemasok melalui telepon atau pesan, dan mencatat barang masuk di buku gudang. Tanggal kedaluwarsa dan stok minimum tidak dipantau secara sistematis.

3.  **Penjadwalan produksi.** Target porsi ditentukan dari rekap jumlah sasaran yang dikumpulkan manual dari setiap titik layanan. Jadwal masak diumumkan secara lisan atau melalui grup.

4.  **Produksi.** Relawan dapur memasak sesuai arahan. Realisasi porsi, sisa makanan, dan kendala jarang dicatat atau hanya dicatat di kertas.

5.  **Pemeriksaan mutu.** Pemeriksaan suhu, kebersihan, dan sampel dicatat di formulir kertas (bila ada) yang mudah hilang dan tidak terhubung dengan batch produksi.

6.  **Distribusi.** Petugas mengantar makanan dan mengirim foto bukti ke grup percakapan. Rekap jumlah porsi per titik dilakukan manual pada akhir hari.

7.  **Keuangan.** Akuntan mengumpulkan nota fisik dan foto nota, lalu merekapnya di *spreadsheet*. Sisa anggaran baru diketahui saat rekap periodik.

8.  **Umpan balik.** Keluhan diterima secara lisan atau melalui pesan pribadi, tidak terdokumentasi, dan tidak memiliki status tindak lanjut.

**Analisis kelemahan:** proses manual menyebabkan (a) keterlambatan informasi bagi pengambil keputusan, (b) duplikasi pencatatan dan risiko kesalahan rekap, (c) tidak adanya ketertelusuran antara bahan, produksi, mutu, distribusi, dan keluhan, (d) bukti foto yang tercecer dan sulit diaudit, serta (e) tidak adanya kontrol akses terhadap perubahan data.

### Proses Bisnis Usulan (dengan SiGAP SPPG)

<img src="docs/sumber/media/media/image1.png" style="width:6.7in;height:3.98657in" />

<img src="docs/sumber/media/media/image2.png" style="width:6.7in;height:4.05378in" />

Uraian alur usulan:

1.  **Onboarding.** Admin membuat akun pengguna (nama, peran, SPPG, unit tugas). Sistem menghasilkan tautan undangan sekali pakai yang berlaku 72 jam. Pengguna membuka tautan, bot menerima /start \<token\> melalui *webhook*, lalu mengaitkan telegram_user_id dengan akun tersebut. Kepala SPPG kemudian memperbarui Data SPPG (profil dan kapasitas) serta Data Titik Layanan beserta PIC-nya, sedangkan Admin mencatat penerima manfaat beserta catatan alergi.

2.  **Menu dan gizi.** Ahli Gizi menginput menu, komposisi bahan (gram per porsi), dan kandungan gizi per porsi. Sistem memberi peringatan bila nilai gizi berada di luar rentang standar kelompok sasaran yang dikonfigurasi.

3.  **Jadwal produksi.** Jadwal dibuat untuk tanggal tertentu dengan menu dan target porsi. Sistem menolak target yang melebihi kapasitas harian SPPG dan menampilkan estimasi kebutuhan bahan dibandingkan stok. Kekurangan bahan memicu notifikasi ke Staf Gudang.

4.  **Gudang.** Staf Gudang mencatat mutasi masuk (dari pemasok, termasuk tanggal kedaluwarsa) dan mutasi keluar (ke dapur, dengan referensi jadwal produksi). Penjadwal harian memeriksa stok minimum dan bahan yang akan kedaluwarsa dalam 3 hari.

5.  **Produksi dan log.** Relawan dapur mencatat realisasi porsi, sisa makanan, kendala, dan foto pada log produksi yang terhubung ke jadwal.

6.  **Pemeriksaan mutu.** Ahli Gizi (berperan sebagai pengawas mutu) mencatat hasil pemeriksaan. Bila hasil "Tidak Layak", status distribusi untuk jadwal tersebut terkunci dan notifikasi dikirim.

7.  **Distribusi.** Kepala SPPG mengalokasikan armada dan pengemudi untuk setiap rute. Saat berangkat, relawan distribusi (kurir) menekan "Berangkat & Kirim Notifikasi" sehingga sistem mengirim Notifikasi Perjalanan Mobil beserta estimasi kedatangan ke PIC titik layanan tujuan. Setibanya di lokasi, relawan mengunggah bukti distribusi (foto, berita acara serah terima, porsi diterima, dan nama PIC). Bila bukti belum masuk 60 menit setelah jam rencana tiba, Kepala SPPG menerima peringatan.

8.  **Feedback/keluhan.** Admin atau Relawan mencatat masukan dari penerima, guru, atau orang tua. Kategori kritis langsung dieskalasi ke Kepala SPPG dan Ahli Gizi beserta tautan ke jadwal produksi dan hasil mutu terkait.

9.  **Keuangan.** Akuntan mencatat pencairan dana per periode dan setiap pengeluaran beserta nota. Sistem menghitung sisa anggaran dan memberi peringatan pada ambang 80% dan 100%.

10. **Input cepat via Agen AI (opsional di setiap langkah).** Pada langkah 2–9, pengguna dapat mengirim teks, foto, atau suara ke bot sebagai pengganti mengetik formulir dari awal. Agen AI menghasilkan draf dan formulir terisi, kemudian data tersimpan setelah pengguna menekan "Simpan" (Gambar 2).

11. **Pemantauan.** Kepala SPPG memantau dasbor (porsi target vs realisasi, status mutu, status distribusi, keluhan terbuka, dan serapan anggaran) serta menerima Laporan Rekapitulasi Harian Operasional otomatis pukul 17.00 WIB yang juga dikirim ke pengawas terdaftar.

### Analisis Aktivitas yang Didukung Sistem

**Tabel 2 Pemetaan aktivitas proses bisnis ke modul sistem**

| **Aktivitas**                     | **Modul SiGAP**                              | **Peran Utama**                         | **Analisis Nilai Tambah**                                                                                             |
|-----------------------------------|----------------------------------------------|-----------------------------------------|-----------------------------------------------------------------------------------------------------------------------|
| Pendaftaran personel & hak akses  | Pengguna                                     | Admin                                   | Hak akses terkontrol; onboarding satu ketukan tanpa kata sandi                                                        |
| Profil & kapasitas dapur          | SPPG                                         | Kepala SPPG                             | Kapasitas menjadi batas validasi target porsi                                                                         |
| Pemetaan lokasi & sasaran         | Titik Layanan, Penerima Manfaat              | Admin, Kepala SPPG                      | Jumlah sasaran per titik menjadi dasar alokasi porsi; catatan alergi tersedia bagi Ahli Gizi                          |
| Pengadaan & penyimpanan bahan     | Bahan Baku, Pemasok                          | Staf Gudang                             | Stok berbasis mutasi; peringatan stok minimum & kedaluwarsa                                                           |
| Perencanaan menu                  | Menu dan Gizi                                | Ahli Gizi                               | Gizi per porsi terstandar dan tervalidasi                                                                             |
| Penjadwalan & pencatatan produksi | Jadwal Produksi, Log Produksi                | Kepala SPPG, Relawan (dapur)            | Target vs realisasi terukur; sisa makanan terpantau                                                                   |
| Kontrol mutu                      | Pemeriksaan Mutu                             | Ahli Gizi                               | Menjadi gerbang keberangkatan distribusi; ketertelusuran per batch                                                    |
| Pengantaran                       | Armada, Bukti Distribusi                     | Relawan (distribusi), Kepala SPPG       | Bukti foto & waktu tersimpan terpusat; keterlambatan terdeteksi                                                       |
| Koordinasi kedatangan armada      | Notifikasi Perjalanan Mobil                  | Relawan (distribusi/kurir), Kepala SPPG | PIC titik layanan mengetahui estimasi tiba sehingga serah terima lebih siap dan cepat                                 |
| Pertanggungjawaban dana           | Data Keuangan (pencairan dana & pengeluaran) | Akuntan                                 | Rekap otomatis per kategori & periode; nota terdigitalisasi; sisa anggaran terpantau                                  |
| Penanganan masukan                | Feedback/Keluhan                             | Admin, Relawan, Kepala SPPG             | Eskalasi otomatis keluhan kritis; status tindak lanjut jelas                                                          |
| Pelaporan kinerja harian          | Laporan Rekapitulasi Harian Operasional      | Kepala SPPG, pengawas                   | Rekap porsi, mutu, distribusi, keluhan, dan anggaran terkirim otomatis pukul 17.00 WIB tanpa rekap manual             |
| Input data lintas fitur           | Agen AI (seluruh fitur CRUD)                 | Semua peran                             | Waktu input berkurang (foto nota, pesan suara); kualitas data terjaga melalui konfirmasi manusia dan validasi backend |

Aktivitas fisik seperti memasak, pemorsian, dan pengantaran tetap dilakukan manual. Sistem mendigitalkan **pencatatan, validasi, notifikasi, dan pemantauan** di sekitar aktivitas tersebut, sehingga informasi mengalir seiring dengan pekerjaan fisik tanpa menambah tahap kerja yang signifikan.

# 

## 

### 

<span id="_Toc_sigap_12" class="anchor"></span>Kebutuhan Sistem<span id="_Toc_sigap_13" class="anchor"></span>Kebutuhan Fungsional<span id="_Toc_sigap_14" class="anchor"></span>Daftar KebutuhanKebutuhan fungsional SiGAP SPPG dirangkum pada Tabel 3. FR-01 sampai FR-10 serta FR-12 dan FR-13 diturunkan dari 16 fitur CRUD pada PBI Subbab 5.2, sedangkan FR-11 mencakup lapisan Agen AI. Pemetaan lengkap fitur, FR, use case, dan mockup ditunjukkan pada Tabel 15. Seluruh kebutuhan diakses pengguna melalui Telegram Mini App, kecuali *onboarding* dan notifikasi yang berlangsung di percakapan bot.

| **ID** | **Kebutuhan Fungsional**                                 | **Deskripsi**                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
|--------|----------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| FR-01  | Autentikasi Telegram & onboarding                        | Sistem memvalidasi initData Mini App menggunakan HMAC-SHA256 (kunci rahasia = HMAC-SHA256 dengan kunci "WebAppData" atas token bot), memeriksa kesegaran auth_date (≤ 1 jam), mencocokkan user.id dengan akun aktif, lalu menerbitkan token sesi (Laravel Sanctum, berlaku 8 jam). Pengguna baru dikaitkan melalui tautan undangan /start \<token\> sekali pakai (berlaku 72 jam). Akun Telegram yang tidak terdaftar ditolak (HTTP 403).                                                                                                                                                          |
| FR-02  | Manajemen pengguna & RBAC                                | Admin dapat menambah, melihat, mengubah, dan menonaktifkan pengguna serta menetapkan peran (Admin, Kepala SPPG, Ahli Gizi, Akuntan, Staf Gudang, Relawan), SPPG, dan unit tugas (dapur/distribusi). Setiap endpoint menerapkan *policy* berdasarkan matriks hak akses (Tabel 5) dan membatasi data pada SPPG pengguna.                                                                                                                                                                                                                                                                             |
| FR-03  | Pengelolaan SPPG, titik layanan & penerima manfaat       | CRUD profil SPPG (nama, alamat, mitra/yayasan, kapasitas porsi per hari, status operasional), titik layanan (jenis, alamat, jarak dari SPPG, jumlah sasaran, PIC), dan penerima manfaat (kelompok sasaran, kelas/keterangan, catatan alergi) tanpa NIK.                                                                                                                                                                                                                                                                                                                                            |
| FR-04  | Pengelolaan bahan baku, stok & pemasok                   | CRUD bahan baku (kategori, satuan, stok minimum, tanggal kedaluwarsa terdekat) dan pemasok (UMKM/petani/peternak/nelayan, kontak, jenis bahan). Stok dihitung dari mutasi masuk/keluar/penyesuaian. Sistem menandai bahan dengan stok ≤ minimum atau kedaluwarsa ≤ 3 hari.                                                                                                                                                                                                                                                                                                                         |
| FR-05  | Pengelolaan menu & gizi                                  | Ahli Gizi mengelola menu, siklus/tanggal sajian, kelompok sasaran, komposisi bahan (gram/porsi), dan kandungan gizi per porsi (energi, protein, lemak, karbohidrat, serat). Sistem memberi peringatan bila nilai di luar rentang standar per kelompok sasaran yang dikonfigurasi.                                                                                                                                                                                                                                                                                                                  |
| FR-06  | Jadwal & log produksi                                    | CRUD jadwal produksi (tanggal, menu, jam mulai masak, target porsi, status). Sistem menolak target porsi yang melebihi kapasitas SPPG dan menampilkan estimasi kebutuhan bahan dibandingkan stok. Log produksi mencatat realisasi porsi, sisa makanan (kg), jam selesai, kendala, dan foto (satu log per jadwal).                                                                                                                                                                                                                                                                                  |
| FR-07  | Pemeriksaan mutu & feedback/keluhan                      | Pencatatan pemeriksaan mutu per jadwal produksi (suhu, kebersihan dapur dan petugas, organoleptik, sampel disimpan, foto, hasil Layak/Tidak Layak). Hasil "Tidak Layak" mengunci keberangkatan distribusi. Feedback/keluhan dicatat dengan sumber, kategori, titik layanan, jadwal produksi terkait, dan foto. Kategori "makanan basi"/"gejala sakit" otomatis berprioritas kritis dan memiliki alur status Baru → Diproses → Selesai.                                                                                                                                                             |
| FR-08  | Armada & bukti distribusi                                | CRUD armada (jenis, plat nomor, kapasitas, pengemudi, status) dan jadwal distribusi (titik layanan, armada, petugas, jam berangkat, jumlah porsi). Petugas mengunggah bukti distribusi berupa foto dari kamera, waktu berangkat dan tiba, porsi diterima, nama PIC penerima, dan koordinat opsional.                                                                                                                                                                                                                                                                                               |
| FR-09  | Data keuangan (pencairan dana & pengeluaran operasional) | Akuntan mengelola pencairan dana (periode, sumber, nominal, tanggal, no. referensi, bukti) dan pengeluaran operasional (tanggal, kategori seperti belanja bahan, insentif relawan, energi, uraian, nominal, pemasok opsional, nota) dalam satu fitur Data Keuangan. Sistem menyajikan rekap per kategori dan periode serta sisa anggaran (total pencairan − total pengeluaran).                                                                                                                                                                                                                    |
| FR-10  | Notifikasi bot & dasbor pemantauan                       | Sistem mengirim notifikasi Telegram berbasis peran melalui antrean untuk peristiwa: stok minimum, bahan mendekati kedaluwarsa, kekurangan bahan untuk jadwal, mutu tidak layak, bukti distribusi terlambat, keluhan kritis, dan serapan anggaran 80%/100%. Notifikasi memuat tombol web_app menuju layar terkait. Dasbor Kepala SPPG menampilkan indikator harian secara langsung (porsi, mutu, distribusi, keluhan, anggaran).                                                                                                                                                                    |
| FR-11  | Agen AI input bahasa alami (seluruh fitur)               | Sistem menerima teks, foto, dan pesan suara dari pengguna terdaftar di *chat* pribadi bot; mentranskripsi suara; memanggil LLM dengan *tool* baca/tulis yang **disaring sesuai hak akses peran**; menjawab *tool* baca langsung; mengubah *tool* tulis menjadi draf (ai_draft, berlaku 30 menit) setelah validasi *dry-run*; mengirim tombol web_app menuju formulir Mini App terisi; menyimpan data hanya setelah konfirmasi pengguna melalui endpoint modul standar; mencatat setiap interaksi AI; serta menyediakan *fallback* formulir manual bila AI gagal, tidak yakin, atau tidak tersedia. |
| FR-12  | Notifikasi perjalanan mobil SPPG                         | Relawan unit distribusi (kurir) atau Kepala SPPG (staf operasional) memicu notifikasi dari jadwal distribusi yang siap berangkat (hasil mutu Layak). Sistem menghitung estimasi tiba dari jam berangkat dan jarak titik layanan, mengirim pesan bot ke PIC titik layanan yang telah menghubungkan Telegram melalui tautan undangan khusus PIC, lalu mencatat waktu kirim, estimasi tiba, pemicu, dan status pengiriman. Bila PIC belum terhubung, notifikasi dialihkan ke Kepala SPPG dan status dicatat "tidak terkirim".                                                                         |
| FR-13  | Laporan rekapitulasi harian operasional                  | Scheduler menyusun rekap harian per SPPG pukul 17.00 WIB (realisasi vs target porsi, status mutu, progres distribusi, keluhan terbuka/kritis, serapan anggaran, dan sisa makanan), menyimpannya di tabel rekap_harian, lalu mengirimkannya melalui bot ke Kepala SPPG dan pengawas terdaftar. Kepala SPPG dapat melihat riwayat, mengirim ulang, dan mengunduh rekap sebagai PDF dari Mini App.                                                                                                                                                                                                    |

Kebutuhan lintas modul yang melekat pada FR di atas: (a) **unggah foto bukti** pada Log Produksi, Pemeriksaan Mutu, Bukti Distribusi (foto dan berita acara serah terima), Data Keuangan (nota pengeluaran dan bukti pencairan), dan Feedback/Keluhan; (b) **pencarian, filter tanggal, dan paginasi** pada setiap daftar; (c) **hapus lunak** (*soft delete*) dan **log audit** untuk data keuangan, mutu, dan distribusi.

### Karakteristik Pengguna

| **Kategori Pengguna** | **Deskripsi**                                                                                                                                            | **Hak Akses (melalui Telegram Mini App)**                                                                                                                                                                                                                                                                                 |
|-----------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Admin                 | Administrator sistem yang mengelola akun, peran, dan data referensi lintas SPPG; membantu pencatatan keluhan dari lapangan.                              | Insert, Update, Delete Data Pengguna, SPPG, Titik Layanan, Penerima Manfaat, Pemasok, Armada, Feedback/Keluhan; membaca data operasional, keuangan, dan Laporan Rekapitulasi Harian; dapat ditetapkan sebagai pengawas penerima rekap; menerima notifikasi sistem.                                                        |
| Kepala SPPG           | Pimpinan operasional satu SPPG yang bertanggung jawab atas produksi, mutu, distribusi, dan penggunaan dana (mencakup peran "Staf Operasional" pada PBI). | Insert, Update, Delete Data Jadwal Produksi dan Distribusi, Titik Layanan, Armada, Bukti Distribusi; Update profil SPPG; memicu Notifikasi Perjalanan Mobil; Update status tindak lanjut keluhan; membaca seluruh data SPPG-nya termasuk keuangan dan dasbor; menerima notifikasi kritis dan Laporan Rekapitulasi Harian. |
| Ahli Gizi             | Penanggung jawab menu, standar gizi, dan pengawasan mutu serta keamanan pangan (mencakup peran "Pengawas Mutu" pada PBI).                                | Insert, Update, Delete Data Menu dan Gizi serta Pemeriksaan Mutu; Insert/Update Jadwal Produksi; Update tindak lanjut keluhan terkait mutu; membaca bahan baku, penerima manfaat (alergi), dan log produksi.                                                                                                              |
| Akuntan               | Pengelola administrasi keuangan SPPG.                                                                                                                    | Insert, Update, Delete Data Keuangan (pencairan dana dan pengeluaran operasional); membaca data pemasok, bahan baku, dan distribusi sebagai dokumen pendukung; menerima notifikasi anggaran.                                                                                                                              |
| Staf Gudang           | Pengelola penerimaan, penyimpanan, dan pengeluaran bahan baku.                                                                                           | Insert, Update, Delete Data Bahan Baku dan mutasi stok; Insert/Update Data Pemasok; membaca menu dan jadwal produksi untuk menyiapkan bahan; menerima notifikasi stok dan kedaluwarsa.                                                                                                                                    |
| Relawan               | Personel lapangan dengan unit tugas **dapur** (mencakup "Staf Dapur" pada PBI) atau **distribusi** (mencakup "Petugas Distribusi" dan "Kurir" pada PBI). | Unit dapur: Insert/Update Log Produksi dan Jadwal Produksi. Unit distribusi: Insert/Update Jadwal Distribusi dan Bukti Distribusi, serta memicu Notifikasi Perjalanan Mobil. Semua relawan: Insert Feedback/Keluhan, membaca menu dan jadwal hari berjalan.                                                               |

Seluruh peran dapat memakai Agen AI melalui *chat* bot dengan hak akses yang sama seperti di Mini App. Rincian hak akses per modul ditunjukkan pada matriks CRUD Tabel 5. Seluruh peran selain Admin dibatasi pada data SPPG tempat mereka bertugas. Operasi *Delete* merupakan hapus lunak dan tercatat di log audit.

| **No** | **Modul**                                    | **Admin** | **Kepala SPPG** | **Ahli Gizi** | **Akuntan** | **Staf Gudang** | **Relawan**      |
|--------|----------------------------------------------|-----------|-----------------|---------------|-------------|-----------------|------------------|
| 1      | Data Pengguna (Login Akun)                   | CRUD      | R               | R\*           | R\*         | R\*             | R\*              |
| 2      | Notifikasi Perjalanan Mobil ke Tujuan        | R         | CR              | –             | –           | –               | CR (distribusi)  |
| 3      | Data SPPG                                    | CRUD      | RU              | R             | R           | R               | R                |
| 4      | Titik Layanan                                | CRUD      | CRUD            | R             | R           | R               | R                |
| 5      | Penerima Manfaat                             | CRUD      | CRU             | R             | –           | –               | –                |
| 6      | Bahan Baku (+ mutasi stok)                   | CRUD      | R               | R             | R           | CRUD            | –                |
| 7      | Pemasok                                      | CRUD      | R               | R             | R           | CRU             | –                |
| 8      | Menu dan Gizi                                | R         | R               | CRUD          | –           | R               | R                |
| 9      | Jadwal Produksi dan Distribusi               | R         | CRUD            | CRU           | –           | R               | CRU (relawan)    |
| 10     | Log Produksi Harian                          | R         | RUD             | R             | R           | R               | CRU (dapur)      |
| 11     | Pemeriksaan Mutu dan Keamanan Pangan         | R         | R               | CRUD          | –           | –               | R                |
| 12     | Armada dan Pengemudi                         | CRUD      | CRUD            | –             | R           | –               | R                |
| 13     | Bukti Distribusi                             | R         | CRUD            | R             | R           | –               | CRU (distribusi) |
| 14     | Data Keuangan (Pencairan Dana & Pengeluaran) | R         | R               | –             | CRUD        | –               | –                |
| 15     | Feedback dan Keluhan                         | CRUD      | RU              | RU            | –           | –               | CR               |
| 16     | Laporan Rekapitulasi Harian Operasional      | R         | CRU             | R             | R           | –               | –                |

Keterangan: R\* = hanya membaca profil sendiri. "RU" pada Feedback/Keluhan berarti memperbarui status dan catatan tindak lanjut. "CRU (relawan)" pada Jadwal Produksi dan Distribusi berarti relawan unit dapur mengelola jadwal produksi, sedangkan relawan unit distribusi mengelola jadwal distribusi. Relawan hanya dapat mengubah data yang ia buat sendiri pada hari yang sama. Setelah itu, perubahan dilakukan oleh Kepala SPPG. **Agen AI mewarisi hak akses pengguna yang mengirim pesan**: *tool* untuk sel bertanda "–" tidak pernah ditawarkan ke LLM, dan aksi tulis tetap melalui *policy* yang sama saat draf dikonfirmasi. Urutan baris mengikuti nomor fitur pada Subbab 5.2. "C" pada Notifikasi Perjalanan Mobil berarti memicu pengiriman notifikasi; "CRU" pada Laporan Rekapitulasi Harian berarti membuat/mengirim ulang rekap dan mengatur pengawas penerima, sedangkan rekap rutin dibuat otomatis oleh Scheduler.

## Kebutuhan Non Fungsional

| **ID** | **Kebutuhan Non Fungsional**              | **Deskripsi (kriteria terukur)**                                                                                                                                                                                                                                                                                                                                                                                   |
|--------|-------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| NFR-01 | Kinerja API                               | Waktu respons persentil ke-95 ≤ 500 ms untuk permintaan baca dan ≤ 1 detik untuk tulis (di luar unggah berkas) pada beban 50 pengguna bersamaan, diukur dengan uji beban di lingkungan *staging*.                                                                                                                                                                                                                  |
| NFR-02 | Kinerja Mini App                          | Muat awal Mini App ≤ 3 detik pada jaringan 4G. Ukuran aset awal (HTML+CSS+JS) ≤ 300 KB setelah kompresi gzip. Pergantian layar ≤ 1 detik.                                                                                                                                                                                                                                                                          |
| NFR-03 | Responsivitas webhook & notifikasi        | Endpoint *webhook* mengembalikan HTTP 200 ≤ 1 detik; pemrosesan berat dialihkan ke antrean. Notifikasi keluhan kritis dan mutu tidak layak terkirim ≤ 60 detik sejak data disimpan. Pengiriman massal dibatasi sesuai pedoman Bot API (sekitar 1 pesan/detik per chat dan 30 pesan/detik secara keseluruhan) \[sitasi\].                                                                                           |
| NFR-04 | Keamanan autentikasi                      | Validasi initData dengan HMAC-SHA256 dan perbandingan *constant-time* (hash_equals); auth_date maksimal 3.600 detik; token Sanctum kedaluwarsa 8 jam; *webhook* hanya menerima permintaan dengan header X-Telegram-Bot-Api-Secret-Token yang valid; seluruh lalu lintas melalui HTTPS (TLS 1.2+); token bot dan kredensial hanya disimpan di variabel lingkungan.                                                  |
| NFR-05 | Keamanan otorisasi & aplikasi             | 100% endpoint dilindungi *policy* RBAC dan *scope* SPPG yang diverifikasi oleh *feature test* (akses tanpa hak menghasilkan 403). Validasi input melalui *Form Request*; perlindungan SQL injection melalui Eloquent/*prepared statement*; *rate limit* 60 permintaan/menit per pengguna; uji keamanan mengacu pada OWASP Top 10 \[sitasi\].                                                                       |
| NFR-06 | Keamanan berkas unggahan                  | Hanya JPEG/PNG/WebP, maksimal 5 MB, diverifikasi berdasarkan MIME aktual. Gambar di-*encode* ulang dengan dimensi maksimal 1600 px dan metadata EXIF dihapus. Berkas disimpan di luar direktori publik dan diakses melalui *signed URL* yang berlaku 10 menit.                                                                                                                                                     |
| NFR-07 | Keandalan & ketersediaan                  | Ketersediaan ≥ 99% per bulan pada jam operasional 03.00–17.00 WIB. Pencadangan basis data harian dengan retensi 14 hari (RPO ≤ 24 jam, RTO ≤ 4 jam). *Job* antrean dicoba ulang 3 kali dengan jeda bertahap. *Webhook* idempoten berdasarkan update_id. Permintaan tulis dari Mini App membawa *idempotency key* agar pengiriman ulang tidak menggandakan data.                                                    |
| NFR-08 | Kegunaan (*usability*)                    | Antarmuka berbahasa Indonesia dan mengikuti themeParams Telegram (terang/gelap). Dapat digunakan pada lebar layar ≥ 360 px di Telegram Android, iOS, dan Desktop. Formulir inti (log produksi, bukti distribusi, keluhan) dapat diselesaikan ≤ 60 detik. Skor *System Usability Scale* (SUS) ≥ 70 pada UAT.                                                                                                        |
| NFR-09 | Auditabilitas & kepatuhan                 | Seluruh operasi Create/Update/Delete pada data keuangan, mutu, distribusi, dan pengguna tercatat di log audit (pengguna, waktu, nilai lama/baru) dengan retensi ≥ 1 tahun. Pengumpulan data pribadi diminimalkan sesuai prinsip UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi \[sitasi\].                                                                                                                  |
| NFR-10 | Pemeliharaan, portabilitas & skalabilitas | Kode mengikuti PSR-12; API berversi (/api/v1) dan terdokumentasi OpenAPI; cakupan pengujian kelas *Service* ≥ 60%. Seluruh layanan berjalan melalui Docker Compose sehingga lingkungan dapat direproduksi. Kontainer aplikasi bersifat *stateless* agar dapat diskalakan horizontal, dan skema mendukung banyak SPPG (*multi-tenant* berbasis sppg_id).                                                            |
| NFR-11 | Kinerja & keandalan Agen AI               | Draf dari pesan teks tersedia ≤ 10 detik (p95), sedangkan dari foto/suara ≤ 20 detik (p95). Melewati 20 detik, pengguna menerima *fallback* formulir manual. Akurasi ekstraksi tingkat kolom ≥ 85% pada set uji 100 contoh (nota, pesan teks, dan suara fiktif berbahasa Indonesia) yang diukur saat pengujian. Jumlah data yang tersimpan tanpa konfirmasi pengguna harus 0 (diverifikasi dengan *feature test*). |
| NFR-12 | Keamanan, privasi & biaya Agen AI         | Hanya *tool* sesuai peran yang dikirim ke LLM; konten pengguna diperlakukan sebagai data (mitigasi *prompt injection*); data yang dikirim ke LLM diminimalkan (tanpa NIK, token, atau kredensial); kunci API LLM disimpan di variabel lingkungan; batas 30 permintaan AI/jam per pengguna dan anggaran biaya bulanan dapat dikonfigurasi; seluruh interaksi tercatat di ai_interactions dengan retensi ≥ 1 tahun.  |

## Kebutuhan Teknis

| **ID** | **Kebutuhan Teknis**                | **Deskripsi**                                                                                                                                                                                                                                                                                                                                                                                                                                 |
|--------|-------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| KT-01  | Klien pengguna                      | Aplikasi Telegram resmi (Android, iOS, Desktop, atau Web) versi terbaru yang mendukung Mini Apps. Perangkat pengguna berupa ponsel Android/iOS dengan kamera untuk unggah foto bukti.                                                                                                                                                                                                                                                         |
| KT-02  | Frontend Mini App                   | HTML5, CSS3, dan JavaScript (ES2020+, modul *vanilla*) dengan pustaka resmi telegram-web-app.js (https://telegram.org/js/telegram-web-app.js) untuk initData, MainButton, BackButton, themeParams, HapticFeedback, dan showPopup. Dibundel dengan Vite (bawaan Laravel) dan disajikan sebagai aset statis oleh Nginx. Grafik dasbor memakai pustaka ringan (mis. Chart.js 4.x).                                                               |
| KT-03  | Backend                             | PHP 8.4 (PHP-FPM) dan Laravel 13.x (pola MVC) yang menyediakan REST API JSON /api/v1/\* dan endpoint *webhook* /api/telegram/webhook. Laravel Sanctum 4.x untuk token API, Form Request untuk validasi, Policy/Gate untuk RBAC, dan Composer 2.x untuk manajemen dependensi.                                                                                                                                                                  |
| KT-04  | Integrasi Telegram Bot API          | Bot didaftarkan melalui @BotFather. Komunikasi melalui HTTPS Bot API (setWebhook dengan secret_token, sendMessage, sendPhoto, setChatMenuButton) menggunakan Laravel HTTP Client tanpa SDK pihak ketiga, untuk meminimalkan dependensi.                                                                                                                                                                                                       |
| KT-05  | Basis data                          | MySQL 8.4 LTS, *engine* InnoDB, *charset* utf8mb4, zona waktu aplikasi Asia/Jakarta. Skema dikelola dengan migrasi Laravel; *foreign key* dan indeks pada kolom relasi serta tanggal.                                                                                                                                                                                                                                                         |
| KT-06  | Antrean & penjadwal                 | Laravel Queue dengan *driver* database (tabel jobs/failed_jobs) untuk notifikasi dan pemrosesan gambar. Laravel Scheduler (schedule:work) untuk pemeriksaan stok dan kedaluwarsa (05.00 WIB), deteksi keterlambatan distribusi (setiap 15 menit), dan ringkasan harian (17.00 WIB).                                                                                                                                                           |
| KT-07  | Kontainerisasi                      | Docker Engine 27+ dan Docker Compose v2 dengan layanan: nginx (nginx:1.28-alpine; *reverse proxy*, TLS, aset statis), app (*image* kustom php:8.4-fpm-alpine beserta ekstensi pdo_mysql, gd, intl, zip, opcache, serta ffmpeg), mysql (mysql:8.4), queue (*image* app, perintah php artisan queue:work --tries=3 --backoff=10), dan scheduler (*image* app, perintah php artisan schedule:work). *Volume* persisten: db-data dan app-storage. |
| KT-08  | Server & jaringan                   | VPS Ubuntu Server 24.04 LTS (minimal 2 vCPU, 4 GB RAM, 40 GB SSD), domain publik, dan sertifikat TLS Let's Encrypt. HTTPS wajib karena Mini App dan *webhook* Telegram hanya menerima URL HTTPS. Untuk pengembangan lokal digunakan terowongan HTTPS (mis. Cloudflare Tunnel atau ngrok).                                                                                                                                                     |
| KT-09  | Pengolahan berkas                   | Intervention Image 3.x (GD) untuk *resize*, *re-encode*, dan penghapusan EXIF. Laravel Filesystem (disk local privat) dengan *signed route* untuk akses berkas.                                                                                                                                                                                                                                                                               |
| KT-10  | Perangkat pengembangan & kolaborasi | Git dan GitHub (repositori, *pull request*, GitHub Actions untuk uji otomatis), Jira (manajemen *backlog* dan *sprint*), PHPUnit/Pest (uji unit dan fitur), Postman/Bruno (uji API), Visual Studio Code, serta k6 atau JMeter (uji beban).                                                                                                                                                                                                    |
| KT-11  | Layanan LLM eksternal (Agen AI)     | API LLM komersial yang mendukung *function/tool calling*, masukan gambar (*vision*), dan *speech-to-text* untuk bahasa Indonesia, diakses melalui HTTPS dari LlmClient (antarmuka yang dapat diganti, mis. penyedia dengan API bergaya OpenAI). Penyedia final dipilih pada minggu inisiasi berdasarkan uji akurasi, biaya per permintaan, dan kebijakan privasi data. Kunci API disimpan di variabel lingkungan.                             |
| KT-12  | Pemrosesan audio                    | Pesan suara Telegram (OGG/Opus, diunduh melalui getFile dengan batas 20 MB) dikonversi bila diperlukan menggunakan ffmpeg yang dipasang di *image* app/queue, sebelum dikirim ke layanan *speech-to-text*.                                                                                                                                                                                                                                    |

Contoh ringkas berkas docker-compose.yml yang direncanakan:

> services:
>
> nginx:
>
> image: nginx:1.28-alpine
>
> ports: \["80:80", "443:443"\]
>
> volumes: \["./docker/nginx:/etc/nginx/conf.d", "./public:/var/www/public:ro"\]
>
> depends_on: \[app\]
>
> app:
>
> build: ./docker/php \# FROM php:8.4-fpm-alpine
>
> env_file: .env
>
> volumes: \["app-storage:/var/www/storage"\]
>
> depends_on: { mysql: { condition: service_healthy } }
>
> queue:
>
> build: ./docker/php
>
> command: php artisan queue:work --tries=3 --backoff=10 --max-time=3600
>
> env_file: .env
>
> volumes: \["app-storage:/var/www/storage"\]
>
> depends_on: \[mysql\]
>
> scheduler:
>
> build: ./docker/php
>
> command: php artisan schedule:work
>
> env_file: .env
>
> depends_on: \[mysql\]
>
> mysql:
>
> image: mysql:8.4
>
> environment: { MYSQL_DATABASE: sigap, MYSQL_USER: sigap, MYSQL_PASSWORD: "\${DB_PASSWORD}", MYSQL_ROOT_PASSWORD: "\${DB_ROOT_PASSWORD}" }
>
> volumes: \["db-data:/var/lib/mysql"\]
>
> healthcheck: { test: \["CMD", "mysqladmin", "ping", "-h", "localhost"\], interval: 10s, retries: 5 }
>
> volumes: { db-data: {}, app-storage: {} }

# Rancangan Sistem

## Use Case Diagram

Aktor sistem terdiri atas enam peran pengguna (Relawan digambarkan terpisah menurut unit tugas dapur dan distribusi), tiga aktor sistem, yaitu **Telegram Platform** (mengirim pembaruan *webhook* dan menyediakan initData), **Layanan LLM** (penyedia model bahasa eksternal untuk Agen AI), dan **Scheduler** (pemicu tugas terjadwal), serta dua aktor penerima di luar Mini App, yaitu **PIC Titik Layanan** (penerima Notifikasi Perjalanan Mobil) dan **Pengawas** (penerima Laporan Rekapitulasi Harian). Nama peran pada PBI dipetakan sebagai berikut: Staf Operasional → Kepala SPPG, Pengawas Mutu → Ahli Gizi, Staf Dapur → Relawan unit dapur, serta Kurir/Petugas Distribusi → Relawan unit distribusi. Gambar 3 merangkum use case utama per aktor.

<img src="docs/sumber/media/media/image3.png" style="width:5.54635in;height:6.00855in" />

Penomoran UC2–UC17 mengikuti urutan 16 fitur CRUD pada Subbab 5.2 (nomor UC = nomor fitur + 1), sedangkan UC1 dan UC18–UC21 merupakan use case pendukung. Seluruh use case pengguna mensyaratkan UC1 (atau identitas Telegram terverifikasi untuk pesan *chat*). UC18 selalu diakhiri UC19: draf hasil Agen AI baru tersimpan setelah pengguna mengonfirmasinya melalui formulir Mini App. Tabel 8 dan Tabel 9 menyajikan contoh skenario use case untuk proses yang paling kritis.

| **Elemen**      | **Uraian**                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
|-----------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Aktor           | Relawan (unit distribusi)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| Prakondisi      | Pengguna terautentikasi; jadwal distribusi berstatus "Berangkat"; hasil pemeriksaan mutu jadwal produksi terkait = Layak.                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| Alur utama      | 1\) Relawan membuka Mini App dan memilih "Distribusi Hari Ini". 2) Sistem menampilkan jadwal milik relawan. 3) Relawan memilih titik layanan, menekan "Unggah Bukti", lalu mengambil foto dengan kamera. 4) Relawan mengisi porsi diterima dan nama PIC, kemudian menekan MainButton "Kirim". 5) Mini App mengompres foto dan mengirim *multipart request* bertoken. 6) Backend memvalidasi hak akses, berkas, dan data, menyimpan bukti, lalu mengubah status menjadi "Diterima". 7) Sistem menampilkan konfirmasi dan mengirim notifikasi ke Kepala SPPG. |
| Alur alternatif | 3a) Koneksi terputus: data disimpan sebagai *draft* dan dikirim ulang dengan *idempotency key* yang sama. 4a) Porsi diterima \< porsi dikirim: kolom catatan selisih wajib diisi.                                                                                                                                                                                                                                                                                                                                                                           |
| Pascakondisi    | Bukti distribusi tersimpan dengan waktu server, foto, dan pengguna pencatat; dasbor diperbarui.                                                                                                                                                                                                                                                                                                                                                                                                                                                             |

| **Elemen**      | **Uraian**                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
|-----------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Aktor           | Akuntan; Layanan LLM (aktor sistem)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| Prakondisi      | Akun Telegram Akuntan terhubung dan aktif; fitur Data Keuangan (pengeluaran operasional) termasuk hak akses Create milik Akuntan.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| Alur utama      | 1\) Akuntan memotret nota belanja dan mengirimkannya ke bot dengan keterangan "belanja sayur pasar pagi". 2) *Webhook* menerima pesan, memverifikasi pengirim, lalu memasukkan *job* ke antrean. 3) AiAgentService mengunduh foto melalui getFile, lalu mengirim gambar dan teks ke LLM dengan daftar *tool* yang diizinkan untuk peran Akuntan. 4) LLM mengembalikan pemanggilan *tool* buat_pengeluaran_operasional {tanggal, kategori: bahan_baku, uraian, nominal, pemasok}. 5) Backend melakukan validasi *dry-run* terhadap aturan *Form Request*, mencocokkan nama pemasok dengan data master, lalu menyimpan ai_draft (status *pending*, berlaku 30 menit). 6) Bot membalas ringkasan dan tombol web_app "Periksa & Simpan". 7) Akuntan membuka formulir Mini App yang sudah terisi (foto nota terlampir), mengoreksi bila perlu, lalu menekan "Simpan". 8) Endpoint modul standar menjalankan validasi dan *policy*, menyimpan data, menandai draf *confirmed*, dan mencatat log audit AI. |
| Alur alternatif | 3a) Layanan LLM gagal atau lebih dari 20 detik: bot mengirim tombol formulir kosong (*fallback* manual). 5a) Kolom wajib tidak terbaca atau keyakinan rendah: kolom ditandai "perlu dicek" pada formulir. 4a) Permintaan di luar hak akses (mis. Relawan meminta menghapus pencairan dana): *tool* tidak tersedia bagi peran tersebut sehingga bot menolak dengan sopan.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| Pascakondisi    | Pengeluaran tersimpan hanya setelah konfirmasi manusia; interaksi AI (input, *tool*, argumen, hasil, model, latensi) tercatat di ai_interactions.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |

## Arsitektur Sistem

SiGAP SPPG menggunakan **arsitektur klien–server tiga lapis** dengan backend **monolit modular berpola MVC** (Laravel) yang menyediakan dua pintu masuk: REST API untuk Mini App dan *webhook* untuk pembaruan dari bot. **Agen AI** ditempatkan sebagai lapisan layanan di dalam backend. Lapisan ini memanggil LLM eksternal, dan setiap *tool* yang dipanggil LLM diteruskan ke *Service* serta aturan validasi modul yang sama dengan yang dipakai REST API, sehingga Agen AI tidak memiliki jalur tulis ke basis data yang berbeda dari jalur manusia.

Pemilihan monolit modular (bukan *microservices*) didasarkan pada skala tim (lima orang), durasi pengembangan satu semester, dan kebutuhan konsistensi transaksi lintas modul (misalnya mutasi stok dan jadwal produksi) yang lebih sederhana dicapai dengan satu basis data relasional \[sitasi\]. Antarmuka Telegram Mini App dipilih karena menghilangkan kebutuhan instalasi aplikasi dan manajemen kata sandi, sedangkan identitas pengguna dijamin oleh mekanisme tanda tangan initData yang terdokumentasi di dokumentasi resmi Telegram Mini Apps \[sitasi\]. Pola *function/tool calling* dengan konfirmasi manusia (*human-in-the-loop*) dipilih agar keluaran LLM yang bersifat probabilistik tidak pernah langsung mengubah data \[sitasi\]. Docker dipilih agar lingkungan pengembangan, pengujian, dan produksi identik.

<img src="docs/sumber/media/media/image4.png" style="width:6.7in;height:7.12808in" />

**Gambar 4 Arsitektur sistem SiGAP SPPG**

**Penjelasan alur:**

1.  
2.  
3.  
4.  
5.  
6.  

**Telegram Client → Bot/Webhook.** Pembaruan dari Telegram dikirim ke https://\<domain\>/api/telegram/webhook yang didaftarkan melalui setWebhook dengan secret_token dan allowed_updates (message, callback_query). Controller memverifikasi header rahasia, menolak update_id duplikat, dan segera mengembalikan HTTP 200. Pesan /start \<token\> diproses sebagai *onboarding*, sedangkan pesan teks, foto, atau suara dari pengguna terdaftar dimasukkan ke antrean sebagai *job* ProcessAiMessage. Pesan hanya diproses dari *chat* pribadi.**Telegram Client → Mini App.** Tombol menu bot (setChatMenuButton bertipe web_app) membuka Mini App, dan Telegram menyisipkan Telegram.WebApp.initData.**Mini App → API Backend.** Mini App menukar initData dengan token Sanctum melalui POST /api/v1/auth/telegram (Gambar 5). Token dipakai sebagai Bearer pada permintaan berikutnya dan disimpan di memori halaman.**API Backend → DB.** Controller memanggil *Form Request* (validasi), *Policy* (RBAC dan *scope* SPPG), dan *Service* (logika bisnis), lalu membaca atau menulis melalui *Eloquent Model* ke MySQL dalam transaksi.**Agen AI.** *Worker* menjalankan AiAgentService: (a) mentranskripsi pesan suara (OGG/Opus dikonversi dengan ffmpeg bila diperlukan) atau menyiapkan gambar nota; (b) IntentParser mengirim teks dan konteks ringkas (peran, SPPG, tanggal, daftar referensi seperti nama menu dan pemasok) ke LLM beserta **hanya *tool* yang diizinkan untuk peran pengguna** dari ToolRegistry; (c) pemanggilan *tool* baca (mis. cek_stok_bahan) dijalankan langsung melalui *Service* dengan *policy* pengguna dan hasilnya dijawab di *chat*; (d) pemanggilan *tool* tulis (create/update/delete) **tidak pernah dieksekusi langsung**, melainkan divalidasi *dry-run* dan disimpan sebagai ai_draft, lalu bot mengirim tombol web_app menuju formulir yang sudah terisi.**Notifikasi.** Peristiwa bisnis memicu *job* SendTelegramNotification. Bot hanya dapat mengirim pesan ke pengguna yang telah memulai percakapan, dan syarat ini terpenuhi melalui alur *onboarding* /start.<img src="docs/sumber/media/media/image5.png" style="width:6.7in;height:3.77117in" />

**Gambar 5 Diagram urutan autentikasi Mini App dengan validasi HMAC initData**

Implementasi validasi di backend (ringkas):

> // App\Services\Telegram\InitDataValidator
>
> public function validate(string \$initData, int \$maxAge = 3600): array
>
> {
>
> parse_str(\$initData, \$data); // nilai otomatis ter-URL-decode
>
> \$hash = \$data\['hash'\] ?? '';
>
> unset(\$data\['hash'\]);
>
> ksort(\$data);
>
> \$checkString = collect(\$data)-\>map(fn (\$v, \$k) =\> "\$k=\$v")-\>implode("\n");
>
> \$secret = hash_hmac('sha256', config('services.telegram.bot_token'), 'WebAppData', true);
>
> \$calc = hash_hmac('sha256', \$checkString, \$secret);
>
> if (! hash_equals(\$calc, \$hash)) throw new InvalidInitDataException('Tanda tangan tidak valid');
>
> if (time() - (int) (\$data\['auth_date'\] ?? 0) \> \$maxAge) throw new InvalidInitDataException('initData kedaluwarsa');
>
> return json_decode(\$data\['user'\], true); // berisi id, first_name, username, ...
>
> }

<img src="docs/sumber/media/media/image6.png" style="width:6.7in;height:2.4302in" />

**Gambar 6 Diagram urutan input berbasis Agen AI dengan konfirmasi manusia**

**Alur unggah foto bukti:** \<input type="file" accept="image/\*" capture="environment"\> → kompresi di sisi klien melalui *canvas* (maksimal 1600 px, JPEG kualitas 0,8, sekaligus mengonversi HEIC dari iOS) → POST multipart ke endpoint modul → validasi mimes:jpg,jpeg,png,webp\|max:5120 → FileUploadService melakukan *re-encode*, menghapus EXIF, menghitung SHA-256, lalu menyimpan ke storage/app/private/{sppg_id}/{yyyy}/{mm}/{uuid}.jpg → mencatat baris lampiran → berkas ditampilkan melalui *signed URL* sementara. Foto yang dikirim lewat *chat* untuk Agen AI diunduh melalui getFile (batas unduh Bot API 20 MB) dan diproses dengan FileUploadService yang sama, lalu dilampirkan ke draf.

**Cakupan Agen AI pada 16 fitur.** ToolRegistry menyediakan *tool* baca (cari\_\*/lihat\_\*) dan tulis (buat\_\*, ubah\_\*, hapus\_\*) untuk setiap modul. Skema argumen diturunkan dari aturan *Form Request* modul, sedangkan ketersediaannya disaring berdasarkan matriks Tabel 5. Contoh pemakaian per fitur ditunjukkan pada Tabel 10.

**Tabel 10 Contoh pemakaian Agen AI per modul**

| **Modul**                            | **Contoh masukan ke Agen AI**                                 | **Hasil**                                                                                       |
|--------------------------------------|---------------------------------------------------------------|-------------------------------------------------------------------------------------------------|
| Data Pengguna                        | "Tambah relawan dapur Siti, HP 0812xxxx"                      | Draf pengguna baru + tautan undangan setelah dikonfirmasi Admin                                 |
| Notifikasi Perjalanan Mobil          | "Mobil B 1234 XY berangkat ke SD Contoh 3 sekarang"           | Draf notifikasi berangkat dengan estimasi tiba; terkirim ke PIC setelah relawan menekan "Kirim" |
| Data SPPG                            | "Ubah kapasitas dapur jadi 3.000 porsi"                       | Draf perubahan profil SPPG                                                                      |
| Titik Layanan                        | "Tambah SDN 2 Sukamaju, 3,5 km, 240 siswa, PIC Bu Rina"       | Draf titik layanan beserta PIC                                                                  |
| Penerima Manfaat                     | Foto daftar siswa per kelas                                   | Draf daftar penerima (nama, kelompok, catatan alergi) untuk dicek                               |
| Bahan Baku                           | Foto surat jalan/nota pemasok                                 | Draf mutasi masuk (bahan, jumlah, satuan, kedaluwarsa)                                          |
| Pemasok                              | "Pemasok baru: Tani Makmur, sayur, 0813xxxx"                  | Draf pemasok                                                                                    |
| Menu dan Gizi                        | "Menu Senin: nasi, ayam suwir, tumis buncis, pisang untuk SD" | Draf menu dan komposisi; nilai gizi tetap diisi/diverifikasi Ahli Gizi                          |
| Jadwal Produksi dan Distribusi       | "Besok masak menu 3, 2.800 porsi, mulai 03.30"                | Draf jadwal (divalidasi terhadap kapasitas)                                                     |
| Log Produksi Harian                  | Pesan suara laporan selesai masak                             | Draf log produksi                                                                               |
| Pemeriksaan Mutu dan Keamanan Pangan | "Suhu 68 derajat, dapur bersih, sampel disimpan, layak"       | Draf pemeriksaan mutu                                                                           |
| Armada dan Pengemudi                 | "Mobil box B 1234 XY, sopir Pak Udin"                         | Draf armada dan pengemudi                                                                       |
| Bukti Distribusi                     | Foto serah terima + "SDN 1 terima 240 porsi, Bu Ani"          | Draf bukti distribusi dengan foto dan berita acara                                              |
| Data Keuangan                        | Foto bukti transfer pencairan atau foto nota belanja          | Draf pencairan (periode, nominal, referensi) atau draf pengeluaran (kategori, nominal, pemasok) |
| Feedback dan Keluhan                 | Pesan suara guru diteruskan relawan                           | Draf keluhan dengan kategori dan prioritas usulan                                               |
| Laporan Rekapitulasi Harian          | "Rekap hari ini gimana?" / "kirim ulang rekap kemarin"        | Jawaban ringkas (tool baca); kirim ulang rekap menjadi draf aksi untuk Kepala SPPG              |

## 

<span id="_Toc_sigap_21" class="anchor"></span>Rancangan ERDERD disusun dengan notasi *Crow's Foot*. Seluruh tabel transaksi memiliki sppg_id (langsung atau melalui induk) sebagai dasar isolasi data per SPPG, kolom created_at/updated_at, serta deleted_at (*soft delete*) untuk data yang diaudit.

<img src="docs/sumber/media/media/image7.png" style="width:5.30141in;height:4.26731in" />

**Gambar 7 Entity Relationship Diagram SiGAP SPPG**

**Penjelasan ERD:**

- 
- 
- 
- 
- 
- 
- 
- 
- 
- 
- 

## 

**SPPG** menjadi entitas induk (1:N) bagi pengguna, titik layanan, pemasok, bahan baku, menu, jadwal produksi, armada, pengemudi, pencairan dana, dan pengeluaran. Pengguna berperan Admin dapat memiliki sppg_id bernilai NULL (lintas SPPG).**Titik Layanan → Penerima Manfaat (1:N).** Jumlah sasaran per titik digunakan untuk alokasi porsi distribusi, sedangkan catatan alergi penerima menjadi masukan Ahli Gizi. Titik layanan juga menyimpan nama PIC dan pic_telegram_chat_id (diperoleh saat PIC membuka tautan undangan khusus PIC) sebagai tujuan Notifikasi Perjalanan Mobil.**Bahan Baku ↔ Menu (M:N)** dipecah oleh entitas asosiatif **MENU_BAHAN** (gram_per_porsi) yang menjadi dasar estimasi kebutuhan bahan: Σ(gram_per_porsi × target_porsi).**Bahan Baku → Mutasi Stok (1:N).** Nilai stok merupakan saldo terhitung dari mutasi (masuk, keluar, penyesuaian) yang diperbarui dalam transaksi basis data yang sama. Mutasi keluar dapat merujuk ke jadwal produksi.**Jadwal Produksi** merupakan simpul ketertelusuran: 1:0..1 ke **Log Produksi**, 1:N ke **Pemeriksaan Mutu** (pemeriksaan ulang setelah tindakan korektif dimungkinkan), 1:N ke **Jadwal Distribusi**, dan 0..1:N ke **Feedback/Keluhan**.**Jadwal Distribusi** (bagian modul *Jadwal Produksi dan Distribusi*) menghubungkan jadwal produksi, titik layanan, armada, pengemudi, dan petugas relawan. Setiap jadwal distribusi memiliki 0..1 **Bukti Distribusi**. Armada dan Pengemudi dipisahkan agar satu kendaraan dapat dikemudikan pengemudi berbeda per hari.**Pencairan Dana → Pengeluaran Operasional (0..1:N).** Kedua entitas ini membentuk fitur Data Keuangan. Pengeluaran dikaitkan dengan pencairan pada periode berjalan untuk menghitung sisa anggaran, dan dapat dikaitkan dengan pemasok bila merupakan belanja bahan.**Jadwal Distribusi → Notifikasi Perjalanan (1:N).** Setiap kali petugas atau kurir memicu keberangkatan armada, dicatat satu notifikasi perjalanan mobil ke titik layanan tujuan beserta estimasi kedatangan, waktu kirim, dan status pengiriman. Pemicunya (users) dan penerimanya (titik layanan) tercatat untuk penelusuran.**SPPG → Rekap Harian (1:N).** Laporan rekapitulasi harian operasional (realisasi porsi, status mutu, distribusi, keluhan, dan serapan anggaran) disimpan per tanggal beserta waktu dan status pengirimannya melalui bot Telegram kepada Kepala SPPG dan pengawas pada pukul 17.00 WIB.**AI_INTERACTION → AI_DRAFT (1:0..1).** Setiap pesan ke Agen AI dicatat (jenis input, *tool*, argumen, model, latensi, hasil). Bila menghasilkan aksi tulis, dibuat satu draf berisi modul, aksi, dan payload dengan status pending/confirmed/rejected/expired. Data modul baru tersimpan saat draf dikonfirmasi melalui endpoint modul, dan AUDIT_LOG.sumber bernilai ai_confirmed.**LAMPIRAN** (tidak digambar agar diagram tetap ringkas) merupakan tabel polimorfik (lampiranable_type, lampiranable_id, path, mime, ukuran, sha256, uploaded_by) untuk foto log produksi, pemeriksaan mutu, bukti distribusi, pencairan dana, pengeluaran, keluhan, dan draf AI. Tabel pendukung lain meliputi standar_gizi, telegram_updates (deduplikasi update_id), personal_access_tokens (Sanctum), dan jobs/failed_jobs.<span id="_Toc_sigap_22" class="anchor"></span>Class DiagramRancangan kelas mengikuti pola MVC Laravel yang diperluas dengan lapisan **Service** (logika bisnis), **Policy** (otorisasi), **Form Request** (validasi), **Job** (proses asinkron), dan **Agen AI** (orkestrasi LLM). Lapisan *View* digantikan oleh Mini App yang mengonsumsi **API Resource** (JSON). Gambar 8 menampilkan kelas-kelas representatif.

<img src="docs/sumber/media/media/image8.png" style="width:8.3in;height:4.944in" />

**Gambar 8 Class diagram SiGAP SPPG (ringkas)**

**Penjelasan rancangan kelas:**

- 
- 
- 
- 
- 
- 

## 

**Model (Eloquent).** Terdapat model utama untuk setiap fitur CRUD (termasuk NotifikasiPerjalanan, RekapHarian, PencairanDana, dan PengeluaranOperasional untuk Data Keuangan) ditambah model pendukung (MutasiStok, MenuBahan, Pengemudi, JadwalDistribusi, Lampiran, AiInteraction, AiDraft, AuditLog). Model memuat relasi dan aturan domain sederhana, misalnya JadwalProduksi::isLayakDistribusi(). *Global scope* SppgScope membatasi kueri pada SPPG pengguna.**Controller.** Setiap modul memiliki Api\V1\\Controller bertipe *resource*. Endpoint store/update menerima ai_draft_id opsional untuk menandai draf terkonfirmasi. AuthController menukar initData dengan token, TelegramWebhookController menerima pembaruan bot, dan AiDraftController menyajikan atau menolak draf untuk formulir Mini App.**Agen AI.** AiAgentService mengorkestrasi alur. SpeechToTextService mentranskripsi suara. IntentParser menyusun *prompt* sistem (peran, SPPG, tanggal, larangan mengarang data) dan memanggil LlmClient, yaitu antarmuka yang implementasinya dapat diganti sesuai penyedia. ToolRegistry memetakan setiap *tool* ke *Service*/aturan modul dan menyaring *tool* berdasarkan User::can(). DraftService memvalidasi *dry-run* dan membuat AiDraft. Agen AI tidak memiliki akses SQL langsung dan tidak dapat mengeksekusi aksi tulis tanpa konfirmasi.**Service lain.** InitDataValidator, TelegramBotService, ProductionPlanningService, StockService, BudgetService, FileUploadService, dan NotificationService, serta TripNotificationService dan DailySummaryService (untuk Notifikasi Perjalanan Mobil dan Laporan Rekapitulasi Harian) dipakai bersama oleh REST API maupun Agen AI.**Policy dan Form Request.** Satu Policy per model mengimplementasikan Tabel 5. Aturan Form Request dipakai ulang untuk validasi *dry-run* draf AI sehingga aturan validasi hanya didefinisikan sekali.**Job.** ProcessAiMessage, SendTelegramNotification, ProcessUploadedImage, CheckLowStockAndExpiry, CheckLateDistribution, dan SendDailySummary dijalankan oleh *queue worker* atau *scheduler*.<span id="_Toc_sigap_23" class="anchor"></span>Mockup (Rancangan Antarmuka)Rancangan antarmuka berikut berupa *mockup* resolusi tinggi pada bingkai ponsel 390 × 844 px dengan palet tema Telegram. Pada implementasinya, warna diambil dinamis dari Telegram.WebApp.themeParams sehingga mengikuti mode terang/gelap pengguna. Seluruh nama orang, lembaga, nominal, dan angka pada *mockup* adalah **data fiktif** untuk ilustrasi. Setiap fitur CRUD pada Subbab 5.2 memiliki minimal satu rancangan layar (Gambar 9–27); pemetaannya terdapat pada Tabel 15.

<table>
<colgroup>
<col style="width: 50%" />
<col style="width: 50%" />
</colgroup>
<thead>
<tr class="header">
<th><p><img src="docs/sumber/media/media/image9.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 9 Percakapan bot SiGAP: input bahasa alami ke Agen AI dan tombol "Buka SiGAP"</strong></p></th>
<th><img src="docs/sumber/media/media/image10.png" style="width:2.55in;height:5.51846in" /></th>
</tr>
</thead>
<tbody>
</tbody>
</table>

**Gambar 9** menampilkan percakapan pribadi dengan bot. Pengguna mengirim teks, foto nota, atau pesan suara. Agen AI membalas ringkasan hasil ekstraksi beserta tombol web_app "Periksa & Simpan", sedangkan tombol menu "Buka SiGAP" di kiri bawah membuka Mini App secara langsung.

**Gambar 10** memuat ringkasan harian Kepala SPPG: target vs realisasi porsi, status pemeriksaan mutu, progres distribusi per titik layanan, keluhan terbuka, dan serapan anggaran periode berjalan.

<table>
<colgroup>
<col style="width: 50%" />
<col style="width: 50%" />
</colgroup>
<thead>
<tr class="header">
<th><p><img src="docs/sumber/media/media/image11.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 11 Daftar stok Bahan Baku</strong></p></th>
<th><p><img src="docs/sumber/media/media/image12.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 12 Formulir konfirmasi draf Agen AI</strong></p></th>
</tr>
</thead>
<tbody>
</tbody>
</table>

**Gambar 11** menampilkan daftar bahan baku dengan pencarian dan filter. Lencana merah menandai stok ≤ minimum dan lencana kuning menandai kedaluwarsa ≤ 3 hari. MainButton "Catat Mutasi Stok" berada di bagian bawah.

**Gambar 12** menampilkan formulir Pengeluaran Operasional yang terisi otomatis dari foto nota. Kolom dengan keyakinan rendah ditandai "Perlu dicek", foto sumber terlampir, dan pengguna dapat mengoreksi sebelum menekan "Simpan" atau menolak draf.

<table>
<colgroup>
<col style="width: 50%" />
<col style="width: 50%" />
</colgroup>
<thead>
<tr class="header">
<th><p><img src="docs/sumber/media/media/image13.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 13 Formulir Log Produksi Harian</strong></p></th>
<th><p><img src="docs/sumber/media/media/image14.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 14 Formulir Pemeriksaan Mutu dan Keamanan Pangan</strong></p></th>
</tr>
</thead>
<tbody>
</tbody>
</table>

**Gambar 13** menunjukkan formulir log produksi yang terhubung dengan jadwal produksi hari ini: target porsi tampil sebagai referensi, sedangkan realisasi, sisa makanan, jam selesai, kendala, dan foto diisi relawan dapur.

**Gambar 14** menunjukkan daftar periksa suhu, kebersihan, organoleptik, dan sampel. Keputusan "Layak/Tidak Layak" menentukan apakah distribusi untuk jadwal tersebut dapat diberangkatkan.

<table>
<colgroup>
<col style="width: 50%" />
<col style="width: 50%" />
</colgroup>
<thead>
<tr class="header">
<th><p><img src="docs/sumber/media/media/image15.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 15 Unggah Bukti Distribusi dengan foto</strong></p></th>
<th><p><img src="docs/sumber/media/media/image16.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 16 Data Keuangan: pencairan dana dan pengeluaran operasional</strong></p></th>
</tr>
</thead>
<tbody>
</tbody>
</table>

**Gambar 15** menampilkan bukti serah terima di titik layanan: pratinjau foto kamera, jam tiba, porsi dikirim vs diterima, nama PIC, dan koordinat opsional.

**Gambar 16** menampilkan total pencairan, total pengeluaran, sisa anggaran, komposisi pengeluaran per kategori, dan daftar transaksi terbaru berikut nota.

<table>
<colgroup>
<col style="width: 50%" />
<col style="width: 50%" />
</colgroup>
<thead>
<tr class="header">
<th><p><img src="docs/sumber/media/media/image17.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 17 Formulir Feedback dan Keluhan</strong></p></th>
<th><p><img src="docs/sumber/media/media/image19m.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 18 Data Pengguna dan peran (Login Akun)</strong></p></th>
</tr>
</thead>
<tbody>
</tbody>
</table>

**Gambar 17** menunjukkan formulir keluhan terstruktur. Pemilihan kategori "Makanan basi" atau "Gejala sakit" otomatis menetapkan prioritas **Kritis** dan memicu notifikasi segera ke Kepala SPPG dan Ahli Gizi.

**Gambar 18** menampilkan layar Data Pengguna (Login Akun) milik Admin: daftar pengguna per peran, status aktif atau undangan, serta tombol untuk menambah pengguna dan mengirim tautan undangan sekali pakai (fitur tanggung jawab kelompok).

<table>
<colgroup>
<col style="width: 50%" />
<col style="width: 50%" />
</colgroup>
<thead>
<tr class="header">
<th><p><img src="docs/sumber/media/media/image20m.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 19 Profil Data SPPG</strong></p></th>
<th><p><img src="docs/sumber/media/media/image21m.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 20 Daftar Titik Layanan</strong></p></th>
</tr>
</thead>
<tbody>
</tbody>
</table>

**Gambar 19** menampilkan profil SPPG yang dikelola Kepala SPPG: nama, alamat, mitra/yayasan pengelola, kapasitas porsi per hari, dan status operasional. Kapasitas ini menjadi batas validasi target porsi pada jadwal produksi.

**Gambar 20** menampilkan daftar titik layanan (sekolah, PAUD, posyandu) beserta jarak dari SPPG, jumlah sasaran, dan PIC yang akan menerima Notifikasi Perjalanan Mobil.

<table>
<colgroup>
<col style="width: 50%" />
<col style="width: 50%" />
</colgroup>
<thead>
<tr class="header">
<th><p><img src="docs/sumber/media/media/image22m.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 21 Pemicu Notifikasi Perjalanan Mobil</strong></p></th>
<th><p><img src="docs/sumber/media/media/image23m.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 22 Daftar Penerima Manfaat</strong></p></th>
</tr>
</thead>
<tbody>
</tbody>
</table>

**Gambar 21** menampilkan layar pemicu Notifikasi Perjalanan Mobil. Relawan distribusi (kurir) memilih armada dan pengemudi, sistem menghitung estimasi tiba, lalu menampilkan pratinjau pesan yang akan diterima PIC titik layanan sebelum tombol "Berangkat & Kirim Notifikasi" ditekan.

**Gambar 22** menampilkan daftar penerima manfaat per kelompok sasaran (siswa, balita, ibu hamil, dan ibu menyusui) dengan lencana catatan alergi atau kebutuhan khusus yang menjadi masukan bagi Ahli Gizi.

<table>
<colgroup>
<col style="width: 50%" />
<col style="width: 50%" />
</colgroup>
<thead>
<tr class="header">
<th><p><img src="docs/sumber/media/media/image24m.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 23 Armada dan Pengemudi</strong></p></th>
<th><p><img src="docs/sumber/media/media/image25m.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 24 Daftar Pemasok</strong></p></th>
</tr>
</thead>
<tbody>
</tbody>
</table>

**Gambar 23** menampilkan data armada dan pengemudi: status kendaraan (bertugas, tersedia, servis), kapasitas, serta penugasan rute dan jumlah porsi pada hari berjalan.

**Gambar 24** menampilkan daftar pemasok lokal (petani, peternak, nelayan, dan UMKM) beserta jenis bahan dan kontak, yang juga dipakai untuk mencocokkan nama pemasok saat Agen AI membaca nota.

<table>
<colgroup>
<col style="width: 50%" />
<col style="width: 50%" />
</colgroup>
<thead>
<tr class="header">
<th><p><img src="docs/sumber/media/media/image26m.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 25 Formulir Menu dan Gizi</strong></p></th>
<th><p><img src="docs/sumber/media/media/image27m.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 26 Formulir Jadwal Produksi dan Distribusi</strong></p></th>
</tr>
</thead>
<tbody>
</tbody>
</table>

**Gambar 25** menampilkan formulir menu dan gizi: komposisi menu per kelompok sasaran serta kandungan gizi per porsi yang dibandingkan dengan standar. Nilai di luar rentang diberi lencana peringatan.

**Gambar 26** menampilkan formulir jadwal produksi dan distribusi: target porsi divalidasi terhadap kapasitas SPPG, estimasi kebutuhan bahan dibandingkan stok (kekurangan ditandai merah), dan alokasi distribusi per titik layanan.

<table>
<colgroup>
<col style="width: 50%" />
<col style="width: 50%" />
</colgroup>
<thead>
<tr class="header">
<th><p><img src="docs/sumber/media/media/image28m.png" style="width:2.55in;height:5.51846in" /></p>
<p><strong>Gambar 27 Laporan Rekapitulasi Harian di bot Telegram</strong></p></th>
<th></th>
</tr>
</thead>
<tbody>
</tbody>
</table>

**Gambar 27** menampilkan Laporan Rekapitulasi Harian Operasional yang dikirim bot pukul 17.00 WIB kepada Kepala SPPG dan pengawas. Pengguna dapat membuka detail, mengunduh PDF, atau menanyakan rekap hari sebelumnya melalui Agen AI.

## 

<span id="_Toc_sigap_24" class="anchor"></span>Risiko dan Mitigasi

| **No** | **Risiko**                                                                                        | **Dampak**                                                                | **Mitigasi**                                                                                                                                                                                                                                                                                                 |
|--------|---------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1      | Adopsi Telegram: sebagian personel belum memiliki akun atau terbiasa memakai aplikasi pesan lain. | Data tidak lengkap karena sebagian personel kembali ke pencatatan manual. | Panduan *onboarding* bergambar dan sesi pendampingan singkat; tautan undangan satu ketukan; antarmuka sederhana mengikuti tema Telegram; Agen AI memungkinkan input semudah mengirim pesan; Kepala SPPG dapat memantau pengguna yang belum aktif.                                                            |
| 2      | Pemalsuan atau pemutaran ulang (*replay*) initData, serta kebocoran token bot.                    | Akses tidak sah ke data dapur dan keuangan.                               | Validasi HMAC-SHA256 di server dengan hash_equals; batas auth_date 1 jam; token Sanctum 8 jam; pencocokan telegram_user_id dengan akun aktif; *secret token* pada *webhook*; token bot hanya di variabel lingkungan dan dapat di-*revoke* melalui @BotFather; uji keamanan khusus pada Sprint pengujian.     |
| 3      | Sinyal lemah di dapur atau titik layanan saat mengunggah foto.                                    | Unggahan gagal, bukti distribusi terlambat atau hilang.                   | Kompresi foto di klien (≤ 1600 px, sekitar 200–400 KB); *draft* lokal dan kirim ulang otomatis dengan *idempotency key*; waktu server dicatat saat diterima, sedangkan waktu ambil foto disimpan sebagai informasi pendukung; batas waktu permintaan dan indikator progres.                                  |
| 4      | Ketergantungan pada platform Telegram (perubahan Bot API, gangguan layanan, kebijakan akses).     | Sistem tidak dapat diakses sementara.                                     | Backend REST API independen dari Telegram sehingga dapat diberi klien lain bila diperlukan; pemantauan *changelog* Bot API; data sepenuhnya tersimpan di server sendiri; prosedur darurat berupa formulir kertas yang diinput ulang setelah layanan pulih.                                                   |
| 5      | Halusinasi atau salah ekstraksi oleh Agen AI (angka, tanggal, pemasok, menu).                     | Data keliru tersimpan, terutama nominal keuangan dan porsi.               | *Human-in-the-loop* wajib untuk seluruh aksi tulis; validasi *dry-run* dengan aturan modul; pencocokan entitas dengan data master (bukan teks bebas); kolom keyakinan rendah ditandai "Perlu dicek"; *prompt* melarang mengisi kolom yang tidak ada di input; log audit AI; evaluasi akurasi dengan set uji. |
| 6      | Kebocoran data pribadi ke penyedia LLM.                                                           | Pelanggaran prinsip pelindungan data pribadi.                             | Minimisasi data yang dikirim (tanpa NIK dan tanpa token); penyamaran nama penerima manfaat bila tidak diperlukan; memilih penyedia dengan kebijakan tidak memakai data API untuk pelatihan; Agen AI dapat dinonaktifkan per SPPG.                                                                            |
| 7      | *Prompt injection* melalui teks atau foto (mis. nota berisi instruksi tersembunyi).               | LLM mencoba memanggil aksi di luar maksud pengguna.                       | *Tool* disaring sesuai peran sebelum dikirim ke LLM; aksi tulis hanya menjadi draf; aksi hapus selalu memerlukan konfirmasi eksplisit di Mini App; konten input diperlakukan sebagai data, bukan instruksi.                                                                                                  |
| 8      | Biaya dan ketersediaan layanan LLM.                                                               | Biaya membengkak atau fitur AI tidak tersedia.                            | Batas permintaan AI per pengguna (30/jam) dan anggaran bulanan; *timeout* 20 detik dengan *fallback* ke formulir manual; LlmClient berbasis antarmuka sehingga penyedia dapat diganti.                                                                                                                       |
| 9      | Ruang lingkup besar (16 fitur CRUD dan Agen AI) dalam dua *sprint*.                               | Target tidak tercapai tepat waktu.                                        | Pembagian modul dan PBI per anggota secara paralel; komponen bersama (form, daftar, unggah, *tool* AI) dibuat generik pada Sprint 1; prioritas MoSCoW pada *backlog*; aksi AI tingkat lanjut (*update/delete* via chat) dapat diturunkan prioritasnya tanpa mengganggu CRUD inti.                            |
| 10     | Pengguna memblokir bot atau mematikan notifikasi.                                                 | Peringatan kritis tidak sampai.                                           | Status pengiriman dicatat; kegagalan kirim ke Kepala SPPG memicu penerima cadangan (Admin); daftar peringatan tetap tampil di dasbor Mini App.                                                                                                                                                               |

# Metode Pengembangan

Pengembangan menggunakan kerangka kerja **Scrum** \[sitasi\]. *Product Backlog* disusun dari PBI pada Subbab 5.2 dan dikelola di Jira. Daven Bagus Putra Santoso berperan sebagai Project Manager (merangkap Scrum Master dan pengelola backlog) sekaligus developer, sedangkan empat anggota lainnya berperan sebagai developer. Setiap *sprint* berdurasi empat minggu (Sprint 1: minggu ke-7–10; Sprint 2: minggu ke-11–14) dan diawali *Sprint Planning*, diikuti *Daily Scrum* singkat (tatap muka atau daring), lalu ditutup dengan *Sprint Review* (demo Mini App dan Agen AI langsung di Telegram) serta *Sprint Retrospective*. Fitur Login Akun (Data Pengguna) menjadi tanggung jawab bersama seluruh kelompok, sedangkan setiap anggota bertanggung jawab atas tiga fitur CRUD (PBI) sesuai Subbab 5.2 (Tabel 12) dan mengerjakannya secara paralel di setiap *sprint* secara vertikal: skema, API, *tool* Agen AI, hingga layar Mini App. *Definition of Done* setiap PBI: endpoint API dan layar Mini App selesai, lolos validasi dan *policy* RBAC, *tool* Agen AI modul tersedia dengan alur konfirmasi draf, memiliki *feature test*, telah ditinjau melalui *pull request*, serta berjalan di lingkungan Docker *staging*.

| **Anggota & Peran**                     | **Nama**                         | **Fitur CRUD (PBI 1)**                              | **Fitur CRUD (PBI 2)** | **Fitur CRUD (PBI 3)**                  |
|-----------------------------------------|----------------------------------|-----------------------------------------------------|------------------------|-----------------------------------------|
| Anggota 1 · Project Manager / Developer | Daven Bagus Putra Santoso        | Penerima Manfaat                                    | Armada dan Pengemudi   | Bukti Distribusi                        |
| Anggota 2 · Developer                   | Prabowo Kuntjoro Analta Ruswanto | Jadwal Produksi dan Distribusi                      | Log Produksi Harian    | Pemeriksaan Mutu dan Keamanan Pangan    |
| Anggota 3 · Developer                   | Muhammad Dhimas Pranaya          | Notifikasi Perjalanan Mobil SPPG                    | Data SPPG              | Titik Layanan                           |
| Anggota 4 · Developer                   | Hilmy Herdiansyah Shiddiq        | Bahan Baku                                          | Pemasok                | Menu dan Gizi                           |
| Anggota 5 · Developer                   | Mutiara Qolbu Thohirah           | Keuangan Operasional (Pencairan Dana & Pengeluaran) | Feedback dan Keluhan   | Laporan Rekapitulasi Harian Operasional |
| Seluruh kelompok                        | Semua anggota                    | Data Pengguna (Login Akun)                          | –                      | –                                       |

## Jadwal Pengembangan

Proyek berlangsung 13 minggu, dari minggu ke-4 hingga minggu ke-16 semester, dan terdiri atas inisiasi (minggu ke-4–6), **dua sprint pengembangan berdurasi empat minggu** (Sprint 1: minggu ke-7–10; Sprint 2: minggu ke-11–14), pengujian dan UAT (minggu ke-15), serta *deployment* dan presentasi akhir (minggu ke-16). Perkiraan tanggal disusun dengan asumsi minggu ke-1 semester dimulai Senin, 14 September 2026, tanpa minggu libur. Tanggal dapat disesuaikan dengan kalender akademik (minggu ke-16 bertepatan dengan libur Tahun Baru). Pembagian sprint disusun agar setiap anggota menyelesaikan fitur CRUD-nya secara vertikal (API, *tool* Agen AI, hingga layar Mini App): Sprint 1 berfokus pada fondasi dan data master, sedangkan Sprint 2 pada alur operasional harian, notifikasi, dan pelaporan.

| **Minggu Semester** | **Perkiraan Tanggal**    | **Tahap / Sprint**                                                     | **Sasaran (Sprint Goal)**                                                                                                         | **Pekerjaan Utama**                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  | **Luaran**                                                                                                  |
|---------------------|--------------------------|------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------|
| 4–6                 | 5 – 25 Okt 2026          | Inisiasi & Proposal                                                    | Ruang lingkup, rancangan, dan *backlog* disepakati                                                                                | Finalisasi proposal; *Product Backlog* dan *story point* di Jira (16 fitur CRUD sesuai PBI 5.2); rancangan ERD, class diagram, arsitektur, mockup; pemilihan penyedia LLM dan uji coba *function calling*; pembagian fitur (Tabel 12)                                                                                                                                                                                                                                                                                | Proposal final, *backlog* Jira, rancangan sistem                                                            |
| 7–10                | 26 Okt – 22 Nov 2026     | **Sprint 1:** Fondasi, autentikasi & data master                       | Pengguna dapat *onboarding* via bot, masuk ke Mini App sesuai peran, dan mengelola seluruh data master; kerangka Agen AI berjalan | Bot @BotFather, setWebhook + secret_token; Docker Compose (nginx, php-fpm, mysql, queue, scheduler), CI GitHub Actions, *staging*; validasi initData + Sanctum + RBAC; Data Pengguna (Login Akun); migrasi skema 16 fitur; cangkang Mini App; API + layar Mini App: Data SPPG, Titik Layanan, Penerima Manfaat, Armada dan Pengemudi, Bahan Baku, Menu dan Gizi, Keuangan Operasional (pencairan dana), perencanaan Jadwal Produksi; FileUploadService; kerangka AiAgentService, IntentParser, ToolRegistry, AiDraft | Bot aktif, login Mini App, data master end-to-end, OpenAPI, AI dapat membuat draf untuk 1 fitur percontohan |
| 11–14               | 23 Nov – 20 Des 2026     | **Sprint 2:** Operasional harian, notifikasi, pelaporan & integrasi AI | Seluruh 16 fitur dapat digunakan *end-to-end* dari Telegram, termasuk konfirmasi draf AI                                          | API + layar Mini App: Jadwal Distribusi, Log Produksi Harian, Pemeriksaan Mutu (gerbang distribusi), Notifikasi Perjalanan Mobil, Bukti Distribusi (foto & berita acara), Pemasok, Keuangan Operasional (pengeluaran), Feedback dan Keluhan, Laporan Rekapitulasi Harian; *tool* AI seluruh fitur; pipeline suara (STT) dan foto (*vision*); notifikasi bot berbasis peran dan *scheduler*; dasbor Kepala SPPG                                                                                                       | Mini App lengkap (*release candidate*)                                                                      |
| 15                  | 21 – 27 Des 2026         | Pengujian & UAT                                                        | Sistem memenuhi FR dan NFR                                                                                                        | Uji unit/fitur (PHPUnit/Pest); uji keamanan (pemalsuan initData, akses lintas peran/SPPG, unggahan berbahaya, *prompt injection*); uji beban (k6); evaluasi akurasi Agen AI; UAT per peran dengan skenario dapur; pengukuran SUS; perbaikan *bug*                                                                                                                                                                                                                                                                    | Laporan pengujian & UAT                                                                                     |
| 16                  | 28 Des 2026 – 3 Jan 2027 | Deployment & Presentasi Akhir                                          | Sistem berjalan di produksi dan didemonstrasikan                                                                                  | Deployment Docker di VPS + TLS, setWebhook produksi, konfigurasi Menu Button, pencadangan otomatis, panduan pengguna, presentasi dan demo akhir                                                                                                                                                                                                                                                                                                                                                                      | Sistem produksi, dokumentasi, presentasi                                                                    |

**Tabel 14 Rencana kerja paralel per anggota dan per sprint**

| **Anggota (Fitur)**                                                                                            | **Sprint 1 (Mg 7–10, 26 Okt – 22 Nov 2026)**                                                                                                                                                                                                                 | **Sprint 2 (Mg 11–14, 23 Nov – 20 Des 2026)**                                                                                                                                                                                     |
|----------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Anggota 1: Daven – Project Manager (Penerima Manfaat, Armada dan Pengemudi, Bukti Distribusi)                  | **PM:** Sprint Planning, *backlog* & *story point* Jira; bot @BotFather, *webhook* + secret_token, *onboarding* /start, TelegramBotService; API + UI Penerima Manfaat dan Armada dan Pengemudi; kerangka AiAgentService, IntentParser, ToolRegistry, AiDraft | API + UI Bukti Distribusi (foto, berita acara, deteksi keterlambatan); SpeechToTextService & pipeline foto; NotificationService terpusat & dasbor Kepala SPPG; *tool* AI ketiga fitur; **PM:** Sprint Review, UAT, dan demo akhir |
| Anggota 2: Prabowo (Jadwal Produksi dan Distribusi, Log Produksi Harian, Pemeriksaan Mutu dan Keamanan Pangan) | Docker Compose, CI GitHub Actions, *staging*; API + UI Jadwal Produksi (validasi kapasitas, estimasi bahan via ProductionPlanningService)                                                                                                                    | API + UI jadwal distribusi, Log Produksi Harian, Pemeriksaan Mutu (gerbang distribusi) + notifikasi mutu tidak layak; *tool* AI ketiga fitur                                                                                      |
| Anggota 3: Dhimas (Notifikasi Perjalanan Mobil, Data SPPG, Titik Layanan)                                      | Autentikasi initData + Sanctum, kerangka RBAC/Policy, Data Pengguna (undangan *deep link*, bersama kelompok); API + UI Data SPPG dan Titik Layanan (PIC + tautan undangan PIC)                                                                               | API + UI Notifikasi Perjalanan Mobil (TripNotificationService, estimasi tiba); log audit; uji *policy* lintas SPPG; *tool* AI ketiga fitur                                                                                        |
| Anggota 4: Hilmy (Bahan Baku, Pemasok, Menu dan Gizi)                                                          | Cangkang Mini App (themeParams, router, klien API, komponen form/daftar/unggah); API + UI Bahan Baku + mutasi stok (StockService) dan Menu dan Gizi (+ standar gizi)                                                                                         | API + UI Pemasok; *scheduler* stok minimum & kedaluwarsa + notifikasi stok; *tool* AI ketiga fitur (foto surat jalan → mutasi masuk)                                                                                              |
| Anggota 5: Mutiara (Keuangan Operasional, Feedback dan Keluhan, Laporan Rekap Harian)                          | Migrasi skema bersama & *seeder* data fiktif; kerangka OpenAPI; tabel audit; API + UI Keuangan Operasional: pencairan dana + BudgetService (ambang 80%/100%)                                                                                                 | API + UI Keuangan Operasional: pengeluaran (+ nota), Feedback dan Keluhan (+ eskalasi kritis), Laporan Rekapitulasi Harian 17.00 WIB (DailySummaryService); *tool* AI ketiga fitur (OCR nota)                                     |

## 

<span id="_Toc_sigap_27" class="anchor"></span>Tim PengembangTim pengembang SiGAP SPPG terdiri atas lima mahasiswa. **Daven Bagus Putra Santoso** berperan sebagai Project Manager sekaligus developer, sedangkan empat anggota lainnya berperan sebagai developer. Fitur Login Akun (Data Pengguna) dikerjakan bersama oleh seluruh kelompok, dan setiap anggota bertanggung jawab atas tiga PBI berikut. Ringkasan 16 fitur CRUD ditunjukkan pada Gambar 28, sedangkan ketertelusuran setiap PBI ke kebutuhan, use case, dan mockup dirangkum pada Tabel 15 di akhir subbab ini.

<img src="docs/sumber/media/media/image18.png" style="width:6.7in;height:3.36667in" />

**Gambar 28 Ringkasan 16 fitur CRUD SiGAP SPPG beserta penanggung jawab (acuan PBI)**

FITUR Login Akun (Tanggung Jawab Kelompok)

- 
- 
- 

**PBI 1: Pengelolaan Data PenggunaTujuan Bisnis:** Memastikan keamanan sistem informasi dengan mengatur hak akses yang tepat agar data dapur hanya dapat dimodifikasi oleh personel yang berwenang.**User Story:** Sebagai Admin, saya ingin mengelola data pengguna dan perannya (Admin, Kepala SPPG, Ahli Gizi, Akuntan, Staf Gudang, Relawan).

FITUR APLIKASI:

- 
- 
- 

1.  1.  
    2.  

2.  1.  
    2.  

3.  1.  
    2.  

**Nama** : Daven Bagus Putra Santoso (102022400019)**Peran** : Project Manager / Developer (memimpin Sprint Planning, Daily Scrum, Sprint Review, dan Retrospective; mengelola backlog dan prioritas PBI di Jira; mengoordinasikan integrasi antaranggota)Tanggung Jawab (Product Backlog Items):**PBI 1 : Pengelolaan Data Penerima Manfaat** Tujuan Bisnis: Memastikan makanan yang diproduksi dan didistribusikan tepat sasaran serta aman dikonsumsi dengan mempertimbangkan riwayat kesehatan spesifik setiap individu.User Story: Sebagai Admin, saya ingin mengelola data penerima manfaat yang mencakup siswa, ibu hamil, ibu menyusui, dan balita, beserta kategori, titik layanan, dan catatan alergi atau kebutuhan khusus, sehingga tidak terjadi kesalahan pemberian makanan yang berisiko bagi kesehatan penerima. **PBI 2: Pengelolaan Data Armada dan Pengemudi** Tujuan Bisnis: Mengoptimalkan manajemen logistik dan memastikan ketersediaan sarana pengiriman yang sesuai dengan beban operasional harian dapur. User Story: Sebagai Admin, saya ingin mengelola data armada dan pengemudi (termasuk informasi kendaraan, kapasitas, status operasional, dan pengemudi yang ditugaskan) sehingga setiap rute distribusi memiliki alokasi kendaraan yang memadai untuk mengangkut porsi makanan secara efisien. **PBI 3: Pencatatan Data Bukti Distribusi** Tujuan Bisnis: Menjamin akuntabilitas dan validasi bahwa pasokan makanan telah benar-benar tiba di lokasi tujuan dengan jumlah yang sesuai. User Story: Sebagai Relawan (atau Petugas Distribusi), saya ingin menginput data bukti distribusi dengan melampirkan foto, berita acara serah terima di titik layanan, serta mencatat jumlah porsi yang diterima, sehingga terdapat bukti serah terima yang sah dan transparan bagi pihak pengelola SPPG.

- 
- 
- 

1.  1.  
    2.  

2.  1.  
    2.  

3.  1.  
    2.  

**Nama** : Prabowo Kuntjoro Analta Ruswanto (102022400345) **Peran :** Anggota Tim / *Developer* Tanggung Jawab (Product Backlog Items): **PBI 1: Pengelolaan Data Jadwal Produksi dan DistribusiTujuan Bisnis:** Mensinkronkan alur waktu dari proses dapur hingga pengiriman agar makanan terdistribusi dalam kondisi segar dan tidak ada jeda waktu tunggu yang lama. **User Story:** Sebagai Staf Operasional, saya ingin mengelola jadwal operasional (jadwal masak, target porsi, jam berangkat, dan titik tujuan) sehingga saya dapat memastikan keselarasan antara selesainya masakan dengan keberangkatan kurir. **PBI 2: Pencatatan Log Produksi Harian** **Tujuan Bisnis:** Mengevaluasi efisiensi produksi dan mengidentifikasi masalah operasional secara harian. **User Story:** Sebagai *Staf Dapur*, saya ingin mencatat log produksi harian (realisasi porsi, menu yang dimasak, sisa makanan, dan kendala) sehingga terdapat pelaporan yang akurat untuk evaluasi dan perbaikan di hari berikutnya. **PBI 3: Manajemen Pemeriksaan Mutu dan Keamanan PanganTujuan Bisnis:** Menjamin standar higienitas dan kelayakan konsumsi dari makanan yang diproduksi. **User Story:** Sebagai *Pengawas Mutu (Quality Control)*, saya ingin mengelola data pemeriksaan mutu (kebersihan dapur dan petugas, suhu makanan, serta pencatatan sampel makanan) sehingga kualitas keamanan pangan selalu memenuhi standar kelayakan dan tercatat per hari produksi.

- 
- 
- 

1.  1.  
    2.  

2.  1.  
    2.  

3.  1.  
    2.  

**Nama** : Muhammad Dhimas Pranaya (102022400147) **Peran :** Anggota Tim / *Developer* Tanggung Jawab (Product Backlog Items): **PBI 1: Pengiriman Notifikasi Perjalanan Mobil SPPG Tujuan Bisnis:** Mengoptimalkan koordinasi pengiriman dengan memberikan informasi waktu nyata terkait status perjalanan armada distribusi makanan, sehingga pihak penerima di titik layanan dapat bersiap dan proses serah terima berjalan lebih efisien.**User Story:** Sebagai Staf Operasional atau Kurir, saya ingin memicu pengiriman notifikasi perjalanan mobil SPPG menuju titik layanan tujuan (seperti Sekolah, PAUD, atau posyandu), sehingga penerima dapat memperkirakan estimasi kedatangan armada dan memastikan kesiapan penerimaan porsi.**PBI 2: Pengelolaan Data SPPG Tujuan Bisnis:** Menjaga keakuratan informasi profil dan kapasitas operasional fasilitas dapur sebagai acuan utama dalam perencanaan jumlah produksi harian. **User Story:** Sebagai Kepala SPPG, saya ingin mengelola data SPPG (mencakup nama, alamat, mitra/yayasan pengelola, kapasitas porsi per hari, dan status operasional) sehingga sistem selalu menampilkan kondisi dan kapasitas aktual dapur yang saya pimpin. **PBI 3: Pengelolaan Data Titik Layanan Tujuan Bisnis:** Menyediakan pemetaan lokasi distribusi dan jumlah target penerima yang akurat untuk memastikan pengiriman makanan tepat sasaran dan efisien secara rute. **User Story:** Sebagai Admin, saya ingin mengelola data titik layanan (seperti Sekolah, PAUD, posyandu beserta jarak dari SPPG dan jumlah sasarannya) sehingga saya dapat merencanakan alokasi porsi dan mempermudah tim armada saat melakukan proses pengantaran makanan.

- 
- 
- 

1.  

<!-- -->

1.  
2.  

<!-- -->

2.  

<!-- -->

1.  
2.  

<!-- -->

3.  

<!-- -->

1.  
2.  

Nama : Hilmy Herdiansyah Shiddiq (102022430006)Peran : Anggota tim / DeveloperTanggung Jawab (Product Backlog Items) PBI 1: **Pengelolaan Data Bahan BakuTujuan Bisnis:** Memastikan ketersediaan bahan masakan di gudang selalu terpantau akurat untuk mencegah terhentinya produksi akibat kehabisan stok atau penggunaan bahan yang tidak layak pakai.**User Story:** Sebagai Staf Gudang, saya ingin mengelola data bahan baku (pencatatan stok gudang, satuan, tanggal kedaluwarsa, dan batas minimum stok) sehingga saya dapat memantau ketersediaan bahan dan segera melakukan pengadaan ulang sebelum batas minimum tercapai.PBI 2 : **Pengelolaan Data PemasokTujuan Bisnis:** Menjamin kelancaran rantai pasok bahan baku lokal serta mempermudah alur komunikasi dan transaksi pengadaan bahan baku segar setiap harinya.**User Story:** Sebagai Admin, saya ingin mengelola data pemasok bahan baku (mencatat identitas UMKM, petani, dan peternak lokal, beserta kontak dan jenis bahan yang disuplai) sehingga proses restok bahan baku dapat dilakukan dengan cepat dan terorganisir.PBI 3 : **Pengelolaan Data Menu dan GiziTujuan Bisnis:** Menjamin mutu kesehatan operasional dapur dengan memastikan setiap porsi yang diproduksi terstandarisasi dan memenuhi syarat nutrisi target penerima.**User Story:** Sebagai Ahli Gizi, saya ingin mengelola data menu dan gizi (menjadwalkan siklus menu, menghitung kandungan gizi per porsi, dan melakukan penyesuaian per kelompok sasaran) sehingga sajian yang didistribusikan selalu memenuhi kebutuhan kalori dan gizi spesifik para penerima manfaat.

- 
- 
- 

1.  1.  
    2.  

2.  1.  
    2.  

3.  1.  
    2.  

Nama : Mutiara Qolbu Thohirah (102022400327)Peran : Anggota tim / DeveloperTanggung Jawab (Product Backlog Items) **PBI 1 : Pengelolaan Keuangan Operasional**Tujuan Bisnis: Memastikan transparansi, pelacakan anggaran, dan pengendalian efisiensi biaya operasional dapur melalui pencatatan dana masuk (pencairan) serta arus kas keluar (pengeluaran operasional per kategori) secara akurat. User Story: Sebagai Akuntan, saya ingin mengelola data pencairan dana operasional dan menginput data pengeluaran operasional (seperti belanja bahan, insentif relawan, dan energi) sehingga riwayat penerimaan serta seluruh transaksi pengeluaran dapur tercatat secara sah dan terpantau pos anggarannya di dalam sistem. **PBI 2: Pengelolaan Feedback dan Keluhan**Tujuan Bisnis: Memfasilitasi pelaporan terstruktur dari lapangan agar setiap masalah kualitas, layanan, atau keamanan makanan segera tercatat di dalam sistem, serta memastikan identifikasi masalah kritis secara cepat dan adanya respons perbaikan layanan terhadap operasional dapur. User Story: Sebagai Admin, saya ingin menginput dan mengelola data *feedback* serta keluhan yang diterima dari penerima manfaat, guru, atau orang tua ke dalam sistem secara terstruktur dan terhubung ke jadwal produksi, sehingga laporan tersebut terdokumentasi dengan baik, memiliki prioritas otomatis (kritis/normal), dan dapat segera ditindaklanjuti oleh Kepala SPPG. **PBI 3: Laporan Rekapitulasi Harian Operasional**Tujuan Bisnis: Memudahkan pihak pengawas (Kepala SPPG) dalam memantau kinerja harian secara menyeluruh, transparan, dan terintegrasi tanpa rekapitulasi manual, guna memastikan seluruh aspek operasional (mulai dari porsi, mutu, distribusi, keluhan, hingga realisasi anggaran) terkontrol dengan baik setiap hari. User Story: Sebagai Kepala SPPG, saya ingin menghasilkan dan mengirimkan rekapitulasi harian operasional dapur (mencakup realisasi porsi, status mutu, distribusi, keluhan, dan serapan anggaran) secara otomatis melalui bot Telegram kepada pengawas setiap pukul 17.00 WIB, sehingga proses pelaporan kinerja harian dapat tersampaikan secara transparan dan tepat waktu.

|        |                                              |                                     |               |              |               |
|--------|----------------------------------------------|-------------------------------------|---------------|--------------|---------------|
| **No** | **Fitur CRUD (PBI)**                         | **Penanggung Jawab**                | **Kebutuhan** | **Use Case** | **Mockup**    |
| 1      | Data Pengguna (Login Akun)                   | Seluruh kelompok                    | FR-01, FR-02  | UC1, UC2     | Gambar 18     |
| 2      | Notifikasi Perjalanan Mobil ke Tujuan        | Muhammad Dhimas Pranaya             | FR-12         | UC3          | Gambar 21     |
| 3      | Data SPPG                                    | Muhammad Dhimas Pranaya             | FR-03         | UC4          | Gambar 19     |
| 4      | Data Titik Layanan                           | Muhammad Dhimas Pranaya             | FR-03         | UC5          | Gambar 20     |
| 5      | Data Penerima Manfaat                        | Daven Bagus Putra Santoso           | FR-03         | UC6          | Gambar 22     |
| 6      | Data Bahan Baku                              | Hilmy Herdiansyah Shiddiq           | FR-04         | UC7          | Gambar 11     |
| 7      | Data Pemasok                                 | Hilmy Herdiansyah Shiddiq           | FR-04         | UC8          | Gambar 24     |
| 8      | Data Menu dan Gizi                           | Hilmy Herdiansyah Shiddiq           | FR-05         | UC9          | Gambar 25     |
| 9      | Data Jadwal Produksi dan Distribusi          | Prabowo Kuntjoro Analta Ruswanto    | FR-06, FR-08  | UC10         | Gambar 26     |
| 10     | Data Log Produksi Harian                     | Prabowo Kuntjoro Analta Ruswanto    | FR-06         | UC11         | Gambar 13     |
| 11     | Data Pemeriksaan Mutu dan Keamanan Pangan    | Prabowo Kuntjoro Analta Ruswanto    | FR-07         | UC12         | Gambar 14     |
| 12     | Data Armada dan Pengemudi                    | Daven Bagus Putra Santoso           | FR-08         | UC13         | Gambar 23     |
| 13     | Data Bukti Distribusi                        | Daven Bagus Putra Santoso           | FR-08         | UC14         | Gambar 15     |
| 14     | Data Keuangan (pencairan dana & pengeluaran) | Mutiara Qolbu Thohirah              | FR-09         | UC15         | Gambar 12, 16 |
| 15     | Data Feedback dan Keluhan                    | Mutiara Qolbu Thohirah              | FR-07         | UC16         | Gambar 17     |
| 16     | Laporan Rekapitulasi Harian Operasional      | Mutiara Qolbu Thohirah              | FR-13         | UC17         | Gambar 10, 27 |
| –      | Agen AI (lintas fitur)                       | Seluruh anggota (tool AI per fitur) | FR-11         | UC18, UC19   | Gambar 9, 12  |

 
