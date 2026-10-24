<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Basis event serapan anggaran (FR-09/FR-10). Dipancarkan SEKALI per (sppg, periode, ambang)
 * oleh BudgetService (penanda unik tabel peringatan_anggarans), setelah transaksi commit.
 * Nominal dalam rupiah bulat (integer).
 */
abstract class AnggaranEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** anggaran - terpakai (negatif bila melampaui). */
    public readonly int $sisa;

    public function __construct(
        public readonly int $sppgId,
        public readonly string $periode,
        public readonly int $anggaran,
        public readonly int $terpakai,
        public readonly float $persen,
    ) {
        $this->sisa = $anggaran - $terpakai;
    }

    abstract public function ambang(): string;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sppg_id' => $this->sppgId,
            'periode' => $this->periode,
            'ambang' => $this->ambang(),
            'anggaran' => $this->anggaran,
            'terpakai' => $this->terpakai,
            'sisa' => $this->sisa,
            'persen' => $this->persen,
        ];
    }
}
