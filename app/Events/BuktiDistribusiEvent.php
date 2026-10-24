<?php

namespace App\Events;

use App\Models\BuktiDistribusi;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Basis event domain bukti distribusi (PBI 13). Hanya nilai skalar (aman diserialisasi ke antrean);
 * notifikasi Telegram dikirim listener. Dipancarkan setelah transaksi commit.
 */
abstract class BuktiDistribusiEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $id,
        public readonly int $sppgId,
        public readonly int $jadwalDistribusiId,
        public readonly int $titikLayananId,
        public readonly string $titikLayananNama,
        public readonly int $porsiDikirim,
        public readonly int $porsiDiterima,
        public readonly string $namaPic,
        public readonly string $waktuTiba,
        public readonly ?string $acuanTiba,
        public readonly bool $terlambat,
        public readonly ?int $menitKeterlambatan,
        public readonly ?int $dicatatOleh,
    ) {}

    public static function dari(BuktiDistribusi $b): static
    {
        $b->loadMissing('jadwalDistribusi.titikLayanan');
        $jadwal = $b->jadwalDistribusi;
        $tz = config('operasional.timezone', 'Asia/Jakarta');

        return new static(
            id: (int) $b->id,
            sppgId: (int) $b->sppg_id,
            jadwalDistribusiId: (int) $b->jadwal_distribusi_id,
            titikLayananId: (int) $jadwal?->titik_layanan_id,
            titikLayananNama: (string) $jadwal?->titikLayanan?->nama,
            porsiDikirim: (int) $jadwal?->jumlah_porsi,
            porsiDiterima: (int) $b->porsi_diterima,
            namaPic: (string) $b->nama_pic,
            waktuTiba: $b->waktu_tiba->copy()->setTimezone($tz)->toIso8601String(),
            acuanTiba: $b->acuan_tiba?->copy()->setTimezone($tz)->toIso8601String(),
            terlambat: (bool) $b->terlambat,
            menitKeterlambatan: $b->menit_keterlambatan,
            dicatatOleh: $b->dicatat_oleh,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sppg_id' => $this->sppgId,
            'jadwal_distribusi_id' => $this->jadwalDistribusiId,
            'titik_layanan' => ['id' => $this->titikLayananId, 'nama' => $this->titikLayananNama],
            'porsi_dikirim' => $this->porsiDikirim,
            'porsi_diterima' => $this->porsiDiterima,
            'selisih_porsi' => $this->porsiDikirim - $this->porsiDiterima,
            'nama_pic' => $this->namaPic,
            'waktu_tiba' => $this->waktuTiba,
            'acuan_tiba' => $this->acuanTiba,
            'terlambat' => $this->terlambat,
            'menit_keterlambatan' => $this->menitKeterlambatan,
            'dicatat_oleh' => $this->dicatatOleh,
        ];
    }
}
