<?php

namespace App\Events;

use App\Models\PengajuanMenu;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/** Investor memutuskan pengajuan menu (notifikasi ke ahli gizi SPPG). */
class MenuDiputuskan implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $id,
        public readonly int $sppgId,
        public readonly int $menuId,
        public readonly string $namaMenu,
        public readonly string $keputusan,
        public readonly ?string $catatan,
        public readonly float $biayaPerPorsi,
        public readonly int $jumlahPorsi,
        public readonly float $totalBiaya,
    ) {}

    public static function dari(PengajuanMenu $p): self
    {
        return new self(
            id: (int) $p->id,
            sppgId: (int) $p->sppg_id,
            menuId: (int) $p->menu_id,
            namaMenu: (string) ($p->rincian['menu']['nama_menu'] ?? ''),
            keputusan: $p->status->value,
            catatan: $p->catatan_keputusan === null ? null : Str::limit($p->catatan_keputusan, 300),
            biayaPerPorsi: (float) $p->biaya_per_porsi,
            jumlahPorsi: (int) $p->jumlah_porsi,
            totalBiaya: (float) $p->total_biaya,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sppg_id' => $this->sppgId,
            'menu_id' => $this->menuId,
            'nama_menu' => $this->namaMenu,
            'keputusan' => $this->keputusan,
            'catatan' => $this->catatan,
            'biaya_per_porsi' => $this->biayaPerPorsi,
            'jumlah_porsi' => $this->jumlahPorsi,
            'total_biaya' => $this->totalBiaya,
        ];
    }
}
