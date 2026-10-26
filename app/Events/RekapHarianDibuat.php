<?php

namespace App\Events;

use App\Models\RekapHarian;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Snapshot rekap harian siap dikirim (listener bot mengirim ke Kepala SPPG dan pengawas). */
class RekapHarianDibuat implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param array<string, mixed> $ringkasan */
    public function __construct(
        public readonly int $id,
        public readonly int $sppgId,
        public readonly string $tanggal,
        public readonly array $ringkasan,
    ) {}

    public static function dari(RekapHarian $rekap): self
    {
        return new self(
            id: (int) $rekap->id,
            sppgId: (int) $rekap->sppg_id,
            tanggal: $rekap->tanggal->toDateString(),
            ringkasan: $rekap->ringkasan ?? [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'sppg_id' => $this->sppgId, 'tanggal' => $this->tanggal, 'ringkasan' => $this->ringkasan];
    }
}
