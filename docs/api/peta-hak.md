# Peta hak server (`/auth/me`, `izin` per record)

Sumber kebenaran hak akses adalah SERVER: `config/rbac.php` (matriks Tabel 5), `App\Support\Rbac`, dan Policy.
Klien (Mini App/bot) tidak boleh menyalin matriks atau aturan unit/kepemilikan; klien hanya membaca peta di bawah.
Peta dihitung oleh `App\Support\PetaHak` dari Rbac/Policy yang SAMA dengan penegakan endpoint (tanpa aturan baru).
Server tetap menegakkan hak: peta hanya cermin untuk menyembunyikan menu/tombol; aksi tanpa hak tetap `403`.

## Gagal tertutup (fail closed)

Klien yang tidak menerima kunci peta (`modules`, `permissions`, `unit_scope`, `aksi`, `izin`) atau menerima nilai
tak dikenal WAJIB menampilkan TIDAK ADA menu/tombol aksi (bukan "tampilkan semua"). Pengguna nonaktif/terhapus
mendapat peta kosong (`modules: []`, `permissions: []`, `unit_scope: {}`, semua `aksi.* = false`).

## `GET /api/v1/auth/me` -> `user`

Bidang lama tidak berubah: `modules`, `permissions` (huruf C/R/U/D efektif per modul; `*` = hanya milik sendiri),
`keputusan`, `agregat`, `baca_saja`, `unit`, `sppg_aktif_id`, `multi_sppg`, dst.

| Kunci baru | Tipe | Arti |
|---|---|---|
| `unit_scope` | objek `{modul: sub-jenis}` | `Rbac::unitScope`. Relawan dapur `{"jadwal":"produksi"}`, relawan distribusi `{"jadwal":"distribusi"}`; peran lain dan relawan tanpa unit `{}` (selalu objek JSON). Menggantikan `unitBoleh` di JS: tab Produksi tampil bila `unit_scope.jadwal` kosong atau `== "produksi"` DAN `permissions.jadwal` ada; tab Distribusi bila kosong atau `== "distribusi"`. |
| `aksi.<kunci>` | boolean | Aksi di luar CRUD. `true` = Policy/Rbac mengizinkan (endpoint tidak 403; tetap bisa 422/409 karena data). `false` = endpoint 403. |
| `aksi.dashboard.tersedia` | boolean | `RingkasanDashboard::tersediaUntuk` = `DashboardPolicy::lihat` + peran punya dashboard (relawan tanpa unit = false). Sama dengan ada/tidaknya tombol bot `db`. |
| `aksi.dashboard.profil` | string atau null | Kunci profil dashboard (`admin`, `investor`, `kepala_sppg`, `ahli_gizi`, `akuntan`, `staf_gudang`, `relawan_dapur`, `relawan_distribusi`); null bila tidak tersedia. |

Kunci `aksi` (semua boolean) dan endpoint yang dilindunginya:

| Kunci | Endpoint | Sumber hak |
|---|---|---|
| `undang_pengguna` | `POST /pengguna/undangan` | `UserPolicy::create` (Admin) |
| `reset_password_pengguna` | `POST /pengguna/{id}/reset-password` | `UserPolicy::update` |
| `kelola_kepemilikan_sppg` | `/pengguna/{id}/kepemilikan-sppg` | `UserPolicy::kelolaKepemilikan` (Admin) |
| `undang_pic` | `POST /titik-layanan/{id}/undangan-pic` | `TitikLayananPolicy::update` |
| `ajukan_menu` | `POST /menu-gizi/{id}/ajukan` | `MenuPolicy::ajukan` (C `persetujuan-menu`, bukan Admin) |
| `lihat_rincian_biaya` | `GET /menu-gizi/{id}/rincian-biaya` | `MenuPolicy::lihatRincianBiaya` |
| `picu_perjalanan` | `POST /notifikasi-perjalanan`, `/pratinjau` | `NotifikasiPerjalananPolicy::create` |
| `susun_rekap` | `POST /rekap-harian` | `RekapHarianPolicy::create` |
| `kirim_ulang_rekap` | `POST /rekap-harian/{id}/kirim-ulang` | `RekapHarianPolicy::update` |
| `tindak_lanjut_keluhan` | `PATCH /keluhan/{id}` | `FeedbackKeluhanPolicy::update` |
| `catat_mutasi_stok` | `POST /bahan-baku/{id}/mutasi` | `BahanBakuPolicy::update` |
| `catat_harga_pemasok` | `POST /pemasok/{id}/harga` | `PemasokPolicy::catatHarga` |
| `lihat_ringkasan_jadwal_produksi` | `GET /jadwal/produksi-ringkas` | `JadwalProduksiPolicy::viewRingkas` |

