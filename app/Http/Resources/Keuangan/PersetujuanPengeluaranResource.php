<?php

namespace App\Http\Resources\Keuangan;

use App\Http\Resources\Concerns\FormatsWib;
use App\Models\PersetujuanPengeluaran;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Satu entri riwayat persetujuan pengeluaran (snapshot saat diajukan + keputusan). @mixin PersetujuanPengeluaran */
class PersetujuanPengeluaranResource extends JsonResource
{
    use FormatsWib;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PersetujuanPengeluaran $p */
        $p = $this->resource;

        return [
            'id' => $p->id,
            'nomor' => $p->nomor,
            'pengeluaran_versi' => $p->pengeluaran_versi,
            'status' => $p->status->value,
            'status_label' => $p->status->label(),
            'kategori' => $p->kategori,
            'nominal' => (int) round((float) $p->getRawOriginal('nominal')),
            'tanggal' => $p->tanggal?->toDateString(),
            'ambang_persetujuan' => (int) round((float) $p->getRawOriginal('ambang_persetujuan')),
            'diajukan_oleh' => $p->relationLoaded('pengaju') ? $p->pengaju?->name : null,
            'diajukan_pada' => $this->wib($p->diajukan_pada),
            'diputuskan_oleh' => $p->relationLoaded('pemutus') ? $p->pemutus?->name : null,
            'diputuskan_pada' => $this->wib($p->diputuskan_pada),
            'catatan_keputusan' => $p->catatan_keputusan,
        ];
    }
}
