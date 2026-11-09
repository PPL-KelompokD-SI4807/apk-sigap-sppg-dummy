<?php

namespace App\Services\Ai;

/**
 * Sinyal dari AiAgentService: penyedia membalas 429 token per menit dan BELUM ada draf -> pesan diproses ulang setelah
 * $detik (job di-release), bukan dibalas "sedang ramai". $interaksiId dan $teksVision dibawa ke percobaan berikutnya
 * (lihat PercobaanAi) supaya AiInteraction tidak digandakan dan model vision tidak dipanggil dua kali.
 */
final class TundaAi
{
    public function __construct(
        public readonly int $detik,
        public readonly int $interaksiId,
        public readonly ?string $teksVision = null,
    ) {}
}
