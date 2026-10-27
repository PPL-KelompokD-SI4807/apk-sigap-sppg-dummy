<?php

namespace App\Http\Resources\Keuangan;

use App\Http\Resources\Concerns\FormatsWib;
use App\Http\Resources\LampiranResource;
use App\Models\Pengeluaran;
use App\Services\Keuangan\BudgetService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Pengeluaran */
class PengeluaranResource extends JsonResource
{
    use FormatsWib;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Pengeluaran $p */
        $p = $this->resource;
        $nota = $p->lampiranUntukPath($p->nota_path);

        return [
            'id' => $p->id,
            'sppg_id' => $p->sppg_id,
            'periode' => $p->tanggal?->format('Y-m'),
            'tanggal' => $p->tanggal?->toDateString(),
            'kategori' => $p->kategori?->value,
            'kategori_label' => $p->kategori?->label(),
            'uraian' => $p->uraian,
            'nominal' => app(BudgetService::class)->rupiah($p->getRawOriginal('nominal') ?? $p->nominal),
            'melebihi_anggaran' => (bool) $p->melebihi_anggaran,
            'status_persetujuan' => $p->status_persetujuan->value,
            'status_persetujuan_label' => $p->status_persetujuan->label(),
            'versi' => (int) $p->versi,
            'pembelian_bahan_id' => $p->pembelian_bahan_id,
            'pemasok' => $p->pemasok === null ? null : ['id' => $p->pemasok->id, 'nama' => $p->pemasok->nama],
            'pencairan_dana_id' => $p->pencairan_dana_id,
            'nota' => $nota === null ? null : ['lampiran_id' => $nota->id, 'url' => $nota->url(), 'mime' => $nota->mime, 'ukuran' => $nota->ukuran],
            'lampiran' => LampiranResource::collection($p->relationLoaded('lampirans') ? $p->lampirans : $p->lampirans()->get()),
            'dicatat_oleh' => $p->pencatat === null ? null : ['id' => $p->pencatat->id, 'name' => $p->pencatat->name],
            'created_at' => $this->wib($p->created_at),
            'updated_at' => $this->wib($p->updated_at),
        ] + ($p->relationLoaded('persetujuans') ? [
            'riwayat_persetujuan' => PersetujuanPengeluaranResource::collection($p->persetujuans),
        ] : []);
    }
}
