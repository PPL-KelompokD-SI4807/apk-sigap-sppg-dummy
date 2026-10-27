<?php

namespace Tests\Feature\Telegram\Bot;

use App\Models\BotAksi;
use App\Models\FeedbackKeluhan;
use App\Models\Sppg;
use App\Services\Auth\SesiService;
use App\Services\Telegram\Bot\BotCallbackHandler;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotLayarService;
use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Aturan 7 untuk layar baca NYATA (R02-B1): tanpa sesi login penuh, setiap layar data, perintah pintasan, dan Segarkan
 * hanya menghasilkan PERLU_MASUK + satu tombol masuk untuk SEMUA peran (termasuk investor satu/banyak SPPG); adapter tool
 * baca tidak dipanggil. Juga paginasi daftar dan pemilih SPPG.
 */
class BotLayarBacaSesiTest extends LayarBacaTestCase
{
    private const PERAN = ['admin', 'kepalaA', 'ahliGiziA', 'akuntanA', 'stafGudangA', 'relawanA', 'relawanDistribusi', 'relawanTanpaUnit', 'investorAB', 'investorC'];

    /** @return iterable<string, array{string}> */
    public static function peran(): iterable
    {
        foreach (self::PERAN as $p) {
            yield $p => [$p];
        }
    }

    #[DataProvider('peran')]
    public function test_tanpa_sesi_penuh_semua_layar_baca_dan_perintah_hanya_tombol_masuk(string $peran): void
    {
        $this->mock(BotLayarService::class, function (MockInterface $m): void {
            $m->shouldNotReceive('daftar');
            $m->shouldNotReceive('daftarTool');
            $m->shouldNotReceive('tersedia');
        });

        $user = $this->pengguna($peran);
        $uid = (int) $user->telegram_id;
        $sppg = $this->sppgDari($peran);
        $ids = $this->idLayarData();
        $this->assertNotEmpty($ids);

        // Variasi: tanpa token sesi sama sekali, lalu sesi dibuat kemudian dicabut setelah tombol dirender.
        foreach (['tanpa_token', 'dicabut'] as $varian) {
            $this->fakeTelegram();

            if ($varian === 'dicabut') {
                $this->sesiPenuh($user);
                $token = $this->n1($user, 'jd', $sppg);
                app(SesiService::class)->keluarSemua($user);
                $this->assertFalse(app(SesiService::class)->punyaSesiPenuh($user->fresh()));
                $this->tekan($user, $token);
            }

            foreach ($ids as $id) {
                $this->tekan($user, $this->n1($user, $id, $sppg));
                $this->tekan($user, $this->n1($user, $id, $sppg)); // Segarkan / klik ulang
            }

            foreach (array_keys(BotLayarRegistry::PERINTAH) as $perintah) {
                $this->ketik($user, '/'.$perintah);
            }

            $metode = collect(Http::recorded())->map(fn (array $p) => basename((string) parse_url($p[0]->url(), PHP_URL_PATH)))->unique()->values()->all();
            $this->assertEmpty(array_diff($metode, ['sendMessage', 'answerCallbackQuery', 'editMessageText']), "{$peran}/{$varian}: metode tak terduga ".implode(',', $metode));

            foreach ($this->telegramCalls('editMessageText') as $edit) {
                $this->assertSame(BotCallbackHandler::LAYAR_DITUTUP_SESI, $edit['text'], 'Edit pesan asal hanya teks netral');
                $this->assertSame([], $this->markupOf($edit)['inline_keyboard'] ?? []);
            }

            $pesan = $this->messagesTo($uid);
            $this->assertNotEmpty($pesan);
            $pesan->each(function ($p) use ($user, $peran): void {
                $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $p['text'], $peran);
                $this->assertSame(
                    [[['text' => TelegramUpdateHandler::LABEL_MASUK, 'web_app' => ['url' => 'https://sigap.test/miniapp/']]]],
                    $this->markupOf($p)['inline_keyboard'],
                );
                $this->assertStringNotContainsString($user->name, (string) $p['text']);
            });

            foreach ($this->telegramCalls('answerCallbackQuery') as $jawab) {
                $this->assertSame(BotCallbackHandler::PERLU_MASUK_SINGKAT, $jawab['text']);
            }
        }

