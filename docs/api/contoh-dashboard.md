# Contoh respons GET /api/v1/dashboard (R-07, tahap 1)

Kontrak untuk `frontend-dev` (layar Dashboard Mini App dan ringkasan di bot). Skema lengkap: `docs/api/openapi.yaml` (`/dashboard`, skema `Dashboard*`). Semua data di bawah fiktif (fixture tes, tanggal dibekukan 2026-11-02 10:00 WIB).

## Permintaan

```
GET /api/v1/dashboard?rentang=7|30&sppg_id=<id>
Authorization: Bearer <token login penuh>
X-SPPG-Id: <id>            (opsional; investor/peran satu-SPPG)
```

- `rentang`: panjang tren harian (7 bawaan, atau 30). Selain itu 422.
- `sppg_id`: pilih satu SPPG. Investor: hanya SPPG miliknya. Peran satu-SPPG: hanya SPPG sendiri. Admin: opsional. Format salah -> 422.
- Investor tanpa `sppg_id` dan tanpa header -> **gabungan** semua SPPG miliknya (endpoint ini tidak membalas 409 `sppg_aktif_belum_dipilih`). Sumber pemilih SPPG: `data.sppg_pilihan`.

## Kode galat

| Kode | Arti |
|---|---|
| 401 | Tanpa login / token tidak sah |
| 403 `perlu_login_penuh` | Token pra-login (belum login password) |
| 403 `sppg_aktif_ditolak` | `sppg_id`/header bukan SPPG milik pengguna (sama untuk SPPG milik investor lain dan yang tidak ada) |
| 403 `tanpa_sppg` | Akun belum terhubung ke SPPG |
| 404 | Hanya Admin: `sppg_id` tidak ada |
| 422 | `rentang`/`sppg_id` tidak valid |
| 429 | Batas laju 60/menit |

## Bentuk umum

```
data:
  peran, unit, profil          # profil: investor | kepala_sppg | ahli_gizi | akuntan | staf_gudang | relawan_dapur | relawan_distribusi | relawan_tanpa_unit | admin
  mode                         # satu | gabungan
  rentang_hari                 # 7 | 30
  periode                      # zona_waktu, hari_ini, bulan_berjalan{bulan,dari,sampai}, tren{rentang_hari,dari,sampai}
  dibuat_pada, cache_detik     # hasil di-cache 60 detik per pengguna
  sppg[]                       # SPPG yang dicakup respons ini
  sppg_pilihan[] | null        # investor: semua SPPG miliknya (untuk pemilih)
  widgets{kunci: widget|null}
```

Aturan untuk UI:

- Kunci `widgets` **stabil per peran**. Nilai `null` = peran tidak (lagi) punya hak baca atas modul sumbernya; sembunyikan kartu. Relawan tanpa unit: `widgets` = `{}`.
- Setiap widget memuat `periode` di kunci pertama (`jenis`: `hari_ini` | `bulan_berjalan` | `tren` | `saat_ini`): tampilkan sebagai keterangan periode kartu.
- Deret grafik (Chart.js): `labels` (tanggal Y-m-d berurutan) + `series.<nama>` (larik sepanjang `labels`). Widget bertren: `tren` (investor, keluhan), `selisih_tren`, `login_gagal.tren`. Widget kategorikal memakai `labels`/`series` (distribusi per titik, serapan per kategori, pengguna per peran) atau daftar `items`.
- Persen: angka 0-100 atau `null` (pembagi 0). Nominal: rupiah bulat (integer). JSON mengirim `60` bukan `60.0` untuk bilangan bulat.
- `anggaran.penanda`: `mencapai_ambang` (>= 80%), `mencapai_100`, `melampaui` -> beri warna kuning/oranye/merah. `status`: `belum_ada_anggaran | aman | peringatan | habis | melampaui`.
- `bukti_nota.bukti_belum_ada.jumlah` > 0 -> tampilkan penanda "bukti belum ada" (jumlah pengeluaran bulan berjalan tanpa foto nota). Tombol lihat foto nota tetap lewat `GET /lampiran/{id}` pada layar keuangan, bukan dari dashboard.
- Sel yang disamarkan (`jumlah: null`, `penanda: "<5"`) tampilkan sebagai "<5" dengan legenda "disamarkan" (bukan selalu kurang dari 5). Jangan menghitung ulang atau menyimpulkan nilai dari sel lain.
- Daftar `items` dibatasi (10-50 baris); jangan membuat paginasi.

## Widget yang datanya belum ada di sistem

- `staf_gudang.pengiriman_pemasok.terjadwal`: selalu `null` (hanya penerimaan yang sudah tercatat; belum ada jadwal pengiriman pemasok).
- `ahli_gizi.biaya_per_porsi.items[].biaya_per_porsi`: `null` untuk menu yang belum pernah diajukan ke investor (memakai snapshot pengajuan terakhir; rincian penuh di `GET /menu-gizi/{id}/rincian-biaya`).
- `ahli_gizi.kebutuhan_khusus`: belum ada kolom terstruktur; indikatornya penerima aktif dengan `catatan_alergi` terisi (`sumber: "catatan_alergi"`).
- `investor.anggaran.rencana_anggaran`: sama dengan total pencairan periode (belum ada tabel rencana anggaran terpisah).
- `admin.antrean_job_webhook.antrean` / `.job_gagal`: `null` bila tabel antrean tidak dipakai (antrean di luar basis data).


## Investor (pemilik, multi-SPPG)

Investor milik SPPG A dan B, tanpa `sppg_id` -> `mode: gabungan`. Dengan `?sppg_id=<id>` (atau header `X-SPPG-Id`) hanya satu SPPG dan `per_sppg` bernilai `null`.