Aksi yang bergantung pada record dievaluasi pada record "probe" di SPPG pengguna (hasil sama dengan Policy untuk record
SPPG sendiri; aturan per-record yang lebih halus tetap lewat `izin` di bawah). Hak "putuskan" tetap di `keputusan`
(modul) dan `izin.putuskan`/`izin.verifikasi` (record). Hak baca modul lain untuk dropdown (mis. daftar armada) cukup
dibaca dari `permissions` (mis. `permissions.armada` memuat `R`); jangan menduplikasi.

Contoh (relawan dapur, ringkas):

```json
{
  "user": {
    "role": "relawan", "unit": "dapur", "baca_saja": false, "keputusan": [],
    "permissions": { "pengguna": "R*", "sppg": "R", "titik-layanan": "R", "menu-gizi": "R", "jadwal": "CRU", "log-produksi": "CRU", "mutu": "R", "armada": "R", "keluhan": "CR" },
    "unit_scope": { "jadwal": "produksi" },
    "aksi": {
      "undang_pengguna": false, "reset_password_pengguna": false, "kelola_kepemilikan_sppg": false, "undang_pic": false,
      "ajukan_menu": false, "lihat_rincian_biaya": false, "picu_perjalanan": false, "susun_rekap": false,
      "kirim_ulang_rekap": false, "tindak_lanjut_keluhan": false, "catat_mutasi_stok": false, "catat_harga_pemasok": false,
      "lihat_ringkasan_jadwal_produksi": true,
      "dashboard": { "tersedia": true, "profil": "relawan_dapur" }
    }
  }
}
```

Investor (`baca_saja: true`): semua `aksi.* = false`, tanpa huruf C/U/D di `permissions`. Ringkasan per profil
(true): admin = `undang_pengguna`, `reset_password_pengguna`, `kelola_kepemilikan_sppg`, `undang_pic`, `lihat_rincian_biaya`,
`tindak_lanjut_keluhan`, `catat_mutasi_stok`, `catat_harga_pemasok`, `lihat_ringkasan_jadwal_produksi`; kepala_sppg = `undang_pic`,
`lihat_rincian_biaya`, `picu_perjalanan`, `susun_rekap`, `kirim_ulang_rekap`, `tindak_lanjut_keluhan`, `lihat_ringkasan_jadwal_produksi`;
ahli_gizi = `ajukan_menu`, `lihat_rincian_biaya`, `tindak_lanjut_keluhan`, `lihat_ringkasan_jadwal_produksi`; staf_gudang = `lihat_rincian_biaya`,
`catat_mutasi_stok`, `catat_harga_pemasok`, `lihat_ringkasan_jadwal_produksi`; akuntan dan relawan tanpa unit = tidak ada;
relawan dapur = `lihat_ringkasan_jadwal_produksi`; relawan distribusi = `picu_perjalanan`, `lihat_ringkasan_jadwal_produksi`.

## `izin` per record

Respons detail dan daftar resource berikut memuat `izin` (objek boolean) dari Policy yang sama dengan endpoint, termasuk
aturan relawan "hanya buatan sendiri pada hari yang sama" (`own_same_day`) dan pemisahan tugas pencatat/pemverifikasi.
Dihitung tanpa query per baris (diuji: jumlah query daftar tidak tumbuh dengan jumlah baris).

**Definisi seragam:** `izin.<kunci>` = HAK (Gate/Policy) DAN syarat keadaan yang dapat dibaca dari atribut record tanpa
query tambahan. Syarat yang butuh query relasional TIDAK dimasukkan dan tetap ditegakkan service (`422`/`409`), jadi
`true` berarti "bukan 403" (tombol boleh tampil), bukan jaminan sukses. Diuji terhadap endpoint nyata: `false` karena hak
selalu 403; `true` tidak pernah 403; `false` karena keadaan tidak pernah sukses (403/409/422).

