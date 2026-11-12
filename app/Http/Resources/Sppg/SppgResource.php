<?php

namespace App\Http\Resources\Sppg;

use App\Models\Sppg;
use App\Support\PetaHak;
use App\Support\Rbac;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Sppg */
class SppgResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Sppg $sppg */
        $sppg = $this->resource;

        $user = $request->user();
        // Data keuangan/rekening hanya untuk peran yang boleh membaca modul keuangan (bukan relawan/gudang/ahli gizi).
        $keuangan = Rbac::can($user, 'keuangan', 'R');

        return [
            'id' => $sppg->id,
            'kode' => $sppg->kode,
            'id_sppg_bgn' => $sppg->id_sppg_bgn,
            'nama' => $sppg->nama,
            'alamat' => $sppg->alamat,
            'kecamatan' => $sppg->kecamatan,
            'kab_kota' => $sppg->kab_kota,
            'provinsi' => $sppg->provinsi,
            'mitra_yayasan' => $sppg->mitra_yayasan,
            'telepon' => $sppg->telepon,
            'kapasitas_porsi_harian' => $sppg->kapasitas_porsi_harian,
            'status_operasional' => $sppg->status_operasional->value,
            'created_at' => $sppg->created_at?->toIso8601String(),
            'updated_at' => $sppg->updated_at?->toIso8601String(),
        ] + ($keuangan ? [
            'nama_ketua_yayasan' => $sppg->nama_ketua_yayasan,
            'no_va' => $sppg->no_va,
            'saldo_awal' => $sppg->saldo_awal === null ? null : (int) $sppg->saldo_awal,
            'saldo_awal_tanggal' => $sppg->saldo_awal_tanggal?->toDateString(),
            'pengaturan_anggaran' => $sppg->pengaturanAnggaran(),
            'pengaturan_anggaran_kustom' => [
                'acuan_bahan_baku_kecil' => $sppg->acuan_bahan_baku_kecil,
                'acuan_bahan_baku_lainnya' => $sppg->acuan_bahan_baku_lainnya,
                'insentif_fasilitas_per_hari' => $sppg->insentif_fasilitas_per_hari,
                'insentif_fasilitas_maks_hari_minggu' => $sppg->insentif_fasilitas_maks_hari_minggu,
            ],
            'izin' => PetaHak::izin($user, $sppg, ['ubah_saldo_awal' => 'ubahSaldoAwal']),
        ] : []);
    }
}