```json
{
  "data": {
    "peran": "investor",
    "unit": null,
    "profil": "investor",
    "mode": "gabungan",
    "rentang_hari": 7,
    "periode": {
      "zona_waktu": "Asia/Jakarta",
      "hari_ini": "2026-11-02",
      "bulan_berjalan": {
        "bulan": "2026-11",
        "dari": "2026-11-01",
        "sampai": "2026-11-30"
      },
      "tren": {
        "rentang_hari": 7,
        "dari": "2026-10-27",
        "sampai": "2026-11-02"
      }
    },
    "dibuat_pada": "2026-11-02T10:00:00+07:00",
    "cache_detik": 60,
    "sppg": [
      {
        "id": 1,
        "kode": "SPPG-T0001-6c1195",
        "nama": "SPPG A",
        "status_operasional": "aktif"
      },
      {
        "id": 2,
        "kode": "SPPG-T0002-6f04ca",
        "nama": "SPPG B",
        "status_operasional": "aktif"
      }
    ],
    "sppg_pilihan": [
      {
        "id": 1,
        "kode": "SPPG-T0001-6c1195",
        "nama": "SPPG A",
        "status_operasional": "aktif"
      },
      {
        "id": 2,
        "kode": "SPPG-T0002-6f04ca",
        "nama": "SPPG B",
        "status_operasional": "aktif"
      }
    ],
    "widgets": {
      "porsi_hari_ini": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "target": 1800,
        "realisasi": 1380,
        "capaian_persen": 76.67,
        "jadwal_aktif": 3,
        "jadwal_dengan_log": 2,
        "jadwal_tanpa_log": 1,
        "sisa_makanan_kg": 1.5,
        "didistribusikan": 1500,
        "diterima": 1240,
        "diterima_persen_dari_diproduksi": 89.86
      },
      "anggaran": {
        "periode": {
          "jenis": "bulan_berjalan",
          "bulan": "2026-11",
          "dari": "2026-11-01",
          "sampai": "2026-11-30"
        },
        "anggaran": 30000000,
        "terpakai": 23000000,
        "sisa": 7000000,
        "persen": 76.67,
        "status": "aman",
        "penanda": {
          "ambang_peringatan_persen": 80,
          "mencapai_ambang": false,
          "mencapai_100": false,
          "melampaui": false
        },
        "rencana_anggaran": 30000000,
        "keterangan_rencana": "Rencana anggaran = total pencairan dana periode ini (belum ada tabel rencana terpisah).",
        "menunggu_persetujuan": {
          "jumlah": 2,
          "nominal": 1200000
        },
        "per_sppg": [
          {
            "sppg_id": 1,
            "nama": "SPPG A",
            "anggaran": 10000000,
            "terpakai": 5000000,
            "sisa": 5000000,
            "persen": 50,
            "status": "aman",
            "penanda": {
              "ambang_peringatan_persen": 80,
              "mencapai_ambang": false,
              "mencapai_100": false,
              "melampaui": false
            },
            "menunggu_persetujuan": 1
          },
          {
            "sppg_id": 2,
            "nama": "SPPG B",
            "anggaran": 20000000,
            "terpakai": 18000000,
            "sisa": 2000000,
            "persen": 90,
            "status": "peringatan",
            "penanda": {
              "ambang_peringatan_persen": 80,
              "mencapai_ambang": true,
              "mencapai_100": false,
              "melampaui": false
            },
            "menunggu_persetujuan": 1
          }
        ]
      },
      "pengajuan_menunggu": {
        "periode": {
          "jenis": "saat_ini",
          "per": "2026-11-02"
        },
        "total": 5,
        "pengeluaran": {
          "jumlah": 2,
          "nominal": 1200000
        },
        "menu": {
          "jumlah": 3
        },
        "per_sppg": [
          {
            "sppg_id": 1,
            "nama": "SPPG A",
            "pengeluaran": 1,
            "menu": 1
          },
          {
            "sppg_id": 2,
            "nama": "SPPG B",
            "pengeluaran": 1,
            "menu": 2
          }
        ]
      },
      "stok_kritis": {
        "periode": {
          "jenis": "saat_ini",
          "per": "2026-11-02"
        },
        "total_bahan": 5,
        "di_bawah_minimum": 3,
        "sudah_kedaluwarsa": 1,
        "akan_kedaluwarsa": 2,
        "hari_peringatan_kedaluwarsa": 3
      },
      "insiden_mutu": {
        "periode": {
          "jenis": "bulan_berjalan",
          "bulan": "2026-11",
          "dari": "2026-11-01",
          "sampai": "2026-11-30"
        },
        "tidak_layak_hari_ini": 1,
        "tidak_layak_bulan": 2,
        "tren": {
          "periode": {
            "jenis": "tren",
            "rentang_hari": 7,
            "dari": "2026-10-27",
            "sampai": "2026-11-02"
          },
          "labels": ["2026-10-27", "2026-10-28", "2026-10-29", "2026-10-30", "2026-10-31", "2026-11-01", "2026-11-02"],
          "series": {
            "tidak_layak": [0, 0, 0, 0, 0, 1, 1]
          }
        }
      },
      "keluhan_kritis": {
        "periode": {
          "jenis": "saat_ini",
          "per": "2026-11-02"
        },
        "kritis_terbuka": 3,
        "kritis_masuk_rentang": 3,
        "terbuka_semua_prioritas": 4,
        "tren": {
          "periode": {
            "jenis": "tren",
            "rentang_hari": 7,
            "dari": "2026-10-27",
            "sampai": "2026-11-02"
          },
          "labels": ["2026-10-27", "2026-10-28", "2026-10-29", "2026-10-30", "2026-10-31", "2026-11-01", "2026-11-02"],
          "series": {
            "kritis": [0, 0, 0, 0, 0, 0, 3]
          }
        }
      },
      "bukti_nota": {
        "periode": {
          "jenis": "bulan_berjalan",
          "bulan": "2026-11",
          "dari": "2026-11-01",
          "sampai": "2026-11-30"
        },
        "pengeluaran_dihitung": 5,
        "bukti_belum_ada": {
          "jumlah": 4,
          "nominal": 20200000
        },
        "persen_bukti_belum_ada": 80
      },
      "tren": {
        "periode": {
          "jenis": "tren",
          "rentang_hari": 7,
          "dari": "2026-10-27",
          "sampai": "2026-11-02"
        },
        "labels": ["2026-10-27", "2026-10-28", "2026-10-29", "2026-10-30", "2026-10-31", "2026-11-01", "2026-11-02"],
        "series": {
          "porsi_target": [0, 0, 0, 0, 0, 200, 1800],
          "porsi_realisasi": [0, 0, 0, 0, 0, 190, 1380],
          "porsi_diterima": [0, 0, 0, 0, 0, 0, 1240],
          "pengeluaran": [0, 0, 0, 0, 0, 0, 23000000],
          "keluhan_masuk": [0, 0, 0, 0, 0, 0, 5],
          "mutu_tidak_layak": [0, 0, 0, 0, 0, 1, 1]
        }
      }
    }
  }
}
```