| Resource | `izin` | Hak | Keadaan atribut (ikut dihitung) | Hanya service (tidak ikut) |
|---|---|---|---|---|
| `GET /jadwal` (produksi) | `{ubah, hapus}` | Policy update/delete | `hapus`: status direncanakan/dibatalkan | `hapus`: sudah ada log/distribusi aktif; `ubah`: field terkunci setelah berjalan |
| `GET /jadwal/distribusi` | `{ubah, hapus}` | Policy update/delete | `hapus`: status menunggu/siap/dibatalkan | `hapus`: sudah ada bukti distribusi; `ubah`: field terkunci setelah berangkat |
| `GET /log-produksi` | `{ubah, hapus}` | Policy update/delete | (tidak ada) | (tidak ada) |
| `GET /bukti-distribusi` | `{ubah, hapus}` | Policy update/delete | (tidak ada) | (tidak ada) |
| `GET /pembelian-bahan` | `{ubah, hapus, lampirkan, verifikasi}` | update, `lampirkan`, `verifikasi` (akuntan SPPG sama, bukan pencatat); `hapus` selalu false (tidak ada endpoint) | `ubah`/`lampirkan` false bila `terverifikasi`; `verifikasi` hanya status `diajukan` | versi konflik (409) |
| `GET /keuangan/pengeluaran` | `{ubah, hapus, lampirkan, putuskan}` | update/delete, `lampirkan`, `putuskan` (investor pemilik SPPG aktif) | `ubah`/`hapus`/`lampirkan` false bila `pembelian_bahan_id` terisi; `lampirkan` juga false bila `disetujui`; `putuskan` hanya status `diajukan` | versi konflik (409), pengajuan tidak ada |
| `GET /notifikasi-perjalanan` | `{ubah_status}` | `ubahStatus` (hak C modul, relawan hanya miliknya) | false bila perjalanan sudah tiba/dibatalkan | transisi tidak valid (422) |

Klien tidak boleh menyimpulkan HAK dari peran/ID; klien boleh tetap memakai `status` record untuk keadaan relasional di atas.

Contoh baris jadwal (relawan dapur, miliknya, dibuat kemarin):

```json
{ "id": 41, "dibuat_oleh": 12, "status": "direncanakan", "izin": { "ubah": false, "hapus": false } }
```

Contoh pembelian (akuntan, dicatat staf gudang, menunggu verifikasi):

```json
{ "id": 7, "status": "diajukan", "izin": { "ubah": false, "hapus": false, "lampirkan": false, "verifikasi": true } }
```

## Dropdown jadwal produksi: `GET /jadwal/produksi-ringkas`

Untuk form Jadwal Distribusi dan Keluhan (batch) yang dipakai peran yang tidak boleh `GET /jadwal` (relawan distribusi).
Gerbang `JadwalProduksiPolicy::viewRingkas` (flag `aksi.lihat_ringkasan_jadwal_produksi`), ber-scope SPPG, kolom MINIMAL:

```json
{ "data": [ { "id": 12, "tanggal_produksi": "2026-11-02", "nama_menu": "Nasi, ayam suwir, sayur bayam", "status": "direncanakan" } ] }
```

Query: `tanggal` (satu hari, default hari ini WIB) atau `from`/`to` (selisih `to` - `from` maks 62 hari, yaitu 63 hari kalender inklusif; mis. batch keluhan 14 hari terakhir),
`sppg_id` (Admin). Tanpa target porsi, catatan, anggaran, atau data pengguna. Akuntan/investor/relawan tanpa unit = `403`.
Perubahan kontrak: kolom `target_porsi` dan `status_label` DIHAPUS (klien memetakan label dari `status`).

## Bot Telegram

Tidak ada tombol baru. Tombol beranda tampil JIKA DAN HANYA JIKA layar akan mengizinkan: satu gerbang
`BotLayarRegistry::layarBolehDibuka` (= `hakRbac` + `LayarAksi::tampilUntuk` + `LayarBaca::izinkan`) dipakai
`BotNavigasi` pada setiap klik dan penyaring beranda. Dashboard: tombol `db` konsisten dengan `aksi.dashboard.tersedia`
dan `DashboardPolicy::lihat`.
