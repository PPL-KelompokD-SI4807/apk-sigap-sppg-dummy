<?php

namespace App\Events;

use App\Models\PengajuanMenu;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Menu diajukan ke investor (notifikasi ke investor pemilik SPPG via pivot investor_sppg). */
class MenuDiajukan implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $id,
        public readonly int $sppgId,
        public readonly int $menuId,
        public readonly string $namaMenu,
        public readonly float $biayaPerPorsi,
        public readonly int $jumlahPorsi,
        public readonly float $totalBiaya,
    ) {}

    public static function dari(PengajuanMenu $p, string $namaMenu): self
    {
        return new self(
            id: (int) $p->id,
            sppgId: (int) $p->sppg_id,
            menuId: (int) $p->menu_id,
            namaMenu: $namaMenu,
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
            'biaya_per_porsi' => $this->biayaPerPorsi,
            'jumlah_porsi' => $this->jumlahPorsi,
            'total_biaya' => $this->totalBiaya,
        ];
    }
}
