<?php

namespace App\Services\Keluhan;

use App\Enums\KategoriKeluhan;
use App\Enums\PrioritasKeluhan;
use App\Enums\StatusKeluhan;
use App\Events\KeluhanKritis;
use App\Models\FeedbackKeluhan;
use App\Models\JadwalDistribusi;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Logika bisnis Feedback dan Keluhan (FR-07).
 *
 * - Prioritas otomatis dari kategori (PrioritasKeluhan::dariKategori); input hanya boleh menaikkan.
 * - Prioritas kritis: dieskalasi (dieskalasi_pada + event KeluhanKritis) dan wajib dikaitkan ke
 *   jadwal produksi (batch) bila diketahui. Bila tidak dikirim namun titik layanan diketahui, batch
 *   disimpulkan dari jadwal distribusi titik tersebut hari ini (WIB). Bila tetap tak diketahui,
 *   pemanggil harus menyatakan batch_tidak_diketahui=true.
 * - Alur status: baru -> diproses -> selesai | ditolak; selesai/ditolak final dan wajib tindak_lanjut.
 */
class KeluhanService
{
    /** Prioritas efektif: tidak pernah lebih rendah dari prioritas otomatis kategori. */
    public function tentukanPrioritas(KategoriKeluhan $kategori, ?PrioritasKeluhan $diminta = null): PrioritasKeluhan
    {
        $otomatis = PrioritasKeluhan::dariKategori($kategori);

        return $diminta !== null && $this->bobot($diminta) > $this->bobot($otomatis) ? $diminta : $otomatis;
    }

    /**
     * @param  array<string, mixed>  $data  hasil validasi StoreKeluhanRequest
     */
    public function buat(User $pencatat, int $sppgId, array $data): FeedbackKeluhan
    {
        return DB::transaction(function () use ($pencatat, $sppgId, $data) {
            $kategori = KategoriKeluhan::from($data['kategori']);
            $prioritas = $this->tentukanPrioritas(
                $kategori,
                isset($data['prioritas']) ? PrioritasKeluhan::from($data['prioritas']) : null,
            );
            $titikId = isset($data['titik_layanan_id']) ? (int) $data['titik_layanan_id'] : null;
            $jadwalId = isset($data['jadwal_produksi_id']) ? (int) $data['jadwal_produksi_id'] : $this->simpulkanBatch($sppgId, $titikId);

            if ($prioritas === PrioritasKeluhan::Kritis && $jadwalId === null && ! ($data['batch_tidak_diketahui'] ?? false)) {
                throw ValidationException::withMessages([
                    'jadwal_produksi_id' => ['Keluhan kritis wajib dikaitkan ke jadwal produksi (batch). Pilih batch, atau tandai batch tidak diketahui.'],
                ]);
            }

            $keluhan = new FeedbackKeluhan;
            $keluhan->forceFill(['sppg_id' => $sppgId]);
            $keluhan->fill([
                'titik_layanan_id' => $titikId,
                'jadwal_produksi_id' => $jadwalId,
                'dicatat_oleh' => $pencatat->getKey(),
                'sumber' => $data['sumber'],
                'kategori' => $kategori,
                'prioritas' => $prioritas,
                'status' => StatusKeluhan::Baru,
                'deskripsi' => $data['deskripsi'],
            ]);

            $this->eskalasiBilaKritis($keluhan);
            $keluhan->save();

            if ($keluhan->dieskalasi_pada !== null) {
                event(KeluhanKritis::dari($keluhan));
            }

            return $keluhan;
        });
    }

