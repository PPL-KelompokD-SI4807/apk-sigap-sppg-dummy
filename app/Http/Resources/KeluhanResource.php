<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsWib;
use App\Models\FeedbackKeluhan;
use App\Support\LampiranTersembunyi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeedbackKeluhan */
class KeluhanResource extends JsonResource
{
    use FormatsWib;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $orang = fn ($user) => $user === null ? null : ['id' => $user->id, 'name' => $user->name];
        // R06a-S-05: foto keluhan disembunyikan dari investor; teks keluhan tetap terlihat.
        $lampiranTersembunyi = LampiranTersembunyi::untuk($request->user(), 'keluhan');

        return [
            'id' => $this->id,
            'sppg_id' => $this->sppg_id,
            'titik_layanan' => $this->titikLayanan === null ? null : ['id' => $this->titikLayanan->id, 'nama' => $this->titikLayanan->nama],
            'jadwal_produksi_id' => $this->jadwal_produksi_id,
            'jadwal_produksi_tanggal' => $this->jadwalProduksi?->tanggal_produksi?->toDateString(),
            'sumber' => $this->sumber->value,
            'kategori' => $this->kategori->value,
            'kategori_label' => $this->kategori->label(),
            'prioritas' => $this->prioritas->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'deskripsi' => $this->deskripsi,
            'tindak_lanjut' => $this->tindak_lanjut,
            'lampiran' => $lampiranTersembunyi ? [] : LampiranResource::collection($this->whenLoaded('lampirans', fn () => $this->lampirans, [])),
            'lampiran_disembunyikan' => $lampiranTersembunyi,
            'dicatat_oleh' => $orang($this->pencatat),
            'ditangani_oleh' => $orang($this->penangan),
            'dieskalasi_pada' => $this->wib($this->dieskalasi_pada),
            'selesai_pada' => $this->wib($this->selesai_pada),
            'created_at' => $this->wib($this->created_at),
            'updated_at' => $this->wib($this->updated_at),
        ];
    }
}
