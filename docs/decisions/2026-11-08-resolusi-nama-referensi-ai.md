# ADR: Resolusi nama referensi oleh server untuk Agen AI

- Tanggal: 2026-11-08
- Status: Diusulkan (menunggu tinjauan manusia dan uji manual di bot)
- Tier: T3 (Agen AI: ToolRegistry, AiDraft, tombol bot)
- Branch: `feat/ai-resolusi-nama-referensi`

## Konteks

Pada perintah bot "buatkan draf menu ... bahan baku ayam 10 gram per porsi", Agen AI menjawab "saya perlu ID bahan baku ayam, jalankan cari_bahan_baku q=ayam". Pengguna tidak boleh diminta ID, dan LLM tidak boleh dipercaya mengarang atau menyalin ID.

## Keputusan

1. **Nama dipetakan ke ID di server**, di dalam tool tulis, lewat satu layanan generik (`App\Services\Ai\Referensi\ResolverReferensi` dengan `JenisReferensi`). Tool menerima `{nama}` atau `{id}`.
2. **Cakupan:** hanya SPPG aktif pengguna, data terhapus tidak ikut, pengguna wajib punya hak baca modul terkait dan lolos Policy. Admin tanpa SPPG ditolak. ID yang dikirim LLM tetap divalidasi; ID milik SPPG lain dijawab "tidak ditemukan" tanpa membocorkan eksistensinya.
3. **Urutan cocok:** sama persis (tanpa beda huruf besar/kecil, spasi dirapikan), lalu awalan, lalu mengandung.
4. **Hasil:** tepat satu dipakai. Lebih dari satu **tidak ditebak**: draf dibuat dengan baris kosong (tanpa ID, gagal validasi) dan pilihan tertunda (`_pilihan`, hanya ID, maks 5 kandidat). Bot menampilkan satu tombol per kandidat (`ai_pilih`); Mini App menerima `data.pilihan_referensi` dari `GET /ai-drafts/{uuid}`. Tidak ditemukan: draf tidak dibuat, bahan tidak pernah dibuat otomatis; pengguna diarahkan membuat bahan lewat draf terpisah (atau meminta peran yang berhak).
5. **Satuan:** konversi hanya pasangan pasti (mg/g/kg, ml/l) dengan desimal berbasis string, bukan float. Massa vs volume, satuan tak dikenal, atau hasil lebih dari 2 desimal tidak dibuatkan draf; model diminta bertanya ulang.
6. **Aksi tulis tetap AiDraft yang dikonfirmasi pengguna.** Jalur eksekusi PR #49 tidak berubah; draf yang masih punya pilihan tertunda tidak pernah dieksekusi dari chat (`AiDraftExecutor::hambatan`). Pratinjau menampilkan nama dan satuan bahan terpilih ("Bahan: Ayam Fillet (kg) - 10 gram per porsi").
7. **Keamanan masukan:** nama adalah data. Parameter terikat; `! % _` di-escape dengan `ESCAPE '!'`; maks 100 karakter; karakter format/bidi dibuang. Nama dari DB diratakan satu baris dan dipotong 60 karakter; tidak pernah masuk prompt sistem, hanya hasil tool terbungkus dan layar yang di-escape HTML.
8. **Tombol `ai_pilih`:** sekali pakai per layar; saat ditekan server memeriksa ulang dalam transaksi `lockForUpdate` (pengguna aktif, draf miliknya, SPPG sama, masih pending dan belum kedaluwarsa, hak peran saat ini, kandidat ada di daftar dan masih milik SPPG). Memilih hanya mengubah isi draf.
9. **Prompt/skema:** LLM langsung mengirim nama; skema tidak lagi menyebut `cari_bahan_baku`. Penambahan skema `buat_menu` +~24 token dan satu aturan prompt ~48 token (batas Groq ±8000 TPM dijaga).
10. **Diterapkan pertama pada draf menu** (`MenuWriteTool`).

## Alternatif yang ditolak

- **LLM memanggil `cari_*` lalu menyalin ID:** boros token Groq, ID bisa dikarang, dan memicu pertanyaan ID ke pengguna (bug asal).
- **Menebak kandidat pertama:** salah bahan diam-diam pada data gizi/stok.
- **Membuat bahan otomatis:** mencemari data master dan melewati hak tulis peran.
- **Konversi massa-volume dengan massa jenis:** tidak pasti.
- **Menyimpan nama kandidat di draf:** nama hanya data tampilan; cukup ID, nama dibaca ulang saat tampil.
- **Endpoint khusus pemilih di Mini App:** cukup formulir yang ada.

## Konsekuensi dan risiko

- `ubah_menu` dengan `bahan` mengganti seluruh komposisi (perilaku lama; deskripsi tool kini menyebutnya).
- Pencocokan huruf besar/kecil non-ASCII bergantung collation MySQL; spasi ganda yang tersimpan di DB tidak dirapikan.
- Sampai Mini App memakai `pilihan_referensi`, baris kosong tampil tanpa pilihan bahan; validasi klien yang ada mencegah tersimpan.
- Satu permintaan dapat memicu ~150 query (50 bahan x 3 tingkat), dibatasi rate limit.
- Perilaku LLM nyata (Groq) belum diuji: wajib uji manual di bot.

## Tahap berikut (resolver sudah generik)

- `buat_/ubah_pembelian_bahan` dan `catat_mutasi_stok` (bahan, pemasok).
- Jadwal produksi (menu) dan jadwal distribusi (titik layanan, armada).
- Pengeluaran (pemasok).
- Pengemudi **sengaja dikecualikan** karena berisi nama orang (UU PDP).