## Kepala SPPG

```json
{
  "data": {
    "peran": "kepala_sppg",
    "unit": null,
    "profil": "kepala_sppg",
    "mode": "satu",
    "rentang_hari": 7,
    "periode": {
      "zona_waktu": "Asia/Jakarta",
      "hari_ini": "2026-11-02",
      "bulan_berjalan": {
        "bulan": "2026-11",
        "dari": "2026-11-01",
        "sampai": "2026-11-30"
      },
      "tren": {
        "rentang_hari": 7,
        "dari": "2026-10-27",
        "sampai": "2026-11-02"
      }
    },
    "dibuat_pada": "2026-11-02T10:00:00+07:00",
    "cache_detik": 60,
    "sppg": [
      {
        "id": 1,
        "kode": "SPPG-T0001-6c1195",
        "nama": "SPPG A",
        "status_operasional": "aktif"
      }
    ],
    "sppg_pilihan": null,
    "widgets": {
      "jadwal_produksi_hari_ini": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "total": 3,
        "aktif": 2,
        "target_porsi": 800,
        "per_status": [
          {
            "status": "direncanakan",
            "label": "Direncanakan",
            "jumlah": 0
          },
          {
            "status": "dimasak",
            "label": "Sedang Dimasak",
            "jumlah": 1
          },
          {
            "status": "selesai",
            "label": "Selesai",
            "jumlah": 1
          },
          {
            "status": "dibatalkan",
            "label": "Dibatalkan",
            "jumlah": 1
          }
        ]
      },
      "porsi_target_vs_realisasi": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "target": 800,
        "realisasi": 480,
        "capaian_persen": 60,
        "jadwal_aktif": 2,
        "jadwal_dengan_log": 1,
        "jadwal_tanpa_log": 1,
        "sisa_makanan_kg": 1.5,
        "didistribusikan": 500,
        "diterima": 290,
        "diterima_persen_dari_diproduksi": 60.42
      },
      "distribusi_per_titik": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "items": [
          {
            "titik_layanan_id": 2,
            "nama": "Posyandu Fiktif Dua",
            "jumlah_jadwal": 1,
            "jadwal_selesai": 0,
            "porsi_rencana": 200,
            "porsi_diterima": 0,
            "terlambat": 0
          },
          {
            "titik_layanan_id": 1,
            "nama": "SDN Fiktif Satu",
            "jumlah_jadwal": 2,
            "jadwal_selesai": 1,
            "porsi_rencana": 400,
            "porsi_diterima": 290,
            "terlambat": 1
          }
        ],
        "labels": ["Posyandu Fiktif Dua", "SDN Fiktif Satu"],
        "series": {
          "porsi_rencana": [200, 400],
          "porsi_diterima": [0, 290]
        }
      },
      "mutu": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "hari_ini": {
          "pemeriksaan": 1,
          "layak": 1,
          "tidak_layak": 0,
          "batch_belum_diperiksa": 1
        },
        "bulan_berjalan": {
          "pemeriksaan": 2,
          "layak": 1,
          "tidak_layak": 1,
          "periode": {
            "jenis": "bulan_berjalan",
            "bulan": "2026-11",
            "dari": "2026-11-01",
            "sampai": "2026-11-30"
          }
        },
        "persen_layak_bulan": 50
      },
      "keluhan_terbuka": {
        "periode": {
          "jenis": "saat_ini",
          "per": "2026-11-02"
        },
        "terbuka": 2,
        "baru": 1,
        "diproses": 1,
        "kritis_terbuka": 1,
        "masuk_hari_ini": 3,
        "per_prioritas": [
          {
            "prioritas": "rendah",
            "label": "Rendah",
            "jumlah": 0
          },
          {
            "prioritas": "sedang",
            "label": "Sedang",
            "jumlah": 1
          },
          {
            "prioritas": "tinggi",
            "label": "Tinggi",
            "jumlah": 0
          },
          {
            "prioritas": "kritis",
            "label": "Kritis",
            "jumlah": 1
          }
        ],
        "tren": {
          "periode": {
            "jenis": "tren",
            "rentang_hari": 7,
            "dari": "2026-10-27",
            "sampai": "2026-11-02"
          },
          "labels": ["2026-10-27", "2026-10-28", "2026-10-29", "2026-10-30", "2026-10-31", "2026-11-01", "2026-11-02"],
          "series": {
            "masuk": [0, 0, 0, 0, 0, 0, 3],
            "kritis": [0, 0, 0, 0, 0, 0, 1]
          }
        }
      },
      "stok_kritis": {
        "periode": {
          "jenis": "saat_ini",
          "per": "2026-11-02"
        },
        "total_bahan": 3,
        "di_bawah_minimum": 1,
        "sudah_kedaluwarsa": 1,
        "akan_kedaluwarsa": 1,
        "hari_peringatan_kedaluwarsa": 3
      },
      "anggaran": {
        "periode": {
          "jenis": "bulan_berjalan",
          "bulan": "2026-11",
          "dari": "2026-11-01",
          "sampai": "2026-11-30"
        },
        "anggaran": 10000000,
        "terpakai": 5000000,
        "sisa": 5000000,
        "persen": 50,
        "status": "aman",
        "penanda": {
          "ambang_peringatan_persen": 80,
          "mencapai_ambang": false,
          "mencapai_100": false,
          "melampaui": false
        },
        "rencana_anggaran": 10000000,
        "keterangan_rencana": "Rencana anggaran = total pencairan dana periode ini (belum ada tabel rencana terpisah).",
        "menunggu_persetujuan": {
          "jumlah": 1,
          "nominal": 500000
        },
        "per_sppg": null
      },
      "bukti_nota": {
        "periode": {
          "jenis": "bulan_berjalan",
          "bulan": "2026-11",
          "dari": "2026-11-01",
          "sampai": "2026-11-30"
        },
        "pengeluaran_dihitung": 3,
        "bukti_belum_ada": {
          "jumlah": 2,
          "nominal": 1500000
        },
        "persen_bukti_belum_ada": 66.67
      },
      "pengajuan_menunggu_investor": {
        "periode": {
          "jenis": "saat_ini",
          "per": "2026-11-02"
        },
        "total": 2,
        "pengeluaran": {
          "jumlah": 1,
          "nominal": 500000
        },
        "menu": {
          "jumlah": 1
        },
        "per_sppg": null
      },
      "rekap_harian": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "jam_kirim": "17:00",
        "sudah_disusun": true,
        "status_kirim": "menunggu",
        "disusun_pada": null,
        "dikirim_pada": null,
        "sudah_lewat_jam_kirim": false
      }
    }
  }
}
```


