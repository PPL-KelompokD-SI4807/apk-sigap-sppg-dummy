<?php

namespace App\Http\Resources\MenuGizi;

use App\Models\Menu;
use App\Services\Gizi\GiziService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Menu */
class MenuResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Menu $m */
        $m = $this->resource;

        return [
            'id' => $m->id,
            'sppg_id' => $m->sppg_id,
            'nama_menu' => $m->nama_menu,
            'kelompok_sasaran' => $m->kelompok_sasaran?->value,
            'kelompok_sasaran_label' => $m->kelompok_sasaran?->label(),
            'tanggal_sajian' => $m->tanggal_sajian?->toDateString(),
            'siklus' => $m->siklus,
            'energi_kkal' => (float) $m->energi_kkal,
            'protein_g' => (float) $m->protein_g,
            'lemak_g' => (float) $m->lemak_g,
            'karbohidrat_g' => (float) $m->karbohidrat_g,
            'serat_g' => (float) $m->serat_g,
            'catatan' => $m->catatan,
            'jumlah_bahan' => $this->when($m->bahan_bakus_count !== null, fn () => (int) $m->bahan_bakus_count),
            'bahan' => $this->whenLoaded('bahanBakus', fn () => $m->bahanBakus->map(fn ($b) => [
                'bahan_baku_id' => $b->id,
                'nama' => $b->nama,
                'satuan' => $b->satuan,
                'gram_per_porsi' => (float) $b->pivot->gram_per_porsi,
            ])->values()),
            'peringatan_gizi' => $this->when(
                $m->relationLoaded('bahanBakus'),
                fn () => app(GiziService::class)->peringatan($m),
            ),
            'created_at' => $m->created_at?->toIso8601String(),
            'updated_at' => $m->updated_at?->toIso8601String(),
        ];
    }
}
