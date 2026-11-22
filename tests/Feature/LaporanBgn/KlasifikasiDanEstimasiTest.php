<?php

namespace Tests\Feature\LaporanBgn;

use App\Enums\BarisSasaranBgn;
use App\Enums\JenisSatuanPendidikan;
use App\Enums\JenjangPenerima;
use App\Enums\KelompokPorsiBgn;
use App\Enums\KelompokSasaran;
use App\Services\LaporanBgn\EstimasiPorsi;
use App\Services\LaporanBgn\PenomoranLaporanService;
use PHPUnit\Framework\TestCase;

/** Murni logika: estimasi porsi (jumlah selalu = porsi), pemetaan 15 kelompok / baris 30b, format nomor. */
class KlasifikasiDanEstimasiTest extends TestCase
{
    public function test_estimasi_selalu_berjumlah_sama_dengan_porsi(): void
    {
        foreach ([[100, ['a' => 1, 'b' => 1, 'c' => 1]], [7, ['a' => 3, 'b' => 3]], [1, ['a' => 5, 'b' => 5, 'c' => 5]], [123, ['a' => 10, 'b' => 20, 'c' => 3, 'd' => 0]]] as [$porsi, $jumlah]) {
            $hasil = EstimasiPorsi::bagi($porsi, $jumlah);
            $this->assertSame($porsi, array_sum($hasil));
            $this->assertSame(array_keys($jumlah), array_keys($hasil));
        }

        $this->assertSame(['a' => 0, 'b' => 0], EstimasiPorsi::bagi(0, ['a' => 1, 'b' => 2]));
        $this->assertSame(['a' => 0, 'b' => 0], EstimasiPorsi::bagi(10, ['a' => 0, 'b' => 0]));
        $this->assertSame(['a' => 10, 'b' => 20, 'c' => 0], EstimasiPorsi::bagi(30, ['a' => 10, 'b' => 20, 'c' => 0]));
        // Seri diputus urutan kunci (deterministik).
        $this->assertSame(['a' => 1, 'b' => 0, 'c' => 0], EstimasiPorsi::bagi(1, ['a' => 1, 'b' => 1, 'c' => 1]));
    }

    public function test_pemetaan_15_kelompok(): void
    {
        $this->assertSame(15, count(KelompokPorsiBgn::cases()));
        $dari = fn (KelompokSasaran $k, ?JenjangPenerima $j = null, ?JenisSatuanPendidikan $s = null) => KelompokPorsiBgn::dari($k, $j, $s);

        $this->assertSame(KelompokPorsiBgn::Sd1a3, $dari(KelompokSasaran::Siswa, JenjangPenerima::Sd1a3));
        $this->assertSame(KelompokPorsiBgn::Sd1a3, $dari(KelompokSasaran::Siswa, JenjangPenerima::Sd1a3, JenisSatuanPendidikan::Reguler));
        $this->assertSame(KelompokPorsiBgn::Slb, $dari(KelompokSasaran::Siswa, JenjangPenerima::Smp, JenisSatuanPendidikan::Slb));
        $this->assertSame(KelompokPorsiBgn::Santri, $dari(KelompokSasaran::Siswa, JenjangPenerima::Sma, JenisSatuanPendidikan::Pesantren));
        $this->assertSame(KelompokPorsiBgn::PendidikanKhususLainnya, $dari(KelompokSasaran::Siswa, null, JenisSatuanPendidikan::PendidikanKhusus));
        $this->assertSame(KelompokPorsiBgn::PendidikanInformal, $dari(KelompokSasaran::Siswa, JenjangPenerima::PaudTk, JenisSatuanPendidikan::Informal));
        $this->assertSame(KelompokPorsiBgn::PendidikanInformal, $dari(KelompokSasaran::Ats, JenjangPenerima::Ats9a18));
        $this->assertSame(KelompokPorsiBgn::AnakBalita, $dari(KelompokSasaran::Balita));
        $this->assertSame(KelompokPorsiBgn::PendidikDanTenaga, $dari(KelompokSasaran::TenagaKependidikan));
        $this->assertSame(KelompokPorsiBgn::PendidikDanTenaga, $dari(KelompokSasaran::Pendidik));
        // Tidak ditebak: siswa tanpa jenjang.
        $this->assertNull($dari(KelompokSasaran::Siswa));
        $this->assertNull($dari(KelompokSasaran::Ats));

        $kecil = array_filter(KelompokPorsiBgn::cases(), fn (KelompokPorsiBgn $k) => $k->kecil());
        $this->assertEqualsCanonicalizing([KelompokPorsiBgn::AnakBalita, KelompokPorsiBgn::PaudTk, KelompokPorsiBgn::Sd1a3], array_values($kecil));
    }

    public function test_pemetaan_baris_sasaran_30b(): void
    {
        $this->assertSame(BarisSasaranBgn::Sd4a6, BarisSasaranBgn::dari(KelompokSasaran::Siswa, JenjangPenerima::Sd4a6));
        $this->assertSame(BarisSasaranBgn::AtsKurang9, BarisSasaranBgn::dari(KelompokSasaran::Ats, JenjangPenerima::AtsKurang9));
        $this->assertSame(BarisSasaranBgn::Pendidik, BarisSasaranBgn::dari(KelompokSasaran::Pendidik, null));
        $this->assertSame(BarisSasaranBgn::Balita, BarisSasaranBgn::dari(KelompokSasaran::Balita, null));
        $this->assertNull(BarisSasaranBgn::dari(KelompokSasaran::Siswa, null));
        $this->assertCount(9, array_filter(BarisSasaranBgn::cases(), fn ($b) => $b->pesertaDidik()));
    }

    public function test_format_nomor_lpa(): void
    {
        $this->assertSame('01/LPA/I/2026', PenomoranLaporanService::format(1, 1, 2026));
        $this->assertSame('12/LPA/XII/2026', PenomoranLaporanService::format(12, 12, 2026));
        $this->assertSame('123/LPA/IX/2027', PenomoranLaporanService::format(123, 9, 2027));
    }
}
