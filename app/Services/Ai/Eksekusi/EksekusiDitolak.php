<?php

namespace App\Services\Ai\Eksekusi;

use RuntimeException;

/** Penolakan eksekusi draf di dalam transaksi (memicu rollback); dipetakan ke HasilEksekusi oleh AiDraftExecutor. */
final class EksekusiDitolak extends RuntimeException
{
    public function __construct(
        public readonly string $kode,
        public readonly ?string $pesan = null,
        public readonly ?int $http = null,
    ) {
        parent::__construct($kode);
    }
}
