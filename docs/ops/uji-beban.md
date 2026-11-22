# Uji Beban NFR-01 (k6)

Skrip: `scripts/k6/beban.js`. Skenario:

| Skenario | Eksekutor | Isi | Ambang |
|---|---|---|---|
| `baca` | 50 VU konstan, 5 menit, jeda acak 5-10 dtk per iterasi | 1 GET acak per iterasi dari `/api/v1/jadwal`, `/api/v1/jadwal/distribusi`, `/api/v1/bahan-baku`, `/api/v1/dashboard` | p(95) < 500 ms |
| `tulis` | 50 VU x 1 iterasi | POST `/api/v1/keluhan` (deskripsi berpenanda `UJI-BEBAN`, data fiktif) | p(95) < 1000 ms |

Ambang global: `http_req_failed` rate < 1%. Check status 200 (baca) dan 200/201 (tulis).

## Peringatan

- Jalankan HANYA di staging atau lokal, jangan produksi. Skrip menulis 50 keluhan uji dan memberi beban tinggi.
- Rate limit: grup rute `/api/v1/*` memakai `throttle:api` = 60 permintaan/menit per pengguna (`AppServiceProvider`, limiter `api`, kunci per ID pengguna). Throttle TIDAK dilonggarkan. Karena kuncinya ID pengguna, beban dibagi ke 10 akun uji berbeda, masing-masing satu token, lewat `K6_TOKENS` (dipisah koma). VU dibagi rata: `token = TOKENS[(__VU - 1) % jumlah_token]`.
- Hitungan laju per token: 50 VU / 10 token = 5 VU per token. Tiap iterasi `baca` = 1 GET + jeda acak 5-10 dtk (rata-rata 7,5 dtk) = ~8 iterasi/menit/VU. Jadi 5 VU x ~8 = ~40 permintaan/menit per token (< 60). Skenario `tulis` menambah 5 POST per token sekali di awal, total ~45 pada menit pertama. (Bila memakai 4 GET per iterasi: 5 x 4 x 8 = ~160/menit per token, melebihi 60, karena itu hanya 1 GET per iterasi.) Pada jeda minimum 5 dtk bisa sesaat mendekati 60/menit, tetapi rata-rata aman.
- `K6_TOKEN` tunggal hanya fallback; skrip memberi peringatan karena throttle pasti kena (429) dan hasil tidak mewakili kinerja aplikasi.
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

## Mendapatkan 10 token uji

Perlu 10 akun uji BERBEDA (throttle per pengguna, bukan per token). Akun harus fiktif, ada di staging, berperan yang boleh membaca keempat endpoint dan `create` keluhan, dan berada di SPPG staging yang sama (mis. `uji01` sampai `uji10`; buat lewat fitur Data Pengguna atau seeder staging). Akun Admin wajib `K6_SPPG_ID`; akun non-Admin memakai SPPG-nya sendiri.

Token dibuat langsung via Sanctum `createToken` di server staging (jalur login normal terikat perangkat tepercaya dan batas sesi, kurang cocok untuk 10 token otomatis). Ability: `auth:penuh` + ability peran (`Rbac::abilities`), kedaluwarsa singkat. Token hanya dicetak ke terminal, jangan ditulis ke berkas repo.

```bash
docker compose exec app php artisan tinker --execute="foreach (range(1,10) as \$i) { \$u = \App\Models\User::where('username', sprintf('uji%02d', \$i))->firstOrFail(); echo \$u->createToken('k6', ['auth:penuh', ...\App\Support\Rbac::abilities(\$u)], now()->addHours(3))->plainTextToken, PHP_EOL; }"
```

Sesuaikan pola `username` dengan akun uji. Keluaran: 10 baris token.

Menyusun `K6_TOKENS` (tempel 10 token dari keluaran di atas, tanpa menyimpannya di berkas):

```powershell
$env:K6_TOKENS = "tok1,tok2,tok3,tok4,tok5,tok6,tok7,tok8,tok9,tok10"
```

```bash
export K6_TOKENS='tok1,tok2,tok3,tok4,tok5,tok6,tok7,tok8,tok9,tok10'
```

Atau dari keluaran perintah (bash, token tidak menyentuh berkas):

```bash
export K6_TOKENS=$(docker compose exec -T app php artisan tinker --execute="..." | tr -d '\r' | paste -sd, -)
```

Mencabut token sesudah uji (wajib):

```bash
docker compose exec app php artisan tinker --execute="\App\Models\User::where('username','like','uji%')->get()->each(fn (\$u) => \$u->tokens()->where('name','k6')->delete());"
```

Lalu kosongkan variabel: PowerShell `Remove-Item Env:K6_TOKENS`, bash `unset K6_TOKENS`. Token juga kedaluwarsa otomatis dalam 3 jam.

## Menjalankan

PowerShell:

```powershell
$env:BASE_URL = "https://staging.example.test"
# K6_TOKENS sudah di-set (lihat atas)
$env:K6_SPPG_ID = "1"   # hanya bila Admin
k6 run scripts/k6/beban.js
```

Bash:

```bash
BASE_URL=https://staging.example.test K6_TOKENS="$K6_TOKENS" K6_SPPG_ID=1 k6 run scripts/k6/beban.js
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
