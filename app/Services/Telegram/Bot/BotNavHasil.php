<?php

namespace App\Services\Telegram\Bot;

/** Hasil BotNavigasi::buka. `layar` hanya terisi bila status OK. */
final class BotNavHasil
{
    public const OK = 'ok';

    /** Pengguna tidak aktif / tanpa sesi penuh (pemanggil seharusnya sudah menolak lebih dulu). */
    public const TANPA_SESI = 'tanpa_sesi';

    /** Peran/RBAC/Policy/SPPG saat ini tidak mengizinkan layar ini. */
    public const TIDAK_BERHAK = 'tidak_berhak';

    /** Layar tidak dikenal (belum dibangun / versi lama). */
    public const TIDAK_TERSEDIA = 'tidak_tersedia';

    /** Layar butuh SPPG terpilih (admin / investor banyak SPPG). */
    public const PILIH_SPPG = 'pilih_sppg';

    /** Dibuka terlalu cepat (jeda per pengguna per layar). */
    public const TERLALU_CEPAT = 'terlalu_cepat';

    private function __construct(
        public readonly string $status,
        public readonly ?BotLayar $layar = null,
    ) {}

    public static function ok(BotLayar $layar): self
    {
        return new self(self::OK, $layar->terlindungi());
    }

    public static function gagal(string $status): self
    {
        return new self($status);
    }

    public function berhasil(): bool
    {
        return $this->status === self::OK;
    }
}
