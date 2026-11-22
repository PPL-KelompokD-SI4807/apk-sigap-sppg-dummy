<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Models\User;
use Closure;

/**
 * Satu seksi daftar pada layar baca: sumber tool baca katalog, argumen (dibangun server), whitelist kolom tampil,
 * dan pemformat satu baris (menerima HANYA kolom whitelist, mengembalikan teks biasa yang di-escape layar).
 */
final class SeksiDaftar
{
    /**
     * @param  string  $sumber  kunci ResourceSpec katalog (mis. 'bahan_baku' -> cari_bahan_baku)
     * @param  list<string>  $kolom  whitelist kolom tampil
     * @param  Closure(array<string, mixed>): string  $format
     * @param  array<string, mixed>|Closure(User): array<string, mixed>  $argumen
     */
    public function __construct(
        public readonly string $sumber,
        public readonly string $judul,
        public readonly array $kolom,
        public readonly Closure $format,
        public readonly array|Closure $argumen = [],
        public readonly ?string $kosong = null,
    ) {}

    /** @return array<string, mixed> */
    public function argumenUntuk(User $user): array
    {
        return $this->argumen instanceof Closure ? ($this->argumen)($user) : $this->argumen;
    }
}
