# Uji Beban NFR-01 (k6)

Skrip: `scripts/k6/beban.js`. Skenario:

| Skenario | Eksekutor | Isi | Ambang |
|---|---|---|---|
| `baca` | 50 VU konstan, 5 menit, `sleep(1)` | GET `/api/v1/jadwal`, `/api/v1/jadwal/distribusi`, `/api/v1/bahan-baku`, `/api/v1/dashboard` | p(95) < 500 ms |
| `tulis` | 50 VU x 1 iterasi | POST `/api/v1/keluhan` (deskripsi berpenanda `UJI-BEBAN`, data fiktif) | p(95) < 1000 ms |

Ambang global: `http_req_failed` rate < 1%. Check status 200 (baca) dan 200/201 (tulis).

## Peringatan

- Jalankan HANYA di staging atau lokal, jangan produksi. Skrip menulis 50 keluhan uji dan memberi beban tinggi.
- Rate limit: grup rute `/api/v1/*` memakai `throttle:api` = 60 permintaan/menit per pengguna (`AppServiceProvider`, limiter `api`, kunci per ID pengguna). Skenario `baca` mengirim sekitar 4 permintaan per VU per detik, jadi satu token akan cepat menerima HTTP 429 dan ambang gagal. Pilihan:
  1. Kirim banyak token akun uji lewat `K6_TOKENS` (dipisah koma); skrip memakai token bergilir per VU. Tetap perlu cukup token agar tiap pengguna di bawah 60/menit (tidak realistis untuk 50 VU), atau
  2. Di staging saja, naikkan sementara limiter `api` (mis. lewat penyesuaian lokal di `AppServiceProvider`, jangan di-commit) lalu kembalikan sesudah uji, atau jalankan dengan beberapa token dan kurangi VU.
  Hasil uji dengan 429 tidak mewakili kinerja aplikasi; catat dalam laporan bila throttle sengaja dibiarkan aktif.
- Limiter `unggah` tidak berlaku pada `POST /keluhan` (tanpa lampiran).

## Memasang k6 (Windows)

Pilih salah satu:

```powershell
winget install k6 --source winget
# atau
choco install k6
# atau unduh MSI dari https://k6.io/docs/get-started/installation/
```

Tutup lalu buka ulang PowerShell, lalu cek `k6 version`.

## Mendapatkan token uji

Gunakan akun uji khusus (bukan akun pribadi). Token harus berkemampuan `auth:penuh`.

- Cara resmi: masuk lewat Mini App/web dengan akun uji; token Sanctum sesi didapat dari respons login (jangan dibagikan atau disimpan di repo).
- Cara cepat di staging (tinker, dijalankan di server staging):

```bash
docker compose exec app php artisan tinker --execute="echo \App\Models\User::where('username','AKUN_UJI')->firstOrFail()->createToken('k6','[auth:penuh]')->plainTextToken;"
```

  Sesuaikan kolom pencarian (`username`/`email`) dengan skema `users`. Cabut token sesudah uji: `User::find(ID)->tokens()->where('name','k6')->delete()`.
- Akun Admin wajib menyertakan `sppg_id` pada POST keluhan: set `K6_SPPG_ID`. Akun non-Admin (mis. petugas SPPG yang berhak membuat keluhan) memakai SPPG-nya sendiri. Pastikan peran akun uji boleh `create` keluhan dan membaca keempat endpoint.

## Menjalankan

PowerShell:

```powershell
$env:BASE_URL = "https://staging.example.test"
$env:K6_TOKEN = "<token>"
$env:K6_SPPG_ID = "1"   # hanya bila Admin
k6 run scripts/k6/beban.js
```

Bash:

```bash
BASE_URL=https://staging.example.test K6_TOKEN='<token>' K6_SPPG_ID=1 k6 run scripts/k6/beban.js
```

Bila `BASE_URL` atau token kosong, skrip berhenti dengan pesan jelas pada tahap `setup`.

## Membaca hasil

- Baris `http_req_duration{skenario:baca}` dan `{skenario:tulis}`: lihat nilai `p(95)`. Tanda centang hijau pada thresholds di akhir laporan = lulus (baca < 500 ms, tulis < 1000 ms).
- `http_req_failed` harus < 1%. Bila tinggi, cek `checks` dan kode status (429 = throttle, 401/403 = token atau hak peran salah, 422 = validasi).
- Lampirkan ringkasan k6 (salin teks akhir) ke laporan NFR-01 beserta spesifikasi server staging.

## Menghapus keluhan uji

Tabel `feedback_keluhans`, kolom `deskripsi`, memakai soft delete (`deleted_at`). Jalankan di staging, urut:

1. Hitung dahulu:

```bash
docker compose exec mysql mysql -u root -p -e "SELECT COUNT(*) FROM <nama_db>.feedback_keluhans WHERE deskripsi LIKE 'UJI-BEBAN%';"
```

2. Cadangkan baris itu (atau jalankan `scripts/backup.sh`):

```bash
docker compose exec mysql mysqldump -u root -p <nama_db> feedback_keluhans --where="deskripsi LIKE 'UJI-BEBAN%'" --no-create-info > uji-beban-cadangan.sql
```

3. Hapus permanen (jumlah harus sama dengan langkah 1, yaitu 50 per sekali uji):

```bash
docker compose exec app php artisan tinker --execute="echo \App\Models\FeedbackKeluhan::withoutGlobalScopes()->withTrashed()->where('deskripsi','like','UJI-BEBAN%')->forceDelete();"
```

Alternatif SQL: `DELETE FROM feedback_keluhans WHERE deskripsi LIKE 'UJI-BEBAN%';`. Baris audit (jejak `Auditable`) untuk keluhan itu tetap ada di tabel audit; itu perilaku wajar. Bila ada lampiran atau notifikasi terkait, periksa terpisah. Jangan jalankan penghapusan ini di produksi.
