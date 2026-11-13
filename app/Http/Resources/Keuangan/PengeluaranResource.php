<?php

namespace App\Http\Resources\Keuangan;

use App\Enums\StatusPersetujuanPengeluaran;
use App\Http\Resources\Concerns\FormatsWib;
use App\Http\Resources\LampiranResource;
use App\Models\Pengeluaran;
use App\Models\User;
use App\Services\Keuangan\BudgetService;
use App\Support\PetaHak;
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
            'sub_kategori' => $p->sub_kategori?->value,
            'sub_kategori_label' => $p->sub_kategori?->label(),
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
            'izin' => $this->izin($request->user(), $p),
            'created_at' => $this->wib($p->created_at),
            'updated_at' => $this->wib($p->updated_at),
        ] + ($p->relationLoaded('persetujuans') ? [
            'riwayat_persetujuan' => PersetujuanPengeluaranResource::collection($p->persetujuans),
        ] : []);
    }

    /**
     * Izin per record = Policy yang sama dengan endpoint. `ubah`/`hapus` ditolak service untuk pengeluaran hasil
     * verifikasi pembelian (pembelian_bahan_id terisi); `lampirkan` juga false saat status `disetujui`; `putuskan` hanya saat status `diajukan`.
     *
     * @return array<string, bool>
     */
    private function izin(?User $user, Pengeluaran $p): array
    {
        $izin = PetaHak::izin($user, $p, ['ubah' => 'update', 'hapus' => 'delete', 'lampirkan' => 'lampirkan', 'putuskan' => 'putuskan']);
        $izin['ubah'] = $izin['ubah'] && $p->pembelian_bahan_id === null;
        $izin['hapus'] = $izin['hapus'] && $p->pembelian_bahan_id === null;
        // POST /lampiran ditolak 409 untuk pengeluaran yang sudah disetujui (harus diubah/diajukan ulang dulu).
        $izin['lampirkan'] = $izin['lampirkan'] && $p->status_persetujuan !== StatusPersetujuanPengeluaran::Disetujui;
        $izin['putuskan'] = $izin['putuskan'] && $p->status_persetujuan === StatusPersetujuanPengeluaran::Diajukan;

        return $izin;
    }
}
