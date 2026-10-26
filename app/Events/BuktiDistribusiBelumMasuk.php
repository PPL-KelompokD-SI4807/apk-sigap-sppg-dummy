<?php

namespace App\Events;

use App\Models\JadwalDistribusi;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Mobil sudah berangkat tetapi bukti distribusi belum masuk melewati jam rencana tiba + batas
 * (config operasional.bukti_distribusi.batas_belum_masuk_menit, default 60 menit). Dipancarkan
 * sekali per jadwal oleh `sigap:cek-bukti-distribusi` (penanda atomik bukti_belum_masuk_pada).
 * Hanya nilai skalar (aman diserialisasi ke antrean); notifikasi Telegram dikirim listener.
 */
class BuktiDistribusiBelumMasuk
{
    use Dispatchable;

    public function __construct(
        public readonly int $sppgId,
        public readonly int $jadwalDistribusiId,
        public readonly int $titikLayananId,
        public readonly string $titikLayananNama,
        /** Jam rencana tiba (HH:MM, WIB). */
        public readonly string $jamRencanaTiba,
        /** Menit sejak jam rencana tiba. */
        public readonly int $menitTerlambat,
    ) {}

    public static function dari(JadwalDistribusi $jadwal, int $menitTerlambat): self
    {
        return new self(
            sppgId: (int) $jadwal->sppg_id,
            jadwalDistribusiId: (int) $jadwal->id,
            titikLayananId: (int) $jadwal->titik_layanan_id,
            titikLayananNama: (string) $jadwal->titikLayanan?->nama,
            jamRencanaTiba: substr((string) $jadwal->jam_rencana_tiba, 0, 5),
            menitTerlambat: $menitTerlambat,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sppg_id' => $this->sppgId,
            'jadwal_distribusi_id' => $this->jadwalDistribusiId,
            'titik_layanan' => ['id' => $this->titikLayananId, 'nama' => $this->titikLayananNama],
            'jam_rencana_tiba' => $this->jamRencanaTiba,
            'menit_terlambat' => $this->menitTerlambat,
        ];
    }
}
