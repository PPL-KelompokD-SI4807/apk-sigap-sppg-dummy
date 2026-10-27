<?php

namespace App\Http\Resources\Pembelian;

use App\Http\Resources\Concerns\FormatsWib;
use App\Http\Resources\LampiranResource;
use App\Models\PembelianBahan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pembelian bahan. `total` rupiah bulat (int); `harga_referensi` hanya terisi pada detail (relasi
 * `hargaReferensi` dimuat controller) dan bersifat informatif. Jalur nota internal tidak dikirim.
 *
 * @mixin PembelianBahan
 */
class PembelianBahanResource extends JsonResource
{
    use FormatsWib;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PembelianBahan $p */
        $p = $this->resource;
        $lampirans = $p->relationLoaded('lampirans') ? $p->lampirans : $p->lampirans()->get();
        $harga = $p->relationLoaded('hargaReferensi') ? $p->getRelation('hargaReferensi') : null;

        $data = [
            'id' => $p->id,
            'sppg_id' => $p->sppg_id,
            'tanggal_beli' => $p->tanggal_beli?->toDateString(),
            'bahan_baku' => $p->bahanBaku === null ? null : ['id' => $p->bahanBaku->id, 'nama' => $p->bahanBaku->nama, 'satuan' => $p->bahanBaku->satuan],
            'pemasok' => $p->pemasok === null ? null : ['id' => $p->pemasok->id, 'nama' => $p->pemasok->nama],
            'jumlah' => (float) $p->jumlah,
            'satuan' => $p->satuan,
            'harga_satuan' => (float) $p->harga_satuan,
            'total' => (int) round((float) $p->getRawOriginal('total')),
            'catatan' => $p->catatan,
            'status' => $p->status->value,
            'status_label' => $p->status->label(),
            'versi' => (int) $p->versi,
            'alasan_penolakan' => $p->alasan_penolakan,
            'pengeluaran_id' => $p->pengeluaran_id,
            'dicatat_oleh' => $p->pencatat === null ? null : ['id' => $p->pencatat->id, 'name' => $p->pencatat->name],
            'diverifikasi_oleh' => $p->pemverifikasi === null ? null : ['id' => $p->pemverifikasi->id, 'name' => $p->pemverifikasi->name],
            'diverifikasi_pada' => $this->wib($p->diverifikasi_pada),
            'lampirans' => LampiranResource::collection($lampirans),
            'created_at' => $this->wib($p->created_at),
            'updated_at' => $this->wib($p->updated_at),
        ];

        if ($p->relationLoaded('hargaReferensi')) {
            $data['harga_referensi'] = $harga === null ? null : [
                'harga' => (float) $harga->harga,
                'berlaku_mulai' => $harga->berlaku_mulai?->toDateString(),
                'selisih_persen' => (float) $p->harga_satuan > 0 && (float) $harga->harga > 0
                    ? round(((float) $p->harga_satuan - (float) $harga->harga) / (float) $harga->harga * 100, 2)
                    : null,
            ];
        }

        return $data;
    }
}
