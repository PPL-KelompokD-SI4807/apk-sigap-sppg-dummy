<?php

namespace App\Services\LaporanBgn;

use App\Enums\JenisLaporanBgn;
use App\Models\LaporanBgn;
use App\Models\Sppg;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Penomor laporan BGN `xx/LPA/x/tahun` (tafsiran Q-14: xx = nomor urut dua digit per SPPG per tahun, x = bulan akhir
 * periode dalam angka Romawi, tahun = tahun akhir periode).
 *
 * - Idempoten: periode yang sama (SPPG + jenis dokumen + awal + akhir) selalu mendapat nomor yang sama.
 * - Urutan nomor per (SPPG, tahun) dibagi seluruh jenis bernomor (30b, 30c, 30d); dikunci lewat baris `sppgs`
 *   (mutex per SPPG seperti BudgetService) dan dijaga indeks unik (sppg, tahun, nomor_urut).
 * - Nomor yang pernah terbit tidak dipakai ulang (tidak ada hapus lunak/penghapusan).
 * - GET laporan hanya membaca (`baca`): tidak membuat baris dan tidak menghabiskan nomor. Nomor baru hanya
 *   diterbitkan lewat `terbitkan` (POST khusus, pengguna berhak).
 */
class PenomoranLaporanService
{
    private const ROMAWI = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'];

    /** Baca-saja: catatan yang sudah ada (bernomor atau belum), atau null. */
    public function baca(Sppg $sppg, JenisLaporanBgn $jenis, CarbonInterface $awal, CarbonInterface $akhir): ?LaporanBgn
    {
        return LaporanBgn::query()->withoutGlobalScopes()
            ->where('sppg_id', $sppg->getKey())->where('jenis', $jenis->jenisDokumen())
            ->whereDate('periode_awal', $awal->toDateString())->whereDate('periode_akhir', $akhir->toDateString())
            ->first();
    }

    /**
     * Terbitkan (atau kembalikan yang sudah ada) nomor untuk periode. Idempoten.
     *
     * @return array{0: LaporanBgn, 1: bool} catatan + apakah nomor baru terbit pada panggilan ini
     */
    public function terbitkan(Sppg $sppg, JenisLaporanBgn $jenis, CarbonInterface $awal, CarbonInterface $akhir, ?int $penggunaId): array
    {
        try {
            return $this->dalamTransaksi($sppg, $jenis, $awal, $akhir, $penggunaId);
        } catch (UniqueConstraintViolationException) {
            // Dua permintaan serentak (mis. DB tanpa penguncian baris): baris sudah dibuat pihak lain; ulangi sekali.
            return $this->dalamTransaksi($sppg, $jenis, $awal, $akhir, $penggunaId);
        }
    }

    public static function format(int $urut, int $bulan, int $tahun): string
    {
        return sprintf('%02d/LPA/%s/%d', $urut, self::ROMAWI[$bulan], $tahun);
    }

    private function dalamTransaksi(Sppg $sppg, JenisLaporanBgn $jenis, CarbonInterface $awal, CarbonInterface $akhir, ?int $penggunaId): array
    {
        return DB::transaction(function () use ($sppg, $jenis, $awal, $akhir, $penggunaId): array {
            DB::table('sppgs')->where('id', $sppg->getKey())->lockForUpdate()->first(['id']);

            $dokumen = $jenis->jenisDokumen();
            $catatan = LaporanBgn::query()->withoutGlobalScopes()
                ->where('sppg_id', $sppg->getKey())->where('jenis', $dokumen)
                ->whereDate('periode_awal', $awal->toDateString())->whereDate('periode_akhir', $akhir->toDateString())
                ->lockForUpdate()->first();

            if ($catatan === null) {
                $catatan = new LaporanBgn;
                $catatan->forceFill([
                    'sppg_id' => $sppg->getKey(),
                    'jenis' => $dokumen,
                    'periode_awal' => $awal->toDateString(),
                    'periode_akhir' => $akhir->toDateString(),
                    'tahun' => (int) $akhir->format('Y'),
                    'dibuat_oleh' => $penggunaId,
                ]);
                $catatan->save();
            }

            $baru = false;

            if ($jenis->bernomor() && $catatan->nomor === null) {
                $urut = ((int) LaporanBgn::query()->withoutGlobalScopes()
                    ->where('sppg_id', $sppg->getKey())->where('tahun', $catatan->tahun)->max('nomor_urut')) + 1;

                $catatan->forceFill([
                    'nomor_urut' => $urut,
                    'nomor' => self::format($urut, (int) $akhir->format('n'), (int) $catatan->tahun),
                ])->save();
                $baru = true;
            }

            return [$catatan, $baru];
        });
    }
}
