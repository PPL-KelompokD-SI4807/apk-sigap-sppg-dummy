<?php

namespace App\Services\Ai\Referensi;

/**
 * Hasil ResolverReferensi untuk satu rujukan {nama} atau {id}:
 *   tepat      = tepat satu data cocok ($item) -> boleh dipakai;
 *   ambigu     = >1 cocok -> JANGAN menebak, tampilkan $kandidat (maks ResolverReferensi::MAKS_PILIHAN);
 *   tidak_ada  = tidak ada data cocok di SPPG pengguna (termasuk ID milik SPPG lain / terhapus);
 *   tidak_valid = rujukan kosong / bukan teks / bukan ID;
 *   ditolak    = pengguna tidak berhak membaca jenis data ini atau tidak punya SPPG aktif.
 */
final class HasilResolusi
{
    public const TEPAT = 'tepat';

    public const AMBIGU = 'ambigu';

    public const TIDAK_ADA = 'tidak_ada';

    public const TIDAK_VALID = 'tidak_valid';

    public const DITOLAK = 'ditolak';

    /**
     * @param  list<KandidatReferensi>  $kandidat
     * @param  string  $dicari  rujukan yang dicari, sudah dinormalisasi (untuk pesan; bukan data basis data)
     */
    public function __construct(
        public readonly string $status,
        public readonly ?KandidatReferensi $item = null,
        public readonly array $kandidat = [],
        public readonly bool $lebihBanyak = false,
        public readonly string $dicari = '',
    ) {}

    public function tepat(): bool
    {
        return $this->status === self::TEPAT && $this->item !== null;
    }
}