## Ahli Gizi

`kebutuhan_khusus` memakai penyamaran sel kecil: pada contoh ini siswa=6 dan balita=3, sehingga balita (<5) disamarkan dan siswa ikut disamarkan sebagai sel pelengkap; `total` menjadi `null`.

```json
{
  "data": {
    "peran": "ahli_gizi",
    "unit": null,
    "profil": "ahli_gizi",
    "mode": "satu",
    "rentang_hari": 7,
    "periode": {
      "zona_waktu": "Asia/Jakarta",
      "hari_ini": "2026-11-02",
      "bulan_berjalan": {
        "bulan": "2026-11",
        "dari": "2026-11-01",
        "sampai": "2026-11-30"
      },
      "tren": {
        "rentang_hari": 7,
        "dari": "2026-10-27",
        "sampai": "2026-11-02"
      }
    },
    "dibuat_pada": "2026-11-02T10:00:00+07:00",
    "cache_detik": 60,
    "sppg": [
      {
        "id": 1,
        "kode": "SPPG-T0001-6c1195",
        "nama": "SPPG A",
        "status_operasional": "aktif"
      }
    ],
    "sppg_pilihan": null,
    "widgets": {
      "menu_hari_ini": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "jumlah": 2,
        "per_status": [
          {
            "status": "draf",
            "label": "Draf",
            "jumlah": 1
          },
          {
            "status": "diajukan",
            "label": "Menunggu Persetujuan",
            "jumlah": 0
          },
          {
            "status": "disetujui",
            "label": "Disetujui",
            "jumlah": 1
          },
          {
            "status": "ditolak",
            "label": "Ditolak",
            "jumlah": 0
          },
          {
            "status": "perlu_revisi",
            "label": "Perlu Revisi",
            "jumlah": 0
          }
        ],
        "items": [
          {
            "menu_id": 2,
            "nama_menu": "Bubur Kacang Fiktif",
            "kelompok_sasaran": "balita",
            "status_persetujuan": "draf",
            "status_label": "Draf"
          },
          {
            "menu_id": 1,
            "nama_menu": "Nasi Ayam Fiktif",
            "kelompok_sasaran": "siswa",
            "status_persetujuan": "disetujui",
            "status_label": "Disetujui"
          }
        ]
      },
      "gizi_vs_standar": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "sesuai": 1,
        "tidak_sesuai": 0,
        "tanpa_standar": 1,
        "items": [
          {
            "menu_id": 2,
            "nama_menu": "Bubur Kacang Fiktif",
            "standar_tersedia": false,
            "sesuai": null,
            "zat": [
              {
                "kolom": "energi_kkal",
                "label": "Energi",
                "satuan": "kkal",
                "nilai": 900,
                "minimum": null,
                "maksimum": null,
                "status": null
              },
              {
                "kolom": "protein_g",
                "label": "Protein",
                "satuan": "g",
                "nilai": 20,
                "minimum": null,
                "maksimum": null,
                "status": null
              },
              {
                "kolom": "lemak_g",
                "label": "Lemak",
                "satuan": "g",
                "nilai": 15,
                "minimum": null,
                "maksimum": null,
                "status": null
              },
              {
                "kolom": "karbohidrat_g",
                "label": "Karbohidrat",
                "satuan": "g",
                "nilai": 70,
                "minimum": null,
                "maksimum": null,
                "status": null
              },
              {
                "kolom": "serat_g",
                "label": "Serat",
                "satuan": "g",
                "nilai": 5,
                "minimum": null,
                "maksimum": null,
                "status": null
              }
            ]
          },
          {
            "menu_id": 1,
            "nama_menu": "Nasi Ayam Fiktif",
            "standar_tersedia": true,
            "sesuai": true,
            "zat": [
              {
                "kolom": "energi_kkal",
                "label": "Energi",
                "satuan": "kkal",
                "nilai": 500,
                "minimum": 400,
                "maksimum": 600,
                "status": "sesuai"
              },
              {
                "kolom": "protein_g",
                "label": "Protein",
                "satuan": "g",
                "nilai": 20,
                "minimum": 15,
                "maksimum": 30,
                "status": "sesuai"
              },
              {
                "kolom": "lemak_g",
                "label": "Lemak",
                "satuan": "g",
                "nilai": 15,
                "minimum": 10,
                "maksimum": 25,
                "status": "sesuai"
              },
              {
                "kolom": "karbohidrat_g",
                "label": "Karbohidrat",
                "satuan": "g",
                "nilai": 70,
                "minimum": 50,
                "maksimum": 90,
                "status": "sesuai"
              },
              {
                "kolom": "serat_g",
                "label": "Serat",
                "satuan": "g",
                "nilai": 5,
                "minimum": 3,
                "maksimum": 10,
                "status": "sesuai"
              }
            ]
          }
        ]
      },
      "biaya_per_porsi": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "rata_rata": 12500,
        "menu_tanpa_biaya": 1,
        "items": [
          {
            "menu_id": 2,
            "nama_menu": "Bubur Kacang Fiktif",
            "biaya_per_porsi": null,
            "sumber": null,
            "nomor_pengajuan": null,
            "status_pengajuan": null
          },
          {
            "menu_id": 1,
            "nama_menu": "Nasi Ayam Fiktif",
            "biaya_per_porsi": 12500,
            "sumber": "pengajuan_terakhir",
            "nomor_pengajuan": 1,
            "status_pengajuan": "diajukan"
          }
        ]
      },
      "kebutuhan_khusus": {
        "periode": {
          "jenis": "saat_ini",
          "per": "2026-11-02"
        },
        "sumber": "catatan_alergi",
        "keterangan": "Penerima aktif dengan catatan alergi/kebutuhan khusus terisi. Sel bertanda disamarkan (bukan selalu kurang dari ambang).",
        "total": null,
        "per_kelompok_sasaran": [
          {
            "kelompok_sasaran": "siswa",
            "label": "Siswa",
            "jumlah": null,
            "penanda": "<5"
          },
          {
            "kelompok_sasaran": "balita",
            "label": "Balita",
            "jumlah": null,
            "penanda": "<5"
          },
          {
            "kelompok_sasaran": "ibu_hamil",
            "label": "Ibu Hamil",
            "jumlah": 0,
            "penanda": null
          },
          {
            "kelompok_sasaran": "ibu_menyusui",
            "label": "Ibu Menyusui",
            "jumlah": 0,
            "penanda": null
          }
        ],
        "penyamaran": {
          "diterapkan": true,
          "ambang_k": 5,
          "penanda": "<5"
        }
      },
      "hasil_mutu": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "hari_ini": {
          "pemeriksaan": 1,
          "layak": 1,
          "tidak_layak": 0,
          "batch_belum_diperiksa": 1
        },
        "bulan_berjalan": {
          "pemeriksaan": 2,
          "layak": 1,
          "tidak_layak": 1,
          "periode": {
            "jenis": "bulan_berjalan",
            "bulan": "2026-11",
            "dari": "2026-11-01",
            "sampai": "2026-11-30"
          }
        },
        "persen_layak_bulan": 50
      }
    }
  }
}
```