        $this->assertSame(0, BotAksi::query()->count());
    }

    // ------------------------------------------------------------------ paginasi

    private function banyakKeluhan(int $jumlah): void
    {
        $this->forgetActor();

        for ($i = 1; $i <= $jumlah; $i++) {
            FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA, 'status' => 'baru', 'prioritas' => 'sedang', 'deskripsi' => sprintf('Keluhan antrean %02d', $i)]);
        }
    }

    public function test_paginasi_daftar_lima_baris_sebelumnya_berikutnya_tanpa_duplikat(): void
    {
        $this->banyakKeluhan(12);
        $this->sesiPenuh($this->kepalaA);
        $uid = (int) $this->kepalaA->telegram_id;

        $h1 = $this->buka($this->kepalaA, 'kl');
        $this->assertSame(5, substr_count($h1, 'Keluhan antrean'));
        $this->assertStringContainsString('antrean 12', $h1);
        $this->assertTrue($this->adaTombol($this->kepalaA, 'Berikutnya'));
        $this->assertFalse($this->adaTombol($this->kepalaA, 'Sebelumnya'));

        $this->tekan($this->kepalaA, $this->dataTombol($this->kepalaA, 'Berikutnya'));
        $h2 = $this->teksLayar($this->kepalaA);
        $this->assertStringContainsString('(hal. 2)', $h2);
        $this->assertSame(5, substr_count($h2, 'Keluhan antrean'));
        $this->assertStringContainsString('antrean 07', $h2);
        $this->assertStringNotContainsString('antrean 12', $h2);
        $this->assertTrue($this->adaTombol($this->kepalaA, 'Sebelumnya'));

        $this->tekan($this->kepalaA, $this->dataTombol($this->kepalaA, 'Berikutnya'));
        $h3 = $this->teksLayar($this->kepalaA);
        $this->assertSame(2, substr_count($h3, 'Keluhan antrean'));
        $this->assertStringContainsString('antrean 01', $h3);
        $this->assertFalse($this->adaTombol($this->kepalaA, 'Berikutnya'));

        // Segarkan mempertahankan halaman; Sebelumnya kembali ke halaman 2.
        $this->tekan($this->kepalaA, $this->dataTombol($this->kepalaA, 'Segarkan'));
        $this->assertStringContainsString('(hal. 3)', $this->teksLayar($this->kepalaA));
        $this->tekan($this->kepalaA, $this->dataTombol($this->kepalaA, 'Sebelumnya'));
        $this->assertStringContainsString('(hal. 2)', $this->teksLayar($this->kepalaA));

        foreach ($this->tombolLayar($uid) as $t) {
            if (isset($t['callback_data'])) {
                $this->assertLessThan(64, strlen($t['callback_data']));
            }
        }
    }

    public function test_paginasi_dibatasi_dua_puluh_baris_dan_halaman_palsu_ditolak_hak(): void
    {
        $this->banyakKeluhan(25);
        $this->sesiPenuh($this->kepalaA, $this->relawanA);

        $h4 = $this->buka($this->kepalaA, 'kl4');
        $this->assertStringContainsString('(hal. 4)', $h4);
        $this->assertStringContainsString('Daftar dibatasi 20 baris', $h4);
        $this->assertFalse($this->adaTombol($this->kepalaA, 'Berikutnya'));

        // Halaman tidak mengubah hak: relawan tanpa hak pada layar tetap ditolak walau id berakhiran halaman.
        $this->assertNull($this->buka($this->relawanA, 'st3'));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
        $this->assertNull($this->buka($this->kepalaA, 'zz9'), 'Layar tak dikenal');
    }

    public function test_pilih_sppg_paginasi_untuk_admin(): void
    {
        for ($i = 1; $i <= 18; $i++) {
            $this->createSppg(sprintf('SPPG Uji %02d', $i));
        }

        $this->sesiPenuh($this->admin);
        $this->forgetActor();
        $total = Sppg::query()->aktif()->count();
        $this->assertGreaterThan(16, $total);

        $this->buka($this->admin, 'ps', null);
        $pilihan = fn (): int => count(array_filter($this->tombolLayar((int) $this->admin->telegram_id), fn ($t) => str_starts_with((string) $t['text'], 'SPPG')));
        $this->assertSame(8, $pilihan());
        $this->assertTrue($this->adaTombol($this->admin, 'Berikutnya'));

        $this->tekan($this->admin, $this->dataTombol($this->admin, 'Berikutnya'));
        $this->assertSame(8, $pilihan());
        $this->assertStringContainsString('(hal. 2)', $this->teksLayar($this->admin));
        $this->assertTrue($this->adaTombol($this->admin, 'Sebelumnya'));

        $this->tekan($this->admin, $this->dataTombol($this->admin, 'Berikutnya'));
        $this->assertSame($total - 16, $pilihan());
        $this->assertFalse($this->adaTombol($this->admin, 'Berikutnya'));

        // Memilih dari halaman lanjut membuka beranda SPPG tsb.
        $this->tekan($this->admin, $this->dataTombol($this->admin, 'SPPG'));
        $this->assertStringContainsString('Menu SiGAP', $this->teksLayar($this->admin));
    }

    public function test_pilih_sppg_investor_hanya_pivot_dan_tanpa_sesi_ditolak(): void
    {
        $this->sesiPenuh($this->investorAB);
        $this->buka($this->investorAB, 'ps', null);
        $this->assertTrue($this->adaTombol($this->investorAB, 'SPPG A'));
        $this->assertTrue($this->adaTombol($this->investorAB, 'SPPG B'));
        $this->assertFalse($this->adaTombol($this->investorAB, 'SPPG C'));
        $this->assertFalse($this->adaTombol($this->investorAB, 'Berikutnya'));

        $this->sesiPenuh($this->kepalaA);
        $this->assertNull($this->buka($this->kepalaA, 'ps', null), 'Peran satu-SPPG tidak punya pemilih');
    }
}
