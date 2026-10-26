<?php

namespace App\Events;

use App\Models\Pengeluaran;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pengeluaran diajukan ke investor (notifikasi ke investor pemilik SPPG via pivot investor_sppg).
 * Sengaja TANPA `uraian` (teks bebas): hanya kategori, nominal, tanggal, dan serapan anggaran periode.
 * `versi` membedakan pengajuan ulang setelah pengeluaran diubah (dedup per versi).
 */
class PengeluaranDiajukan implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $id,
        public readonly int $sppgId,
        public readonly int $versi,
        public readonly string $kategori,
        public readonly int $nominal,
        public readonly string $tanggal,
        public readonly string $periode,
        public readonly int $anggaran,
        public readonly int $terpakai,
    ) {}

    /** @param array{periode: string, anggaran: int, terpakai: int} $ringkasan serapan anggaran (hanya pengeluaran disetujui) */
    public static function dari(Pengeluaran $p, int $nominal, array $ringkasan): self
    {
        return new self(
            id: (int) $p->id,
            sppgId: (int) $p->sppg_id,
            versi: (int) $p->versi,
            kategori: $p->kategori->value,
            nominal: $nominal,
            tanggal: $p->tanggal->toDateString(),
            periode: $ringkasan['periode'],
            anggaran: $ringkasan['anggaran'],
            terpakai: $ringkasan['terpakai'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sppg_id' => $this->sppgId,
            'versi' => $this->versi,
            'kategori' => $this->kategori,
            'nominal' => $this->nominal,
            'tanggal' => $this->tanggal,
            'periode' => $this->periode,
            'anggaran' => $this->anggaran,
            'terpakai' => $this->terpakai,
        ];
    }
}
