<?php

namespace App\Services\Stock;

use App\Enums\JenisMutasi;
use App\Events\BahanAkanKedaluwarsa;
use App\Events\StokMenipis;
use App\Models\BahanBaku;
use App\Models\JadwalProduksi;
use App\Models\MutasiStok;
use App\Models\Pemasok;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * Logika stok bahan baku (FR-04).
 *
 * - Saldo `bahan_bakus.stok` hanya berubah lewat catat() (transaksi + kunci baris), tidak pernah
 *   negatif. Aritmetika memakai bilangan bulat milli-satuan (3 desimal) karena bcmath tidak ada.
 * - Stok per batch dihitung dari mutasi masuk dengan urutan FEFO (kedaluwarsa terdekat habis lebih
 *   dulu): total terpakai = total masuk - saldo; dialokasikan ke batch menurut kedaluwarsa.
 * - Peringatan harian (stok menipis/kedaluwarsa) dipancarkan sebagai event, satu kali per kondisi
 *   per hari lewat penanda cache atomik (Cache::add).
 */
class StockService
{
    /**
     * @param  array{tanggal_mutasi?: string|null, tanggal_kedaluwarsa?: string|null, harga_satuan?: string|float|int|null, pemasok_id?: int|null, jadwal_produksi_id?: int|null, catatan?: string|null}  $opsi
     */
    public function catat(BahanBaku $bahan, JenisMutasi $jenis, string|float|int $jumlah, User $pengguna, array $opsi = []): MutasiStok
    {
        $delta = self::milli($jumlah);

        if ($delta === 0 || ($jenis !== JenisMutasi::Penyesuaian && $delta < 0)) {
            throw ValidationException::withMessages([
                'jumlah' => [$jenis === JenisMutasi::Penyesuaian
                    ? 'Jumlah penyesuaian tidak boleh 0.'
                    : 'Jumlah harus lebih besar dari 0.'],
            ]);
        }

        return DB::transaction(function () use ($bahan, $jenis, $delta, $pengguna, $opsi): MutasiStok {
            $terkunci = BahanBaku::withoutGlobalScope(SppgScope::class)
                ->whereKey($bahan->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $pemasokId = $jenis === JenisMutasi::Masuk ? ($opsi['pemasok_id'] ?? null) : null;
            $jadwalId = $jenis === JenisMutasi::Keluar ? ($opsi['jadwal_produksi_id'] ?? null) : null;

            if ($pemasokId !== null) {
                $ada = Pemasok::withoutGlobalScope(SppgScope::class)
                    ->where('sppg_id', $terkunci->sppg_id)->whereKey($pemasokId)->exists();

                if (! $ada) {
                    throw ValidationException::withMessages(['pemasok_id' => ['Pemasok tidak ditemukan.']]);
                }
            }

            if ($jadwalId !== null) {
                $ada = JadwalProduksi::withoutGlobalScope(SppgScope::class)
                    ->where('sppg_id', $terkunci->sppg_id)->whereKey($jadwalId)->exists();

                if (! $ada) {
                    throw ValidationException::withMessages(['jadwal_produksi_id' => ['Jadwal produksi tidak ditemukan.']]);
                }
            }

            $signed = $jenis === JenisMutasi::Keluar ? -$delta : $delta;
            $saldoBaru = self::milli($terkunci->stok) + $signed;

            if ($saldoBaru < 0) {
                throw ValidationException::withMessages([
                    'jumlah' => [sprintf(
                        'Stok %s tidak mencukupi. Tersedia %s %s, diminta %s %s.',
                        $terkunci->nama,
                        self::fromMilli(self::milli($terkunci->stok)),
                        $terkunci->satuan,
                        self::fromMilli(abs($delta)),
                        $terkunci->satuan,
                    )],
                ]);
            }

            $mutasi = new MutasiStok;
            $mutasi->forceFill([
                'sppg_id' => $terkunci->sppg_id,
                'bahan_baku_id' => $terkunci->getKey(),
                'jenis' => $jenis,
                'jumlah' => self::fromMilli($jenis === JenisMutasi::Penyesuaian ? $delta : abs($delta)),
                'harga_satuan' => $jenis === JenisMutasi::Masuk ? ($opsi['harga_satuan'] ?? null) : null,
                'tanggal_mutasi' => $opsi['tanggal_mutasi'] ?? now()->toDateString(),
                'tanggal_kedaluwarsa' => $jenis === JenisMutasi::Masuk ? ($opsi['tanggal_kedaluwarsa'] ?? null) : null,
                'pemasok_id' => $pemasokId,
                'jadwal_produksi_id' => $jadwalId,
                'dicatat_oleh' => $pengguna->getKey(),
                'catatan' => $opsi['catatan'] ?? null,
            ])->save();

            $terkunci->stok = self::fromMilli($saldoBaru);

            if ($jenis === JenisMutasi::Masuk && $pemasokId !== null && $terkunci->pemasok_id === null) {
                $terkunci->pemasok_id = $pemasokId;
            }

            $terkunci->kedaluwarsa_terdekat = $this->hitungKedaluwarsaTerdekat($terkunci);
            $terkunci->save();

            $mutasi->setRelation('bahanBaku', $terkunci);
            $mutasi->setAttribute('stok_setelah', $terkunci->stok);

            return $mutasi;
        });
    }

    /**
     * Batch dari mutasi masuk beserta sisanya (FEFO). Hanya batch yang sisanya > 0.
     *
     * @return Collection<int, array{mutasi_stok_id: int, tanggal_masuk: string, tanggal_kedaluwarsa: ?string, jumlah_awal: string, sisa: string, pemasok_id: ?int, sisa_hari: ?int}>
     */
    public function batch(BahanBaku $bahan): Collection
    {
        [$batches] = $this->alokasiBatch($bahan);

        return collect($batches)
            ->filter(fn (array $b) => $b['sisa_milli'] > 0)
            ->map(fn (array $b) => [
                'mutasi_stok_id' => $b['id'],
                'tanggal_masuk' => $b['tanggal_masuk'],
                'tanggal_kedaluwarsa' => $b['kedaluwarsa'],
                'jumlah_awal' => self::fromMilli($b['awal_milli']),
                'sisa' => self::fromMilli($b['sisa_milli']),
                'pemasok_id' => $b['pemasok_id'],
                'sisa_hari' => $b['kedaluwarsa'] === null
                    ? null
                    : (int) now()->startOfDay()->diffInDays(Carbon::parse($b['kedaluwarsa'])->startOfDay(), false),
            ])
            ->values();
    }

    /** Stok yang tidak terikat batch masuk (stok awal / penyesuaian positif), 3 desimal. */
    public function stokTanpaBatch(BahanBaku $bahan): string
    {
        [, $tanpaBatch] = $this->alokasiBatch($bahan);

        return self::fromMilli($tanpaBatch);
    }

    /**
     * Kedaluwarsa terdekat dari batch yang masih bersisa. Bila bahan belum punya riwayat mutasi masuk
     * (nilai diisi manual), nilai lama dipertahankan.
     */
    public function hitungKedaluwarsaTerdekat(BahanBaku $bahan): ?string
    {
        [$batches] = $this->alokasiBatch($bahan);

        if ($batches === []) {
            return $bahan->kedaluwarsa_terdekat?->toDateString();
        }

        $tanggal = collect($batches)
            ->filter(fn (array $b) => $b['sisa_milli'] > 0 && $b['kedaluwarsa'] !== null)
            ->pluck('kedaluwarsa')
            ->min();

        return $tanggal;
    }

    /**
     * Periksa semua SPPG: pancarkan StokMenipis dan BahanAkanKedaluwarsa, sekali per kondisi per hari.
     *
     * @return array{stok_menipis: int, kedaluwarsa: int}
     */
    public function peringatanHarian(?int $hari = null, ?CarbonInterface $sekarang = null): array
    {
        $sekarang ??= now();
        $hari ??= (int) config('gudang.kedaluwarsa_hari', 3);
        $tanggal = $sekarang->toDateString();
        $ttl = $sekarang->copy()->addHours((int) config('gudang.penanda_ttl_jam', 36));
        $hasil = ['stok_menipis' => 0, 'kedaluwarsa' => 0];

        BahanBaku::withoutGlobalScope(SppgScope::class)
            ->whereColumn('stok', '<=', 'stok_minimum')
            ->chunkById(200, function ($daftar) use (&$hasil, $tanggal, $ttl): void {
                foreach ($daftar as $bahan) {
                    if (Cache::add("gudang:stok-menipis:{$bahan->id}:{$tanggal}", true, $ttl)) {
                        Event::dispatch(new StokMenipis(
                            (int) $bahan->sppg_id, (int) $bahan->id, $bahan->nama, $bahan->satuan,
                            (string) $bahan->stok, (string) $bahan->stok_minimum,
                        ));
                        $hasil['stok_menipis']++;
                    }
                }
            });

        BahanBaku::withoutGlobalScope(SppgScope::class)
            ->whereNotNull('kedaluwarsa_terdekat')
            ->where('stok', '>', 0)
            ->whereDate('kedaluwarsa_terdekat', '<=', $sekarang->copy()->addDays($hari)->toDateString())
            ->chunkById(200, function ($daftar) use (&$hasil, $tanggal, $ttl, $sekarang): void {
                foreach ($daftar as $bahan) {
                    $kedaluwarsa = $bahan->kedaluwarsa_terdekat->toDateString();

                    if (Cache::add("gudang:kedaluwarsa:{$bahan->id}:{$kedaluwarsa}:{$tanggal}", true, $ttl)) {
                        Event::dispatch(new BahanAkanKedaluwarsa(
                            (int) $bahan->sppg_id, (int) $bahan->id, $bahan->nama, $bahan->satuan,
                            (string) $bahan->stok, $kedaluwarsa,
                            (int) $sekarang->copy()->startOfDay()->diffInDays($bahan->kedaluwarsa_terdekat->copy()->startOfDay(), false),
                        ));
                        $hasil['kedaluwarsa']++;
                    }
                }
            });

        return $hasil;
    }

    /**
     * @return array{0: list<array{id: int, tanggal_masuk: string, kedaluwarsa: ?string, awal_milli: int, sisa_milli: int, pemasok_id: ?int}>, 1: int}
     */
    private function alokasiBatch(BahanBaku $bahan): array
    {
        $masuk = MutasiStok::withoutGlobalScope(SppgScope::class)
            ->where('bahan_baku_id', $bahan->getKey())
            ->where('jenis', JenisMutasi::Masuk->value)
            ->orderByRaw('tanggal_kedaluwarsa IS NULL')
            ->orderBy('tanggal_kedaluwarsa')
            ->orderBy('tanggal_mutasi')
            ->orderBy('id')
            ->get();

        $totalMasuk = 0;
        $batches = [];

        foreach ($masuk as $m) {
            $awal = self::milli($m->jumlah);
            $totalMasuk += $awal;
            $batches[] = [
                'id' => (int) $m->id,
                'tanggal_masuk' => $m->tanggal_mutasi->toDateString(),
                'kedaluwarsa' => $m->tanggal_kedaluwarsa?->toDateString(),
                'awal_milli' => $awal,
                'sisa_milli' => $awal,
                'pemasok_id' => $m->pemasok_id === null ? null : (int) $m->pemasok_id,
            ];
        }

        $saldo = self::milli($bahan->stok);
        $terpakai = max(0, $totalMasuk - $saldo);

        foreach ($batches as &$batch) {
            $ambil = min($terpakai, $batch['sisa_milli']);
            $batch['sisa_milli'] -= $ambil;
            $terpakai -= $ambil;
        }
        unset($batch);

        $sisaBatch = array_sum(array_column($batches, 'sisa_milli'));

        return [$batches, max(0, $saldo - $sisaBatch)];
    }

    public static function milli(string|float|int|null $nilai): int
    {
        return (int) round(((float) $nilai) * 1000);
    }

    public static function fromMilli(int $milli): string
    {
        $tanda = $milli < 0 ? '-' : '';
        $abs = abs($milli);

        return sprintf('%s%d.%03d', $tanda, intdiv($abs, 1000), $abs % 1000);
    }
}
