<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsWib;
use App\Models\NotifikasiPerjalanan;
use App\Support\PetaHak;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NotifikasiPerjalanan */
class NotifikasiPerjalananResource extends JsonResource
{
    use FormatsWib;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $titik = $this->titikLayanan;

        return [
            'id' => $this->id,
            'sppg_id' => $this->sppg_id,
            'jadwal_distribusi_id' => $this->jadwal_distribusi_id,
            'titik_layanan' => $titik === null ? null : [
                'id' => $titik->id,
                'nama' => $titik->nama,
                'pic_nama' => $titik->pic_nama,
                'pic_terhubung' => $titik->picTerhubung(),
            ],
            'status' => $this->status_perjalanan->value,
            'status_label' => $this->status_perjalanan->label(),
            'status_kirim' => $this->status_kirim->value,
            'dialihkan_ke_kepala' => $this->dialihkan_ke_kepala,
            'dipicu_oleh' => $this->pemicu === null ? null : ['id' => $this->pemicu->id, 'name' => $this->pemicu->name],
            'waktu_berangkat' => $this->wib($this->waktu_berangkat),
            'estimasi_tiba' => $this->wib($this->estimasi_tiba),
            'estimasi_menit' => $this->estimasi_menit,
            'estimasi_sumber' => $this->estimasi_sumber,
            'waktu_tiba' => $this->wib($this->waktu_tiba),
            'waktu_dibatalkan' => $this->wib($this->waktu_dibatalkan),
            'alasan_batal' => $this->alasan_batal,
            'terlambat' => $this->isTerlambat(),
            'terlambat_dideteksi_pada' => $this->wib($this->terlambat_dideteksi_pada),
            'pesan' => $this->pesan,
            'izin' => $this->izin($request),
            'created_at' => $this->wib($this->created_at),
        ];
    }

    /**
     * Hak ubah status = Policy `ubahStatus` (C notifikasi-perjalanan, SPPG sama, relawan hanya miliknya)
     * dan perjalanan belum final (tiba/dibatalkan tidak punya transisi lanjutan).
     *
     * @return array<string, bool>
     */
    private function izin(Request $request): array
    {
        $izin = PetaHak::izin($request->user(), $this->resource, ['ubah_status' => 'ubahStatus']);
        $izin['ubah_status'] = $izin['ubah_status'] && $this->status_perjalanan->transisiValid() !== [];

        return $izin;
    }
}