## Akuntan

```json
{
  "data": {
    "peran": "akuntan",
    "unit": null,
    "profil": "akuntan",
    "mode": "satu",
    "rentang_hari": 7,
    "periode": {
      "zona_waktu": "Asia/Jakarta",
      "hari_ini": "2026-11-02",
      "bulan_berjalan": {
        "bulan": "2026-11",
        "dari": "2026-11-01",
        "sampai": "2026-11-30"
      },
      "tren": {
        "rentang_hari": 7,
        "dari": "2026-10-27",
        "sampai": "2026-11-02"
      }
    },
    "dibuat_pada": "2026-11-02T10:00:00+07:00",
    "cache_detik": 60,
    "sppg": [
      {
        "id": 1,
        "kode": "SPPG-T0001-6c1195",
        "nama": "SPPG A",
        "status_operasional": "aktif"
      }
    ],
    "sppg_pilihan": null,
    "widgets": {
      "pencairan_vs_pengeluaran": {
        "periode": {
          "jenis": "bulan_berjalan",
          "bulan": "2026-11",
          "dari": "2026-11-01",
          "sampai": "2026-11-30"
        },
        "pencairan": 10000000,
        "jumlah_pencairan": 1,
        "pengeluaran": 5000000,
        "jumlah_pengeluaran": 2,
        "selisih": 5000000,
        "persen": 50,
        "status": "aman"
      },
      "serapan_per_kategori": {
        "periode": {
          "jenis": "bulan_berjalan",
          "bulan": "2026-11",
          "dari": "2026-11-01",
          "sampai": "2026-11-30"
        },
        "anggaran": 10000000,
        "terpakai": 5000000,
        "items": [
          {
            "kategori": "bahan_baku",
            "label": "Belanja Bahan",
            "nominal": 4000000,
            "jumlah": 1,
            "persen_dari_terpakai": 80,
            "persen_dari_anggaran": 40
          },
          {
            "kategori": "insentif_relawan",
            "label": "Insentif Relawan",
            "nominal": 0,
            "jumlah": 0,
            "persen_dari_terpakai": 0,
            "persen_dari_anggaran": 0
          },
          {
            "kategori": "energi",
            "label": "Energi (Gas/Listrik/Air)",
            "nominal": 1000000,
            "jumlah": 1,
            "persen_dari_terpakai": 20,
            "persen_dari_anggaran": 10
          },
          {
            "kategori": "transportasi",
            "label": "Transportasi",
            "nominal": 0,
            "jumlah": 0,
            "persen_dari_terpakai": 0,
            "persen_dari_anggaran": 0
          },
          {
            "kategori": "peralatan",
            "label": "Peralatan",
            "nominal": 0,
            "jumlah": 0,
            "persen_dari_terpakai": 0,
            "persen_dari_anggaran": 0
          },
          {
            "kategori": "lainnya",
            "label": "Lainnya",
            "nominal": 0,
            "jumlah": 0,
            "persen_dari_terpakai": 0,
            "persen_dari_anggaran": 0
          }
        ],
        "labels": ["Belanja Bahan", "Insentif Relawan", "Energi (Gas/Listrik/Air)", "Transportasi", "Peralatan", "Lainnya"],
        "series": {
          "nominal": [4000000, 0, 1000000, 0, 0, 0]
        }
      },
      "pengeluaran_menunggu": {
        "periode": {
          "jenis": "saat_ini",
          "per": "2026-11-02"
        },
        "pengeluaran": {
          "jumlah": 1,
          "nominal": 500000
        },
        "pembelian_menunggu_verifikasi": {
          "jumlah": 0,
          "nominal": 0
        }
      },
      "selisih_tren": {
        "periode": {
          "jenis": "tren",
          "rentang_hari": 7,
          "dari": "2026-10-27",
          "sampai": "2026-11-02"
        },
        "labels": ["2026-10-27", "2026-10-28", "2026-10-29", "2026-10-30", "2026-10-31", "2026-11-01", "2026-11-02"],
        "series": {
          "pencairan": [0, 0, 0, 0, 0, 0, 10000000],
          "pengeluaran": [0, 0, 0, 0, 0, 0, 5000000],
          "selisih": [0, 0, 0, 0, 0, 0, 5000000],
          "selisih_kumulatif": [0, 0, 0, 0, 0, 0, 5000000]
        },
        "total_selisih": 5000000
      },
      "bukti_nota": {
        "periode": {
          "jenis": "bulan_berjalan",
          "bulan": "2026-11",
          "dari": "2026-11-01",
          "sampai": "2026-11-30"
        },
        "pengeluaran_dihitung": 3,
        "bukti_belum_ada": {
          "jumlah": 2,
          "nominal": 1500000
        },
        "persen_bukti_belum_ada": 66.67
      }
    }
  }
}
```


## Staf Gudang

`pengiriman_pemasok.terjadwal` selalu `null` (jadwal pengiriman pemasok belum ada di sistem).

