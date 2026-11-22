<?php

namespace App\Services\Ai\Eksekusi;

use App\Models\AiDraft;

/**
 * Hasil satu langkah konfirmasi draf. `rahasia` (mis. tautan undangan) hanya hidup di memori untuk dikirim
 * langsung ke pengguna; tidak pernah disimpan di AiDraft, ai_interactions, audit, log, atau dikirim ke LLM.
 */
final class HasilEksekusi
{
    public const SUKSES = 'sukses';

    /** Langkah 1 aksi berbahaya selesai; menunggu "Ya, yakin". */
    public const KONFIRMASI_ULANG = 'konfirmasi_ulang';

    public const DIBATALKAN = 'dibatalkan';

    public const DITOLAK = 'ditolak';

    public const TIDAK_ADA = 'tidak_ada';

    /** Data target berubah / versi tidak cocok (409): "data berubah, ulangi permintaan". */
    public const BERUBAH = 'berubah';

    public const VALIDASI = 'validasi';

    public const TERLALU_CEPAT = 'terlalu_cepat';

    public const TIDAK_BERLAKU = 'tidak_berlaku';

    public const TANPA_SESI = 'tanpa_sesi';

    /** Draf tidak dapat dijalankan dari chat (butuh berkas / isian perlu dicek / tanpa rute): hanya Ubah. */
    public const HANYA_MINIAPP = 'hanya_miniapp';

    public const GAGAL = 'gagal';

    public function __construct(
        public readonly string $kode,
        public readonly ?string $pesan = null,
        public readonly ?AiDraft $draft = null,
        #[\SensitiveParameter]
        public readonly ?string $rahasia = null,
    ) {}

    public function sukses(): bool
    {
        return $this->kode === self::SUKSES;
    }

    public function __debugInfo(): array
    {
        return ['kode' => $this->kode, 'draft' => $this->draft?->getKey(), 'rahasia' => $this->rahasia === null ? null : '[redacted]'];
    }
}
