<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsWib;
use App\Models\RekapHarian;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RekapHarian */
class RekapHarianResource extends JsonResource
{
    use FormatsWib;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sppg_id' => $this->sppg_id,
            'tanggal' => $this->tanggal->toDateString(),
            'ringkasan' => $this->ringkasan,
            'catatan' => $this->catatan,
            'status_kirim' => $this->status_kirim->value,
            'dikirim_pada' => $this->wib($this->dikirim_pada),
            'disusun_oleh' => $this->penyusun === null ? null : ['id' => $this->penyusun->id, 'name' => $this->penyusun->name],
            'disusun_pada' => $this->wib($this->disusun_pada),
            'created_at' => $this->wib($this->created_at),
            'updated_at' => $this->wib($this->updated_at),
        ];
    }
}