```json
{
  "data": {
    "peran": "staf_gudang",
    "unit": null,
    "profil": "staf_gudang",
    "mode": "satu",
    "rentang_hari": 7,
    "periode": {
      "zona_waktu": "Asia/Jakarta",
      "hari_ini": "2026-11-02",
      "bulan_berjalan": {
        "bulan": "2026-11",
        "dari": "2026-11-01",
        "sampai": "2026-11-30"
      },
      "tren": {
        "rentang_hari": 7,
        "dari": "2026-10-27",
        "sampai": "2026-11-02"
      }
    },
    "dibuat_pada": "2026-11-02T10:00:00+07:00",
    "cache_detik": 60,
    "sppg": [
      {
        "id": 1,
        "kode": "SPPG-T0001-6c1195",
        "nama": "SPPG A",
        "status_operasional": "aktif"
      }
    ],
    "sppg_pilihan": null,
    "widgets": {
      "stok_per_bahan": {
        "periode": {
          "jenis": "saat_ini",
          "per": "2026-11-02"
        },
        "total_bahan": 3,
        "per_kategori": [
          {
            "kategori": "karbohidrat",
            "label": "Karbohidrat",
            "jumlah": 2
          },
          {
            "kategori": "protein_hewani",
            "label": "Protein Hewani",
            "jumlah": 1
          },
          {
            "kategori": "protein_nabati",
            "label": "Protein Nabati",
            "jumlah": 0
          },
          {
            "kategori": "sayur",
            "label": "Sayur",
            "jumlah": 0
          },
          {
            "kategori": "buah",
            "label": "Buah",
            "jumlah": 0
          },
          {
            "kategori": "susu",
            "label": "Susu",
            "jumlah": 0
          },
          {
            "kategori": "bumbu",
            "label": "Bumbu",
            "jumlah": 0
          },
          {
            "kategori": "lainnya",
            "label": "Lainnya",
            "jumlah": 0
          }
        ],
        "terendah": [
          {
            "bahan_baku_id": 1,
            "nama": "Beras Fiktif",
            "kategori": "karbohidrat",
            "satuan": "kg",
            "stok": 5,
            "stok_minimum": 10,
            "kedaluwarsa_terdekat": "2026-11-03"
          },
          {
            "bahan_baku_id": 2,
            "nama": "Telur Fiktif",
            "kategori": "protein_hewani",
            "satuan": "kg",
            "stok": 50,
            "stok_minimum": 10,
            "kedaluwarsa_terdekat": "2026-11-01"
          },
          {
            "bahan_baku_id": 3,
            "nama": "Minyak Fiktif",
            "kategori": "karbohidrat",
            "satuan": "kg",
            "stok": 100,
            "stok_minimum": 10,
            "kedaluwarsa_terdekat": null
          }
        ]
      },
      "stok_di_bawah_minimum": {
        "periode": {
          "jenis": "saat_ini",
          "per": "2026-11-02"
        },
        "jumlah": 1,
        "items": [
          {
            "bahan_baku_id": 1,
            "nama": "Beras Fiktif",
            "kategori": "karbohidrat",
            "satuan": "kg",
            "stok": 5,
            "stok_minimum": 10,
            "kedaluwarsa_terdekat": "2026-11-03"
          }
        ]
      },
      "stok_mendekati_kedaluwarsa": {
        "periode": {
          "jenis": "saat_ini",
          "per": "2026-11-02"
        },
        "sudah_kedaluwarsa": 1,
        "akan_kedaluwarsa": 1,
        "hari_peringatan": 3,
        "items": [
          {
            "bahan_baku_id": 2,
            "nama": "Telur Fiktif",
            "kategori": "protein_hewani",
            "satuan": "kg",
            "stok": 50,
            "stok_minimum": 10,
            "kedaluwarsa_terdekat": "2026-11-01"
          },
          {
            "bahan_baku_id": 1,
            "nama": "Beras Fiktif",
            "kategori": "karbohidrat",
            "satuan": "kg",
            "stok": 5,
            "stok_minimum": 10,
            "kedaluwarsa_terdekat": "2026-11-03"
          }
        ]
      },
      "mutasi_hari_ini": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "total": 3,
        "per_jenis": [
          {
            "jenis": "masuk",
            "jumlah": 2
          },
          {
            "jenis": "keluar",
            "jumlah": 1
          },
          {
            "jenis": "penyesuaian",
            "jumlah": 0
          }
        ]
      },
      "pengiriman_pemasok": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "penerimaan_hari_ini": 2,
        "pemasok_hari_ini": 1,
        "per_jenis_pemasok": [
          {
            "jenis": "umkm",
            "label": "UMKM",
            "penerimaan": 0,
            "pemasok": 0
          },
          {
            "jenis": "petani",
            "label": "Petani",
            "penerimaan": 0,
            "pemasok": 0
          },
          {
            "jenis": "peternak",
            "label": "Peternak",
            "penerimaan": 0,
            "pemasok": 0
          },
          {
            "jenis": "nelayan",
            "label": "Nelayan",
            "penerimaan": 0,
            "pemasok": 0
          },
          {
            "jenis": "koperasi",
            "label": "Koperasi",
            "penerimaan": 2,
            "pemasok": 1
          }
        ],
        "terjadwal": null,
        "pembelian": {
          "menunggu_verifikasi": 0,
          "ditolak_bulan_ini": 0
        }
      }
    }
  }
}
```


## Relawan unit dapur

