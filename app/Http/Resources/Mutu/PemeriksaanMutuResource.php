<?php

namespace App\Http\Resources\Mutu;

use App\Http\Resources\LampiranResource;
use App\Models\PemeriksaanMutu;
use App\Support\LampiranTersembunyi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PemeriksaanMutu */
class PemeriksaanMutuResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PemeriksaanMutu $m */
        $m = $this->resource;
        $jadwal = $m->relationLoaded('jadwalProduksi') ? $m->jadwalProduksi : null;
        $lampiranTersembunyi = LampiranTersembunyi::untuk($request->user(), 'mutu');

        return [
            'id' => $m->id,
            'sppg_id' => $m->sppg_id,
            'jadwal_produksi_id' => $m->jadwal_produksi_id,
            'jadwal' => $this->whenLoaded('jadwalProduksi', fn () => $jadwal === null ? null : [
                'id' => $jadwal->id,
                'tanggal_produksi' => $jadwal->tanggal_produksi?->toDateString(),
                'status' => $jadwal->status?->value,
                'menu' => $jadwal->relationLoaded('menu') && $jadwal->menu !== null
                    ? ['id' => $jadwal->menu->id, 'nama_menu' => $jadwal->menu->nama_menu]
                    : null,
            ]),
            'pemeriksa_id' => $m->pemeriksa_id,
            'pemeriksa' => $this->whenLoaded('pemeriksa', fn () => $m->pemeriksa?->name),
            'waktu_pemeriksaan' => $m->waktu_pemeriksaan?->toIso8601String(),
            'suhu_makanan_c' => $m->suhu_makanan_c === null ? null : (float) $m->suhu_makanan_c,
            'kebersihan_dapur' => $m->kebersihan_dapur,
            'kebersihan_petugas' => $m->kebersihan_petugas,
            'rasa_baik' => $m->rasa_baik,
            'aroma_baik' => $m->aroma_baik,
            'tampilan_baik' => $m->tampilan_baik,
            // Turunan: true bila rasa, aroma, dan tampilan semuanya baik (kompatibilitas klien lama).
            'organoleptik_baik' => $m->organoleptik_baik,
            'catatan_organoleptik' => $m->catatan_organoleptik,
            'sampel_disimpan' => $m->sampel_disimpan,
            'hasil' => $m->hasil?->value,
            'hasil_label' => $m->hasil?->label(),
            'tindakan_korektif' => $m->tindakan_korektif,
            // R06a-S-05: foto pemeriksaan mutu disembunyikan dari investor; data mutu tetap terlihat.
            'lampiran' => $lampiranTersembunyi ? [] : LampiranResource::collection($this->whenLoaded('lampirans', fn () => $m->lampirans, [])),
            'lampiran_disembunyikan' => $lampiranTersembunyi,
            'catatan_koreksi' => $m->catatan_koreksi,
            'dikoreksi_pada' => $m->dikoreksi_pada?->toIso8601String(),
            'dikoreksi_oleh' => $m->dikoreksi_oleh,
            'memengaruhi_distribusi' => $this->when(
                array_key_exists('memengaruhi_distribusi', $m->getAttributes()),
                fn () => (bool) $m->getAttribute('memengaruhi_distribusi'),
            ),
            'created_at' => $m->created_at?->toIso8601String(),
            'updated_at' => $m->updated_at?->toIso8601String(),
        ];
    }
}
