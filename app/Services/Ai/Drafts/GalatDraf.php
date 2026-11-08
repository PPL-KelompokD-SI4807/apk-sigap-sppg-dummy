<?php

namespace App\Services\Ai\Drafts;

use App\Models\AiDraft;
use Illuminate\Support\Arr;

/**
 * Galat per kolom pada draf "perlu diperbaiki" (payload internal `_galat`, tidak pernah disajikan mentah).
 *
 * Bila masukan pengguna salah (satuan tak cocok, rujukan tidak ditemukan, angka/tanggal tidak valid, kolom wajib kosong,
 * galat Form Request, atau argumen tool tidak lolos skema), server TIDAK bertanya balik lewat LLM: draf dibuat dari
 * bagian yang valid (kolom tidak valid dikosongkan), galat disimpan di sini, loop agen berhenti, dan bot mengirim SATU
 * pesan server + tombol web_app ke formulir draf. Draf seperti ini tidak pernah dijalankan dari chat
 * (AiDraftExecutor::hambatan) dan tidak menampilkan layar konfirmasi/pilihan.
 *
 * Kunci bertitik untuk baris (mis. `bahan.0.bahan_baku_id`), sama dengan kontrak `errors` GET /ai-drafts/{uuid}.
 */
final class GalatDraf
{
    public const KUNCI = '_galat';

    public const MAKS_KOLOM = 30;

    public const MAKS_PESAN = 3;

    public const MAKS_PANJANG = 160;

    public const MAKS_KOLOM_BALASAN = 6;

    /** Ditambahkan ke balasan bila aksi lain pada pesan yang sama tidak diproses karena loop berhenti. */
    public const PESAN_TERTUNDA = 'Permintaan lain dalam pesan yang sama BELUM diproses; kirim ulang setelah draf ini diperbaiki.';

    /**
     * Galat yang aman disimpan: kunci kolom berpola ketat, pesan teks satu baris dan dipotong.
     *
     * @param  array<mixed>  $galat
     * @return array<string, list<string>>
     */
    public static function bersihkan(array $galat): array
    {
        $hasil = [];

        foreach ($galat as $kolom => $pesan) {
            if (! is_string($kolom) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}(\.[A-Za-z0-9_]{1,64}){0,4}$/', $kolom) !== 1 || str_starts_with($kolom, '_')) {
                continue;
            }

            $daftar = [];

            foreach ((array) $pesan as $p) {
                if (! is_string($p)) {
                    continue;
                }

                $p = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $p));

                if ($p !== '' && ! in_array($p, $daftar, true)) {
                    $daftar[] = mb_strlen($p) > self::MAKS_PANJANG ? rtrim(mb_substr($p, 0, self::MAKS_PANJANG - 1)).'…' : $p;
                }
            }

            if ($daftar !== []) {
                $hasil[$kolom] = array_slice(array_values(array_unique(array_merge($hasil[$kolom] ?? [], $daftar))), 0, self::MAKS_PESAN);
            }

            if (count($hasil) >= self::MAKS_KOLOM) {
                break;
            }
        }

        return $hasil;
    }

    /**
     * Gabung galat: pesan dari $utama didahulukan; kolom yang sudah ada di $utama tidak ditambah pesan $lain (pesan server
     * lebih spesifik daripada "wajib diisi" hasil dry-run setelah kolomnya dikosongkan).
     *
     * @param  array<string, list<string>>  $utama
     * @param  array<string, list<string>>  $lain
     * @return array<string, list<string>>
     */
    public static function gabung(array $utama, array $lain): array
    {
        foreach ($lain as $kolom => $pesan) {
            if (! array_key_exists($kolom, $utama)) {
                $utama[$kolom] = $pesan;
            }
        }

        return $utama;
    }

    /** @return array<string, list<string>> galat tersimpan pada draf (sudah dibersihkan ulang). */
    public static function dari(AiDraft $draft): array
    {
        $galat = ((array) $draft->payload)[self::KUNCI] ?? null;

        return is_array($galat) ? self::bersihkan($galat) : [];
    }

    public static function ada(AiDraft $draft): bool
    {
        return self::dari($draft) !== [];
    }

    /**
     * Kosongkan kolom yang bergalat di payload: nilai skalar pada jalur galat dibuang; larik (mis. seluruh komposisi
     * `bahan`) dipertahankan agar baris valid lain tidak hilang.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<string>>  $galat
     * @return array<string, mixed>
     */
    public static function kosongkan(array $payload, array $galat): array
    {
        foreach (array_keys($galat) as $kolom) {
            if (Arr::has($payload, $kolom) && ! is_array(Arr::get($payload, $kolom))) {
                Arr::forget($payload, $kolom);
            }
        }

        return $payload;
    }

    /** Label kolom untuk balasan chat: `lemak_g` -> "Lemak (g)", `bahan.2.bahan_baku_id` -> "Bahan baris 3". */
    public static function labelKolom(string $kolom): string
    {
        $bagian = explode('.', $kolom);
        $dasar = (string) (preg_replace(['/_id$/', '/_(g|kg|kkal|km|ml|l)$/'], ['', ' ($1)'], $bagian[0]) ?? $bagian[0]);
        $teks = str_replace('_', ' ', $dasar);
        $teks = mb_strtoupper(mb_substr($teks, 0, 1)).mb_substr($teks, 1);

        if (isset($bagian[1]) && ctype_digit($bagian[1])) {
            $teks .= ' baris '.((int) $bagian[1] + 1);
        }

        return $teks;
    }

    /**
     * SATU pesan balasan server (teks polos): modul + daftar kolom yang perlu diperbaiki. Tanpa nilai masukan pengguna,
     * tanpa ID, tanpa pesan galat mentah.
     *
     * @param  array<string, list<string>>  $galat
     */
    public static function pesanBalasan(string $labelModul, array $galat): string
    {
        $kolom = array_values(array_unique(array_map(self::labelKolom(...), array_keys($galat))));
        $daftar = implode(', ', array_slice($kolom, 0, self::MAKS_KOLOM_BALASAN));

        if (count($kolom) > self::MAKS_KOLOM_BALASAN) {
            $daftar .= ', dan '.(count($kolom) - self::MAKS_KOLOM_BALASAN).' kolom lain';
        }

        $label = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', mb_substr($labelModul, 0, 40)));

        return 'Draf '.($label === '' ? 'data' : mb_strtolower($label)).' sudah disiapkan, tetapi ada isian yang perlu diperbaiki: '
            .($daftar === '' ? 'beberapa kolom' : $daftar).'. '
            .'Buka di Mini App untuk memperbaiki lalu simpan. Data BELUM disimpan.';
    }
}