```json
{
  "data": {
    "peran": "relawan",
    "unit": "dapur",
    "profil": "relawan_dapur",
    "mode": "satu",
    "rentang_hari": 7,
    "periode": {
      "zona_waktu": "Asia/Jakarta",
      "hari_ini": "2026-11-02",
      "bulan_berjalan": {
        "bulan": "2026-11",
        "dari": "2026-11-01",
        "sampai": "2026-11-30"
      },
      "tren": {
        "rentang_hari": 7,
        "dari": "2026-10-27",
        "sampai": "2026-11-02"
      }
    },
    "dibuat_pada": "2026-11-02T10:00:00+07:00",
    "cache_detik": 60,
    "sppg": [
      {
        "id": 1,
        "kode": "SPPG-T0001-6c1195",
        "nama": "SPPG A",
        "status_operasional": "aktif"
      }
    ],
    "sppg_pilihan": null,
    "widgets": {
      "menu_target_hari_ini": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "jumlah_jadwal": 2,
        "total_target_porsi": 800,
        "items": [
          {
            "jadwal_id": 1,
            "menu": "Nasi Ayam Fiktif",
            "kelompok_sasaran": "siswa",
            "target_porsi": 500,
            "status": "selesai",
            "status_label": "Selesai",
            "jam_mulai_masak": "03:00"
          },
          {
            "jadwal_id": 2,
            "menu": "Bubur Kacang Fiktif",
            "kelompok_sasaran": "balita",
            "target_porsi": 300,
            "status": "dimasak",
            "status_label": "Sedang Dimasak",
            "jam_mulai_masak": "03:00"
          }
        ]
      },
      "status_log_produksi": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "jumlah_jadwal": 2,
        "sudah_ada_log": 1,
        "belum_ada_log": 1,
        "items": [
          {
            "jadwal_id": 1,
            "menu": "Nasi Ayam Fiktif",
            "target_porsi": 500,
            "ada_log": true,
            "realisasi_porsi": 480,
            "jam_selesai": "07:30"
          },
          {
            "jadwal_id": 2,
            "menu": "Bubur Kacang Fiktif",
            "target_porsi": 300,
            "ada_log": false,
            "realisasi_porsi": null,
            "jam_selesai": null
          }
        ]
      },
      "checklist_mutu_belum_diisi": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "jumlah": 1,
        "items": [
          {
            "jadwal_id": 2,
            "menu": "Bubur Kacang Fiktif",
            "jam_mulai_masak": "03:00",
            "status": "dimasak"
          }
        ]
      }
    }
  }
}
```


## Relawan unit distribusi

```json
{
  "data": {
    "peran": "relawan",
    "unit": "distribusi",
    "profil": "relawan_distribusi",
    "mode": "satu",
    "rentang_hari": 7,
    "periode": {
      "zona_waktu": "Asia/Jakarta",
      "hari_ini": "2026-11-02",
      "bulan_berjalan": {
        "bulan": "2026-11",
        "dari": "2026-11-01",
        "sampai": "2026-11-30"
      },
      "tren": {
        "rentang_hari": 7,
        "dari": "2026-10-27",
        "sampai": "2026-11-02"
      }
    },
    "dibuat_pada": "2026-11-02T10:00:00+07:00",
    "cache_detik": 60,
    "sppg": [
      {
        "id": 1,
        "kode": "SPPG-T0001-6c1195",
        "nama": "SPPG A",
        "status_operasional": "aktif"
      }
    ],
    "sppg_pilihan": null,
    "widgets": {
      "rute_hari_ini": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "jumlah_titik": 3,
        "items": [
          {
            "jadwal_id": 1,
            "titik_layanan": {
              "id": 1,
              "nama": "SDN Fiktif Satu",
              "jenis": "sekolah",
              "alamat": "Jl. Fiktif 1"
            },
            "jam_berangkat": "07:00",
            "jam_rencana_tiba": "08:00",
            "jumlah_porsi": 300,
            "status": "diterima",
            "status_label": "Diterima",
            "armada_plat": null,
            "pengemudi": null,
            "bukti_ada": true
          },
          {
            "jadwal_id": 2,
            "titik_layanan": {
              "id": 2,
              "nama": "Posyandu Fiktif Dua",
              "jenis": "sekolah",
              "alamat": "Jl. Fiktif 1"
            },
            "jam_berangkat": "07:00",
            "jam_rencana_tiba": "08:00",
            "jumlah_porsi": 200,
            "status": "berangkat",
            "status_label": "Berangkat",
            "armada_plat": null,
            "pengemudi": null,
            "bukti_ada": false
          },
          {
            "jadwal_id": 3,
            "titik_layanan": {
              "id": 1,
              "nama": "SDN Fiktif Satu",
              "jenis": "sekolah",
              "alamat": "Jl. Fiktif 1"
            },
            "jam_berangkat": "07:00",
            "jam_rencana_tiba": "08:00",
            "jumlah_porsi": 100,
            "status": "menunggu",
            "status_label": "Menunggu",
            "armada_plat": null,
            "pengemudi": null,
            "bukti_ada": false
          }
        ]
      },
      "status_kirim": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "total": 3,
        "per_status": [
          {
            "status": "menunggu",
            "label": "Menunggu",
            "jumlah": 1
          },
          {
            "status": "siap",
            "label": "Siap Berangkat",
            "jumlah": 0
          },
          {
            "status": "berangkat",
            "label": "Berangkat",
            "jumlah": 1
          },
          {
            "status": "diterima",
            "label": "Diterima",
            "jumlah": 1
          },
          {
            "status": "dibatalkan",
            "label": "Dibatalkan",
            "jumlah": 0
          }
        ],
        "notifikasi_perjalanan": {
          "terkirim": 0,
          "menunggu": 0,
          "gagal": 0
        }
      },
      "bukti_belum_diunggah": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "jumlah": 1,
        "items": [
          {
            "jadwal_id": 2,
            "titik_layanan": "Posyandu Fiktif Dua",
            "jam_rencana_tiba": "08:00",
            "status": "berangkat"
          }
        ]
      },
      "armada_pengemudi": {
        "periode": {
          "jenis": "saat_ini",
          "per": "2026-11-02"
        },
        "armada_per_status": [
          {
            "status": "tersedia",
            "label": "Tersedia",
            "jumlah": 1
          },
          {
            "status": "bertugas",
            "label": "Bertugas",
            "jumlah": 1
          },
          {
            "status": "servis",
            "label": "Servis",
            "jumlah": 0
          }
        ],
        "armada": [
          {
            "id": 1,
            "plat_nomor": "B 1234 FIK",
            "jenis": "Pickup",
            "kapasitas_box": 40,
            "status": "bertugas"
          },
          {
            "id": 2,
            "plat_nomor": "B 5678 FIK",
            "jenis": "Pickup",
            "kapasitas_box": 40,
            "status": "tersedia"
          }
        ],
        "pengemudi_aktif": [
          {
            "id": 1,
            "nama": "Pengemudi Fiktif"
          }
        ],
        "bertugas_hari_ini": {
          "armada": 0,
          "pengemudi": 0
        }
      }
    }
  }
}
```


## Admin

Tanpa `sppg_id`: gabungan semua SPPG. Widget `login_gagal`, `antrean_job_webhook` selalu global.