    /**
     * @param  array<string, mixed>  $data  hasil validasi UpdateKeluhanRequest (hanya key yang dikirim)
     */
    public function perbarui(User $pengguna, FeedbackKeluhan $keluhan, array $data): FeedbackKeluhan
    {
        return DB::transaction(function () use ($pengguna, $keluhan, $data) {
            $keluhan = FeedbackKeluhan::query()->whereKey($keluhan->getKey())->lockForUpdate()->firstOrFail();
            $sebelumnyaEskalasi = $keluhan->dieskalasi_pada !== null;

            foreach (['sumber', 'titik_layanan_id', 'jadwal_produksi_id', 'deskripsi', 'tindak_lanjut'] as $kolom) {
                if (array_key_exists($kolom, $data)) {
                    $keluhan->{$kolom} = $data[$kolom];
                }
            }

            if (array_key_exists('kategori', $data) || array_key_exists('prioritas', $data)) {
                $kategoriBerubah = array_key_exists('kategori', $data) && $data['kategori'] !== $keluhan->kategori->value;
                $kategori = array_key_exists('kategori', $data) ? KategoriKeluhan::from($data['kategori']) : $keluhan->kategori;
                $diminta = ! empty($data['prioritas'])
                    ? PrioritasKeluhan::from($data['prioritas'])
                    : ($kategoriBerubah ? null : $keluhan->prioritas);

                $keluhan->kategori = $kategori;
                $keluhan->prioritas = $this->tentukanPrioritas($kategori, $diminta);
            }

            if (! empty($data['status'])) {
                $this->terapkanStatus($pengguna, $keluhan, StatusKeluhan::from($data['status']));
            }

            if (! $sebelumnyaEskalasi) {
                $this->eskalasiBilaKritis($keluhan);
            }

            $keluhan->save();

            if (! $sebelumnyaEskalasi && $keluhan->dieskalasi_pada !== null) {
                event(KeluhanKritis::dari($keluhan));
            }

            return $keluhan;
        });
    }

    /**
     * Ringkasan jumlah per status dan prioritas pada cakupan query (sudah ter-scope).
     *
     * @param  Builder<FeedbackKeluhan>  $query
     * @return array<string, mixed>
     */
    public function ringkasan(Builder $query): array
    {
        $perStatus = array_fill_keys(StatusKeluhan::values(), 0);
        $perPrioritas = array_fill_keys(PrioritasKeluhan::values(), 0);

        $status = (clone $query)->reorder()->toBase()->selectRaw('status, count(*) as jumlah')->groupBy('status')->pluck('jumlah', 'status');
        $prioritas = (clone $query)->reorder()->toBase()->selectRaw('prioritas, count(*) as jumlah')->groupBy('prioritas')->pluck('jumlah', 'prioritas');

        foreach ($status as $k => $jumlah) {
            $perStatus[$k] = (int) $jumlah;
        }

        foreach ($prioritas as $k => $jumlah) {
            $perPrioritas[$k] = (int) $jumlah;
        }

        return [
            'total' => array_sum($perStatus),
            'per_status' => $perStatus,
            'per_prioritas' => $perPrioritas,
            'terbuka' => $perStatus[StatusKeluhan::Baru->value] + $perStatus[StatusKeluhan::Diproses->value],
            'kritis_terbuka' => (clone $query)->reorder()->kritis()->terbuka()->count(),
        ];
    }

    private function terapkanStatus(User $pengguna, FeedbackKeluhan $keluhan, StatusKeluhan $tujuan): void
    {
        $asal = $keluhan->status;

        if ($asal === $tujuan) {
            return;
        }

        if (! $asal->bolehKe($tujuan)) {
            throw ValidationException::withMessages([
                'status' => ["Status keluhan tidak dapat diubah dari {$asal->label()} ke {$tujuan->label()}."],
            ]);
        }

        if ($tujuan->final() && blank($keluhan->tindak_lanjut)) {
            throw ValidationException::withMessages([
                'tindak_lanjut' => ["Catatan tindak lanjut wajib diisi saat keluhan {$tujuan->label()}."],
            ]);
        }

        $keluhan->status = $tujuan;
        $keluhan->ditangani_oleh = $pengguna->getKey();

        if ($tujuan->final()) {
            $keluhan->selesai_pada = Carbon::now(config('operasional.timezone'));
        }
    }

    private function eskalasiBilaKritis(FeedbackKeluhan $keluhan): void
    {
        if ($keluhan->prioritas === PrioritasKeluhan::Kritis && $keluhan->dieskalasi_pada === null) {
            $keluhan->dieskalasi_pada = Carbon::now(config('operasional.timezone'));
        }
    }

    private function simpulkanBatch(int $sppgId, ?int $titikId): ?int
    {
        if ($titikId === null) {
            return null;
        }

        $id = JadwalDistribusi::query()->withoutGlobalScope(SppgScope::class)
            ->where('sppg_id', $sppgId)
            ->where('titik_layanan_id', $titikId)
            ->whereDate('tanggal_distribusi', Carbon::now(config('operasional.timezone'))->toDateString())
            ->orderByDesc('id')
            ->value('jadwal_produksi_id');

        return $id === null ? null : (int) $id;
    }

    private function bobot(PrioritasKeluhan $p): int
    {
        return (int) array_search($p, PrioritasKeluhan::cases(), true);
    }
}
