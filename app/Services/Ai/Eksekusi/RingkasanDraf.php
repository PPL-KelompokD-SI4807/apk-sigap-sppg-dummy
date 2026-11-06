<?php

namespace App\Services\Ai\Eksekusi;

/**
 * Ringkasan konfirmasi yang DIBANGUN SERVER dari payload draf tersimpan + data basis data (nama target), bukan dari
 * teks bebas LLM. Semua nilai teks polos; layar Bot meng-escape HTML sebelum mengirim.
 */
final class RingkasanDraf
{
    /**
     * @param  list<string>  $baris
     */
    public function __construct(
        public readonly string $judul,
        public readonly array $baris = [],
        public readonly ?string $target = null,
        public readonly ?string $peringatan = null,
    ) {}
}
