<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\Role;
use App\Services\Telegram\Bot\BotCallbackHandler;
use App\Services\Telegram\Bot\BotNavCodec;
use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Matriks layar baca nyata per peran/profil (R02-B1) terhadap config/rbac.php dan tes IDOR lintas SPPG.
 * Daftar layar yang boleh dibuka ditulis TANGAN dari matriks Tabel 5 (bukan dihitung ulang dari kode produksi):
 * layar di luar daftar harus ditolak tanpa data (alert "tidak berhak"), layar di dalam daftar harus dirender.
 */
class BotLayarBacaMatriksTest extends LayarBacaTestCase
{
    private const SEMUA = ['jd', 'ds', 'ar', 'rk', 'kl', 'ku', 'pc', 'pk', 'ag', 'st', 'sm', 'kd', 'mt', 'lg', 'tl', 'tm', 'np', 'mg', 'mh', 'pn', 'pe', 'pb', 'pj', 'pf'];

    /** @return array<string, list<string>> peran => id layar yang boleh dibuka (dari rbac.php) */
    private static function boleh(): array
    {
        $tanpa = fn (array $buang): array => array_values(array_diff(self::SEMUA, $buang));

        return [
            'admin' => self::SEMUA,
            'kepalaA' => self::SEMUA,
            'ahliGiziA' => $tanpa(['tm', 'np', 'ar', 'ku', 'pc', 'pk', 'ag', 'pb']),
            'akuntanA' => $tanpa(['tm', 'np', 'pn', 'mg', 'mh', 'jd', 'mt', 'kl', 'pj']),
            'stafGudangA' => $tanpa(['tm', 'np', 'pn', 'mt', 'ar', 'ds', 'ku', 'pc', 'pk', 'ag', 'kl', 'rk', 'pj']),
            'relawanA' => ['jd', 'lg', 'mg', 'mh', 'mt', 'ar', 'tl', 'kl', 'pf'],
            'relawanDistribusi' => ['jd', 'ds', 'np', 'mg', 'mh', 'mt', 'ar', 'tl', 'kl', 'pf'],
            'relawanTanpaUnit' => ['mg', 'mh', 'mt', 'ar', 'tl', 'kl', 'pf'],
            'investorAB' => ['st', 'sm', 'kd', 'mt', 'ku', 'pc', 'pk', 'ag', 'kl', 'rk', 'pj', 'pn', 'pf'],
            'investorC' => ['st', 'sm', 'kd', 'mt', 'ku', 'pc', 'pk', 'ag', 'kl', 'rk', 'pj', 'pn', 'pf'],
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function peran(): iterable
    {
        foreach (array_keys(self::boleh()) as $p) {
            yield $p => [$p];
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->isiData($this->sppgA, 'Alfa');
        $this->isiData($this->sppgB, 'Beta');
        $this->isiData($this->sppgC, 'Gamma');
    }

    public function test_matriks_ditulis_tangan_mencakup_semua_layar_data_terdaftar(): void
    {
        $this->assertEqualsCanonicalizing(self::SEMUA, $this->idLayarData(), 'Layar baru wajib masuk matriks tes ini');
    }

    #[DataProvider('peran')]
    public function test_layar_sesuai_rbac_peran(string $peran): void
    {
        $user = $this->pengguna($peran);
        $this->sesiPenuh($user);
        $sppg = $this->sppgDari($peran);
        $boleh = self::boleh()[$peran];

        foreach (self::SEMUA as $id) {
            $teks = $this->buka($user, $id, $sppg);

            if (in_array($id, $boleh, true)) {
                $this->assertNotNull($teks, "{$peran} harus bisa membuka {$id}. Jawaban: ".$this->jawabanTerakhir());
                $this->assertStringNotContainsString('Terjadi kesalahan', $teks);
                $this->assertTanpaTerlarang($teks, "{$peran}/{$id}");

                continue;
            }

            $this->assertNull($teks, "{$peran} TIDAK boleh membuka {$id}");
            $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir(), "{$peran}/{$id}");
            $this->assertSame('true', $this->telegramCalls('answerCallbackQuery')->last()['show_alert']);
        }
    }

    #[DataProvider('peran')]
    public function test_tombol_beranda_hanya_menunjuk_layar_yang_boleh_dibuka(string $peran): void
    {
        $user = $this->pengguna($peran);
        $this->sesiPenuh($user);

        $this->ketik($user, '/menu');

        if ($user->isAdmin() || $peran === 'investorAB') {
            $this->tekan($user, $this->dataTombol($user, 'Pilih SPPG'));
            $this->tekan($user, $this->dataTombol($user, 'SPPG A'));
        }

        $dilihat = 0;

        foreach ($this->tombolLayar((int) $user->telegram_id) as $t) {
            $tujuan = isset($t['callback_data']) ? BotNavCodec::urai($t['callback_data'], (int) $user->telegram_id) : null;

            if ($tujuan === null || ! in_array($tujuan->layar, self::SEMUA, true)) {
                continue;
            }

            $dilihat++;
            $this->assertContains($tujuan->layar, self::boleh()[$peran], "{$peran}: tombol beranda menunjuk layar terlarang {$tujuan->layar}");
        }

        $this->assertGreaterThan(0, $dilihat, "{$peran} harus punya tombol layar data");
    }

    // ------------------------------------------------------------------ IDOR lintas SPPG

    public function test_peran_satu_sppg_tidak_bisa_berpindah_sppg_lewat_token(): void
    {
        $this->sesiPenuh($this->kepalaA, $this->kepalaB);

        foreach (['kl', 'st', 'pk', 'tl', 'rk', 'pj'] as $id) {
            $this->assertNull($this->buka($this->kepalaA, $id, $this->sppgB), "kepalaA -> SPPG B / {$id}");
            $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());

            $milikB = $this->buka($this->kepalaB, $id, $this->sppgB);
            $this->assertNotNull($milikB);
            $this->assertStringNotContainsString('Alfa', $milikB, "kepalaB/{$id} bocor SPPG A");
            $this->assertStringNotContainsString('Gamma', $milikB);
        }
    }

    public function test_investor_hanya_sppg_miliknya_dan_pivot_dicabut_menolak_klik_berikutnya(): void
    {
        $this->sesiPenuh($this->investorAB, $this->investorC);

        // SPPG B milik investor AB; C bukan; A bukan milik investor C.
        $this->assertNotNull($this->buka($this->investorAB, 'pk', $this->sppgB));
        $this->assertNull($this->buka($this->investorAB, 'pk', $this->sppgC));
        $this->assertNull($this->buka($this->investorC, 'pk', $this->sppgA));
        $this->assertNull($this->buka($this->investorC, 'pk', $this->sppgB));
        $this->assertStringNotContainsString('Alfa', (string) $this->buka($this->investorAB, 'kl', $this->sppgB));

        DB::table('investor_sppg')->where('user_id', $this->investorAB->id)->where('sppg_id', $this->sppgB)->delete();
        $this->assertNull($this->buka($this->investorAB, 'pk', $this->sppgB), 'Pivot dicabut: klik token lama ditolak');
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
        $this->assertNotNull($this->buka($this->investorAB, 'pk', $this->sppgA), 'SPPG A masih miliknya');
    }

    public function test_peran_diubah_pengguna_nonaktif_dan_sesi_dicabut_di_antara_render_dan_klik(): void
    {
        $this->sesiPenuh($this->akuntanA);
        $token = $this->n1($this->akuntanA, 'pk', $this->sppgA);
        $this->tekan($this->akuntanA, $token);
        $this->assertStringContainsString('Pengeluaran', $this->teksLayar($this->akuntanA));
        $sebelum = $this->jumlahEditData();

        // Peran berubah di basis data tanpa mencabut sesi: layar keuangan ditolak.
        DB::table('users')->where('id', $this->akuntanA->id)->update(['role' => Role::StafGudang->value]);
        $this->tekan($this->akuntanA, $token);
        $this->assertSame($sebelum, $this->jumlahEditData());
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());

        // Pengguna dinonaktifkan: dijawab seragam, tanpa data.
        DB::table('users')->where('id', $this->akuntanA->id)->update(['is_active' => false]);
        $this->tekan($this->akuntanA, $token);
        $this->assertSame($sebelum, $this->jumlahEditData());
        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());
    }

    public function test_token_milik_pengguna_lain_ditolak_seragam(): void
    {
        $this->sesiPenuh($this->kepalaA, $this->akuntanA);
        $milikKepala = $this->n1($this->kepalaA, 'pk', $this->sppgA);
        $sebelum = $this->jumlahEditData();

        $this->tekan($this->akuntanA, $milikKepala);

        $this->assertSame($sebelum, $this->jumlahEditData());
        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());
    }

    public function test_layar_berat_dijeda_dan_pulih_setelah_jeda(): void
    {
        config(['telegram.layar_berat_jeda_detik' => 3]);
        $this->sesiPenuh($this->kepalaA);

        $this->assertNotNull($this->buka($this->kepalaA, 'rk', $this->sppgA));
        $this->assertNull($this->buka($this->kepalaA, 'rk', $this->sppgA), 'Segarkan beruntun dijeda');
        $this->assertSame('Terlalu cepat. Coba lagi sebentar.', $this->jawabanTerakhir());
        $this->assertNotNull($this->buka($this->kepalaA, 'st', $this->sppgA), 'Layar ringan tidak dijeda');
    }

    public function test_perintah_pintasan_membuka_layar_nyata_terlindungi_dan_ditolak_bila_tanpa_hak(): void
    {
        $this->sesiPenuh($this->kepalaA, $this->akuntanA, $this->stafGudangA);
        $uid = (int) $this->kepalaA->telegram_id;

        foreach (['jadwal' => 'Jadwal Hari Ini', 'stok' => 'Stok Bahan Baku', 'keluhan' => 'Keluhan Terbuka', 'rekap' => 'Rekap Harian', 'keuangan' => 'Keuangan', 'anggaran' => 'Anggaran', 'distribusi' => 'Distribusi Hari Ini', 'mutu' => 'Pemeriksaan Mutu'] as $perintah => $judul) {
            $this->ketik($this->kepalaA, '/'.$perintah);
            $pesan = $this->messagesTo($uid)->last();
            $this->assertStringContainsString("<b>{$judul}</b>", $pesan['text'], $perintah);
            $this->assertSame('true', $pesan['protect_content']);
        }

        $this->ketik($this->akuntanA, '/anggaran');
        $this->assertStringContainsString('<b>Anggaran</b>', $this->teksLayar($this->akuntanA));
        $this->ketik($this->stafGudangA, '/keuangan');
        $this->assertSame(TelegramUpdateHandler::PERINTAH_TIDAK_TERSEDIA, $this->teksLayar($this->stafGudangA));
        $this->ketik($this->stafGudangA, '/stok');
        $this->assertStringContainsString('<b>Stok Bahan Baku</b>', $this->teksLayar($this->stafGudangA));
    }
}
