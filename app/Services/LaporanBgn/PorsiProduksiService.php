<?php

namespace App\Services\LaporanBgn;

use App\Enums\StatusJadwalDistribusi;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Porsi harian per SPPG untuk Lampiran 30: `log_produksis.realisasi_porsi` (per tanggal produksi jadwalnya),
 * cadangan `jadwal_distribusis.jumlah_porsi` (kecuali dibatalkan) pada hari tanpa log produksi.
 * Porsi Food Sample dan Uji Organoleptik adalah porsi tambahan (kolom nullable; kosong = 0), tidak termasuk
 * `realisasi_porsi` penerima manfaat (tafsiran Q-15).
 */
class PorsiProduksiService
{
    /**
     * @return array<string, array{porsi: int, food_sample: int, uji_organoleptik: int, sumber: string}> kunci = tanggal Y-m-d (hanya hari yang punya data)
     */
    public function perTanggal(int $sppgId, CarbonInterface $awal, CarbonInterface $akhir): array
    {
        $hasil = [];

        $log = DB::table('log_produksis')
            ->join('jadwal_produksis', 'jadwal_produksis.id', '=', 'log_produksis.jadwal_produksi_id')
            ->where('log_produksis.sppg_id', $sppgId)
            ->whereNull('log_produksis.deleted_at')
            ->whereNull('jadwal_produksis.deleted_at')
            ->whereDate('jadwal_produksis.tanggal_produksi', '>=', $awal->toDateString())
            ->whereDate('jadwal_produksis.tanggal_produksi', '<=', $akhir->toDateString())
            ->groupBy('jadwal_produksis.tanggal_produksi')
            ->get([
                'jadwal_produksis.tanggal_produksi as tanggal',
                DB::raw('SUM(log_produksis.realisasi_porsi) as porsi'),
                DB::raw('SUM(COALESCE(log_produksis.porsi_food_sample, 0)) as food_sample'),
                DB::raw('SUM(COALESCE(log_produksis.porsi_uji_organoleptik, 0)) as uji'),
            ]);

        foreach ($log as $baris) {
            $hasil[self::tanggal($baris->tanggal)] = [
                'porsi' => (int) $baris->porsi,
                'food_sample' => (int) $baris->food_sample,
                'uji_organoleptik' => (int) $baris->uji,
                'sumber' => 'log_produksi',
            ];
        }

        $distribusi = DB::table('jadwal_distribusis')
            ->where('sppg_id', $sppgId)
            ->whereNull('deleted_at')
            ->where('status', '!=', StatusJadwalDistribusi::Dibatalkan->value)
            ->whereDate('tanggal_distribusi', '>=', $awal->toDateString())
            ->whereDate('tanggal_distribusi', '<=', $akhir->toDateString())
            ->groupBy('tanggal_distribusi')
            ->get(['tanggal_distribusi as tanggal', DB::raw('SUM(jumlah_porsi) as porsi')]);

        foreach ($distribusi as $baris) {
            $tanggal = self::tanggal($baris->tanggal);

            if (! isset($hasil[$tanggal])) {
                $hasil[$tanggal] = ['porsi' => (int) $baris->porsi, 'food_sample' => 0, 'uji_organoleptik' => 0, 'sumber' => 'jadwal_distribusi'];
            }
        }

        ksort($hasil);

        return $hasil;
    }

    private static function tanggal(mixed $nilai): string
    {
        return substr((string) $nilai, 0, 10);
    }
}
