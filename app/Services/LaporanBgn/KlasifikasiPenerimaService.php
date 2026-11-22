<?php

namespace App\Services\LaporanBgn;

use App\Enums\BarisSasaranBgn;
use App\Enums\JenisKelamin;
use App\Enums\JenisSatuanPendidikan;
use App\Enums\JenjangPenerima;
use App\Enums\KelompokPorsiBgn;
use App\Enums\KelompokSasaran;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Agregasi penerima manfaat AKTIF untuk Lampiran 30: hanya hitungan, tidak pernah nama/kelas/alergi/ID penerima.
 * Penerima yang klasifikasinya belum lengkap (jenjang kosong dan sebagainya) tidak ditebak: dihitung di
 * `belum_terklasifikasi` (R07-1 K2).
 */
class KlasifikasiPenerimaService
{
    public const BELUM = 'belum_terklasifikasi';

    /**
     * Hitungan penerima aktif per kelompok 30a (tanpa Food Sample/Uji Organoleptik, yang berupa porsi).
     *
     * @return array<string, int> kunci = value KelompokPorsiBgn (tanpa porsi langsung) + self::BELUM
     */
    public function per15Kelompok(int $sppgId): array
    {
        $hasil = [];

        foreach (KelompokPorsiBgn::cases() as $k) {
            if (! $k->porsiLangsung()) {
                $hasil[$k->value] = 0;
            }
        }

        $hasil[self::BELUM] = 0;

        foreach ($this->kelompokan($sppgId) as $baris) {
            $kelompok = KelompokSasaran::tryFrom((string) $baris->kelompok_sasaran);
            $kunci = $kelompok === null ? null : KelompokPorsiBgn::dari(
                $kelompok,
                JenjangPenerima::tryFrom((string) $baris->jenjang),
                JenisSatuanPendidikan::tryFrom((string) $baris->jenis_satuan),
            )?->value;

            $hasil[$kunci ?? self::BELUM] += (int) $baris->jumlah;
        }

        return $hasil;
    }

    /**
     * Hitungan penerima aktif per baris sasaran 30b/30d-C.
     *
     * @return array<string, int> kunci = value BarisSasaranBgn + self::BELUM
     */
    public function perBarisSasaran(int $sppgId): array
    {
        $hasil = array_fill_keys(BarisSasaranBgn::values(), 0) + [self::BELUM => 0];

        foreach ($this->kelompokan($sppgId) as $baris) {
            $kunci = $this->barisDari($baris)?->value;
            $hasil[$kunci ?? self::BELUM] += (int) $baris->jumlah;
        }

        return $hasil;
    }

    /**
     * Rincian 30b: per baris sasaran, per titik layanan (nama sekolah/posyandu), per jenis kelamin.
     * Titik layanan terhapus lunak tetap dipakai namanya (riwayat sasaran).
     *
     * @return array{rincian: array<string, array<int, array{nama: string, laki_laki: int, perempuan: int, tanpa_jenis_kelamin: int}>>, belum_terklasifikasi: int}
     */
    public function rincianPerTitik(int $sppgId): array
    {
        $rincian = array_fill_keys(BarisSasaranBgn::values(), []);
        $belum = 0;

        foreach ($this->kelompokan($sppgId) as $baris) {
            $kunci = $this->barisDari($baris)?->value;

            if ($kunci === null) {
                $belum += (int) $baris->jumlah;

                continue;
            }

            $titik = (int) $baris->titik_layanan_id;
            $rincian[$kunci][$titik] ??= ['nama' => (string) $baris->titik_nama, 'laki_laki' => 0, 'perempuan' => 0, 'tanpa_jenis_kelamin' => 0];

            $kolom = match (JenisKelamin::tryFrom((string) $baris->jenis_kelamin)) {
                JenisKelamin::LakiLaki => 'laki_laki',
                JenisKelamin::Perempuan => 'perempuan',
                null => 'tanpa_jenis_kelamin',
            };
            $rincian[$kunci][$titik][$kolom] += (int) $baris->jumlah;
        }

        foreach ($rincian as &$perBaris) {
            uasort($perBaris, fn (array $a, array $b) => strcmp($a['nama'], $b['nama']));
        }
        unset($perBaris);

        return ['rincian' => $rincian, 'belum_terklasifikasi' => $belum];
    }

    private function barisDari(object $baris): ?BarisSasaranBgn
    {
        $kelompok = KelompokSasaran::tryFrom((string) $baris->kelompok_sasaran);

        return $kelompok === null ? null : BarisSasaranBgn::dari($kelompok, JenjangPenerima::tryFrom((string) $baris->jenjang));
    }

    /** @return Collection<int, object> */
    private function kelompokan(int $sppgId)
    {
        return DB::table('penerima_manfaats')
            ->leftJoin('titik_layanans', 'titik_layanans.id', '=', 'penerima_manfaats.titik_layanan_id')
            ->where('penerima_manfaats.sppg_id', $sppgId)
            ->whereNull('penerima_manfaats.deleted_at')
            ->where('penerima_manfaats.is_active', true)
            ->groupBy(
                'penerima_manfaats.kelompok_sasaran', 'penerima_manfaats.jenjang', 'penerima_manfaats.jenis_satuan',
                'penerima_manfaats.jenis_kelamin', 'penerima_manfaats.titik_layanan_id', 'titik_layanans.nama',
            )
            ->get([
                'penerima_manfaats.kelompok_sasaran', 'penerima_manfaats.jenjang', 'penerima_manfaats.jenis_satuan',
                'penerima_manfaats.jenis_kelamin', 'penerima_manfaats.titik_layanan_id',
                DB::raw('titik_layanans.nama as titik_nama'), DB::raw('COUNT(*) as jumlah'),
            ]);
    }
}
