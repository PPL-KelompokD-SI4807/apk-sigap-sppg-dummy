<?php

namespace App\Services\Ai;

/**
 * Konteks percobaan satu pesan Agen AI (R-05 retry 429 token per menit), dari job ProcessAiMessage via AiChatHandler.
 *
 * - ke: percobaan ke-n (attempts job); > 1 = proses ulang setelah release.
 * - bolehTunda: 429 token per menit boleh ditunda (job di-release) alih-alih dibalas "sedang ramai".
 * - interaksiId: AiInteraction percobaan sebelumnya (dipakai ulang: tidak digandakan). Batas laju pengguna dan pelindung
 *   token TETAP dicek dan dihitung pada setiap percobaan (setiap percobaan memanggil penyedia; retry tidak menembusnya).
 * - teksVision: hasil baca foto percobaan sebelumnya (DATA TAK TEPERCAYA; dipakai ulang agar vision tidak dipanggil dua
 *   kali). Hanya disimpan terenkripsi di Cache dengan TTL pendek; tidak pernah di-log.
 */
final class PercobaanAi
{
    public function __construct(
        public readonly int $ke = 1,
        public readonly bool $bolehTunda = false,
        public readonly ?int $interaksiId = null,
        public readonly ?string $teksVision = null,
    ) {}

    public function ulang(): bool
    {
        return $this->ke > 1;
    }
}
