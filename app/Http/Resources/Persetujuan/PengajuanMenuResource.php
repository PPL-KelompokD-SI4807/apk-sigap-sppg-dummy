<?php

namespace App\Http\Resources\Persetujuan;

use App\Models\PengajuanMenu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pengajuan menu. Daftar (route index) memakai ringkasan tanpa `rincian`; detail memuat snapshot lengkap.
 * `menu_versi_sekarang`/`kedaluwarsa` membantu Mini App: kirim `menu_versi` sebagai `versi_menu` saat memutuskan.
 *
 * @mixin PengajuanMenu
 */
class PengajuanMenuResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PengajuanMenu $p */
        $p = $this->resource;
        $menu = $p->relationLoaded('menu') ? $p->menu : null;

        $data = [
            'id' => $p->id,
            'sppg_id' => $p->sppg_id,
            'menu_id' => $p->menu_id,
            'nama_menu' => $p->rincian['menu']['nama_menu'] ?? $menu?->nama_menu,
            'kelompok_sasaran' => $p->rincian['menu']['kelompok_sasaran'] ?? null,
            'nomor' => $p->nomor,
            'menu_versi' => $p->menu_versi,
            'status' => $p->status->value,
            'status_label' => $p->status->label(),
            'biaya_per_porsi' => (float) $p->biaya_per_porsi,
            'jumlah_porsi' => $p->jumlah_porsi,
            'sumber_porsi' => $p->sumber_porsi,
            'keterangan_asumsi' => $p->keterangan_asumsi,
            'total_biaya' => (float) $p->total_biaya,
            'catatan_pengaju' => $p->catatan_pengaju,
            'diajukan_oleh' => $p->relationLoaded('pengaju') ? $p->pengaju?->name : null,
            'diajukan_pada' => $p->diajukan_pada?->toIso8601String(),
            'diputuskan_oleh' => $p->relationLoaded('pemutus') ? $p->pemutus?->name : null,
            'diputuskan_pada' => $p->diputuskan_pada?->toIso8601String(),
            'catatan_keputusan' => $p->catatan_keputusan,
        ];

        if ($request->routeIs('api.v1.persetujuan-menu.index')) {
            return $data;
        }

        return $data + [
            'menu_versi_sekarang' => $menu === null ? null : (int) $menu->versi,
            'kedaluwarsa' => $menu === null || $menu->trashed() || (int) $menu->versi !== (int) $p->menu_versi,
            'rincian' => $p->rincian,
        ];
    }
}
