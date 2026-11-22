<?php

namespace App\Services\Dashboard;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cakupan satu pembangunan dashboard: pengguna, himpunan SPPG yang BOLEH dibaca (sudah divalidasi
 * DashboardService), dan jendela waktu. Semua query widget wajib lewat tabel() sehingga filter
 * `sppg_id IN (...)` selalu terpasang.
 *
 * Mengapa query builder + whereIn eksplisit, bukan global scope SppgScope: investor dapat melihat
 * GABUNGAN beberapa SPPG miliknya, sedangkan SppgScope hanya mengenal SATU SPPG aktif. Himpunan SPPG
 * di sini berasal dari sumber otoritatif yang sama dengan SppgScope (SppgAktif/users.sppg_id/pivot
 * investor_sppg) dan tidak pernah dari input mentah. Pola yang sama dipakai DailySummaryService.
 */
final class Cakupan
{
    public const MODE_SATU = 'satu';

    public const MODE_GABUNGAN = 'gabungan';

    /**
     * @param  list<int>  $ids
     * @param  list<array{id: int, kode: string, nama: string, status_operasional: string}>  $sppg
     */
    public function __construct(
        public readonly User $user,
        public readonly array $ids,
        public readonly array $sppg,
        public readonly string $mode,
        public readonly CarbonImmutable $hariIni,
        public readonly int $rentang,
    ) {}

    public function hari(): string
    {
        return $this->hariIni->toDateString();
    }

    public function besok(): string
    {
        return $this->hariIni->addDay()->toDateString();
    }

    public function bulan(): string
    {
        return $this->hariIni->format('Y-m');
    }

    public function awalBulan(): string
    {
        return $this->hariIni->startOfMonth()->toDateString();
    }

    public function akhirBulan(): string
    {
        return $this->hariIni->endOfMonth()->toDateString();
    }

    public function mulaiTren(): string
    {
        return $this->hariIni->subDays($this->rentang - 1)->toDateString();
    }

    /** Tanggal paling awal yang dibutuhkan oleh jendela bulan berjalan DAN tren. */
    public function mulaiTerjauh(): string
    {
        return min($this->awalBulan(), $this->mulaiTren());
    }

    /** @return list<string> tanggal Y-m-d dari awal tren sampai hari ini (berurutan). */
    public function hariTren(): array
    {
        $hasil = [];

        for ($i = $this->rentang - 1; $i >= 0; $i--) {
            $hasil[] = $this->hariIni->subDays($i)->toDateString();
        }

        return $hasil;
    }

    /** Tabel dengan filter SPPG; $lunak = tabel punya deleted_at (hapus lunak). */
    public function tabel(string $nama, bool $lunak = true): Builder
    {
        $q = DB::table($nama)->whereIn("{$nama}.sppg_id", $this->ids);

        return $lunak ? $q->whereNull("{$nama}.deleted_at") : $q;
    }

    /** Batasi kolom tanggal/waktu ke [$dari, $sampai] (inklusif, format Y-m-d). Aman untuk DATE dan DATETIME. */
    public function antara(Builder $q, string $kolom, string $dari, string $sampai): Builder
    {
        return $q->where($kolom, '>=', $dari)->where($kolom, '<', CarbonImmutable::parse($sampai)->addDay()->toDateString());
    }

    /** Batasi kolom tanggal/waktu ke satu hari. */
    public function pada(Builder $q, string $kolom, ?string $tanggal = null): Builder
    {
        $tanggal ??= $this->hari();

        return $this->antara($q, $kolom, $tanggal, $tanggal);
    }

    /**
     * Isi deret harian nol untuk setiap hari tren dari baris `[hari => nilai]`.
     *
     * @param  array<string, int|float>  $peta
     * @return list<int|float>
     */
    public function deret(array $peta, bool $desimal = false): array
    {
        return array_map(fn (string $h) => $desimal ? round((float) ($peta[$h] ?? 0), 2) : (int) ($peta[$h] ?? 0), $this->hariTren());
    }

    /** @return array{jenis: string, tanggal: string} */
    public function periodeHariIni(): array
    {
        return ['jenis' => 'hari_ini', 'tanggal' => $this->hari()];
    }

    /** @return array{jenis: string, bulan: string, dari: string, sampai: string} */
    public function periodeBulan(): array
    {
        return ['jenis' => 'bulan_berjalan', 'bulan' => $this->bulan(), 'dari' => $this->awalBulan(), 'sampai' => $this->akhirBulan()];
    }

    /** @return array{jenis: string, rentang_hari: int, dari: string, sampai: string} */
    public function periodeTren(): array
    {
        return ['jenis' => 'tren', 'rentang_hari' => $this->rentang, 'dari' => $this->mulaiTren(), 'sampai' => $this->hari()];
    }

    /** @return array{jenis: string, per: string} keadaan terkini (tanpa batas awal periode). */
    public function periodeSaatIni(): array
    {
        return ['jenis' => 'saat_ini', 'per' => $this->hari()];
    }

    /** @return array<int, string> id SPPG => nama */
    public function namaSppg(): array
    {
        $hasil = [];

        foreach ($this->sppg as $s) {
            $hasil[$s['id']] = $s['nama'];
        }

        return $hasil;
    }

    public function banyakSppg(): int
    {
        return count($this->ids);
    }
}
