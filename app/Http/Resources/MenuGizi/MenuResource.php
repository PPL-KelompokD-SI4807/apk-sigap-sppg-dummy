<?php

namespace App\Http\Resources\MenuGizi;

use App\Models\Menu;
use App\Services\Gizi\GiziService;
use App\Support\Rbac;
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
            'status_persetujuan' => $m->status_persetujuan?->value,
            'status_persetujuan_label' => $m->status_persetujuan?->label(),
            'versi' => (int) $m->versi,
            // Memuat biaya/total/catatan keputusan investor: hanya peran dengan hak R `persetujuan-menu` (S1b).
            'pengajuan_terakhir' => $this->when($m->relationLoaded('pengajuanTerakhir') && Rbac::can($request->user(), 'persetujuan-menu', 'R'), fn () => $m->pengajuanTerakhir === null ? null : [
                'id' => $m->pengajuanTerakhir->id,
                'nomor' => $m->pengajuanTerakhir->nomor,
                'menu_versi' => $m->pengajuanTerakhir->menu_versi,
                'status' => $m->pengajuanTerakhir->status->value,
                'biaya_per_porsi' => (float) $m->pengajuanTerakhir->biaya_per_porsi,
                'jumlah_porsi' => $m->pengajuanTerakhir->jumlah_porsi,
                'total_biaya' => (float) $m->pengajuanTerakhir->total_biaya,
                'diajukan_pada' => $m->pengajuanTerakhir->diajukan_pada?->toIso8601String(),
                'diputuskan_pada' => $m->pengajuanTerakhir->diputuskan_pada?->toIso8601String(),
                'catatan_keputusan' => $m->pengajuanTerakhir->catatan_keputusan,
            ]),
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
