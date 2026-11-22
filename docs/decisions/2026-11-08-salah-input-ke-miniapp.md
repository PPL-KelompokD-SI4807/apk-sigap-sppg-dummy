# ADR: Kesalahan input di bot langsung ke Mini App

- Tanggal: 2026-11-08
- Status: Diusulkan (menunggu tinjauan manusia dan uji manual di bot + Mini App)
- Tier: T3 (Agen AI: loop agen, AiDraft, tombol bot)
- Branch: `feat/ai-salah-input-ke-miniapp`
- Rencana: `docs/plan/2026-11-08-salah-input-ke-miniapp.md`

## Konteks

Uji staging (Ahli Gizi): "lemak 5 ml, protein 10 ml, bahan ay" membuat LLM bertanya balik di chat. Percakapan lalu melenceng ("tidak punya akses menambah bahan baku"), terkena batas token Groq ("Agen AI sedang sibuk"), dan konteks hilang. Penyebabnya, galat tool tulis (Form Request, skema argumen, satuan, rujukan tidak ditemukan) dikembalikan ke LLM dengan instruksi "tanyakan ke pengguna". Selain itu, "bahan ay" langsung menjadi "Ayam (kg)".

Aturan pengguna: bila input salah, bot tidak bertanya balik. Bot langsung memberi tombol "Buka di Mini App". Aturan ini berlaku untuk semua peran dan semua tool tulis.

## Keputusan

1. **Kesalahan ditangani server dengan aturan tetap.** Kategori: satuan tidak cocok/tak dikenal, rujukan tidak ditemukan, angka/tanggal tidak valid atau di luar batas, kolom wajib kosong, galat Form Request, dan argumen tool tulis yang tidak lolos skema (`PemisahArgumen`). AiDraft dibuat dari bagian yang valid. Kolom bergalat dikosongkan, dan galat per kolom disimpan di payload internal `_galat` (`GalatDraf`; kunci berpola ketat, pesan dipotong, tidak disajikan mentah).
2. **Loop agen berhenti** pada tool call pertama yang menghasilkan draf "perlu diperbaiki". Tidak ada panggilan LLM tambahan. Balasan berupa SATU pesan server: label modul, daftar label kolom, dan "Data BELUM disimpan". Pesan tidak memuat nilai masukan, ID, atau galat mentah. Bot menyertakan tombol web_app "Buka di Mini App" (`?draft=<uuid>`; tidak ada tombol bila URL bukan HTTPS).
3. **Kontrak API tanpa status baru.** `GET /ai-drafts/{uuid}`: `status` tetap `pending`, ada field baru `perlu_diperbaiki` (bool). `errors` berisi galat server dan galat dry-run dengan kunci bertitik (`bahan.0.bahan_baku_id`). Tidak ada migrasi.
4. **Draf "perlu diperbaiki" hanya diselesaikan di Mini App.** `AiDraftExecutor::hambatan` mengembalikan `HANYA_MINIAPP`. Draf ini tidak mendapat layar konfirmasi chat dan tidak mendapat Lanjutkan. Tetap tidak ada penyimpanan otomatis: pengguna menekan Simpan (`ai_draft_id`).
5. **Ambigu bukan kesalahan.** Bila lebih dari satu kandidat cocok (bukan sama persis) dan tidak ada galat lain, tombol `ai_pilih` tetap dipakai (PR #57). Bila draf punya galat lain sekaligus pilihan tertunda, semuanya dibuka di Mini App: formulir menampilkan kandidat dari `pilihan_referensi`. Rujukan yang tidak ditemukan masuk jalur kesalahan, dan bahan tidak pernah ditawarkan untuk dibuat otomatis.
6. **tool_use_failed (Groq HTTP 400):** percobaan ulang SATU kali dari PR #57 dipertahankan. Bila masih gagal, argumen dari `failed_generation` (tidak tepercaya) dijalankan lewat pipeline tool biasa: tool harus milik peran dan ditawarkan, argumen terlindungi dibuang, skema dipisah, lalu Form Request dijalankan. Kolom yang ditolak penyedia dikosongkan, lalu hasilnya menjadi draf "perlu diperbaiki". Tidak ada panggilan LLM ketiga. `failed_generation` tidak dicatat. Untuk tool baca, balasan ramah tetap seperti dulu.
7. **Multi-aksi terpotong** (diterima untuk PR ini). Bila tool call lain pada pesan yang sama tidak diproses, pesan server ditambah kalimat: "Permintaan lain dalam pesan yang sama BELUM diproses; kirim ulang setelah draf ini diperbaiki."
8. **Hak peran.** Tool tetap disaring `ToolRegistry::resolveFor` + Policy. Peran tanpa hak tulis modul tidak mendapat draf dan tidak mendapat tombol. Admin tanpa SPPG terpilih tetap baca-saja. Draf hanya milik pengguna dan SPPG-nya.
9. **Bug "bahan ay" -> "Ayam (kg)" terbukti lewat tes.** LLM mengirim "ayam" (nama dilengkapi), lalu tingkat "sama persis" lolos satu baris. Perbaikannya ada di dua sisi:
   - (a) Prompt aturan 9 dan deskripsi skema kini berbunyi "kirim nama persis seperti ditulis pengguna, jangan dilengkapi atau diperbaiki". Prompt bertambah ±31 token, skema +0.
   - (b) Penjaga server di `ResolverReferensi`: bila nama dari LLM tidak tertulis di pesan pengguna dan nama itu juga awalan lebih dari satu data, hasilnya menjadi pilihan, bukan tebakan. ID tanpa nama yang tidak tertulis di pesan pengguna dianggap karangan LLM (tidak valid, masuk jalur kesalahan).
10. **Markdown mentah** (`**`, `__`, backtick, heading) dibuang dari jawaban LLM. Balasan tetap teks polos tanpa parse_mode.

## Alternatif yang ditolak

- **LLM bertanya balik:** boros token Groq, konteks hilang, dan percakapan melenceng (bug asal).
- **Status DB baru `perlu_diperbaiki`:** butuh perubahan banyak titik status terbuka. Field turunan sudah cukup.
- **Menolak tanpa draf:** pengguna harus mengetik ulang semuanya.
- **Menyimpan otomatis bagian yang valid:** melanggar aturan konfirmasi manusia.

## Konsekuensi dan risiko

- Galat format yang sebenarnya kesalahan LLM (bukan kesalahan pengguna) juga diarahkan ke Mini App. Ini disengaja oleh keputusan pengguna.
- Penjaga ID: `ubah_menu` yang memakai ID bahan dari hasil tool baca (tanpa nama) akan diarahkan ke Mini App, bukan dijalankan dari chat.
- Penjaga "tertulis di pesan" memakai pencocokan substring huruf kecil. Ejaan berbeda (mis. "ayem") tetap mengikuti urutan cocok biasa.
- Modul Mini App dengan formulir kustom di luar crud-module mungkin hanya menampilkan galat di notice, tanpa sorotan kolom.
- Perilaku Groq nyata belum teruji otomatis. Uji manual di bot staging wajib.

## Tindak lanjut

- **Multi-aksi:** proses aksi yang valid dan tunda hanya aksi yang salah (atau antrekan sisanya) agar pengguna tidak perlu mengirim ulang.
- Terapkan rujukan nama + jalur kesalahan pada pembelian bahan, mutasi stok, jadwal, dan pengeluaran (lanjutan ADR PR #57).
