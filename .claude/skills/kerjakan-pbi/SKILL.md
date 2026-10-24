---
name: kerjakan-pbi
description: Menjalankan satu PBI SiGAP SPPG dari rencana sampai PR. Gunakan saat pengguna mengetik /kerjakan-pbi <nomor fitur 1-16> atau meminta mengerjakan fitur tertentu dari Subbab 5.2.
---

# Kerjakan PBI

Argumen: nomor fitur 1–16 (lihat tabel di CLAUDE.md), opsional catatan tambahan.

1. Baca baris fitur di CLAUDE.md dan PBI terkait (tujuan bisnis + user story). Sebutkan penanggung jawab manusianya.
2. Susun 4–8 tugas, masing-masing dengan tier T0–T3, contoh untuk fitur CRUD biasa:
   - [T1] backend: migrasi + model + Form Request + Policy + Controller + feature test
   - [T2] backend: logika bisnis khusus fitur (jika ada)
   - [T1] frontend: layar daftar + formulir sesuai mockup
   - [T2/T3] backend: tool Agen AI fitur (T3 bila menyentuh hak akses/keuangan)
   - [T0] github: branch, commit, PR, label `fitur-<no>`
3. Tampilkan rencana ke pengguna dalam satu tabel singkat, lalu langsung delegasikan (kecuali ada tugas T3 yang mengubah skema/keamanan: minta persetujuan dulu).
4. Delegasikan ke `backend` (backend-dev), `frontend` (frontend-dev), `github` (github-ops) dengan parameter `model` dan `effort` sesuai tier.
5. Setelah semua selesai: tinjau diff, pastikan DoD, minta `github` membuka PR, laporkan tautan PR dan sisa pekerjaan.
