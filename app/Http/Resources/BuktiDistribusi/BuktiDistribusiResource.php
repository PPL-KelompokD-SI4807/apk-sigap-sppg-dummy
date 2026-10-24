<?php

namespace App\Http\Resources\BuktiDistribusi;

use App\Http\Resources\Concerns\FormatsWib;
use App\Http\Resources\LampiranResource;
use App\Models\BuktiDistribusi;
use App\Models\Lampiran;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bukti distribusi. Jalur berkas internal (foto_path, berita_acara_path) tidak dikirim; klien
 * memakai `foto.url` / `berita_acara.url` (GET /api/v1/lampiran/{id}, Bearer token).
 *
 * @mixin BuktiDistribusi
 */
class BuktiDistribusiResource extends JsonResource
{
    use FormatsWib;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BuktiDistribusi $b */
        $b = $this->resource;
        $jadwal = $b->jadwalDistribusi;
        $berkas = fn (?Lampiran $l) => $l === null ? null : ['lampiran_id' => $l->id, 'url' => $l->url(), 'mime' => $l->mime, 'ukuran' => $l->ukuran];
        $dikirim = $jadwal?->jumlah_porsi;

        return [
            'id' => $b->id,
            'sppg_id' => $b->sppg_id,
            'jadwal_distribusi' => $jadwal === null ? null : [
                'id' => $jadwal->id,
                'tanggal_distribusi' => $jadwal->tanggal_distribusi?->toDateString(),
                'jam_rencana_tiba' => $jadwal->jam_rencana_tiba === null ? null : substr((string) $jadwal->jam_rencana_tiba, 0, 5),
                'status' => $jadwal->status?->value,
                'titik_layanan' => $jadwal->titikLayanan === null ? null : ['id' => $jadwal->titikLayanan->id, 'nama' => $jadwal->titikLayanan->nama],
            ],
            'porsi_dikirim' => $dikirim,
            'porsi_diterima' => $b->porsi_diterima,
            'selisih_porsi' => $dikirim === null ? null : $dikirim - (int) $b->porsi_diterima,
            'nama_pic' => $b->nama_pic,
            'no_berita_acara' => $b->no_berita_acara,
            'catatan_selisih' => $b->catatan_selisih,
            'catatan' => $b->catatan,
            'waktu_berangkat' => $this->wib($b->waktu_berangkat),
            'waktu_tiba' => $this->wib($b->waktu_tiba),
            'acuan_tiba' => $this->wib($b->acuan_tiba),
            'waktu_ambil_foto' => $this->wib($b->waktu_ambil_foto),
            'terlambat' => (bool) $b->terlambat,
            'menit_keterlambatan' => $b->menit_keterlambatan,
            'latitude' => $b->latitude === null ? null : (float) $b->latitude,
            'longitude' => $b->longitude === null ? null : (float) $b->longitude,
            'foto' => $berkas($b->lampiranUntukPath($b->foto_path)),
            'berita_acara' => $berkas($b->lampiranUntukPath($b->berita_acara_path)),
            'lampiran' => LampiranResource::collection($b->relationLoaded('lampirans') ? $b->lampirans : $b->lampirans()->get()),
            'dicatat_oleh' => $b->pencatat === null ? null : ['id' => $b->pencatat->id, 'name' => $b->pencatat->name],
            'created_at' => $this->wib($b->created_at),
            'updated_at' => $this->wib($b->updated_at),
        ];
    }
}
