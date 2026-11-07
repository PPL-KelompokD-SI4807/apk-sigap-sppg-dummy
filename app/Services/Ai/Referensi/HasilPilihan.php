<?php

namespace App\Services\Ai\Referensi;

use App\Models\AiDraft;

/** Hasil menerapkan satu pilihan rujukan dari tombol Bot (PilihanReferensiDraf::pilih). */
final class HasilPilihan
{
    public const DIPILIH = 'dipilih';

    public const TIDAK_BERLAKU = 'tidak_berlaku';

    public const DITOLAK = 'ditolak';

    /** Kandidat sudah tidak ada di SPPG pengguna (dihapus/dipindah) saat ditekan. */
    public const TIDAK_ADA = 'tidak_ada';

    /** Kandidat ada tetapi tidak dapat dipakai (mis. satuan tidak kompatibel, sudah ada di komposisi). */
    public const TIDAK_COCOK = 'tidak_cocok';

    public function __construct(
        public readonly string $kode,
        public readonly ?AiDraft $draft = null,
        public readonly ?string $pesan = null,
    ) {}
}
