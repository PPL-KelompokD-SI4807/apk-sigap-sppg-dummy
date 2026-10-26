<?php

namespace App\Services\Produksi;

use App\Enums\StatusJadwalProduksi;
use App\Models\JadwalProduksi;
use App\Models\LogProduksi;
use App\Models\Scopes\SppgScope;
use App\Models\Sppg;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Log produksi harian (FR-06): satu log per jadwal, kode batch otomatis, restore bila log lama
 * dihapus lunak, validasi realisasi terhadap kapasitas dan selisih terhadap target.
 */
class LogProduksiService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function catat(User $pengguna, array $data): LogProduksi
    {
        return DB::transaction(function () use ($pengguna, $data): LogProduksi {
            $jadwal = JadwalProduksi::withoutGlobalScope(SppgScope::class)
                ->where('sppg_id', $pengguna->sppg_id)
                ->whereKey($data['jadwal_produksi_id'])
                ->lockForUpdate()
                ->first();

            if ($jadwal === null) {
                throw ValidationException::withMessages(['jadwal_produksi_id' => ['Jadwal produksi tidak ditemukan.']]);
            }

            if ($jadwal->status === StatusJadwalProduksi::Dibatalkan) {
                throw ValidationException::withMessages(['jadwal_produksi_id' => ['Jadwal produksi sudah dibatalkan.']]);
            }

            if ($jadwal->tanggal_produksi->toDateString() > now()->toDateString()) {
                throw ValidationException::withMessages(['jadwal_produksi_id' => ['Jadwal produksi belum dimulai (tanggal produksi di masa depan).']]);
            }

            $this->pastikanRealisasiWajar((int) $data['realisasi_porsi'], (int) $jadwal->sppg_id);

            $ada = LogProduksi::withoutGlobalScope(SppgScope::class)->withTrashed()
                ->where('jadwal_produksi_id', $jadwal->id)->lockForUpdate()->first();

            if ($ada !== null && ! $ada->trashed()) {
                throw ValidationException::withMessages(['jadwal_produksi_id' => ['Jadwal produksi ini sudah memiliki log produksi. Ubah log yang ada.']]);
            }

            $atribut = $this->atribut($data);

            if ($ada !== null) {
                $ada->restore();
                $ada->fill($atribut);
                $ada->forceFill(['dicatat_oleh' => $pengguna->getKey(), 'created_at' => now()]);
                $this->simpanLog($ada, $jadwal, $ada->kode_batch === null);

                return $ada;
            }

            $log = new LogProduksi;
            $log->fill($atribut);
            $log->forceFill([
                'sppg_id' => $jadwal->sppg_id,
                'jadwal_produksi_id' => $jadwal->id,
                'dicatat_oleh' => $pengguna->getKey(),
            ]);
            $this->simpanLog($log, $jadwal, true);

            return $log;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function perbarui(LogProduksi $log, array $data): LogProduksi
    {
        if (isset($data['realisasi_porsi'])) {
            $this->pastikanRealisasiWajar((int) $data['realisasi_porsi'], (int) $log->sppg_id);
        }

        $atribut = $this->atribut($data);
        $waktuMulai = array_key_exists('waktu_mulai', $atribut) ? $atribut['waktu_mulai'] : $log->waktu_mulai;
        $jamSelesai = array_key_exists('jam_selesai', $atribut) ? $atribut['jam_selesai'] : $log->jam_selesai;

        if ($waktuMulai !== null && $jamSelesai !== null && substr((string) $jamSelesai, 0, 5) <= substr((string) $waktuMulai, 0, 5)) {
            throw ValidationException::withMessages(['jam_selesai' => ['Jam selesai harus setelah waktu mulai.']]);
        }

        $log->fill($atribut)->save();

        return $log;
    }

    /**
     * Kode batch `BATCH-YYYYMMDD-NN` (urutan per SPPG per tanggal produksi, termasuk log terhapus).
     * Indeks unik (sppg_id, kode_batch) menjamin keunikan; simpanLog() mengulang bila ada bentrok.
     */
    private function buatKodeBatch(JadwalProduksi $jadwal): string
    {
        $awalan = 'BATCH-'.$jadwal->tanggal_produksi->format('Ymd').'-';

        $terakhir = LogProduksi::withoutGlobalScope(SppgScope::class)->withTrashed()
            ->where('sppg_id', $jadwal->sppg_id)
            ->where('kode_batch', 'like', $awalan.'%')
            ->pluck('kode_batch')
            ->map(fn (string $kode) => (int) substr($kode, strlen($awalan)))
            ->max() ?? 0;

        return $awalan.str_pad((string) ($terakhir + 1), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Simpan log; bentrok kode batch (dua log berbeda pada tanggal yang sama bersamaan) diulang
     * dengan kode baru lewat savepoint.
     */
    private function simpanLog(LogProduksi $log, JadwalProduksi $jadwal, bool $buatKode): void
    {
        for ($percobaan = 1; $percobaan <= 5; $percobaan++) {
            if ($buatKode) {
                $log->kode_batch = $this->buatKodeBatch($jadwal);
            }

            try {
                DB::transaction(fn () => $log->save());

                return;
            } catch (UniqueConstraintViolationException $e) {
                if (! $buatKode || $percobaan === 5) {
                    throw $e;
                }
            }
        }
    }

    private function pastikanRealisasiWajar(int $realisasi, int $sppgId): void
    {
        $kapasitas = (int) Sppg::query()->whereKey($sppgId)->value('kapasitas_porsi_harian');

        if ($kapasitas > 0 && $realisasi > $kapasitas) {
            throw ValidationException::withMessages([
                'realisasi_porsi' => [sprintf('Realisasi %d porsi melebihi kapasitas harian SPPG (%d porsi).', $realisasi, $kapasitas)],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function atribut(array $data): array
    {
        return array_intersect_key($data, array_flip(['realisasi_porsi', 'sisa_makanan_kg', 'waktu_mulai', 'jam_selesai', 'kendala']));
    }
}
