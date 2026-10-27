<?php

namespace App\Http\Resources\Jadwal;

use App\Enums\HasilMutu;
use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusPersetujuanMenu;
use App\Models\JadwalProduksi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JadwalProduksi */
class JadwalProduksiResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var JadwalProduksi $j */
        $j = $this->resource;
        $mutu = $j->relationLoaded('pemeriksaanTerbaru') ? $j->pemeriksaanTerbaru : $j->pemeriksaanMutuTerbaru();

        return [
            'id' => $j->id,
            'sppg_id' => $j->sppg_id,
            'menu_id' => $j->menu_id,
            'menu' => $this->whenLoaded('menu', fn () => $j->menu === null ? null : [
                'id' => $j->menu->id,
                'nama_menu' => $j->menu->nama_menu,
                'kelompok_sasaran' => $j->menu->kelompok_sasaran?->value,
                'status_persetujuan' => $j->menu->status_persetujuan?->value,
            ]),
            // R-06b-2 (S2): dihitung saat baca dari status menu sekarang; true = menu kembali ke draf/ditolak
            // setelah dijadwalkan, jadwal tidak dapat dimasak sebelum menu disetujui lagi.
            'menu_belum_disetujui' => $j->menu === null || $j->menu->status_persetujuan !== StatusPersetujuanMenu::Disetujui,
            'dibuat_oleh' => $j->dibuat_oleh,
            'tanggal_produksi' => $j->tanggal_produksi?->toDateString(),
            'jam_mulai_masak' => $j->jam_mulai_masak === null ? null : substr((string) $j->jam_mulai_masak, 0, 5),
            'target_porsi' => $j->target_porsi,
            'status' => $j->status?->value,
            'status_label' => $j->status?->label(),
            'catatan' => $j->catatan,
            'porsi_terjadwal_distribusi' => $this->when(
                $j->getAttribute('porsi_terjadwal_distribusi') !== null || $j->relationLoaded('jadwalDistribusis'),
                fn () => (int) ($j->getAttribute('porsi_terjadwal_distribusi')
                    ?? $j->jadwalDistribusis->where('status', '!=', StatusJadwalDistribusi::Dibatalkan)->sum('jumlah_porsi')),
            ),
            'status_mutu' => $mutu?->hasil?->value,
            'layak_distribusi' => $mutu?->hasil === HasilMutu::Layak,
            'created_at' => $j->created_at?->toIso8601String(),
            'updated_at' => $j->updated_at?->toIso8601String(),
        ];
    }
}
