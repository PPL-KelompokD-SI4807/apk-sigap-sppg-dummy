<?php

namespace App\Services\Ai\Tools;

/**
 * Tool yang secara EKSPLISIT menerima sebagian argumen terlindungi (config ai.protected_arguments) sebagai isian sah,
 * mis. tool undangan/peran pengguna (Admin) menerima `role`, `unit`, `sppg_id`. Hanya kunci di
 * ArgumentValidator::DAPAT_DIIZINKAN yang dapat diizinkan, hanya di tingkat atas, dan nilainya tetap divalidasi
 * skema (enum) + Form Request modul + Policy. Tool lain tidak terpengaruh (kunci tetap dibuang).
 */
interface MenerimaArgumenTerlindungi
{
    /** @return list<string> */
    public function argumenTerlindungiSah(): array;
}