```json
{
  "data": {
    "peran": "admin",
    "unit": null,
    "profil": "admin",
    "mode": "gabungan",
    "rentang_hari": 7,
    "periode": {
      "zona_waktu": "Asia/Jakarta",
      "hari_ini": "2026-11-02",
      "bulan_berjalan": {
        "bulan": "2026-11",
        "dari": "2026-11-01",
        "sampai": "2026-11-30"
      },
      "tren": {
        "rentang_hari": 7,
        "dari": "2026-10-27",
        "sampai": "2026-11-02"
      }
    },
    "dibuat_pada": "2026-11-02T10:00:00+07:00",
    "cache_detik": 60,
    "sppg": [
      {
        "id": 1,
        "kode": "SPPG-T0001-6c1195",
        "nama": "SPPG A",
        "status_operasional": "aktif"
      },
      {
        "id": 2,
        "kode": "SPPG-T0002-6f04ca",
        "nama": "SPPG B",
        "status_operasional": "aktif"
      },
      {
        "id": 3,
        "kode": "SPPG-T0003-7bd878",
        "nama": "SPPG C",
        "status_operasional": "aktif"
      }
    ],
    "sppg_pilihan": null,
    "widgets": {
      "operasional_lintas_sppg": {
        "periode": {
          "jenis": "hari_ini",
          "tanggal": "2026-11-02"
        },
        "jumlah_sppg": 3,
        "total": {
          "target_porsi": 1800,
          "realisasi_porsi": 1380,
          "capaian_persen": 76.67,
          "distribusi_jadwal": 4,
          "distribusi_selesai": 2,
          "keluhan_terbuka": 4,
          "keluhan_kritis": 3,
          "stok_di_bawah_minimum": 3
        },
        "per_sppg": [
          {
            "sppg_id": 1,
            "kode": "SPPG-T0001-6c1195",
            "nama": "SPPG A",
            "status_operasional": "aktif",
            "target_porsi": 800,
            "realisasi_porsi": 480,
            "capaian_persen": 60,
            "distribusi_jadwal": 3,
            "distribusi_selesai": 1,
            "keluhan_terbuka": 2,
            "keluhan_kritis": 1,
            "stok_di_bawah_minimum": 1
          },
          {
            "sppg_id": 2,
            "kode": "SPPG-T0002-6f04ca",
            "nama": "SPPG B",
            "status_operasional": "aktif",
            "target_porsi": 1000,
            "realisasi_porsi": 900,
            "capaian_persen": 90,
            "distribusi_jadwal": 1,
            "distribusi_selesai": 1,
            "keluhan_terbuka": 2,
            "keluhan_kritis": 2,
            "stok_di_bawah_minimum": 2
          },
          {
            "sppg_id": 3,
            "kode": "SPPG-T0003-7bd878",
            "nama": "SPPG C",
            "status_operasional": "aktif",
            "target_porsi": 0,
            "realisasi_porsi": 0,
            "capaian_persen": null,
            "distribusi_jadwal": 0,
            "distribusi_selesai": 0,
            "keluhan_terbuka": 0,
            "keluhan_kritis": 0,
            "stok_di_bawah_minimum": 0
          }
        ]
      },
      "pengguna_aktif_per_peran": {
        "periode": {
          "jenis": "saat_ini",
          "per": "2026-11-02"
        },
        "cakupan": "semua_sppg",
        "total_aktif": 10,
        "items": [
          {
            "peran": "admin",
            "label": "Admin",
            "aktif": 1,
            "nonaktif": 0
          },
          {
            "peran": "kepala_sppg",
            "label": "Kepala SPPG",
            "aktif": 2,
            "nonaktif": 0
          },
          {
            "peran": "ahli_gizi",
            "label": "Ahli Gizi",
            "aktif": 1,
            "nonaktif": 0
          },
          {
            "peran": "akuntan",
            "label": "Akuntan",
            "aktif": 1,
            "nonaktif": 0
          },
          {
            "peran": "staf_gudang",
            "label": "Staf Gudang",
            "aktif": 1,
            "nonaktif": 0
          },
          {
            "peran": "relawan",
            "label": "Relawan",
            "aktif": 2,
            "nonaktif": 0
          },
          {
            "peran": "investor",
            "label": "Investor",
            "aktif": 2,
            "nonaktif": 0
          }
        ],
        "labels": ["Admin", "Kepala SPPG", "Ahli Gizi", "Akuntan", "Staf Gudang", "Relawan", "Investor"],
        "series": {
          "aktif": [1, 2, 1, 1, 1, 2, 2]
        }
      },
      "login_gagal": {
        "periode": {
          "jenis": "tren",
          "rentang_hari": 7,
          "dari": "2026-10-27",
          "sampai": "2026-11-02"
        },
        "cakupan": "sistem",
        "hari_ini": {
          "masuk_gagal": 0,
          "token_salah": 0,
          "akun_terkunci": 0
        },
        "total_rentang": 0,
        "akun_terkunci_saat_ini": 0,
        "tren": {
          "labels": ["2026-10-27", "2026-10-28", "2026-10-29", "2026-10-30", "2026-10-31", "2026-11-01", "2026-11-02"],
          "series": {
            "masuk_gagal": [0, 0, 0, 0, 0, 0, 0],
            "token_salah": [0, 0, 0, 0, 0, 0, 0],
            "akun_terkunci": [0, 0, 0, 0, 0, 0, 0],
            "ditolak_terkunci": [0, 0, 0, 0, 0, 0, 0]
          }
        }
      },
      "antrean_job_webhook": {
        "periode": {
          "jenis": "saat_ini",
          "per": "2026-11-02"
        },
        "cakupan": "sistem",
        "antrean": {
          "menunggu_atau_ditunda": 0,
          "sedang_diproses": 0
        },
        "job_gagal": {
          "total": 0,
          "dalam_rentang": 0
        },
        "webhook_tersangkut": {
          "jumlah": 0,
          "tertua_menit": null
        }
      },
      "draf_ai_tertunda": {
        "periode": {
          "jenis": "saat_ini",
          "per": "2026-11-02"
        },
        "cakupan": "semua_sppg",
        "menunggu_konfirmasi": 0,
        "kedaluwarsa_belum_dibersihkan": 0,
        "per_modul": []
      }
    }
  }
}
```
