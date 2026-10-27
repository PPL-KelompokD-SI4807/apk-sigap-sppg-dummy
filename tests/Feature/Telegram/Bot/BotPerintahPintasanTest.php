<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\User;
use App\Services\Telegram\Bot\BotCallbackHandler;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\TelegramUpdateHandler;
use App\Support\Rbac;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Perintah pintasan (R02-A1, bagian 3.9): tiap perintah membuka layar yang sama dengan tombolnya lewat BotNavigasi,
 * tunduk aturan sesi penuh + RBAC + SPPG saat dijalankan, dan dikirim dengan protect_content.
 */
class BotPerintahPintasanTest extends BotTestCase
{
    /** Modul layar uji per id perintah (mengikuti matriks 3.4). */
    private const MODUL = [
        'jd' => 'jadwal', 'st' => 'bahan-baku', 'kl' => 'keluhan', 'rk' => 'rekap-harian',
        'ku' => 'keuangan', 'ag' => 'keuangan', 'ds' => 'bukti-distribusi', 'mt' => 'mutu',
    ];

    private const PERAN = ['admin', 'kepalaA', 'ahliGiziA', 'akuntanA', 'stafGudangA', 'relawanA', 'relawanDistribusi', 'relawanTanpaUnit', 'investorAB', 'investorC'];

    protected function setUp(): void
    {
        parent::setUp();
        LayarUji::$render = [];
        $layar = BotLayarRegistry::layarBawaan();

        foreach (self::MODUL as $id => $modul) {
            $layar[] = new LayarUji($id, $modul);
        }

        $this->app->instance(BotLayarRegistry::class, new BotLayarRegistry($layar));
    }

    private function pengguna(string $peran): User
    {
        return match ($peran) {
            'relawanDistribusi' => $this->userOf(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi),
            'relawanTanpaUnit' => $this->relawanTanpaUnit(),
            default => $this->{$peran},
        };
    }

    private function relawanTanpaUnit(): User
    {
        $user = $this->userOf(Role::Relawan, $this->sppgA);
        $user->forceFill(['unit' => null])->save();

        return $user->fresh();
    }

    /** @return iterable<string, array{string}> */
    public static function peran(): iterable
    {
        foreach (self::PERAN as $p) {
            yield $p => [$p];
        }
    }

    #[DataProvider('peran')]
    public function test_setiap_perintah_mengikuti_rbac_dan_sppg_peran_saat_ini(string $peran): void
    {
        $user = $this->pengguna($peran);
        $this->sesiPenuh($user);
        $uid = (int) $user->telegram_id;

        foreach (BotLayarRegistry::PERINTAH as $perintah => $id) {
            $modul = self::MODUL[$id];
            LayarUji::$render = [];
            $this->ketik($user, '/'.$perintah);
            $pesan = $this->messagesTo($uid)->last();
            $berhak = Rbac::can($user, $modul, 'R') && ! Rbac::isSelfOnly($user, $modul, 'R');

            if (! $berhak) {
                $this->assertSame(TelegramUpdateHandler::PERINTAH_TIDAK_TERSEDIA, $pesan['text'], "{$peran} /{$perintah}");
                $this->assertSame([], LayarUji::$render);

                continue;
            }

            if ($user->isAdmin() || $peran === 'investorAB') {
                $this->assertSame(BotCallbackHandler::PILIH_SPPG, $pesan['text'], "{$peran} /{$perintah}: SPPG belum dipilih");
                $this->assertSame([], LayarUji::$render);

                continue;
            }

            $sppg = $peran === 'investorC' ? $this->sppgC : $this->sppgA;
            $this->assertStringStartsWith("LAYAR-{$id} SPPG-{$sppg} ", $pesan['text'], "{$peran} /{$perintah}");
            $this->assertSame('true', $pesan['protect_content'], 'Pesan data terlindungi');
            $this->assertCount(1, LayarUji::$render);
        }
    }

    public function test_perintah_dengan_nama_bot_dan_huruf_besar(): void
    {
        $this->sesiPenuh($this->kepalaA);

        $this->ketik($this->kepalaA, '/jadwal@SigapFiktifBot');
        $this->assertStringStartsWith('LAYAR-jd', $this->teksLayar($this->kepalaA));
        $this->ketik($this->kepalaA, '/STOK');
        $this->assertStringStartsWith('LAYAR-st', $this->teksLayar($this->kepalaA));

        // Argumen tambahan bukan perintah pintasan: tidak membuka layar (diteruskan seperti teks biasa).
        LayarUji::$render = [];
        $this->ketik($this->kepalaA, '/jadwal besok');
        $this->assertSame([], LayarUji::$render);
    }

    public function test_layar_belum_dibangun_dijawab_tidak_tersedia(): void
    {
        $this->app->instance(BotLayarRegistry::class, new BotLayarRegistry(BotLayarRegistry::layarBawaan()));
        $this->sesiPenuh($this->kepalaA);

        foreach (array_keys(BotLayarRegistry::PERINTAH) as $perintah) {
            $this->ketik($this->kepalaA, '/'.$perintah);
            $this->assertSame(TelegramUpdateHandler::PERINTAH_TIDAK_TERSEDIA, $this->teksLayar($this->kepalaA), $perintah);
        }
    }

    public function test_perintah_pintasan_ikut_batas_laju_perintah(): void
    {
        config(['telegram.perintah_rate' => 3]);
        $this->sesiPenuh($this->kepalaA);

        for ($i = 0; $i < 5; $i++) {
            $this->ketik($this->kepalaA, '/rekap');
        }

        $this->assertCount(3, $this->messagesTo((int) $this->kepalaA->telegram_id));
        $this->assertCount(3, LayarUji::$render);
    }

    public function test_menu_start_dan_bantuan_dikirim_terlindungi_dengan_sesi(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $uid = (int) $this->kepalaA->telegram_id;

        foreach (['/menu', '/start', '/bantuan'] as $perintah) {
            $this->ketik($this->kepalaA, $perintah);
            $this->assertSame('true', $this->messagesTo($uid)->last()['protect_content'] ?? null, $perintah);
        }
    }
}
