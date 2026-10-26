<?php

namespace App\Http\Resources\Armada;

use App\Models\Pengemudi;
use App\Support\Rbac;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pengemudi. Keputusan privasi: no_sim dan sim_berlaku_hingga hanya terlihat oleh peran yang
 * boleh mengubah data armada/pengemudi (Admin, Kepala SPPG). Peran R lain (Akuntan, Relawan)
 * hanya melihat `sim_status` agar dapat menilai kelayakan tanpa melihat nomor SIM.
 *
 * @mixin Pengemudi
 */
class PengemudiResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Pengemudi $p */
        $p = $this->resource;
        $lihatSim = Rbac::can($request->user(), 'armada', 'U');

        return [
            'id' => $p->id,
            'sppg_id' => $p->sppg_id,
            'nama' => $p->nama,
            'no_hp' => $p->no_hp,
            $this->mergeWhen($lihatSim, [
                'no_sim' => $p->no_sim,
                'sim_berlaku_hingga' => $p->sim_berlaku_hingga?->toDateString(),
            ]),
            'sim_status' => $p->simStatus(),
            'is_active' => $p->is_active,
            'created_at' => $p->created_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }
}
