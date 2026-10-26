<?php

namespace App\Http\Resources\Keuangan;

use App\Http\Resources\Concerns\FormatsWib;
use App\Http\Resources\LampiranResource;
use App\Models\PencairanDana;
use App\Services\Keuangan\BudgetService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PencairanDana */
class PencairanDanaResource extends JsonResource
{
    use FormatsWib;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PencairanDana $p */
        $p = $this->resource;
        $bukti = $p->lampiranUntukPath($p->bukti_path);

        return [
            'id' => $p->id,
            'sppg_id' => $p->sppg_id,
            'periode' => $p->periode,
            'sumber_dana' => $p->sumber_dana,
            'nominal' => app(BudgetService::class)->rupiah($p->getRawOriginal('nominal') ?? $p->nominal),
            'tanggal_cair' => $p->tanggal_cair?->toDateString(),
            'no_referensi' => $p->no_referensi,
            'catatan' => $p->catatan,
            'bukti' => $bukti === null ? null : ['lampiran_id' => $bukti->id, 'url' => $bukti->url(), 'mime' => $bukti->mime, 'ukuran' => $bukti->ukuran],
            'lampiran' => LampiranResource::collection($p->relationLoaded('lampirans') ? $p->lampirans : $p->lampirans()->get()),
            'dicatat_oleh' => $p->pencatat === null ? null : ['id' => $p->pencatat->id, 'name' => $p->pencatat->name],
            'created_at' => $this->wib($p->created_at),
            'updated_at' => $this->wib($p->updated_at),
        ];
    }
}
