<?php

namespace App\Http\Resources\Jadwal;

use App\Enums\HasilMutu;
use App\Enums\StatusJadwalDistribusi;
use App\Models\JadwalDistribusi;
use App\Models\User;
use App\Services\Distribusi\AturanCatatBukti;
use App\Support\PetaHak;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JadwalDistribusi */
class JadwalDistribusiResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var JadwalDistribusi $d */
        $d = $this->resource;

        return [
            'id' => $d->id,
            'sppg_id' => $d->sppg_id,
            'jadwal_produksi_id' => $d->jadwal_produksi_id,
            'titik_layanan_id' => $d->titik_layanan_id,
            'titik_layanan' => $this->whenLoaded('titikLayanan', fn () => $d->titikLayanan === null ? null : [
                'id' => $d->titikLayanan->id,
                'nama' => $d->titikLayanan->nama,
            ]),
            'armada_id' => $d->armada_id,
            'armada' => $this->whenLoaded('armada', fn () => $d->armada === null ? null : [
                'id' => $d->armada->id,
                'plat_nomor' => $d->armada->plat_nomor,
            ]),
            'pengemudi_id' => $d->pengemudi_id,
            'pengemudi' => $this->whenLoaded('pengemudi', fn () => $d->pengemudi === null ? null : [
                'id' => $d->pengemudi->id,
                'nama' => $d->pengemudi->nama,
            ]),
            'petugas_id' => $d->petugas_id,
            'petugas' => $this->whenLoaded('petugas', fn () => $d->petugas?->name),
            'dibuat_oleh' => $d->getAttribute('dibuat_oleh'),
            'tanggal_distribusi' => $d->tanggal_distribusi?->toDateString(),
            'jam_berangkat' => $d->jam_berangkat === null ? null : substr((string) $d->jam_berangkat, 0, 5),
            'jam_rencana_tiba' => $d->jam_rencana_tiba === null ? null : substr((string) $d->jam_rencana_tiba, 0, 5),
            'jumlah_porsi' => $d->jumlah_porsi,
            'status' => $d->status?->value,
            'status_label' => $d->status?->label(),
            'layak_distribusi' => $this->when($d->relationLoaded('jadwalProduksi'), function () use ($d): bool {
                $produksi = $d->jadwalProduksi;

                if ($produksi === null) {
                    return false;
                }

                $mutu = $produksi->relationLoaded('pemeriksaanTerbaru') ? $produksi->pemeriksaanTerbaru : $produksi->pemeriksaanMutuTerbaru();

                return $mutu?->hasil === HasilMutu::Layak;
            }),
            'catatan' => $d->catatan,
            'izin' => $this->izin($request, $d),
            'created_at' => $d->created_at?->toIso8601String(),
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Hak (Policy) DAN keadaan dari atribut record: distribusi hanya dapat dihapus pada status menunggu/siap/dibatalkan;
     * `catat_bukti` = boleh POST bukti untuk jadwal ini.
     * Syarat relasional (sudah ada bukti distribusi) butuh query dan tetap ditegakkan service (422).
     *
     * @return array<string, bool>
     */
    private function izin(Request $request, JadwalDistribusi $d): array
    {
        $izin = PetaHak::izin($request->user(), $d, ['ubah' => 'update', 'hapus' => 'delete']);
        $izin['hapus'] = $izin['hapus'] && in_array($d->status, [StatusJadwalDistribusi::Menunggu, StatusJadwalDistribusi::Siap, StatusJadwalDistribusi::Dibatalkan], true);
        $izin['catat_bukti'] = $this->bolehCatatBukti($request->user(), $d);

        return $izin;
    }

    /**
     * Aturan SAMA dengan penegakan POST /bukti-distribusi (AturanCatatBukti: hak, petugas, hari ini, status, gerbang mutu).
     * Gerbang mutu dibaca dari relasi `jadwalProduksi.pemeriksaanTerbaru` yang sudah di-eager-load (tanpa query per baris,
     * produksi terhapus lunak ikut dimuat seperti di service). GAGAL TERTUTUP: bila relasi tidak dimuat atau batch tidak
     * ada, hasilnya false (tombol tersembunyi; server tetap menegakkan).
     */
    private function bolehCatatBukti(?User $pengguna, JadwalDistribusi $d): bool
    {
        if ($pengguna === null) {
            return false;
        }

        $layak = function () use ($d): bool {
            if (! $d->relationLoaded('jadwalProduksi') || $d->jadwalProduksi === null) {
                return false;
            }

            $produksi = $d->jadwalProduksi;

            return $produksi->relationLoaded('pemeriksaanTerbaru') && $produksi->pemeriksaanTerbaru?->hasil === HasilMutu::Layak;
        };

        return AturanCatatBukti::boleh($pengguna, $d, $layak);
    }
}
